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
 * adds:
 *   - `Selector::inNamespace(NS)`  → at least one non-trait, non-interface, non-enum
 *                                     class exists in NS (a trait-only namespace, the
 *                                     manifested bug, fails here);
 *   - `Selector::classname(FQCN)`  → that class exists (a renamed or mistyped target
 *                                     fails here);
 *   - `Selector::isAbstract()`     → NOT liveness-checked: it is a conditional naming
 *                                     guard that legitimately matches nothing until an
 *                                     abstract class is added, so an empty match is
 *                                     correct, not a bug.
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

/**
 * Reads at most MAX_SOURCE_BYTES + 1 bytes, with PHP's own diagnostic suppressed.
 *
 * Capped at the read, the way bin/check-consumer-config.php caps its own. Both the
 * ArchitectureTest and every `src/*.php` below are pull-request content in the
 * CONSUMER's CI, and an uncapped read of one ends in `Allowed memory size
 * exhausted` — exit 255, no gate diagnostic. Reproduced with a 196 MB file at
 * memory_limit=128M.
 *
 * The scoped handler is why this is a function and not a bare call. On an
 * unreadable file PHP raises an E_WARNING nothing suppresses, so
 * `Failed to open stream: Permission denied` lands on the stream AHEAD of this
 * gate's own diagnostic, carrying a path that never passed safeReportValue() —
 * and the shared test harness classifies any run carrying such a line as having
 * produced no verdict at all. Both sibling gates already read this way.
 *
 * @param string $path Path to the file to read.
 *
 * @return string|false The contents, or false when the file could not be read.
 */
$readSource = static function (string $path): string|false {
    set_error_handler(static fn (): bool => true);

    try {
        return file_get_contents($path, false, null, 0, MAX_SOURCE_BYTES + 1);
    } finally {
        restore_error_handler();
    }
};

$sourceRaw = $readSource($architectureTest);

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

if (strlen($sourceRaw) > MAX_SOURCE_BYTES) {
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
    // uses, needing `assert_accepts` to hold) never reaches more than one inner
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
    $sourceFile = $readSource($file->getPathname());

    if ($sourceFile === false) {
        $violations[]        = sprintf('%s cannot be read, so its classes are not in the inventory.', safeReportValue($file->getPathname()));
        $inventoryIncomplete = true;

        continue;
    }

    if (strlen($sourceFile) > MAX_SOURCE_BYTES) {
        $violations[]        = sprintf(
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

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];

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

        $fqcn            = ($namespace !== '') ? $namespace . '\\' . $name : $name;
        $isAbstractClass = ($kinds[$token[0]] === 'class') && in_array(\T_ABSTRACT, $modifiers, true);

        $inventory[$fqcn] = $isAbstractClass ? 'abstract-class' : $kinds[$token[0]];
        $modifiers        = [];
    }
}

/**
 * Reports whether at least one concrete or abstract CLASS (not a trait, interface or
 * enum) lives in the given namespace or a sub-namespace of it — the condition phpat's
 * `InClassNode` needs for an `inNamespace` subject to match anything.
 *
 * @param array<string, string> $inventory FQCN to declaration kind, as built above.
 * @param string                $namespace The namespace the subject names.
 *
 * @return bool True when at least one class lives there.
 */
$namespaceHasClass = static function (array $inventory, string $namespace): bool {
    foreach ($inventory as $fqcn => $kind) {
        if (($kind !== 'class') && ($kind !== 'abstract-class')) {
            continue;
        }

        if (($fqcn === $namespace) || str_starts_with($fqcn, $namespace . '\\')) {
            return true;
        }
    }

    return false;
};

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
// grep -n 'getMethods\|reflectTest' .build/vendor/phpat/phpat/src/Test/Test{Parser,Extractor}.php),
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
 * Classifies a token's effect on brace depth: +1 for an opener, -1 for a closer, 0 for
 * neither. A bare CHAR `{`/`}` is the usual case; the two string-interpolation openers
 * are the exception that must also count as +1, because their CLOSING brace is an
 * ordinary CHAR `}` — `{$a}` opens with T_CURLY_OPEN, `${a}` with
 * T_DOLLAR_OPEN_CURLY_BRACES, and skipping them leaves that `}` decrementing against
 * nothing (measured: cut a live rule's body short and reported it as unparseable).
 * Shared by every depth counter in this file so this recognition rule lives in
 * exactly one place — $resolveTestRuleAliases's own pre-pass depth, $topDepth, and
 * the per-method body-extraction loop all call it rather than each carrying their
 * own copy of the same four-way token check.
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
 * Resolves every local name that resolves to the TestRule attribute — the literal
 * name plus every `as`-alias a `use` import establishes for it. A `use
 * PHPat\Test\Attributes\TestRule as X;` import makes `#[X]` the real attribute — PHP
 * resolves it via ordinary import-alias resolution, and phpat's own TestParser filters
 * by FQCN (`getAttributes(TestRule::class)`, re-derive with:
 * grep -n 'getAttributes\|preg_match' .build/vendor/phpat/phpat/src/Test/TestParser.php),
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
                $importNameLower = strtolower($importName);
                $aliasName       = $nextName($tokens, $ahead + 1, $count, [\T_WHITESPACE]);

                if (!$isFunctionOrConstItem
                    && ($aliasName !== null)
                    && (($importNameLower === 'testrule') || str_ends_with($importNameLower, '\testrule'))
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

// Lowercased once, compared against a lowercased segment at the recognition site below
// — case-insensitivity rationale at $resolveTestRuleAliases's T_AS branch above.
$testRuleAliasesLower = array_map(strtolower(...), $testRuleAliases);

/**
 * Scans one attribute group (`#[...]`) for a name matching a TestRule alias —
 * shared by the rule-discovery loop's own T_ATTRIBUTE handling and the
 * body-extraction loop's inline nested-attribute tracking below, both of which
 * need the identical bracket/paren/comma state machine and name-matching rule.
 *
 * T_ATTRIBUTE is the opening `#[` alone; the names follow as ordinary tokens
 * until the bracket closes. Only the last `\`-separated segment is compared,
 * so the qualified and imported spellings answer the same — case-insensitive,
 * against $testRuleAliasesLower, for the same reason that set itself is: PHP
 * resolves a class/attribute reference case-insensitively.
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
 * @param list<array{0: int, 1: string, 2: int}|string>  $tokens               The token stream to scan.
 * @param int                                            $count                The token count (exclusive upper bound).
 * @param int                                            $start                The index to start scanning from (inclusive) — the token right after T_ATTRIBUTE.
 * @param list<string>                                   $testRuleAliasesLower Every local, lowercased name that resolves to the TestRule attribute.
 *
 * @return array{matched: bool, end: int} Whether a TestRule name was found, and the index one past the group's closing `]`.
 */
$scanAttributeGroup = static function (array $tokens, int $count, int $start, array $testRuleAliasesLower): array {
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

            if (in_array(strtolower(end($segments)), $testRuleAliasesLower, true)) {
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
        $group = $scanAttributeGroup($ruleTokens, $ruleCount, $index + 1, $testRuleAliasesLower);

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
    // a `Test…`-named method does not qualify). phpat is vendored in THIS repository,
    // not just described — a version bump inside the ^0.12.4 constraint could change
    // it, so re-derive rather than trusting this comment:
    // grep -n 'preg_match\|getMethods' .build/vendor/phpat/phpat/src/Test/TestParser.php
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
            $group = $scanAttributeGroup($ruleTokens, $ruleCount, $ahead + 1, $testRuleAliasesLower);

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

foreach ($ruleMethods as [$ruleName, $methodBody]) {

    // The subject is the FIRST Selector::…(…) inside the FIRST ->classes(…) found
    // ANYWHERE in $methodBody's text — NOT anchored to a `PHPat::rule()` call (there is
    // no such anchor in the code below; a prior version of this comment claimed one that
    // was never implemented). Slice up to the first ->should/->shouldNot within the
    // method.
    //
    // Two known, deliberately undefended gaps follow from scanning unanchored text:
    //
    //   - A #[TestRule]-attributed method NESTED inside another rule's own body (via a
    //     closure or anonymous class) is correctly excluded from $ruleMethods and from
    //     $attributeResolvedCount, but its text is still part of $methodBody for the
    //     ENCLOSING rule — the body-extraction loop bounds by brace depth alone, with no
    //     awareness of a nested function's own scope. If the nested rule's own
    //     ->classes(...)->should(Not)? call appears earlier in the text than the
    //     enclosing rule's, this scan misattributes the nested rule's subject to the
    //     enclosing rule's name in the printed violation. NAMING only, not fail-open:
    //     the misattachment check above already reds the run for the nested attribute
    //     regardless. Pinned by the nested-testrule-not-counted-as-resolved fixture's
    //     must-carry check.
    //   - The same unanchored scan can be defeated in the OTHER, fail-OPEN direction by a
    //     decoy: unattributed helper code inside the method body that happens to contain
    //     its own, textually-earlier ->classes(Selector::live(...))->should(Not)? chain
    //     would have ITS live subject picked up and reported in place of the enclosing
    //     rule's actual (possibly vacuous) one. Deliberately undefended — this needs
    //     hand-authored code shaped like a second phpat rule chain that never runs as
    //     one, not something written by accident; no real ArchitectureTest does this
    //     (same disposition class as $topDepth's two documented gaps above). Fixing it
    //     would mean anchoring the scan to the actual `PHPat::rule()`/`$this->{name}()`
    //     call the rule builder starts from, a materially bigger parse than this file
    //     otherwise needs.
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
    $stop = preg_match('/->should(?:Not)?\s*\(/', $methodBody, $sm, \PREG_OFFSET_CAPTURE) === 1 ? $sm[0][1] : strlen($methodBody);
    $head = substr($methodBody, 0, $stop);

    if (preg_match('/->classes\s*\(\s*Selector::(\w+)\s*\(([^)]*)\)/', $head, $subj) !== 1) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', safeReportValue($ruleName));

        continue;
    }

    $selector = $subj[1];
    $argument = trim($subj[2]);

    if ($selector === 'isAbstract') {
        // Conditional naming guard — legitimately empty until an abstract class exists.
        fwrite(\STDOUT, sprintf("  %s: isAbstract() subject — conditional guard, liveness not checked.\n", safeReportValue($ruleName)));

        continue;
    }

    // Resolve the selector argument. The pattern is anchored to the WHOLE argument so
    // that only two shapes resolve: `self::NAMESPACE_ROOT` optionally concatenated with
    // a single `'\\Sub'` literal, or a bare quoted literal. A composed expression the
    // checker does not model (another constant, a variable, a second concatenation)
    // fails to match and falls through to the fail-closed branch below, rather than
    // silently resolving to just the root and testing the wrong namespace.
    $resolved = null;

    if (($namespaceRoot !== null) && (preg_match('/^self::NAMESPACE_ROOT(?:\s*\.\s*\'([^\']*)\')?$/', $argument, $am) === 1)) {
        $suffix   = isset($am[1]) ? str_replace('\\\\', '\\', $am[1]) : '';
        $resolved = $namespaceRoot . $suffix;
    } elseif (preg_match('/^\'([^\']+)\'$/', $argument, $lm) === 1) {
        $resolved = str_replace('\\\\', '\\', $lm[1]);
    }

    if ($resolved === null) {
        $violations[] = sprintf('%s: could not resolve the %s() argument `%s` (fail-closed).', safeReportValue($ruleName), safeReportValue($selector), safeReportValue($argument));

        continue;
    }

    // phpat's own Classname/ClassNamespace selectors strip a leading and trailing
    // `\` before comparing (`trimSeparators()` in phpat's helpers.php, `rtrim(ltrim($name,
    // '\\'), '\\')`) — verified live: `Selector::classname('\Vendor\Mod\Model\Node')`
    // matches the class phpat resolves as `Vendor\Mod\Model\Node`. Without this, a
    // fully-qualified-style argument (a common authoring convention) never matches this
    // gate's inventory, which is keyed WITHOUT a leading `\` (built from `namespace X;`
    // declarations, which never start with one) — a genuinely live rule reported vacuous.
    $resolved = trim($resolved, '\\');

    // A subject is judged against the inventory, so a short inventory can only
    // produce "not found" — which this gate would otherwise print as "matches no
    // class", a cause the repository does not have. Measured before this guard:
    // one `chmod 000` on a single class made the gate report a live rule as
    // vacuous, beside the read failure that explained it. The read failure already
    // reds the run, so staying silent about liveness loses nothing.
    if ($selector === 'inNamespace') {
        if (!$inventoryIncomplete && !$namespaceHasClass($inventory, $resolved)) {
            $violations[] = sprintf('%s: subject inNamespace(%s) matches no class — a vacuous rule (a trait-only or empty namespace enforces nothing).', safeReportValue($ruleName), safeReportValue($resolved));
        }

        continue;
    }

    if ($selector === 'classname') {
        $kind = $inventory[$resolved] ?? null;

        if (!$inventoryIncomplete && ($kind !== 'class') && ($kind !== 'abstract-class')) {
            $violations[] = sprintf('%s: subject classname(%s) matches no class — renamed, moved or mistyped, so the rule enforces nothing.', safeReportValue($ruleName), safeReportValue($resolved));
        }

        continue;
    }

    $violations[] = sprintf('%s: unhandled subject selector Selector::%s() (fail-closed).', safeReportValue($ruleName), safeReportValue($selector));
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
