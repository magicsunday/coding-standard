<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Guard against vacuous phpat architecture rules.
 *
 * phpat rules run inside PHPStan, and a rule whose SUBJECT selector matches nothing
 * enforces nothing while looking active — PHPStan and PHPUnit both stay green. This
 * already bit a consumer once: a rule whose subject was a `Traits` namespace was a
 * silent no-op, because phpat resolves a subject through PHPStan's `InClassNode`,
 * which never fires for a trait.
 *
 * This checker parses a consumer's `ArchitectureTest`, extracts each rule method's
 * subject selector, and asserts the subject matches at least one real class in `src/`.
 * phpat itself discovers a rule method two ways — `PHPat\Test\TestParser` accepts a
 * PUBLIC method carrying the `#[TestRule]` attribute OR one whose name starts with
 * `test` (the exact regex is reproduced, and re-derived rather than trusted, at its
 * own comment below) — and this gate recognises both, or a repository writing its
 * rules in the `test*` naming style would get a false "no rule methods found" while
 * phpat runs those rules perfectly well. Both paths are read from this ONE file only:
 * a rule method phpat picks up via reflection from an inherited base class or a `use`d
 * trait — real by either discovery path, just declared somewhere else — is invisible
 * to this gate, which tokenises `ArchitectureTest.php` alone. Pre-existing for the
 * attribute path; carried over unchanged for the name-based one, not a new gap this
 * adds.
 *
 * A subject is a selector EXPRESSION (GH-190), evaluated to the SET of `src/`
 * declarations it selects, and a rule is live iff that set is non-empty. Each
 * argument of a variadic `->classes(a, b)` is a rule of its own to phpat and is
 * checked on its own. The selectors this gate evaluates, each replicating phpat's own
 * `matches()` (measured against phpat itself — see the evaluation section below):
 *   - `inNamespace(NS[, regex])`, `classname(FQCN[, regex])`, `implements(X[, regex])`,
 *     `extends(X[, regex])` — `implements`/`extends` transitively, through `src/`;
 *   - `isInterface()`, `isAbstract()`, `isEnum()`, `isTrait()`, `all()`;
 *   - `AllOf(…)`, `AnyOf(…)`, `NoneOf(…)`, `Not(x)` — intersection, union and
 *     complement against every class phpat can see, nested to any depth up to
 *     MAX_SELECTOR_DEPTH.
 * An argument is a single-quoted string, `self::NAMESPACE_ROOT`, `Foo::class`
 * (resolved through the ArchitectureTest's own `use` imports), `.`-concatenations of
 * those, or `true`/`false`. The liveness verdict itself:
 *   - a trait never counts: phpat resolves a subject through PHPStan's InClassNode,
 *     which never fires for a trait (the manifested bug — a trait-only namespace);
 *   - a bare top-level `Selector::isAbstract()` subject is NOT liveness-checked: it is
 *     a conditional naming guard that legitimately matches nothing until an abstract
 *     class is added, so an empty match is correct, not a bug (inside a composite it
 *     is an ordinary set);
 *   - `->excluding(…)` is not evaluated, for the same conditional-guard reason.
 * Any other selector, argument shape or malformed expression fails closed.
 *
 * It is a STATIC check — it does not run PHPStan — so it verifies the one invariant the
 * vacuous-rule trap violates (the subject is non-empty), not the full rule mechanics.
 * It fails CLOSED: every rule method, found either way, must yield a classifiable
 * subject, or the run reds.
 *
 * Usage (from a consumer repo root, wired as a `ci:test:php:phpat-subjects` script):
 *
 *     php .build/vendor/magicsunday/coding-standard/bin/check-phpat-subjects.php .
 *
 * Exit 0 = nothing to check (no ArchitectureTest), or every liveness-checked
 * subject matches a class; 1 = a vacuous or unparseable subject, or a src/ file
 * this gate could not read; 2 = the gate could not run at all (bad arguments, no
 * src/ directory, or an ArchitectureTest this gate could not read).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

// This is a global-namespace entry script, so built-in functions are called
// unqualified (a `use function` import would be a no-op here).

// safeReportValue() — shared, see its header for the boundary and the requirers.
// A consumer's phpat subject expression reaches this gate's report.
require_once __DIR__ . '/support/safe-report-value.php';

// readCapped() — shared, see its header. Both the ArchitectureTest and every
// `src/*.php` below are pull-request content in the CONSUMER's CI: the read is
// capped at MAX_SOURCE_BYTES (an uncapped read of a huge file ends in `Allowed
// memory size exhausted`, exit 255, no gate diagnostic), and PHP's own E_WARNING
// on an unreadable file is suppressed so it cannot land ahead of this gate's own
// diagnostic carrying a path that never passed safeReportValue().
require_once __DIR__ . '/support/read-quietly.php';

/**
 * The largest PHP source this gate reads, in bytes.
 *
 * A quarter of a megabyte. The ArchitectureTest of the largest first-party consumer
 * is under 8 KB and no `src/` class file comes near this, so the bound only ever
 * meets a file no consumer wrote by hand. Re-derive before raising it:
 * `find src -name '*.php' -printf '%s\n' | sort -n | tail -1`.
 */
const MAX_SOURCE_BYTES = 262144;

$repoRoot = $argv[1] ?? '.';

if (!is_dir($repoRoot)) {
    fwrite(\STDERR, sprintf("Not a directory: %s\n", $repoRoot));
    exit(2);
}

$srcDir = $repoRoot . '/src';

// Locate the ArchitectureTest — the phpat rule class. It lives under tests/, by
// convention at tests/Architecture/ArchitectureTest.php.
$architectureTest = null;

foreach (['/tests/Architecture/ArchitectureTest.php', '/tests/ArchitectureTest.php'] as $candidate) {
    if (is_file($repoRoot . $candidate)) {
        $architectureTest = $repoRoot . $candidate;

        break;
    }
}

if ($architectureTest === null) {
    // A module that ships no phpat rules has nothing to guard — skip cleanly.
    fwrite(\STDOUT, "check-phpat-subjects: no ArchitectureTest found — nothing to check.\n");
    exit(0);
}

if (!is_dir($srcDir)) {
    fwrite(\STDERR, sprintf("check-phpat-subjects: %s has an ArchitectureTest but no src/ directory.\n", $repoRoot));
    exit(2);
}

/**
 * Strips comments and doc-comments from the ArchitectureTest source.
 *
 * This is not only needed for the NAMESPACE_ROOT constant-name token walk below:
 * $ruleTokens further down is `token_get_all()` of THIS function's OWN output, so a
 * bug here reaches rule discovery and alias resolution too, and the class inventory
 * walk tokenises each `src/*.php` file through this same closure as well — a bug
 * here is not confined to ArchitectureTest.php.
 *
 * A comment spanning ZERO newlines must contribute a real character, not an empty
 * string: two token TEXTS either side of such a comment otherwise concatenate into ONE
 * token on re-tokenisation. `as/**\/Alias` (a same-line comment between `as` and an
 * alias name) stripped to nothing there becomes `asAlias`, destroying the `T_AS` token
 * the alias-resolution scan depends on — verified live: `use Foo\Bar as/**\/Alias;`
 * re-tokenises with no `T_AS` at all, so `Alias` is never added to $testRuleAliases,
 * and a #[Alias]-attributed vacuous rule escapes detection whenever the file also has
 * one other genuine rule. The identical mechanism (`function/**\/testFoo` collapsing to
 * `functiontestFoo`, losing the `T_FUNCTION` token entirely) also hides a rule from the
 * test*-name discovery path. A single space — never itself a valid substring of another
 * token, so it can only ever ADD a boundary, never remove one the real source didn't
 * already have — is inserted whenever the comment carries no newline; a multi-line
 * comment still contributes only its own newlines. That newline count is cosmetic —
 * nothing in this file reads a token's line offset — kept only so the stripped
 * output's line count matches the original source for anyone reading it while
 * debugging.
 *
 * @param string $code The raw PHP source.
 *
 * @return string The source with every comment token blanked out.
 */
$stripComments = static function (string $code): string {
    $result = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if (($token[0] === \T_COMMENT) || ($token[0] === \T_DOC_COMMENT)) {
                $newlineCount = substr_count($token[1], "\n");

                // A multi-line comment still contributes only its own newlines
                // (cosmetic — kept for line-count parity with the source, see the
                // docblock above); a same-line comment gets a single space instead
                // of nothing, so it cannot glue its neighbouring tokens together.
                $result .= ($newlineCount > 0) ? str_repeat("\n", $newlineCount) : ' ';

                continue;
            }

            $result .= $token[1];

            continue;
        }

        $result .= $token;
    }

    return $result;
};

$sourceRaw = readCapped($architectureTest, MAX_SOURCE_BYTES);

// Two causes, two reports, and exit 2 for both: neither is drift the consumer can
// fix in a rule, they are conditions under which this gate did not run. Collapsing
// them into one sentence sends the reader to split a file that a permission bit put
// out of reach, and reporting either as exit 1 puts a setup failure in the drift
// bucket this file keeps apart everywhere else.
if ($sourceRaw === false) {
    fwrite(\STDERR, sprintf(
        "check-phpat-subjects: %s cannot be read.\n",
        safeReportValue($architectureTest)
    ));

    exit(2);
}

if ($sourceRaw === null) {
    fwrite(\STDERR, sprintf(
        "check-phpat-subjects: %s is larger than the %d bytes this gate reads.\n",
        safeReportValue($architectureTest),
        MAX_SOURCE_BYTES
    ));

    exit(2);
}

$source = $stripComments($sourceRaw);

/**
 * Returns the first name token reached while scanning forward from a token index,
 * skipping only the given token kinds — null once a non-skipped, non-name token is
 * reached, since that means no name follows.
 *
 * Four callers share this closure: the class inventory's namespace-name and
 * class-name lookaheads, the TestRule-alias-name lookahead in
 * $resolveTestRuleAliases, and the rule-discovery method-name lookahead further
 * below — so the accepted skip-set lives in exactly one place per caller rather
 * than each carrying its own copy of the same "skip a set of kinds, take the first
 * name token" loop. Two of those call sites drifted apart once already when only
 * one of them grew a second skip-kind (return-by-reference's
 * T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG), and the namespace-name lookahead went
 * unconsolidated for a further round because its accepted name-kind set differs
 * (T_STRING or T_NAME_QUALIFIED, since a namespace segment can be a single
 * identifier or an already-qualified one) — hence $nameKinds, defaulted to the
 * plain-identifier-only case every other caller needs.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens    The token stream to scan.
 * @param int                                           $start     The index to start scanning from (inclusive).
 * @param int                                           $count     The token count (exclusive upper bound).
 * @param list<int>                                     $skipKinds Token kinds to skip past before the name.
 * @param list<int>                                     $nameKinds Token kinds accepted as the name itself.
 *
 * @return string|null The name, or null when none follows.
 */
$nextName = static function (array $tokens, int $start, int $count, array $skipKinds, array $nameKinds = [\T_STRING]): ?string {
    for ($ahead = $start; $ahead < $count; ++$ahead) {
        $next = $tokens[$ahead];

        if (is_array($next) && in_array($next[0], $skipKinds, true)) {
            continue;
        }

        return (is_array($next) && in_array($next[0], $nameKinds, true)) ? $next[1] : null;
    }

    return null;
};

// --- Resolve the module root namespace (the NAMESPACE_ROOT constant) ---
//
// Tokens, not a substring search over the whole file — the same reason the class
// inventory further below is token-based rather than a regex. A `preg_match` here
// matches the same-looking text ANYWHERE in $source, including inside an unrelated
// string literal: verified live, a decoy class constant whose STRING VALUE happens to
// read `const string NAMESPACE_ROOT = '...'`, declared before the real one, resolved
// every subject in the file against the decoy's value instead — a rule targeting the
// REAL namespace (which has no matching class) was silently certified live because the
// decoy's value named a DIFFERENT namespace that does. `$stripComments` closes the
// comment variant of this same class of bug elsewhere in this file; it cannot close
// this one, since the decoy text lives inside a real string token, not a comment.
//
// `Type` in `const Type NAME = value;` and the constant's own NAME both tokenise as
// T_STRING (the lexer does not know one is a type and the other a name); whichever one
// is LAST before the `=` is the real name, so $name is overwritten on every T_STRING
// seen — but only up to the `=`. A T_STRING appearing in the VALUE expression itself
// (e.g. the `NAMESPACE_ROOT` segment of a qualified constant fetch,
// `Prefix::NAMESPACE_ROOT`, inside an unrelated constant's own value) must never
// overwrite $name after that point — verified live: without the `$sawEquals` guard,
// `private const string DECOY = Prefix::NAMESPACE_ROOT . 'Vendor\Fake';`, declared
// before the real constant, mistook DECOY's own value expression for a NAMESPACE_ROOT
// declaration and hijacked resolution, the same failure this rewrite otherwise closes.
//
// A single `T_CONST` token covers the WHOLE statement, including a comma-separated
// list of several constants (`const A = 'x', NAMESPACE_ROOT = 'y';`) — verified live:
// checking only the first name/value pair per T_CONST left NAMESPACE_ROOT unresolved
// whenever it was not the first constant in such a list. Each `,` inside the
// statement starts a new name/value pair, so $name and $sawEquals reset there and
// every pair is checked, not just the first.
//
// Two narrower gaps remain, deliberately undefended, the same disposition as the
// bracketed-namespace and second-top-level-class gaps documented further below:
//   - This walk takes the FIRST `T_CONST` named NAMESPACE_ROOT anywhere in the file,
//     with no check on which class/trait it belongs to — a genuine (not decoy-string)
//     `const NAMESPACE_ROOT` in an earlier, unrelated top-level declaration in the
//     same file would still win by source order. This needs the same second-class
//     precondition already accepted below (nothing real produces it; PSR-1 makes it
//     conventionally rare, not syntactically impossible).
//   - Only the FIRST `T_CONSTANT_ENCAPSED_STRING` after `=` is read, not the complete
//     right-hand side, so a value built from concatenation
//     (`NAMESPACE_ROOT = 'Vendor' . '\Mod';`) resolves to only its first segment, and
//     a conditional expression (`NAMESPACE_ROOT = false ? 'Vendor\Fake' : 'Vendor\Real';`)
//     resolves to whichever literal happens to appear first, not the one PHP would
//     actually evaluate (codex-rescue, re-raised the same underlying limitation via a
//     ternary example). The regex this walk replaced had the identical limitation (it
//     matched only a literal immediately after `=`), so this is pre-existing behaviour,
//     not a regression — and a namespace-root constant is, in every real consumer, a
//     single plain string literal, never a computed expression.
$namespaceRoot  = null;
$constantTokens = token_get_all($source);
$constantCount  = count($constantTokens);

for ($index = 0; $index < $constantCount; ++$index) {
    if (!is_array($constantTokens[$index]) || ($constantTokens[$index][0] !== \T_CONST)) {
        continue;
    }

    $name      = null;
    $sawEquals = false;

    for ($ahead = $index + 1; $ahead < $constantCount; ++$ahead) {
        $next = $constantTokens[$ahead];

        if (!is_array($next)) {
            if ($next === ';') {
                break;
            }

            if ($next === ',') {
                $name      = null;
                $sawEquals = false;

                continue;
            }

            if ($next === '=') {
                $sawEquals = true;
            }

            continue;
        }

        if ($next[0] === \T_WHITESPACE) {
            continue;
        }

        if (!$sawEquals && ($next[0] === \T_STRING)) {
            $name = $next[1];

            continue;
        }

        if ($sawEquals
            && ($next[0] === \T_CONSTANT_ENCAPSED_STRING)
            && ($name === 'NAMESPACE_ROOT')
            && ($next[1][0] === "'")
        ) {
            // Single-quoted only — a double-quoted literal is NOT read as raw text
            // the way this token's own text otherwise is: PHP decodes `\n`, `\t`,
            // `\xNN` and friends in a double-quoted string, so `"Vendor\node"`
            // evaluates at runtime to `Vendor` + a real newline + `ode`, not the
            // literal text between the quotes — verified live (`php -r
            // 'var_dump("Vendor\node");'` prints a 10-byte string containing an
            // actual newline). Reading the raw token text as this gate does
            // everywhere else would silently accept a namespace argument that
            // does not match what phpat's own runtime evaluation of the SAME
            // constant produces, precisely the class of divergence this rewrite
            // exists to close. Single-quoted PHP strings have no such ambiguity
            // (only `\\` and `\'` are escapes), so restricting to them — the only
            // shape the `preg_match` this walk replaced ever accepted — keeps
            // this gate's reading and PHP's own evaluation in agreement; a
            // double-quoted NAMESPACE_ROOT value falls through to the fail-closed
            // "could not resolve" report below instead of being misread.
            //
            // A single-quoted namespace literal may be written with single or
            // escaped (`\\`) backslashes; normalise to the single-backslash form
            // the `namespace` declarations in the class inventory always use.
            // substr() strips the literal's own surrounding quote characters.
            $namespaceRoot = str_replace('\\\\', '\\', substr($next[1], 1, -1));

            break 2;
        }
    }

    // Advances the OUTER loop past everything the inner one just scanned — without
    // this, a file consisting of many `const` keywords with no terminating `;`
    // between them (tokenises fine; need not be valid PHP) makes every occurrence
    // re-scan all the way to end-of-file, O(n) work times O(n) occurrences — but
    // ONLY when NAMESPACE_ROOT is never actually found: `break 2` on a match exits
    // both loops on the FIRST occurrence, so a payload that ALSO carries a real,
    // resolvable NAMESPACE_ROOT constant (the shape the regression fixture below
    // uses, needing an accept verdict to hold) never reaches more than one inner
    // scan regardless of how many junk `const` keywords precede it — the fixture
    // proves the real constant is still found past the noise, not the quadratic
    // blowup itself, which needs an UNRESOLVABLE payload to manifest. Measured
    // live against that unresolvable shape, BEFORE this fix: an 8000-repetition
    // payload under the 256KB size cap took ~11s; a near-cap payload did not
    // finish in two minutes. ArchitectureTest.php is consumer PR content this
    // gate already treats as adversarial (the size cap above exists for exactly
    // that reason), so a CPU-time bound matters here the same way the byte
    // bound does. The same fix
    // repeats at three other sites in
    // this file with the identical shape (an inner "scan to a terminator" loop
    // whose outer loop never skipped past it): the pre-existing attribute-group
    // scan below, the TestRule-alias `use`-import walk, and the rule-method
    // body-extraction loop — each of the latter two points back here rather than
    // repeating this rationale.
    $index = $ahead - 1;
}

/**
 * Classifies a token's effect on brace depth: +1 for an opener, -1 for a closer, 0 for
 * neither. A bare CHAR `{`/`}` is the usual case; the two string-interpolation openers
 * are the exception that must also count as +1, because their CLOSING brace is an
 * ordinary CHAR `}` — `{$a}` opens with T_CURLY_OPEN, `${a}` with
 * T_DOLLAR_OPEN_CURLY_BRACES, and skipping them leaves that `}` decrementing against
 * nothing (measured: cut a live rule's body short and reported it as unparseable).
 * Shared by every depth counter in this file so this recognition rule lives in
 * exactly one place — the src/ inventory walk's import-depth tracking,
 * $resolveTestRuleAliases's own pre-pass depth, $topDepth, the ArchitectureTest's
 * own import walk and the per-method body-extraction loop all call it rather than
 * each carrying their own copy of the same four-way token check.
 *
 * @param array{0: int, 1: string, 2: int}|string $token A token from token_get_all().
 *
 * @return int Returns -1, 0 or 1.
 */
$braceDelta = static function (array|string $token): int {
    if (is_array($token)) {
        return (($token[0] === \T_CURLY_OPEN) || ($token[0] === \T_DOLLAR_OPEN_CURLY_BRACES)) ? 1 : 0;
    }

    return match ($token) {
        '{'     => 1,
        '}'     => -1,
        default => 0,
    };
};

/**
 * Folds a name to lower case the way PHP itself folds a class, namespace or method
 * name: ASCII only and locale-independent (zend_str_tolower). Not strtolower(), which
 * this repository bans (phpstan/disallowed-function-calls.neon), and not
 * mb_strtolower(), which would also fold bytes PHP keeps distinct — two names this
 * closure folds to the same key are exactly the two names PHP treats as one.
 *
 * @param string $value The name to fold.
 *
 * @return string The name with A-Z folded to a-z and every other byte kept.
 */
$asciiLower = static fn (string $value): string => strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

/**
 * Reads one `use` statement's CLASS imports, starting right after its T_USE token —
 * shared by the src/ class inventory (to resolve an `extends`/`implements` name) and
 * the ArchitectureTest (to resolve a `Foo::class` selector argument), so both sides of
 * a comparison resolve a short name by the same rule PHP applies.
 *
 * Handles a single import, a comma-separated list, a brace-grouped list
 * (`use A\{B, C as D};` — the group prefix arrives as one name token, its own trailing
 * T_NS_SEPARATOR, then the `{` CHAR, the same token shape $resolveTestRuleAliases
 * below documents) and an `as` alias on any item. A declaration-level `use function`/
 * `use const` statement, and a per-item `function`/`const` inside a group, import from
 * a different symbol table than classes, so they contribute nothing here. A closure's
 * `use (…)` (T_USE followed by `(`) is not an import at all and returns no entries.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens     The token stream.
 * @param int                                           $start      The index right after the T_USE token.
 * @param int                                           $count      The token count (exclusive upper bound).
 * @param Closure(string): string                       $asciiLower The name fold.
 *
 * @return array{0: array<string, string>, 1: int} The imports keyed by folded local name, and the index of the token the statement ended on.
 */
$parseUseImports = static function (array $tokens, int $start, int $count, Closure $asciiLower): array {
    $imports     = [];
    $groupPrefix = null;
    $name        = null;
    $alias       = null;
    $expectAlias = false;
    $skipAll     = false;
    $skipItem    = false;
    $first       = true;

    for ($index = $start; $index < $count; ++$index) {
        $token = $tokens[$index];

        if (is_array($token)) {
            if ($token[0] === \T_WHITESPACE) {
                continue;
            }

            if (($token[0] === \T_FUNCTION) || ($token[0] === \T_CONST)) {
                // First significant token: the keyword governs the whole statement.
                // Anywhere else: it governs one group item only.
                if ($first) {
                    $skipAll = true;
                }

                $skipItem = true;
                $first    = false;

                continue;
            }

            $first = false;

            if ($token[0] === \T_AS) {
                $expectAlias = true;

                continue;
            }

            if (($token[0] === \T_STRING) || ($token[0] === \T_NAME_QUALIFIED) || ($token[0] === \T_NAME_FULLY_QUALIFIED)) {
                if ($expectAlias) {
                    $alias       = $token[1];
                    $expectAlias = false;
                } elseif ($name === null) {
                    $name = $token[1];
                }
            }

            continue;
        }

        if ($first && ($token === '(')) {
            return [[], $start];
        }

        $first = false;

        if ($token === '{') {
            $groupPrefix = $name;
            $name        = null;
            $alias       = null;
            $skipItem    = false;

            continue;
        }

        if (($token === ',') || ($token === '}') || ($token === ';')) {
            if (($name !== null) && !$skipAll && !$skipItem) {
                $full     = ltrim((($groupPrefix !== null) ? $groupPrefix . '\\' : '') . $name, '\\');
                $segments = explode('\\', $full);
                $local    = $alias ?? end($segments);

                $imports[$asciiLower($local)] = $full;
            }

            $name        = null;
            $alias       = null;
            $expectAlias = false;
            $skipItem    = false;

            if ($token === '}') {
                $groupPrefix = null;
            }

            if ($token === ';') {
                return [$imports, $index];
            }

            continue;
        }

        // Anything else cannot continue a `use` statement: stop here.
        return [$imports, $index];
    }

    return [$imports, $count];
};

/**
 * Resolves a class-name token to the fully qualified name PHP binds it to, by PHP's
 * own rules for a CLASS reference: a fully qualified name as written; a
 * `namespace\X` relative name against the current namespace; otherwise the first
 * segment through the file's `use` imports (case-insensitively, like PHP), falling
 * back to the current namespace — with NO fallback to the global namespace, which
 * PHP only applies to functions and constants, never to classes.
 *
 * `self`, `static` and `parent` depend on the class they are written in, which
 * nothing that calls this needs to model, so they resolve to null and the caller
 * fails closed.
 *
 * @param array{0: int, 1: string, 2: int} $token      A T_STRING or T_NAME_* token.
 * @param string                           $namespace  The file's current namespace ('' for global).
 * @param array<string, string>            $imports    The file's class imports, keyed by folded local name.
 * @param Closure(string): string          $asciiLower The name fold.
 *
 * @return string|null The fully qualified name without a leading `\`, or null when it cannot be resolved.
 */
$resolveClassName = static function (array $token, string $namespace, array $imports, Closure $asciiLower): ?string {
    if ($token[0] === \T_NAME_FULLY_QUALIFIED) {
        return ltrim($token[1], '\\');
    }

    $prefix = ($namespace !== '') ? $namespace . '\\' : '';

    if ($token[0] === \T_NAME_RELATIVE) {
        // The token text is `namespace\Rest`; `namespace\` is 10 bytes whatever its case.
        return $prefix . substr($token[1], 10);
    }

    $segments = explode('\\', $token[1], 2);
    $key      = $asciiLower($segments[0]);

    if (($token[0] === \T_STRING) && in_array($key, ['self', 'static', 'parent'], true)) {
        return null;
    }

    if (isset($imports[$key])) {
        return $imports[$key] . (isset($segments[1]) ? '\\' . $segments[1] : '');
    }

    return $prefix . $token[1];
};

// --- Build the class inventory of src/ (FQCN => kind) ---
//
// Declared HERE, not after the loop: the loop below appends to it, and a later
// `$violations = []` silently discarded every one of those reports. Measured — a
// `src/` file past the size cap left the gate printing OK and exiting 0, with the
// file absent from the inventory and nothing saying so.
/** @var list<string> $violations */
$violations = [];

// Set when a src/ file could not be inventoried. The liveness arms below compare a
// subject against the inventory, so once it is short they can only answer "not
// found", which is not the same fact as "does not exist".
$inventoryIncomplete = false;

/** @var array<string, string> $inventory */
$inventory = [];

// The declared supertypes of each inventoried declaration, resolved to fully
// qualified names through the declaring file's own namespace and `use` imports — what
// the implements()/extends() selectors walk. A class's single `extends` name goes to
// $inventoryParent; its `implements` list, an interface's `extends` list (which ARE
// its interfaces) and an enum's `implements` list go to $inventoryInterfaces. An enum
// also carries the interfaces PHP adds implicitly — UnitEnum always, BackedEnum when
// the enum declares a backing type — because phpat sees them: measured against
// phpat in tests/consumer, implements('UnitEnum') matched a pure and a backed enum,
// implements('BackedEnum') the backed one only.
/** @var array<string, string> $inventoryParent */
$inventoryParent = [];

/** @var array<string, list<string>> $inventoryInterfaces */
$inventoryInterfaces = [];

$directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));

foreach ($directory as $file) {
    if (!$file->isFile() || ($file->getExtension() !== 'php')) {
        continue;
    }

    // Tokens, not a line-anchored REGEX. Two defects the regex form had, both silent
    // and both fail-OPEN for the liveness check this feeds: a line reading `class Fake`
    // inside a string literal or a heredoc registered a class that does not exist, so a
    // vacuous `classname(Fake)` subject was certified live; and `preg_match` took the
    // FIRST declaration per file only, so a second class in one file was invisible.
    //
    // The tokeniser answers both by construction — a string is one token, and the
    // loop does not stop at the first hit. Re-derive the token names rather than
    // trusting this list: https://www.php.net/manual/en/tokens.php
    $sourceFile = readCapped($file->getPathname(), MAX_SOURCE_BYTES);

    if ($sourceFile === false) {
        $violations[]        = sprintf('%s cannot be read, so its classes are not in the inventory.', safeReportValue($file->getPathname()));
        $inventoryIncomplete = true;

        continue;
    }

    if ($sourceFile === null) {
        $violations[] = sprintf(
            '%s is larger than the %d bytes this gate reads, so its classes are not in the inventory.',
            safeReportValue($file->getPathname()),
            MAX_SOURCE_BYTES
        );
        $inventoryIncomplete = true;

        continue;
    }

    // Through $stripComments (declared above, already hardened for the ArchitectureTest
    // path): the size cap above already ran against the RAW $sourceFile, so stripping
    // here does not change what counts against it. Without this, the namespace-name
    // and class-name lookaheads below — which only ever skipped T_WHITESPACE — gave up
    // the moment a comment sat between `namespace`/a modifier/`class` and the name that
    // follows, since a bare, un-skipped T_COMMENT token satisfies neither the "keep
    // scanning" nor the "found the name" branch. This is a DIFFERENT failure shape than
    // $stripComments's own re-tokenisation-gluing bug — that one is about what a
    // ZERO-newline comment contributes to the STRIPPED text, not about whether this
    // line retokenises (it does: $stripComments already runs token_get_all() once
    // internally, and this line's own token_get_all() retokenises its output, the
    // identical strip-then-retokenise shape the ArchitectureTest path uses) — verified
    // live: `namespace /* c */ Vendor\Mod\Model;` left $namespace empty, so a class
    // genuinely declared in Vendor\Mod\Model was inventoried under its bare name
    // instead, certifying a `classname()` subject targeting that bare name as live
    // when the real class does not exist there.
    $tokens    = token_get_all($stripComments($sourceFile));
    $namespace = '';
    $modifiers = [];
    $count     = count($tokens);

    // Brace depth, and the depth a `use` IMPORT sits at: 0 under an unbracketed
    // `namespace X;`, 1 inside a bracketed `namespace X { … }`. A T_USE anywhere else
    // is a trait import inside a class body (or a closure's `use (…)`, which
    // $parseUseImports itself recognises), never a class import.
    $depth       = 0;
    $importDepth = 0;

    /** @var array<string, string> $imports */
    $imports = [];

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];
        $depth += $braceDelta($token);

        if (!is_array($token)) {
            // A `;` or `{` ends whatever modifier run was open; anything else that
            // is not a declaration keyword cannot carry one across.
            $modifiers = [];

            continue;
        }

        if ($token[0] === \T_WHITESPACE) {
            continue;
        }

        if ($token[0] === \T_NAMESPACE) {
            $namespace = $nextName($tokens, $index + 1, $count, [\T_WHITESPACE], [\T_STRING, \T_NAME_QUALIFIED]) ?? '';
            $imports   = [];

            // Bracketed iff the name (if any) is followed by `{`. Bounded to the
            // whitespace and the one name token in between, so a file of bare
            // `namespace` keywords cannot make every occurrence scan to end-of-file.
            $ahead = $index + 1;

            while (($ahead < $count) && is_array($tokens[$ahead])
                && in_array($tokens[$ahead][0], [\T_WHITESPACE, \T_STRING, \T_NAME_QUALIFIED], true)
            ) {
                ++$ahead;
            }

            $importDepth = (($ahead < $count) && ($tokens[$ahead] === '{')) ? 1 : 0;
            $modifiers   = [];

            continue;
        }

        if (($token[0] === \T_USE) && ($depth === $importDepth)) {
            [$statementImports, $end] = $parseUseImports($tokens, $index + 1, $count, $asciiLower);

            $imports = array_replace($imports, $statementImports);

            // Resume ON the token the statement ended at, so its `;` (or whatever
            // stopped the scan) is still processed by this loop. A group's own
            // `{ … }` is balanced inside the statement and correctly never reaches
            // the depth counter.
            $index     = max($index, $end - 1);
            $modifiers = [];

            continue;
        }

        if (($token[0] === \T_ABSTRACT) || ($token[0] === \T_FINAL) || ($token[0] === \T_READONLY)) {
            $modifiers[] = $token[0];

            continue;
        }

        $kinds = [
            \T_CLASS     => 'class',
            \T_TRAIT     => 'trait',
            \T_INTERFACE => 'interface',
            \T_ENUM      => 'enum',
        ];

        if (!isset($kinds[$token[0]])) {
            $modifiers = [];

            continue;
        }

        // `Foo::class` and `new class { … }` both produce T_CLASS and declare
        // nothing. The previous non-whitespace token separates them from a real
        // declaration; an anonymous class has no name to inventory either way.
        $previous = null;

        for ($back = $index - 1; $back >= 0; --$back) {
            if (is_array($tokens[$back]) && ($tokens[$back][0] === \T_WHITESPACE)) {
                continue;
            }

            $previous = $tokens[$back];

            break;
        }

        if (is_array($previous) && (($previous[0] === \T_DOUBLE_COLON) || ($previous[0] === \T_NEW))) {
            $modifiers = [];

            continue;
        }

        $name = $nextName($tokens, $index + 1, $count, [\T_WHITESPACE]);

        if ($name === null) {
            $modifiers = [];

            continue;
        }

        $kind            = $kinds[$token[0]];
        $fqcn            = ($namespace !== '') ? $namespace . '\\' . $name : $name;
        $isAbstractClass = ($kind === 'class') && in_array(\T_ABSTRACT, $modifiers, true);

        $inventory[$fqcn] = $isAbstractClass ? 'abstract-class' : $kind;
        $modifiers        = [];

        // The declaration header, up to its body's `{`: `extends`/`implements` name
        // lists and an enum's `: type`. Only the tokens a header can hold are
        // walked — anything else ends the scan — so a run of headerless
        // declarations cannot make each one scan on to end-of-file.
        $section    = null;
        $parent     = null;
        $interfaces = ($kind === 'enum') ? ['UnitEnum'] : [];

        for ($ahead = $index + 1; $ahead < $count; ++$ahead) {
            $next = $tokens[$ahead];

            if (!is_array($next)) {
                if ($next === ':') {
                    $section = 'type';

                    if ($kind === 'enum') {
                        $interfaces[] = 'BackedEnum';
                    }

                    continue;
                }

                if ($next === ',') {
                    continue;
                }

                break;
            }

            if ($next[0] === \T_WHITESPACE) {
                continue;
            }

            if (($next[0] === \T_EXTENDS) || ($next[0] === \T_IMPLEMENTS)) {
                $section = ($next[0] === \T_EXTENDS) ? 'extends' : 'implements';

                continue;
            }

            if (!in_array($next[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)) {
                break;
            }

            // The declared name itself (no section yet) and a backing type carry no supertype.
            if (($section === null) || ($section === 'type')) {
                continue;
            }

            $resolved = $resolveClassName($next, $namespace, $imports, $asciiLower);

            if ($resolved === null) {
                continue;
            }

            if (($section === 'extends') && ($kind === 'class')) {
                $parent ??= $resolved;
            } else {
                $interfaces[] = $resolved;
            }
        }

        if ($parent !== null) {
            $inventoryParent[$fqcn] = $parent;
        }

        $inventoryInterfaces[$fqcn] = $interfaces;
    }
}

/**
 * Whether a declaration of this kind can be a phpat subject. PHPStan emits the
 * `InClassNode` phpat resolves subjects through for every class-like declaration
 * EXCEPT a trait, which it visits through `InTraitNode` instead — so a class
 * (concrete or abstract), an interface and an enum are all live, a trait never is.
 * Measured against phpat 0.12 in tests/consumer: an interface-only and an enum-only
 * namespace subject each reported their forbidden dependency.
 *
 * @param string|null $kind The inventory kind, or null for an absent declaration.
 *
 * @return bool True when phpat can match a declaration of this kind.
 */
$isLiveKind = static fn (?string $kind): bool => ($kind !== null) && ($kind !== 'trait');

// --- Extract each rule method's subject selector (both of phpat's discovery paths) ---

// Each rule method, found by walking TOKENS rather than by matching text.
//
// The text form could not see three legitimate spellings at once, and every one of
// them made this gate exit 0 on rules it had not looked at — in a file whose header
// says it fails CLOSED:
//
//   - the attribute written `#[TestRule()]` or fully qualified as
//     `#[\PHPat\Test\Attributes\TestRule]`, which a consumer without the `use`
//     writes;
//   - a return type spelled `\PHPat\Test\Builder\Rule` or through an alias;
//   - a convincing-looking rule inside a heredoc or a string, which the text scan
//     counted as real. Measured: an ArchitectureTest with ZERO real rules and one in
//     a heredoc printed OK.
//
// A cardinality guard over the same text could not close it either, because it
// inherited the same blind spot: it counted the literal `#[TestRule]` and nothing
// else.
//
// The walk is the same tokeniser the class inventory uses. For each attribute group
// whose LAST name segment is `TestRule`, OR for each PUBLIC method whose name matches
// phpat's own test*-name regex (see $isTestNamed below), it takes the `function`, its
// name, and the body between the matching braces. Brace counting over tokens is what
// bounds the body — a `{` inside a string or a heredoc is one token, not a delimiter —
// so a malformed rule cannot run past its own method and adopt a following helper's
// selector.
$ruleMethods            = [];
$ruleTokens             = token_get_all($source);
$ruleCount              = count($ruleTokens);
$sawTestRule            = false;
$attributeSum           = 0;
$attributeResolvedCount = 0;

// Brace depth over the WHOLE file, not just within one method's body (that is the
// separate, inner $depth further down). phpat's TestParser finds rule methods by
// reflecting the ONE extracted ArchitectureTest class (`getMethods()` on a single
// `$reflected` — re-derive rather than trusting this comment, same reason as the
// regex/IS_PUBLIC note further down:
// grep -n 'getMethods\|reflectTest' tests/consumer/.build/vendor/phpat/phpat/src/Test/Test{Parser,Extractor}.php),
// so a `test*`-named method nested inside a closure or an anonymous
// class within another method's body is invisible to phpat — this gate must not treat
// it as a rule either, or a name this common (unlike the deliberate `#[TestRule]`
// attribute) turns any such nested helper into a false vacuous-rule report, or worse,
// a false accept that hides a real ArchitectureTest with zero actual rules. A rule
// method is only ever a DIRECT member of the top-level class body, i.e. depth 1 at
// the point `T_FUNCTION` is seen (its own opening brace has not been counted yet).
//
// This assumes the unbracketed `namespace X;` form every fixture and this whole
// codebase uses, and ONE class per file (PSR-1 — a near-universal PHP convention,
// though this gate neither checks nor enforces it). Two ways that assumption can be
// wrong, both deliberately not defended against:
//   - A bracketed `namespace X { … }` declaration adds a brace level, shifting
//     `ArchitectureTest`'s own methods to depth 2 and hiding them. This gate does not
//     itself require or check PSR-4/Composer autoloading (it locates the file by two
//     hardcoded conventional paths, not an autoload map), and PSR-4 would not preclude
//     the bracketed form regardless — the actual, narrower reason is that nothing real
//     produces it: re-derive with
//     `grep -rn 'namespace .*{' --include=ArchitectureTest.php` across any consumer,
//     which returns nothing today.
//   - A SECOND top-level class or trait declared in the same file also opens its body
//     at depth 1, so a `test*`-named public method on IT would be misattributed to
//     ArchitectureTest's rule set. PSR-1 is a STYLE convention, not something that
//     makes this syntactically unreachable — nothing here checks or enforces one
//     class per file, so this gap is real, just conventionally rare. No re-derivation
//     command here (unlike the bracketed-namespace gap above): a line-anchored regex
//     over a consumer's file cannot reliably answer "is a second declaration present"
//     — a modifier this gate does not enumerate (`abstract class`, `readonly class`)
//     false-negatives, and a `class `-looking line inside a heredoc or string
//     false-positives, exactly the class of trap this gate's own inventory walk
//     switched off regex for. Checking by eye (or with the same token-based approach
//     this file already uses) is the only reliable answer.
// Defending either would need tracking which depth the ArchitectureTest class's OWN
// body opened at (and that it IS `ArchitectureTest`), rather than assuming 1 for
// whichever class comes first — a materially bigger change than tokenising one file,
// to defend shapes this codebase has never seen written.
/**
 * Resolves every local name that resolves to the TestRule attribute — the literal
 * name plus every `as`-alias a `use` import establishes for it. A `use
 * PHPat\Test\Attributes\TestRule as X;` import makes `#[X]` the real attribute — PHP
 * resolves it via ordinary import-alias resolution, and phpat's own TestParser filters
 * by FQCN (`getAttributes(TestRule::class)`, re-derive with:
 * grep -n 'getAttributes\|preg_match' tests/consumer/.build/vendor/phpat/phpat/src/Test/TestParser.php),
 * not by the literal text `TestRule`. Without tracking an alias, that rule's attribute
 * never matches the comparison at the attribute-recognition site, so it never
 * increments $attributeSum and never enters $ruleMethods — its subject, vacuous or
 * not, is never inspected, while the run stays green as long as the file also has one
 * other, non-aliased rule. Verified live: a fixture with one aliased, deliberately
 * vacuous rule alongside one genuine rule printed OK.
 *
 * Handles a single import (`use A\TestRule as X;`), a comma-separated list on one
 * `use` line (`use A, B\TestRule as X;`), and a brace-grouped list
 * (`use A\{TestRule as X, B};`) — verified against token_get_all() output for all
 * three shapes: the group prefix arrives as one T_NAME_QUALIFIED token, followed by
 * its own trailing T_NS_SEPARATOR, then the `{` CHAR. A doubly-nested group
 * (`use A\{B\{TestRule as X}}`) is NOT handled — $groupPrefix holds only one level, so
 * a nested group's items would resolve against the OUTER prefix alone. Deliberately
 * undefended, same disposition as the two other documented gaps at $topDepth below —
 * but stronger than either of them: this shape is not merely unwritten, it is not
 * syntactically valid PHP at all (confirmed live with `php -l`, re-derive with:
 * `php -l <(printf '<?php\nuse A\{B\{C}};\n')`), so no fixture is needed to prove it
 * unreachable.
 *
 * A dedicated FORWARD pre-pass over the whole token stream, not part of the main
 * rule-discovery loop below — it shares no mutable state with it (its own $depth,
 * not $topDepth) and can be read and tested on its own, same rationale as
 * $braceDelta being pulled out of the loop it serves.
 *
 * A `function`/`const` group item (`use A\{function f, TestRule}`) imports from a
 * DIFFERENT namespace than classes/attributes — `use A\{function TestRule as X};`
 * imports a namespaced FUNCTION named TestRule, and `#[X]` never resolves to it, no
 * matter how it reads. Verified live (token_get_all()): T_FUNCTION/T_CONST arrive as
 * their own token immediately after `{`/`,`, before the name — this scan marks that
 * item and excludes it from $aliases even if its name ends in `\TestRule`, rather
 * than treating the keyword as ordinary noise between commas.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens The file's full
 *                                                                    token stream.
 *
 * @return list<string> Every local name that resolves to the TestRule attribute,
 *                      'TestRule' itself always included.
 */
$resolveTestRuleAliases = static function (array $tokens) use ($braceDelta, $nextName): array {
    $aliases = ['TestRule'];
    $count   = count($tokens);
    $depth   = 0;

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];
        $depth += $braceDelta($token);

        // Bounded to $depth === 0 (before the class body opens): PHP's tokenizer
        // emits the identical T_USE for an IMPORT and for trait-adaptation
        // `use Trait { … }` inside a class body, and only the import form is a
        // candidate for aliasing this attribute.
        if (!is_array($token) || ($token[0] !== \T_USE) || ($depth !== 0)) {
            continue;
        }

        $groupPrefix           = null;
        $importName            = null;
        $isFunctionOrConstItem = false;

        // A declaration-level `use function …`/`use const …` keyword — the ONLY
        // position PHP allows one at top level (`php -l` on `use A, function B;`
        // fails to parse) — applies to EVERY item in the statement, brace-grouped or
        // not: `use function A\{B as X};` and `use function A\bar, A\TestRule as X;`
        // both bind ALL their items as function imports. $isFunctionOrConstItem alone
        // cannot carry this: it is reset at every `,`/`{`/`}` boundary to start each
        // GROUP item fresh (correct for `use A\{function f, TestRule}`, where the
        // keyword genuinely is per-item), which also erased a declaration-level
        // keyword the moment the next item began. Verified live (two independent
        // reproductions): `use function A\{TestRule as X};` and
        // `use function A\bar, A\TestRule as X;` each still tracked X as a TestRule
        // alias with only the per-item flag. Seeded once, from the FIRST significant
        // token only, and never reset — the grammar guarantees no later token in the
        // same statement can be this keyword unless it already governs everything
        // before it.
        $declarationIsFunctionOrConst = false;
        $isFirstSignificantToken      = true;

        for ($ahead = $index + 1; $ahead < $count; ++$ahead) {
            $next = $tokens[$ahead];

            if (!is_array($next)) {
                if ($next === ';') {
                    break;
                }

                if (($next === ',') || ($next === '{') || ($next === '}')) {
                    // A new item starts at each of these — inside a group if
                    // $groupPrefix is set, else the next import on the same `use`
                    // line. All three fall back to the declaration-level keyword
                    // rather than hard-`false`.
                    if ($next === '{') {
                        // The name gathered so far becomes the prefix every item
                        // inside the group is relative to.
                        $groupPrefix = $importName;
                    } elseif ($next === '}') {
                        $groupPrefix = null;
                    }

                    $importName            = null;
                    $isFunctionOrConstItem = $declarationIsFunctionOrConst;

                    continue;
                }

                break;
            }

            if ($next[0] === \T_NS_SEPARATOR) {
                continue;
            }

            if ($next[0] === \T_WHITESPACE) {
                continue;
            }

            if (($next[0] === \T_FUNCTION) || ($next[0] === \T_CONST)) {
                $isFunctionOrConstItem = true;

                if ($isFirstSignificantToken) {
                    $declarationIsFunctionOrConst = true;
                }

                $isFirstSignificantToken = false;

                continue;
            }

            $isFirstSignificantToken = false;

            if (($importName === null)
                && (($next[0] === \T_STRING) || ($next[0] === \T_NAME_QUALIFIED) || ($next[0] === \T_NAME_FULLY_QUALIFIED))
            ) {
                $importName = ($groupPrefix !== null) ? ($groupPrefix . '\\' . $next[1]) : $next[1];

                continue;
            }

            if (($next[0] === \T_AS) && ($importName !== null)) {
                // PHP resolves a class/attribute reference CASE-INSENSITIVELY — verified
                // live: `#[testrule]` on a method still resolves to `TestRule::class`
                // via `getAttributes(TestRule::class)`, the same call phpat's own
                // TestParser makes. A case-SENSITIVE compare here missed an import whose
                // name or alias used any other casing, letting that rule's vacuous
                // subject escape undetected — the same class of gap the literal-string
                // compare this closure replaced already had for aliasing itself.
                //
                // Compared with strcasecmp(), never by folding: PHP folds a class name
                // ASCII-only and locale-independently (zend_str_tolower), and so does
                // strcasecmp(), while mb_strtolower() would also fold bytes PHP itself
                // keeps distinct. Only the last `\`-separated segment is compared, so
                // a bare `TestRule` and any qualified `…\TestRule` import answer the same.
                $importSegments = explode('\\', $importName);
                $aliasName      = $nextName($tokens, $ahead + 1, $count, [\T_WHITESPACE]);

                if (!$isFunctionOrConstItem
                    && ($aliasName !== null)
                    && (strcasecmp(end($importSegments), 'TestRule') === 0)
                ) {
                    $aliases[] = $aliasName;
                }

                continue;
            }
        }

        // Same index-resync fix as the NAMESPACE_ROOT constant walk above — see its
        // comment for the mechanism and why it matters here. Measured live: an
        // 8000-repetition `use` payload took ~16s under the 256KB cap, sub-second
        // after this fix.
        $index = $ahead - 1;
    }

    return $aliases;
};

$testRuleAliases = $resolveTestRuleAliases($ruleTokens);

/**
 * Whether $name is one of $aliases, compared case-insensitively — rationale (and why
 * strcasecmp() rather than folding) at $resolveTestRuleAliases's T_AS branch above.
 *
 * @param string       $name    The attribute name segment to look up.
 * @param list<string> $aliases Every local name that resolves to the TestRule attribute.
 *
 * @return bool True when $name matches one of $aliases, ignoring ASCII case.
 */
$isTestRuleAlias = static function (string $name, array $aliases): bool {
    foreach ($aliases as $alias) {
        if (strcasecmp($name, $alias) === 0) {
            return true;
        }
    }

    return false;
};

/**
 * Scans one attribute group (`#[...]`) for a name matching a TestRule alias —
 * shared by the rule-discovery loop's own T_ATTRIBUTE handling and the
 * body-extraction loop's inline nested-attribute tracking below, both of which
 * need the identical bracket/paren/comma state machine and name-matching rule.
 *
 * T_ATTRIBUTE is the opening `#[` alone; the names follow as ordinary tokens
 * until the bracket closes. Only the last `\`-separated segment is compared,
 * so the qualified and imported spellings answer the same — case-insensitive,
 * via $isTestRuleAlias, because PHP resolves a class/attribute reference
 * case-insensitively.
 *
 * T_NAME_RELATIVE (`namespace\TestRule`) is deliberately absent from the
 * accepted name-token kinds. It denotes TestRule relative to the CURRENT
 * namespace, i.e. a class in the consumer's own test namespace — not phpat's
 * attribute — so matching it would be a false positive rather than the
 * missing spelling it looks like.
 *
 * Matches the LAST segment only, not the full FQCN — an unrelated attribute
 * class from another namespace whose own name happens to be `TestRule`
 * (fully qualified, or imported under an alias never used for phpat's own
 * TestRule) is indistinguishable from the real one here, and gets
 * misattributed as a rule method. phpat itself filters by the exact FQCN
 * (`getAttributes(TestRule::class)`), so such a method is never a real rule
 * to phpat — this gate would instead fail closed on it (no `->classes(...)`
 * pattern to find), a spurious CI failure a developer sees immediately, not a
 * silent bypass. Deliberately undefended: distinguishing "the bare name
 * `TestRule` backed by a real `use PHPat\Test\Attributes\TestRule;` import"
 * from "any fully-qualified name merely ending in `TestRule`" needs the same
 * per-name import-resolution this file already does for AVOIDING a false
 * negative, applied in the opposite direction — a materially bigger change
 * to defend a naming collision no consumer of this gate has ever written.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens          The token stream to scan.
 * @param int                                           $count           The token count (exclusive upper bound).
 * @param int                                           $start           The index to start scanning from (inclusive) — the token right after T_ATTRIBUTE.
 * @param list<string>                                  $testRuleAliases Every local name that resolves to the TestRule attribute.
 *
 * @return array{matched: bool, end: int} Whether a TestRule name was found, and the index one past the group's closing `]`.
 */
$scanAttributeGroup = static function (array $tokens, int $count, int $start, array $testRuleAliases) use ($isTestRuleAlias): array {
    $depth      = 1;
    $parens     = 0;
    $expectName = true;
    $matched    = false;
    $ahead      = $start;

    for (; ($ahead < $count) && ($depth > 0); ++$ahead) {
        $inner = $tokens[$ahead];

        if (!is_array($inner)) {
            if ($inner === '[') {
                ++$depth;
            } elseif ($inner === ']') {
                --$depth;
            } elseif ($inner === '(') {
                // From here to the matching `)` everything is an ARGUMENT, and a
                // name there denotes nothing: `#[UsesClass(TestRule::class)]` is
                // not a rule. Only the token in NAME position counts.
                ++$parens;
                $expectName = false;
            } elseif ($inner === ')') {
                --$parens;
            } elseif (($inner === ',') && ($depth === 1) && ($parens === 0)) {
                // `#[A, TestRule]` is one group holding two attributes, so a name
                // is expected again after the comma.
                //
                // `$parens` is what keeps this off an ARGUMENT separator. Bracket
                // depth alone does not: a comma between two arguments is also at
                // depth 1, so it re-armed name position inside the list the `(`
                // arm had just closed. Measured before the counter existed —
                // `#[UsesClass(Node::class, X\TestRule::class)]` on an ordinary
                // helper produced `could not identify a subject selector` and
                // exit 1, naming a method that carries no rule.
                $expectName = true;
            }

            continue;
        }

        if ($inner[0] === \T_WHITESPACE) {
            continue;
        }

        $isName = ($inner[0] === \T_STRING)
            || ($inner[0] === \T_NAME_QUALIFIED)
            || ($inner[0] === \T_NAME_FULLY_QUALIFIED);

        if ($expectName && $isName) {
            $segments = explode('\\', $inner[1]);

            if ($isTestRuleAlias(end($segments), $testRuleAliases)) {
                $matched = true;
            }
        }

        $expectName = false;
    }

    return ['matched' => $matched, 'end' => $ahead];
};

/**
 * True when the method whose `function` token sits at $functionIndex is NOT public —
 * `getMethods(ReflectionMethod::IS_PUBLIC)` (re-derive with the same command as
 * $resolveTestRuleAliases above) gates BOTH of phpat's discovery paths, not just the
 * name-based one, so a `private`/`protected` method is invisible to phpat too and this
 * gate would otherwise fail-close on a rule phpat never runs.
 *
 * Reads BACKWARD from `function` over its own immediately preceding, contiguous
 * modifier run — not a flag carried FORWARD from wherever a `T_PRIVATE`/`T_PROTECTED`
 * token last appeared. The forward form was tried first and was wrong: PHP's
 * trait-conflict-resolution syntax (`use Helper { someMethod as private; }`) emits a
 * bare T_PRIVATE/T_PROTECTED token with no following T_FUNCTION/T_VARIABLE/T_CONST to
 * reset it, so that trait-adaptation line silently poisoned the NEXT real, genuinely
 * public rule method into looking non-public — defeating the fail-closed guarantee on
 * a rule phpat actually runs. Verified: reverting to the forward form reproduces exit
 * 0 on such a fixture where this lookback correctly reds it. Mirrors the
 * `Foo::class`/`new class` lookback used elsewhere in this file for the same reason —
 * bounded to the immediate run, nothing outside it can poison the read.
 *
 * No T_READONLY in the whitelist below: `readonly` is a property/promoted-parameter
 * modifier, never a method one — `readonly function` is not valid PHP — so a real
 * ArchitectureTest can never place it directly before `function`. Any earlier
 * `readonly` (on a property) already ends its own declaration in `;`, a non-array CHAR
 * token this scan already breaks on before reaching it.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens        The file's full token stream.
 * @param int                                                 $functionIndex The index of the `function` token.
 *
 * @return bool True when the method is not public.
 */
$isNonPublicMethod = static function (array $tokens, int $functionIndex): bool {
    for ($back = $functionIndex - 1; $back >= 0; --$back) {
        $previous = $tokens[$back];

        // A non-array token here is always the attribute group's closing `]` (or a
        // `;`/`{` from something else entirely) — either way, the modifier run ends.
        if (!is_array($previous)) {
            break;
        }

        if ($previous[0] === \T_WHITESPACE) {
            continue;
        }

        if (($previous[0] === \T_PRIVATE) || ($previous[0] === \T_PROTECTED)) {
            return true;
        }

        if (($previous[0] === \T_PUBLIC)
            || ($previous[0] === \T_STATIC)
            || ($previous[0] === \T_ABSTRACT)
            || ($previous[0] === \T_FINAL)
        ) {
            continue;
        }

        break;
    }

    return false;
};

$topDepth = 0;

for ($index = 0; $index < $ruleCount; ++$index) {
    $token = $ruleTokens[$index];

    $topDepth += $braceDelta($token);

    if (is_array($token) && ($token[0] === \T_ATTRIBUTE)) {
        $group = $scanAttributeGroup($ruleTokens, $ruleCount, $index + 1, $testRuleAliases);

        if ($group['matched']) {
            $sawTestRule = true;
            ++$attributeSum;
        }

        // Resume AFTER the closing `]`. Without this the outer loop re-walks the
        // group's own tokens and re-classifies them — a `Foo::class` argument reads as
        // T_CLASS and hits the declaration barrier below, clearing the flag the
        // `#[TestRule]` beside it just set. Measured: `#[TestRule]` followed by
        // `#[CoversClass(Node::class)]` reported `no #[TestRule] methods found` for a
        // live rule.
        $index = $group['end'] - 1;

        continue;
    }

    // A TestRule attribute attaches to the declaration that FOLLOWS it. Any other
    // declaration keyword ends its reach, so an attribute written on a property or a
    // class cannot be carried forward onto the next method — which would make that
    // method a rule it is not, and hide the misplaced attribute from the count below.
    if (is_array($token)
        && (($token[0] === \T_CLASS)
            || ($token[0] === \T_TRAIT)
            || ($token[0] === \T_INTERFACE)
            || ($token[0] === \T_ENUM)
            || ($token[0] === \T_CONST)
            || ($token[0] === \T_VARIABLE))
    ) {
        $sawTestRule = false;

        continue;
    }

    if (!is_array($token) || ($token[0] !== \T_FUNCTION)) {
        continue;
    }

    // A return-by-reference declaration (`function &testFoo()`) inserts a token here
    // this loop must also skip past to reach the name — verified (php -r against
    // token_get_all()) as T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, an ARRAY token,
    // not a bare `&` CHAR. Without this, `$name` stayed null and the method was not
    // recognised as a rule — which is NOT reliably fail-closed the way it first looks:
    // a no-attribute `&testFoo` mixed with any OTHER correctly-recognised rule left
    // `$ruleMethods` non-empty, so the whole run could print OK with this method's
    // subject — vacuous or not — never checked.
    $name = $nextName($ruleTokens, $index + 1, $ruleCount, [\T_WHITESPACE, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG]);

    // The attribute DID attach to a real function here, regardless of what happens
    // next — counted separately from $ruleMethods below, which the visibility filter
    // still has to shrink. Comparing $attributeSum against THIS count (not against
    // count($ruleMethods)) keeps the misattachment check answering only "did the
    // attribute reach a method", not "is that method one phpat will run" — a
    // non-public #[TestRule] method is the latter, not the former, and reporting it
    // as an attribute that "did not resolve to a method" would name the wrong cause.
    //
    // $topDepth === 1 IS required here, unlike the visibility filter: a non-public
    // method is still a real member of ArchitectureTest that phpat's getMethods()
    // enumerates (IS_PUBLIC only filters it afterwards), so counting it as "resolved"
    // names the right cause for its exclusion. A method nested inside a closure or
    // anonymous class is not a member of ArchitectureTest's method list AT ALL — counting
    // it here let a nested #[TestRule] with a vacuous subject escape both the emptiness
    // check and the misattachment check whenever the file also contained one other
    // genuine top-level rule (measured: the gate printed OK on such a fixture with this
    // condition absent).
    if ($sawTestRule && ($name !== null) && ($topDepth === 1)) {
        ++$attributeResolvedCount;
    }

    // phpat/src/Test/TestParser.php's own regex, reproduced verbatim (case-sensitive —
    // a `Test…`-named method does not qualify). phpat is a `suggest` of this package,
    // installed only into its tests/consumer fixture (the opt-in phpstan/phpat.neon
    // preset, GH-183) — a version bump inside the suggested ^0.12.4 constraint could
    // change it, so re-derive rather than trusting this comment:
    // grep -n 'preg_match\|getMethods' tests/consumer/.build/vendor/phpat/phpat/src/Test/TestParser.php
    $isTestNamed = ($name !== null) && (preg_match('/^(test)[A-Za-z0-9_\x80-\xff]*/', $name) === 1);

    // See $isNonPublicMethod's own docblock above for why this reads backward rather
    // than a flag carried forward.
    $isNonPublic = $isNonPublicMethod($ruleTokens, $index);

    // `$topDepth === 1` is the same "invisible to phpat's reflection" idea (re-derivation
    // command at $topDepth's own declaration above) applied to NESTING: a method declared
    // inside a closure or an anonymous class within another method's body is equally
    // invisible to phpat's reflection.
    $isRuleMethod = ($sawTestRule || $isTestNamed) && !$isNonPublic && ($topDepth === 1);

    // Unconditional: both the taken and the not-taken branch below reset this to the
    // same value, and $isTestNamed/$isRuleMethod above already read the pre-reset
    // state, so hoisting the reset above the branch is behavior-preserving.
    $sawTestRule = false;

    if (($name === null) || !$isRuleMethod) {
        continue;
    }

    // The body, by brace depth over $braceDelta (declared above) rather than a
    // hand-rolled copy of the same classification.
    //
    // Reading `$inner[1]` of an array token for the delimiter text was wrong in both
    // directions, measured on the shipped binary: `"$a{"` lexes the brace as
    // T_ENCAPSED_AND_WHITESPACE whose text is exactly `{`, so one added character
    // inside a string made a vacuous rule's body run past its own method and adopt the
    // following helper's live subject — the gate printed OK. The mirror, `"a $what}"`,
    // cut a correct body short and reported a live rule as unparseable. $braceDelta
    // sidesteps this by classifying the TOKEN, never its text.
    //
    // An abstract or interface method ends on a CHAR `;` before any `{` and carries no
    // subject to read.
    $body  = '';
    $depth = 0;

    // A #[TestRule] attribute nested inside an anonymous class within THIS body
    // must still be counted against $attributeSum (see this loop's own
    // index-resync comment below for why) — via the same $scanAttributeGroup
    // the outer loop above uses, so the two never drift apart the way the
    // class-name/method-name lookaheads once did before $nextName existed.
    // Unlike that outer call site, a matched group's own tokens must still be
    // appended to $body (an attribute can legitimately sit inside a nested
    // method this body's text needs to preserve), and $ahead must land on the
    // group's own last token rather than one past it, since this loop's `for`
    // advances $ahead itself on the next iteration.
    for ($ahead = $index + 1; $ahead < $ruleCount; ++$ahead) {
        $inner = $ruleTokens[$ahead];
        $text  = is_array($inner) ? $inner[1] : $inner;

        if (is_array($inner) && ($inner[0] === \T_ATTRIBUTE)) {
            $group = $scanAttributeGroup($ruleTokens, $ruleCount, $ahead + 1, $testRuleAliases);

            if ($group['matched']) {
                ++$attributeSum;
            }

            // A constant expression (an attribute's own argument list) cannot
            // contain a bare `;`, `{` or `}` CHAR token, so appending this whole
            // range's text in one pass — rather than letting the loop below
            // revisit each token — cannot skip a terminator or desync $depth.
            if ($depth > 0) {
                for ($groupToken = $ahead; $groupToken < $group['end']; ++$groupToken) {
                    $body .= is_array($ruleTokens[$groupToken]) ? $ruleTokens[$groupToken][1] : $ruleTokens[$groupToken];
                }
            }

            $ahead = $group['end'] - 1;

            continue;
        }

        if (!is_array($inner) && ($depth === 0) && ($inner === ';')) {
            break;
        }

        $delta = $braceDelta($inner);

        if ($delta === 1) {
            ++$depth;

            if ($depth === 1) {
                continue;
            }
        } elseif ($delta === -1) {
            --$depth;

            if ($depth === 0) {
                break;
            }
        }

        if ($depth > 0) {
            $body .= $text;
        }
    }

    // The scan above can end with $depth still nonzero: an unclosed brace
    // anywhere in this method's own body runs the scan all the way to
    // end-of-file without ever seeing $depth return to 0. A fixture built this
    // way against the pre-this-guard gate did print OK, silently skipping a
    // genuinely vacuous test*-named method declared after the malformed one —
    // but that fixture, checked afterward, is itself invalid PHP (`php -l`:
    // "unexpected token \"public\""), and every construction found so far that
    // reproduces the skip is invalid PHP the same way: for the local depth
    // count to still be nonzero at true end-of-file while the OVERALL file
    // still compiles, a later class member's own `public`/`protected`/etc.
    // keyword would need to sit lexically inside the still-open method body,
    // which is not valid syntax there. So this specific "swallow a real
    // sibling method" shape is likely NOT constructible in any ArchitectureTest
    // that could actually load for phpat/PHPUnit to run — the same disposition
    // as the decoy-interpolation case a few lines below, just not as cleanly
    // provable (a nested, modifier-less `function` declaration IS legal to
    // write here, but is then a plain conditionally-declared function, not a
    // reflectable class method, so phpat would never run it as a rule either).
    // Kept as free, harmless defense-in-depth regardless — it fails closed only
    // on an already-malformed body and never misfires on valid input, so
    // there is no cost to keeping it even if the scenario it guards turns out
    // to be unreachable.
    if ($depth !== 0) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', safeReportValue($name));

        break;
    }

    // Same index-resync fix as the NAMESPACE_ROOT constant walk and the
    // TestRule-alias `use`-import walk above — unconditional here too, now that
    // the $scanAttributeGroup call just above keeps $attributeSum accurate
    // without needing the outer loop to revisit this body's own tokens.
    //
    // An earlier version of this fix skipped ONLY when the scan ran off the true
    // end of the token stream, leaving a normally-closed body fully reprocessed —
    // reasoned (wrongly) to be safe since a single body's own size bounds that
    // reprocessing. Measured live that this reasoning missed a real case: many
    // `public function testN` candidates, none with their OWN terminator, that
    // all share ONE real `;` placed late in the file each "close normally" on
    // that SAME shared terminator, so each one's own scan still spans nearly the
    // whole remaining file — O(n) work per candidate, O(n) candidates, the
    // identical O(n²) this fix exists to remove, just needing one extra
    // character to reach instead of zero. Unconditionally skipping to $ahead
    // closes this completely: the range from $index+1 to $ahead can never be
    // independently re-entered by a later candidate, well-formed or not.
    $index = $ahead;

    $ruleMethods[] = [$name, $body];
}

if (count($ruleMethods) === 0) {
    $violations[] = 'no #[TestRule] or test*-named public rule methods found — the ArchitectureTest defines no rules.';
}

// The emptiness check above asks whether the RECOGNISED set is empty, which is not
// the same question as whether every TestRule attribute was recognised. One written on
// a property or a class attaches to no method, so the walk cannot read a subject from
// it — and the count says so rather than passing over it. Only TestRule attributes are
// counted: totalling every attribute would red an ArchitectureTest carrying an ordinary
// `#[CoversNothing]` beside its rules.
//
// Compared against $attributeResolvedCount, not count($ruleMethods): the latter is
// additionally shrunk by the visibility filter above, and a #[TestRule] on a
// non-public method DID attach to a method — it is excluded from $ruleMethods for an
// unrelated reason (phpat will never run it), not because this gate could not find
// what the attribute was on. Comparing against count($ruleMethods) instead reported a
// perfectly-attached protected method as an attribute "this gate cannot attach to a
// method", naming the wrong cause.
if ($attributeSum > $attributeResolvedCount) {
    $violations[] = sprintf(
        '%d #[TestRule] attribute(s) found but only %d resolved to a method — an attribute this gate cannot attach to a method is a rule it cannot check.',
        $attributeSum,
        $attributeResolvedCount
    );
}

// --- Evaluate each rule's subject selector EXPRESSION to a set of src/ declarations ---
//
// A subject is not a single selector call but an expression: phpat composes selectors
// with AllOf/AnyOf/NoneOf/Not and narrows them with predicates such as isInterface(),
// so "is this subject live" means "is the SET it selects from src/ non-empty" (GH-190).
// Each selector below evaluates to that set, over the same inventory the liveness
// checks always used, restricted to the kinds phpat can see (see $isLiveKind), and
// replicating phpat's own matches() for each selector — re-derive rather than trust
// this comment: tests/consumer/.build/vendor/phpat/phpat/src/Selector/*.php. Measured
// against phpat itself in tests/consumer (a throwaway rule per selector using
// `shouldNot()->exist()`, which reports every class a subject matches):
//
//   - inNamespace(NS): the declaration's NAMESPACE (its FQCN minus the last segment)
//     starts with NS, compared on whole segments after stripping a leading/trailing
//     `\` from both (`trimSeparators()`), case-sensitively; a class is NOT inside a
//     "namespace" named after itself (inNamespace('A\Plain') matched nothing for
//     class A\Plain). With the regex flag, the pattern runs against that namespace.
//   - classname(X): FQCN === trimSeparators(X), case-sensitive; with the regex flag the
//     pattern runs against the FQCN without a leading `\` (`/^FooRepository$/` matched
//     nothing for A\FooRepository, `/^A\\.*Repository$/` matched it).
//   - implements(X): every interface the declaration has, transitively — through its
//     parent classes and through interface inheritance; an interface extending X
//     matches, X itself does not, and a CLASS name never does. Case-insensitive for a
//     name without a leading `\`, case-sensitive with one (BetterReflection's adapter
//     folds only a name it finds verbatim-lowercased in the interface list).
//   - extends(X): every ancestor class, transitively, case-sensitive; X itself does not.
//   - isInterface()/isAbstract()/isEnum(): the declaration kind; isAbstract() matches an
//     abstract class only, never an interface. isTrait() and all(): phpat never visits
//     a trait at all, so isTrait() selects nothing and all() every class-like but a trait.
//   - AllOf/AnyOf/NoneOf/Not: intersection, union, and complement against every
//     analysed class (Not(inNamespace(X)) matched every class, interface and enum
//     outside X — and no trait).
//
// Two approximations, both erring towards a false RED (fail-closed), never a false
// green: the inventory is src/ alone, where phpat sees every analysed path (a Not()
// can match more there, never less than here for src/ classes); and a supertype
// declared OUTSIDE src/ is known by name only — its own ancestors are not, so an
// implements()/extends() reaching a src/ class only THROUGH a vendor type answers
// "matches no class" here. Stringable, which PHP adds implicitly to a class with a
// __toString() method, is not modelled either; UnitEnum/BackedEnum on an enum are.

/**
 * The deepest selector nesting this gate evaluates. Every level recurses once, and
 * PHP 8.3+ turns a deep enough recursion into a fatal "Maximum call stack size
 * reached" — exit 255, no gate diagnostic — so a pathological nesting fails closed
 * here instead. The deepest a first-party consumer nests today is two
 * (`AllOf(…, Not(…))`).
 */
const MAX_SELECTOR_DEPTH = 32;

/** @var array<string, string> $inventoryByFolded */
$inventoryByFolded = [];

/** @var array<string, true> $universe */
$universe = [];

foreach ($inventory as $fqcn => $kind) {
    $inventoryByFolded[$asciiLower((string) $fqcn)] = (string) $fqcn;

    if ($isLiveKind($kind)) {
        $universe[(string) $fqcn] = true;
    }
}

/**
 * Maps a resolved supertype name onto the inventory's own spelling of it: PHP binds
 * `implements countable`-style references case-insensitively, and phpat compares the
 * name the DECLARATION carries, not the reference.
 *
 * @param string $name A resolved class name.
 *
 * @return string The inventoried spelling, or $name itself when it is not in src/.
 */
$canonicalName = static fn (string $name): string => $inventoryByFolded[$asciiLower($name)] ?? $name;

/**
 * The ancestor classes of a declaration, nearest first — iteratively, with a seen-set,
 * so an (invalid) inheritance cycle cannot loop and a long chain cannot recurse. A
 * parent declared outside src/ ends the walk: its name is known, its own parent is not.
 *
 * @param string $fqcn An inventoried FQCN.
 *
 * @return list<string> The ancestor FQCNs.
 */
$parentsOf = static function (string $fqcn) use ($inventoryParent, $canonicalName): array {
    $parents = [];
    $seen    = [$fqcn => true];
    $current = $fqcn;

    while (isset($inventoryParent[$current])) {
        $parent = $canonicalName($inventoryParent[$current]);

        if (isset($seen[$parent])) {
            break;
        }

        $seen[$parent] = true;
        $parents[]     = $parent;
        $current       = $parent;
    }

    return $parents;
};

/** @var array<string, list<string>> $interfaceCache */
$interfaceCache = [];

/**
 * Every interface a declaration has, the way PHPStan's ClassReflection::getInterfaces()
 * collects them: its own, its ancestors', and every interface those extend — a
 * breadth-first walk with a seen-set, memoised per declaration.
 *
 * @param string $fqcn An inventoried FQCN.
 *
 * @return list<string> The interface names.
 */
$interfacesOf = static function (string $fqcn) use (&$interfaceCache, $inventory, $inventoryInterfaces, $parentsOf, $canonicalName): array {
    if (isset($interfaceCache[$fqcn])) {
        return $interfaceCache[$fqcn];
    }

    $queue = $inventoryInterfaces[$fqcn] ?? [];

    foreach ($parentsOf($fqcn) as $parent) {
        foreach ($inventoryInterfaces[$parent] ?? [] as $name) {
            $queue[] = $name;
        }
    }

    $found = [];

    for ($position = 0; $position < count($queue); ++$position) {
        $name = $canonicalName($queue[$position]);

        if (isset($found[$name]) || ($name === $fqcn)) {
            continue;
        }

        $found[$name] = true;

        if (($inventory[$name] ?? null) === 'interface') {
            foreach ($inventoryInterfaces[$name] ?? [] as $extended) {
                $queue[] = $extended;
            }
        }
    }

    return $interfaceCache[$fqcn] = array_map(strval(...), array_keys($found));
};

/**
 * Runs a CONSUMER-supplied regular expression: a pattern PHP cannot compile (or one
 * that exhausts PCRE's backtrack limit) must not print PHP's own warning ahead of this
 * gate's diagnostic, so the warning is swallowed by a scoped handler — the pattern
 * reaches the report only through safeReportValue() — and the caller fails closed.
 *
 * @param string $pattern The pattern as the ArchitectureTest writes it.
 * @param string $subject The string to match.
 *
 * @return bool|null Whether it matches, or null when the pattern could not run.
 */
$regexMatches = static function (string $pattern, string $subject): ?bool {
    set_error_handler(static fn (): bool => true);

    try {
        $result = preg_match($pattern, $subject);
    } catch (ValueError) {
        $result = false;
    } finally {
        restore_error_handler();
    }

    return ($result === false) ? null : ($result === 1);
};

/**
 * The supertype indexes the non-regex implements()/extends() leaves look a name up
 * in, built once on first use rather than walking every declaration per leaf — so a
 * subject with thousands of such leaves costs one pass over the inventory, not one
 * per leaf. `implements` is keyed twice, verbatim and folded, because BetterReflection's
 * adapter matches either way (see $leafSet); `extends` is verbatim only.
 *
 * @var array{implements: array<string, array<string, true>>, implementsFolded: array<string, array<string, true>>, extends: array<string, array<string, true>>}|null $supertypeIndex
 */
$supertypeIndex = null;

/** @var array<string, array<string, true>> $leafCache */
$leafCache = [];

/**
 * The set one leaf selector selects from $universe, per phpat's own matches() (see
 * this section's header for each rule and how it was measured). Memoised per
 * selector, flag and argument, and answered from $supertypeIndex or a direct lookup
 * wherever phpat's comparison is an exact one, so only inNamespace() and a regex
 * leaf still visit every declaration.
 *
 * @param string $selector The canonical selector name.
 * @param string $argument The resolved string argument.
 * @param bool   $regex    The selector's regex flag.
 *
 * @return array<string, true> The selected FQCNs.
 *
 * @throws UnexpectedValueException When a regex argument cannot run.
 */
$leafSet = static function (string $selector, string $argument, bool $regex) use (&$leafCache, &$supertypeIndex, $universe, $parentsOf, $interfacesOf, $regexMatches, $asciiLower): array {
    $cacheKey = $selector . "\0" . ($regex ? '1' : '0') . "\0" . $argument;

    if (isset($leafCache[$cacheKey])) {
        return $leafCache[$cacheKey];
    }

    if ($regex && ($regexMatches($argument, '') === null)) {
        throw new UnexpectedValueException(sprintf('the %s() regular expression `%s` does not compile (fail-closed).', $selector, safeReportValue($argument)));
    }

    $trimmed = rtrim(ltrim($argument, '\\'), '\\');

    if (!$regex && ($selector === 'classname')) {
        return $leafCache[$cacheKey] = isset($universe[$trimmed]) ? [$trimmed => true] : [];
    }

    if (!$regex && (($selector === 'implements') || ($selector === 'extends'))) {
        if ($supertypeIndex === null) {
            $supertypeIndex = ['implements' => [], 'implementsFolded' => [], 'extends' => []];

            foreach (array_keys($universe) as $fqcn) {
                $fqcn = (string) $fqcn;

                foreach ($interfacesOf($fqcn) as $name) {
                    $supertypeIndex['implements'][$name][$fqcn]                    = true;
                    $supertypeIndex['implementsFolded'][$asciiLower($name)][$fqcn] = true;
                }

                foreach ($parentsOf($fqcn) as $name) {
                    $supertypeIndex['extends'][$name][$fqcn] = true;
                }
            }
        }

        // extends(): phpat compares trimSeparators(X) verbatim with each ancestor's
        // name. implements(): BetterReflection's adapter first looks X up FOLDED
        // among the declaration's own interface names, then falls back to X as
        // written with a leading `\` stripped — so a declaration matches when either
        // lookup finds it. The folded key keeps X's leading `\`, which is why
        // `\scr\root\i1` matched nothing in the measurement while `scr\root\i1` did.
        $set = ($selector === 'extends')
            ? ($supertypeIndex['extends'][$trimmed] ?? [])
            : ($supertypeIndex['implementsFolded'][$asciiLower($argument)] ?? []) + ($supertypeIndex['implements'][ltrim($argument, '\\')] ?? []);

        return $leafCache[$cacheKey] = $set;
    }

    $set = [];

    foreach (array_keys($universe) as $fqcn) {
        $fqcn = (string) $fqcn;

        if ($selector === 'inNamespace') {
            $segments = explode('\\', $fqcn);
            array_pop($segments);
            $namespace = implode('\\', $segments);

            $matched = $regex
                ? $regexMatches($argument, $namespace)
                : str_starts_with(rtrim(ltrim($namespace, '\\'), '\\') . '\\', $trimmed . '\\');
        } elseif ($selector === 'classname') {
            $matched = $regexMatches($argument, $fqcn);
        } else {
            $matched = false;

            foreach (($selector === 'extends') ? $parentsOf($fqcn) : $interfacesOf($fqcn) as $name) {
                $matched = $regexMatches($argument, $name);

                if ($matched !== false) {
                    break;
                }
            }
        }

        if ($matched === null) {
            throw new UnexpectedValueException(sprintf('the %s() regular expression `%s` failed to run (fail-closed).', $selector, safeReportValue($argument)));
        }

        if ($matched) {
            $set[$fqcn] = true;
        }
    }

    return $leafCache[$cacheKey] = $set;
};

/**
 * The selectors this gate evaluates, keyed by their folded name (PHP resolves a method
 * name case-insensitively), each with its canonical spelling and its argument shape:
 * `leaf` takes a string and an optional regex flag, `predicate` nothing, `composite`
 * one or more selectors. Anything else — OneOf, AtLeastCountOf, withFilepath,
 * isFinal (which also honours a `@final` tag), … — fails closed as unhandled.
 *
 * @var array<string, array{0: string, 1: string}> $selectorTable
 */
$selectorTable = [
    'innamespace' => ['inNamespace', 'leaf'],
    'classname'   => ['classname', 'leaf'],
    'implements'  => ['implements', 'leaf'],
    'extends'     => ['extends', 'leaf'],
    'isinterface' => ['isInterface', 'predicate'],
    'isabstract'  => ['isAbstract', 'predicate'],
    'isenum'      => ['isEnum', 'predicate'],
    'istrait'     => ['isTrait', 'predicate'],
    'all'         => ['all', 'predicate'],
    'allof'       => ['AllOf', 'composite'],
    'anyof'       => ['AnyOf', 'composite'],
    'noneof'      => ['NoneOf', 'composite'],
    'not'         => ['Not', 'composite'],
];

// The ArchitectureTest's own namespace and class imports, for a `Foo::class`
// argument — the same top-level walk (depth 0, the unbracketed-namespace assumption
// $topDepth documents) and the same $parseUseImports the src/ inventory uses.
$testNamespace = '';

/** @var array<string, string> $testImports */
$testImports = [];
$walkDepth   = 0;

for ($index = 0; $index < $ruleCount; ++$index) {
    $token = $ruleTokens[$index];
    $walkDepth += $braceDelta($token);

    if (!is_array($token) || ($walkDepth !== 0)) {
        continue;
    }

    if ($token[0] === \T_NAMESPACE) {
        $testNamespace = $nextName($ruleTokens, $index + 1, $ruleCount, [\T_WHITESPACE], [\T_STRING, \T_NAME_QUALIFIED]) ?? '';
        $testImports   = [];
    } elseif ($token[0] === \T_USE) {
        [$statementImports, $end] = $parseUseImports($ruleTokens, $index + 1, $ruleCount, $asciiLower);

        $testImports = array_replace($testImports, $statementImports);
        $index       = max($index, $end - 1);
    }
}

/**
 * Caps an assembled selector label. Every consumer value inside one already passed
 * safeReportValue() on its own; this bounds the length a deeply composed expression
 * would otherwise give the report line. mb_strcut(), for the reason safeReportValue()
 * documents.
 *
 * @param string $label The label.
 *
 * @return string The label, cut to 256 bytes with a trailing `…` marker when longer.
 */
$capLabel = static fn (string $label): string => (strlen($label) > 256) ? mb_strcut($label, 0, 256, 'UTF-8') . '…' : $label;

/**
 * Tokenises a rule-method body for the selector parser: the token list, the indexes of
 * its significant (non-whitespace) tokens — which is what every position below counts
 * in — and the matching closer of every bracket, built in ONE pass with a stack so the
 * parser can step over a nested argument in O(1) instead of rescanning it. A closer
 * that does not match the innermost open bracket abandons every bracket still open, so
 * a malformed nesting leaves the enclosing call unmatched and the parser fails closed.
 *
 * @param string $body The rule method's body text.
 *
 * @return array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>}
 */
$selectorContext = static function (string $body): array {
    $tokens = token_get_all('<?php ' . $body);
    $sig    = [];

    foreach ($tokens as $position => $token) {
        if (is_array($token) && (($token[0] === \T_WHITESPACE) || ($token[0] === \T_OPEN_TAG))) {
            continue;
        }

        $sig[] = $position;
    }

    $match = [];
    $stack = [];

    foreach ($sig as $index => $position) {
        $token  = $tokens[$position];
        $closer = null;

        if (is_array($token)) {
            $closer = match ($token[0]) {
                \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES => '}',
                \T_ATTRIBUTE                               => ']',
                default                                    => null,
            };
        } else {
            $closer = match ($token) {
                '('     => ')',
                '['     => ']',
                '{'     => '}',
                default => null,
            };
        }

        if ($closer !== null) {
            $stack[] = [$index, $closer];

            continue;
        }

        if (($token === ')') || ($token === ']') || ($token === '}')) {
            $top = array_pop($stack);

            if (($top === null) || ($top[1] !== $token)) {
                $stack = [];

                continue;
            }

            $match[$top[0]] = $index;
        }
    }

    return ['tokens' => $tokens, 'sig' => $sig, 'match' => $match];
};

/**
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $index   A significant-token index.
 *
 * @return array{0: int, 1: string, 2: int}|string The token.
 */
$tokenAt = static fn (array $context, int $index): array|string => $context['tokens'][$context['sig'][$index]];

/**
 * The source text of the significant-token range [$start, $end), whitespace included.
 *
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $start   First significant index (inclusive).
 * @param int                                                                                                  $end     Last significant index (exclusive).
 *
 * @return string The text.
 */
$rangeText = static function (array $context, int $start, int $end): string {
    $text = '';

    if ($start >= $end) {
        return $text;
    }

    for ($position = $context['sig'][$start]; $position <= $context['sig'][$end - 1]; ++$position) {
        $token = $context['tokens'][$position];
        $text .= is_array($token) ? $token[1] : $token;
    }

    return trim($text);
};

/**
 * Splits the argument list between the brackets at $open and $close on its top-level
 * commas, stepping over every nested bracket through the precomputed match table. A
 * trailing comma is legal PHP and ends no argument; an empty argument elsewhere, a
 * named argument and a spread are not shapes this gate reads, so they fail closed.
 *
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $open    The `(` index.
 * @param int                                                                                                  $close   The matching `)` index.
 * @param string                                                                                               $callee  The call's name, for the report.
 *
 * @return list<array{0: int, 1: int}> Each argument's [start, end) range.
 *
 * @throws UnexpectedValueException On an argument shape this gate does not read.
 */
$splitArguments = static function (array $context, int $open, int $close, string $callee) use ($tokenAt): array {
    $arguments = [];
    $start     = $open + 1;

    for ($index = $open + 1; $index < $close; ++$index) {
        if (isset($context['match'][$index])) {
            $index = $context['match'][$index];

            continue;
        }

        if ($tokenAt($context, $index) === ',') {
            $arguments[] = [$start, $index];
            $start       = $index + 1;
        }
    }

    if ($start < $close) {
        $arguments[] = [$start, $close];
    }

    foreach ($arguments as [$argumentStart, $argumentEnd]) {
        $first = $tokenAt($context, $argumentStart);

        if ($argumentStart === $argumentEnd) {
            throw new UnexpectedValueException(sprintf('could not identify a subject selector — %s() has an empty argument (fail-closed).', $callee));
        }

        if (is_array($first) && ($first[0] === \T_ELLIPSIS)) {
            throw new UnexpectedValueException(sprintf('could not identify a subject selector — %s() spreads its arguments, which this gate does not read (fail-closed).', $callee));
        }

        if (($argumentEnd - $argumentStart > 1) && ($tokenAt($context, $argumentStart + 1) === ':')) {
            throw new UnexpectedValueException(sprintf('could not identify a subject selector — %s() takes a named argument, which this gate does not read (fail-closed).', $callee));
        }
    }

    return $arguments;
};

/**
 * Recognises a range that is exactly one `Selector::name(…)` call: the class spelled
 * `Selector` (the import every fixture and consumer writes) or any name resolving to
 * `PHPat\Selector\Selector` through the ArchitectureTest's imports, `::`, a method name
 * (after `::` PHP's lexer still emits `implements`/`extends` as keyword tokens, hence
 * any identifier-shaped token), and a `(` whose matching `)` ends the range.
 *
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $start   First significant index (inclusive).
 * @param int                                                                                                  $end     Last significant index (exclusive).
 *
 * @return array{0: string, 1: int}|null The method name as written and the `(` index, or null.
 */
$selectorCall = static function (array $context, int $start, int $end) use ($tokenAt, $resolveClassName, $testNamespace, $testImports, $asciiLower): ?array {
    if (($end - $start) < 4) {
        return null;
    }

    $class  = $tokenAt($context, $start);
    $colons = $tokenAt($context, $start + 1);
    $method = $tokenAt($context, $start + 2);

    if (!is_array($class)
        || !in_array($class[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)
        || !is_array($colons) || ($colons[0] !== \T_DOUBLE_COLON)
        || !is_array($method) || (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $method[1]) !== 1)
        || ($tokenAt($context, $start + 3) !== '(')
        || (($context['match'][$start + 3] ?? null) !== ($end - 1))
    ) {
        return null;
    }

    if (strcasecmp($class[1], 'Selector') !== 0) {
        $resolved = $resolveClassName($class, $testNamespace, $testImports, $asciiLower);

        if (($resolved === null) || (strcasecmp($resolved, 'PHPat\Selector\Selector') !== 0)) {
            return null;
        }
    }

    return [$method[1], $start + 3];
};

/**
 * Evaluates a scalar selector argument: single-quoted string literals,
 * `self::NAMESPACE_ROOT` (or `static::`), `Name::class` resolved through the
 * ArchitectureTest's own namespace and imports, joined by `.`; or a lone `true`/`false`
 * for a regex flag. Anything else — a variable, a call, another constant, a
 * double-quoted string (whose escapes PHP decodes, see the NAMESPACE_ROOT walk) —
 * cannot be evaluated statically, so it fails closed rather than being guessed at.
 *
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $start    First significant index (inclusive).
 * @param int                                                                                                  $end      Last significant index (exclusive).
 * @param string                                                                                               $selector The selector the argument belongs to, for the report.
 *
 * @return string|bool The value.
 *
 * @throws UnexpectedValueException When the argument cannot be evaluated.
 */
$evaluateScalar = static function (array $context, int $start, int $end, string $selector) use ($tokenAt, $rangeText, $resolveClassName, $testNamespace, $testImports, $asciiLower, $namespaceRoot): string|bool {
    $unresolvable = static fn (): UnexpectedValueException => new UnexpectedValueException(sprintf(
        'could not resolve the %s() argument `%s` (fail-closed).',
        $selector,
        safeReportValue($rangeText($context, $start, $end))
    ));

    $pieces     = [];
    $pieceStart = $start;

    for ($index = $start; $index < $end; ++$index) {
        if (isset($context['match'][$index])) {
            $index = $context['match'][$index];

            continue;
        }

        if ($tokenAt($context, $index) === '.') {
            $pieces[]   = [$pieceStart, $index];
            $pieceStart = $index + 1;
        }
    }

    $pieces[] = [$pieceStart, $end];
    $values   = [];

    foreach ($pieces as [$pieceStart, $pieceEnd]) {
        $length = $pieceEnd - $pieceStart;
        $first  = ($length > 0) ? $tokenAt($context, $pieceStart) : null;
        $value  = null;

        if (($length === 1) && is_array($first)) {
            if (($first[0] === \T_CONSTANT_ENCAPSED_STRING) && ($first[1][0] === "'")) {
                // A single-quoted literal knows two escapes only, `\\` and `\'`.
                $value = strtr(substr($first[1], 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
            } elseif (($first[0] === \T_STRING) && in_array($asciiLower($first[1]), ['true', 'false'], true)) {
                $value = ($asciiLower($first[1]) === 'true');
            }
        } elseif (($length === 3) && is_array($first)) {
            $colons = $tokenAt($context, $pieceStart + 1);
            $member = $tokenAt($context, $pieceStart + 2);

            if (is_array($colons) && ($colons[0] === \T_DOUBLE_COLON) && is_array($member)) {
                if (($member[0] === \T_CLASS)
                    && in_array($first[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)
                ) {
                    $value = $resolveClassName($first, $testNamespace, $testImports, $asciiLower);
                } elseif (($first[0] === \T_STRING)
                    && in_array($asciiLower($first[1]), ['self', 'static'], true)
                    && ($member[0] === \T_STRING)
                    && ($member[1] === 'NAMESPACE_ROOT')
                ) {
                    $value = $namespaceRoot;
                }
            }
        }

        if ($value === null) {
            throw $unresolvable();
        }

        $values[] = $value;
    }

    if (count($values) === 1) {
        return $values[0];
    }

    $joined = '';

    foreach ($values as $value) {
        if (!is_string($value)) {
            throw $unresolvable();
        }

        $joined .= $value;
    }

    return $joined;
};

/**
 * Evaluates one `Selector::…(…)` call — recursively for a composite — to the set of
 * src/ declarations it selects, plus a report label for it.
 *
 * Linear in the expression: every argument list is split once, stepping over nested
 * brackets through the match table, and every call is evaluated exactly once. Set
 * operations cost at most the inventory size per call.
 *
 * @param array{tokens: list<array{0: int, 1: string, 2: int}|string>, sig: list<int>, match: array<int, int>} $context
 * @param int                                                                                                  $start   First significant index (inclusive) of a range $selectorCall accepted.
 * @param int                                                                                                  $end     Last significant index (exclusive).
 * @param int                                                                                                  $depth   The nesting depth of this call, 1 for a classes() argument.
 *
 * @return array{set: array<string, true>, label: string, selector: string, regex: bool}
 *
 * @throws UnexpectedValueException When the expression cannot be evaluated.
 */
$evaluateSelector = static function (array $context, int $start, int $end, int $depth) use (&$evaluateSelector, $selectorCall, $splitArguments, $evaluateScalar, $leafSet, $rangeText, $selectorTable, $universe, $inventory, $asciiLower, $capLabel): array {
    if ($depth > MAX_SELECTOR_DEPTH) {
        throw new UnexpectedValueException(sprintf('could not identify a subject selector — the expression nests deeper than %d selector calls (fail-closed).', MAX_SELECTOR_DEPTH));
    }

    $call = $selectorCall($context, $start, $end);

    if ($call === null) {
        throw new UnexpectedValueException('could not identify a subject selector (fail-closed).');
    }

    [$written, $open] = $call;

    if (!isset($selectorTable[$asciiLower($written)])) {
        throw new UnexpectedValueException(sprintf('unhandled subject selector Selector::%s() (fail-closed).', safeReportValue($written)));
    }

    [$selector, $shape] = $selectorTable[$asciiLower($written)];
    $arguments          = $splitArguments($context, $open, $end - 1, $selector);
    $argumentCount      = count($arguments);

    $arityError = static fn (string $expected): UnexpectedValueException => new UnexpectedValueException(sprintf(
        'could not identify a subject selector — Selector::%s() takes %s, not %d argument(s) (fail-closed).',
        $selector,
        $expected,
        $argumentCount
    ));

    if ($shape === 'predicate') {
        if ($argumentCount !== 0) {
            throw $arityError('no argument');
        }

        $set = match ($selector) {
            'all'   => $universe,
            default => [],
        };

        if ($selector !== 'all') {
            $kind = match ($selector) {
                'isInterface' => 'interface',
                'isAbstract'  => 'abstract-class',
                'isEnum'      => 'enum',
                default       => null,
            };

            foreach (array_keys($universe) as $fqcn) {
                if (($kind !== null) && (($inventory[$fqcn] ?? null) === $kind)) {
                    $set[(string) $fqcn] = true;
                }
            }
        }

        return ['set' => $set, 'label' => $selector . '()', 'selector' => $selector, 'regex' => false];
    }

    if ($shape === 'leaf') {
        if (($argumentCount < 1) || ($argumentCount > 2)) {
            throw $arityError('a name and an optional regex flag');
        }

        $unresolvable = static fn (int $position): UnexpectedValueException => new UnexpectedValueException(sprintf(
            'could not resolve the %s() argument `%s` (fail-closed).',
            $selector,
            safeReportValue($rangeText($context, $arguments[$position][0], $arguments[$position][1]))
        ));

        if ($selectorCall($context, $arguments[0][0], $arguments[0][1]) !== null) {
            throw $unresolvable(0);
        }

        $argument = $evaluateScalar($context, $arguments[0][0], $arguments[0][1], $selector);
        $regex    = ($argumentCount === 2) ? $evaluateScalar($context, $arguments[1][0], $arguments[1][1], $selector) : false;

        if (!is_string($argument)) {
            throw $unresolvable(0);
        }

        if (!is_bool($regex)) {
            throw $unresolvable(1);
        }

        $shown = $regex ? $argument : rtrim(ltrim($argument, '\\'), '\\');

        return [
            'set'      => $leafSet($selector, $argument, $regex),
            'label'    => sprintf('%s(%s%s)', $selector, safeReportValue($shown), $regex ? ', regex' : ''),
            'selector' => $selector,
            'regex'    => $regex,
        ];
    }

    // Composite. phpat's Not() declares ONE parameter; PHP silently drops any
    // further argument to a userland function, so `Not(a, b)` would mean `Not(a)` —
    // a shape nobody writes on purpose, so it fails closed rather than being read.
    if (($selector === 'Not') && ($argumentCount !== 1)) {
        throw $arityError('exactly one selector');
    }

    $children = [];
    $labels   = [];

    foreach ($arguments as [$argumentStart, $argumentEnd]) {
        if ($selectorCall($context, $argumentStart, $argumentEnd) === null) {
            throw new UnexpectedValueException(sprintf(
                'could not resolve the %s() argument `%s` (fail-closed).',
                $selector,
                safeReportValue($rangeText($context, $argumentStart, $argumentEnd))
            ));
        }

        $child      = $evaluateSelector($context, $argumentStart, $argumentEnd, $depth + 1);
        $children[] = $child['set'];
        $labels[]   = $child['label'];
    }

    if ($selector === 'AllOf') {
        $set = $universe;

        foreach ($children as $child) {
            $set = array_intersect_key($set, $child);
        }
    } else {
        $union = [];

        foreach ($children as $child) {
            $union += $child;
        }

        $set = ($selector === 'AnyOf') ? $union : array_diff_key($universe, $union);
    }

    return [
        'set'      => $set,
        'label'    => $capLabel(sprintf('%s(%s)', $selector, implode(', ', $labels))),
        'selector' => $selector,
        'regex'    => false,
    ];
};

foreach ($ruleMethods as [$ruleName, $methodBody]) {
    // The subject is the FIRST `->classes(…)` call in the method body, read as TOKENS
    // (so a `->classes(` or `->should(` inside a string literal is text, not a call),
    // provided no `->should(…)`/`->shouldNot(…)` call comes before it — past that point
    // a `->classes(…)` names the rule's TARGET, never its subject. It is NOT anchored to
    // a `PHPat::rule()` call.
    //
    // Two known, deliberately undefended gaps follow from scanning the unanchored body:
    //
    //   - A #[TestRule]-attributed method NESTED inside another rule's own body (via a
    //     closure or anonymous class) is correctly excluded from $ruleMethods and from
    //     $attributeResolvedCount, but its text is still part of $methodBody for the
    //     ENCLOSING rule — the body-extraction loop bounds by brace depth alone, with no
    //     awareness of a nested function's own scope. If the nested rule's own
    //     ->classes(...) call appears earlier in the text than the enclosing rule's, this
    //     scan misattributes the nested rule's subject to the enclosing rule's name in
    //     the printed violation. NAMING only, not fail-open: the misattachment check
    //     above already reds the run for the nested attribute regardless. Pinned by the
    //     nested-testrule-not-counted-as-resolved fixture's must-carry check.
    //   - The same unanchored scan can be defeated in the OTHER, fail-OPEN direction by a
    //     decoy: unattributed helper code inside the method body that happens to contain
    //     its own, earlier ->classes(Selector::live(...)) chain would have ITS live
    //     subject picked up and reported in place of the enclosing rule's actual
    //     (possibly vacuous) one. Deliberately undefended — this needs hand-authored code
    //     shaped like a second phpat rule chain that never runs as one, not something
    //     written by accident; no real ArchitectureTest does this (same disposition class
    //     as $topDepth's two documented gaps above). Fixing it would mean anchoring the
    //     scan to the actual `PHPat::rule()`/`$this->{name}()` call the rule builder
    //     starts from, a materially bigger parse than this file otherwise needs.
    //
    // A decoy `"{$x}"`/`${x}` interpolation BEFORE a method's own opening brace (e.g. in
    // a parameter default, to close the brace-depth counter back to 0 before the real
    // body is reached) is NOT a third gap here: PHP requires a parameter default (and an
    // attribute argument) to be a constant expression, and string interpolation is
    // categorically non-constant — verified live (`php -l`) that such a file is a
    // compile-time fatal ("Constant expression contains invalid operations"), so it can
    // never load for phpat/PHPUnit to run in the first place. Considered and rejected as
    // non-manifesting, not merely undefended.
    //
    // A separate, unrelated limitation: phpat accepts a rule method returning an
    // `iterable` of multiple rules (TestParser.php: `is_iterable($ruleBuilder)`), each
    // checked independently. This gate reads only the FIRST ->classes(...) in the whole
    // method and has no notion of "the next rule" at all — a second, later rule yielded
    // by the same method is never inspected. Out of scope for GH-58 (which added the
    // test*-name discovery path, not multi-rule-per-method support); tracked as its own
    // follow-up rather than folded into this already-large change.
    //
    // `->excluding(…)` after the subject is deliberately NOT evaluated: a subject that
    // its exclusions narrow to nothing is a conditional guard in the same sense as a
    // top-level isAbstract() — `inNamespace(Contract)->excluding(isInterface())` is
    // legitimately empty until the first abstract contract class lands — and phpat
    // applies the exclusions to each subject selector alike, so ignoring them can only
    // make a subject look larger here, never hide a vacuous one.
    $context   = $selectorContext($methodBody);
    $sigCount  = count($context['sig']);
    $classesAt = null;

    for ($index = 0; $index + 2 < $sigCount; ++$index) {
        $arrow = $tokenAt($context, $index);
        $name  = $tokenAt($context, $index + 1);

        if (!is_array($arrow) || ($arrow[0] !== \T_OBJECT_OPERATOR) || !is_array($name) || ($tokenAt($context, $index + 2) !== '(')) {
            continue;
        }

        if ((strcasecmp($name[1], 'should') === 0) || (strcasecmp($name[1], 'shouldNot') === 0)) {
            break;
        }

        if (strcasecmp($name[1], 'classes') === 0) {
            $classesAt = $index + 2;

            break;
        }
    }

    if ($classesAt === null) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', safeReportValue($ruleName));

        continue;
    }

    $classesClose = $context['match'][$classesAt] ?? null;

    if ($classesClose === null) {
        $violations[] = sprintf('%s: could not identify a subject selector — its ->classes(…) call does not close (fail-closed).', safeReportValue($ruleName));

        continue;
    }

    try {
        $subjects = $splitArguments($context, $classesAt, $classesClose, 'classes');
    } catch (UnexpectedValueException $exception) {
        $violations[] = sprintf('%s: %s', safeReportValue($ruleName), $exception->getMessage());

        continue;
    }

    if (count($subjects) === 0) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', safeReportValue($ruleName));

        continue;
    }

    // phpat's classes() is variadic, and its StatementBuilder turns EVERY subject
    // selector into a statement of its own (re-derive: grep -n 'getSubjects' -A8
    // tests/consumer/.build/vendor/phpat/phpat/src/Statement/StatementBuilder.php) —
    // so each argument is a rule in its own right, and one that selects nothing is
    // vacuous however live its siblings are. Each is checked, and named by position
    // when there is more than one.
    foreach ($subjects as $position => [$subjectStart, $subjectEnd]) {
        $label = (count($subjects) > 1)
            ? sprintf('%s: classes() argument %d of %d', safeReportValue($ruleName), $position + 1, count($subjects))
            : safeReportValue($ruleName);

        $call = $selectorCall($context, $subjectStart, $subjectEnd);

        if ($call === null) {
            $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', $label);

            continue;
        }

        if ((strcasecmp($call[0], 'isAbstract') === 0) && ($subjectEnd - 1 === $call[1] + 1)) {
            // Conditional naming guard — legitimately empty until an abstract class
            // exists. Only the BARE top-level form: inside a composite, isAbstract()
            // is an ordinary set like any other.
            fwrite(\STDOUT, sprintf("  %s: isAbstract() subject — conditional guard, liveness not checked.\n", $label));

            continue;
        }

        try {
            $subject = $evaluateSelector($context, $subjectStart, $subjectEnd, 1);
        } catch (UnexpectedValueException $exception) {
            $violations[] = sprintf('%s: %s', $label, $exception->getMessage());

            continue;
        }

        // A subject is judged against the inventory, so a short inventory can only
        // produce "not found" — which this gate would otherwise print as "matches no
        // class", a cause the repository does not have. Measured before this guard:
        // one `chmod 000` on a single class made the gate report a live rule as
        // vacuous, beside the read failure that explained it. The read failure already
        // reds the run, so staying silent about liveness loses nothing.
        if ($inventoryIncomplete || (count($subject['set']) > 0)) {
            continue;
        }

        $why = match (true) {
            ($subject['selector'] === 'inNamespace') && !$subject['regex'] => 'a vacuous rule (a trait-only or empty namespace enforces nothing).',
            ($subject['selector'] === 'classname') && !$subject['regex']   => 'renamed, moved or mistyped, so the rule enforces nothing.',
            default                                                        => 'the selector expression selects nothing in src/, so the rule enforces nothing.',
        };

        $violations[] = sprintf('%s: subject %s matches no class — %s', $label, $subject['label'], $why);
    }
}

// --- Report ---
if (count($violations) === 0) {
    fwrite(\STDOUT, "check-phpat-subjects: OK — every phpat rule subject matches at least one class.\n");
    exit(0);
}

fwrite(\STDERR, sprintf("check-phpat-subjects: %d problem(s) — vacuous or unparseable rule subjects, or files this gate could not read:\n", count($violations)));

foreach ($violations as $violation) {
    fwrite(\STDERR, sprintf("  - %s\n", $violation));
}

fwrite(\STDERR, "\nA rule whose subject matches nothing passes green while enforcing nothing.\n");
exit(1);
