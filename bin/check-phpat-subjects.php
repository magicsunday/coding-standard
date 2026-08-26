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
 * Only the NAMESPACE_ROOT regex below still needs this; the rule scan and the class
 * inventory walk tokens, and `token_get_all` emits no T_ATTRIBUTE for a commented-out
 * example anyway (measured on 8.5). Whitespace is preserved so the line-anchored
 * pattern still behaves.
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
                // Keep the newlines a multi-line comment spanned so line numbers and
                // the `^`-anchored patterns are unaffected.
                $result .= str_repeat("\n", substr_count($token[1], "\n"));

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

// --- Resolve the module root namespace (the NAMESPACE_ROOT constant) ---
$namespaceRoot = null;

if (preg_match('/const\s+string\s+NAMESPACE_ROOT\s*=\s*\'([^\']+)\'/', $source, $m) === 1) {
    // A single-quoted namespace literal may be written with single or escaped
    // (`\\`) backslashes; normalise to the single-backslash form the `namespace`
    // declarations in the class inventory always use.
    $namespaceRoot = str_replace('\\\\', '\\', $m[1]);
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

    // Tokens, not a line-anchored pattern over comment-stripped text. Two defects
    // the text form had, both silent and both fail-OPEN for the liveness check it
    // feeds: a line reading `class Fake` inside a string literal or a heredoc
    // registered a class that does not exist, so a vacuous `classname(Fake)`
    // subject was certified live; and `preg_match` took the FIRST declaration per
    // file only, so a second class in one file was invisible.
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

    $tokens    = token_get_all($sourceFile);
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
            $namespace = '';

            for ($ahead = $index + 1; $ahead < $count; ++$ahead) {
                $next = $tokens[$ahead];

                if (!is_array($next)) {
                    break;
                }

                if ($next[0] === \T_WHITESPACE) {
                    continue;
                }

                if (($next[0] !== \T_STRING) && ($next[0] !== \T_NAME_QUALIFIED)) {
                    break;
                }

                $namespace = $next[1];

                break;
            }

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

        $name = null;

        for ($ahead = $index + 1; $ahead < $count; ++$ahead) {
            $next = $tokens[$ahead];

            if (is_array($next) && ($next[0] === \T_WHITESPACE)) {
                continue;
            }

            if (is_array($next) && ($next[0] === \T_STRING)) {
                $name = $next[1];
            }

            break;
        }

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
//     ArchitectureTest's rule set. PSR-1 is what makes this unreachable in practice.
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
 * Shared by every depth counter below so this recognition rule lives in exactly one
 * place — $topDepth and the per-method body-extraction loop both call it rather than
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
 * Resolves every local name that resolves to the TestRule attribute — the literal
 * name plus every `as`-alias a `use` import establishes for it. A `use
 * PHPat\Test\Attributes\TestRule as X;` import makes `#[X]` the real attribute — PHP
 * resolves it via ordinary import-alias resolution, and phpat's own TestParser filters
 * by FQCN (`getAttributes(TestRule::class)`, re-derive with:
 * grep -n 'getMethods\|reflectTest' .build/vendor/phpat/phpat/src/Test/Test{Parser,Extractor}.php),
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
 * `T_FUNCTION`/`T_CONST` are skipped inside a group (`use A\{function f, TestRule}`)
 * without being read as a name — neither can ever be an alias target for a CLASS
 * import, so treating them as ordinary noise between commas is correct, not a gap.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens The file's full
 *                                                                    token stream.
 *
 * @return list<string> Every local name that resolves to the TestRule attribute,
 *                       'TestRule' itself always included.
 */
$resolveTestRuleAliases = static function (array $tokens) use ($braceDelta): array {
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

        $groupPrefix = null;
        $importName  = null;

        for ($ahead = $index + 1; $ahead < $count; ++$ahead) {
            $next = $tokens[$ahead];

            if (!is_array($next)) {
                if ($next === ';') {
                    break;
                }

                if ($next === ',') {
                    // A new item starts — inside a group if $groupPrefix is set, else
                    // the next import on the same `use` line.
                    $importName = null;

                    continue;
                }

                if ($next === '{') {
                    // The name gathered so far becomes the prefix every item inside
                    // the group is relative to.
                    $groupPrefix = $importName;
                    $importName  = null;

                    continue;
                }

                if ($next === '}') {
                    $groupPrefix = null;
                    $importName  = null;

                    continue;
                }

                break;
            }

            if (($next[0] === \T_WHITESPACE)
                || ($next[0] === \T_NS_SEPARATOR)
                || ($next[0] === \T_FUNCTION)
                || ($next[0] === \T_CONST)
            ) {
                continue;
            }

            if (($importName === null)
                && (($next[0] === \T_STRING) || ($next[0] === \T_NAME_QUALIFIED) || ($next[0] === \T_NAME_FULLY_QUALIFIED))
            ) {
                $importName = ($groupPrefix !== null) ? ($groupPrefix . '\\' . $next[1]) : $next[1];

                continue;
            }

            if (($next[0] === \T_AS) && ($importName !== null)) {
                for ($aliasAhead = $ahead + 1; $aliasAhead < $count; ++$aliasAhead) {
                    $aliasToken = $tokens[$aliasAhead];

                    if (is_array($aliasToken) && ($aliasToken[0] === \T_WHITESPACE)) {
                        continue;
                    }

                    if (is_array($aliasToken) && ($aliasToken[0] === \T_STRING)
                        && (($importName === 'TestRule') || str_ends_with($importName, '\TestRule'))
                    ) {
                        $aliases[] = $aliasToken[1];
                    }

                    break;
                }

                continue;
            }
        }
    }

    return $aliases;
};

$testRuleAliases = $resolveTestRuleAliases($ruleTokens);

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
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens       The file's
 *                                                                          full token
 *                                                                          stream.
 * @param int                                                 $functionIndex The index
 *                                                                          of the
 *                                                                          `function`
 *                                                                          token.
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
        // T_ATTRIBUTE is the opening `#[` alone; the names follow as ordinary tokens
        // until the bracket closes. Only the last `\`-separated segment is compared, so
        // the qualified and imported spellings answer the same.
        $depth      = 1;
        $parens     = 0;
        $expectName = true;

        for ($ahead = $index + 1; ($ahead < $ruleCount) && ($depth > 0); ++$ahead) {
            $inner = $ruleTokens[$ahead];

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

            // T_NAME_RELATIVE (`namespace\TestRule`) is deliberately absent. It
            // denotes TestRule relative to the CURRENT namespace, i.e. a class in the
            // consumer's own test namespace — not phpat's attribute — so matching it
            // would be a false positive rather than the missing spelling it looks like.
            $isName = ($inner[0] === \T_STRING)
                || ($inner[0] === \T_NAME_QUALIFIED)
                || ($inner[0] === \T_NAME_FULLY_QUALIFIED);

            if ($expectName && $isName) {
                $segments = explode('\\', $inner[1]);

                // Against $testRuleAliases (built above), not the literal 'TestRule' —
                // an aliased import (`use PHPat\Test\Attributes\TestRule as X;`) makes
                // `#[X]` the real attribute, and `end($segments)` for that spelling is
                // the alias, not 'TestRule'.
                if (in_array(end($segments), $testRuleAliases, true)) {
                    $sawTestRule = true;
                    ++$attributeSum;
                }
            }

            $expectName = false;
        }

        // Resume AFTER the closing `]`. Without this the outer loop re-walks the
        // group's own tokens and re-classifies them — a `Foo::class` argument reads as
        // T_CLASS and hits the declaration barrier below, clearing the flag the
        // `#[TestRule]` beside it just set. Measured: `#[TestRule]` followed by
        // `#[CoversClass(Node::class)]` reported `no #[TestRule] methods found` for a
        // live rule.
        $index = $ahead - 1;

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
    $name = null;

    for ($ahead = $index + 1; $ahead < $ruleCount; ++$ahead) {
        $next = $ruleTokens[$ahead];

        if (is_array($next)
            && (($next[0] === \T_WHITESPACE) || ($next[0] === \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG))
        ) {
            continue;
        }

        if (is_array($next) && ($next[0] === \T_STRING)) {
            $name = $next[1];
        }

        break;
    }

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

    for ($ahead = $index + 1; $ahead < $ruleCount; ++$ahead) {
        $inner = $ruleTokens[$ahead];
        $text  = is_array($inner) ? $inner[1] : $inner;

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

    // The subject is the FIRST Selector::…(…) inside the FIRST ->classes(…) after
    // PHPat::rule(). Slice up to the first ->should/->shouldNot within the method.
    //
    // Known, deliberately undefended gap in the same family as $topDepth's two above:
    // a #[TestRule]-attributed method NESTED inside another rule's own body (via a
    // closure or anonymous class) is correctly excluded from $ruleMethods and from
    // $attributeResolvedCount, but its text is still part of $methodBody for the
    // ENCLOSING rule — because the body-extraction loop bounds by brace depth alone,
    // with no awareness of a nested function's own scope. If the nested rule's own
    // ->classes(...)->should(Not)? call appears earlier in the text than the enclosing
    // rule's, this scan misattributes the nested rule's subject to the enclosing rule's
    // name in the printed violation. This is a NAMING defect only, not a fail-open one:
    // the misattachment check above already reds the run for the nested attribute
    // regardless, and nesting one rule inside another's own body is the same class of
    // pathological, PSR-1-adjacent shape as the other two documented gaps — no real
    // ArchitectureTest does this. Pinned (not merely documented) by the
    // nested-testrule-not-counted-as-resolved fixture's must-carry check.
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
