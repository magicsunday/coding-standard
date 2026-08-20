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
 * This checker parses a consumer's `ArchitectureTest`, extracts each `#[TestRule]`
 * method's subject selector, and asserts the subject matches at least one real class
 * in `src/`:
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
 * It fails CLOSED: every `#[TestRule]` method must yield a classifiable subject, or the
 * run reds.
 *
 * Usage (from a consumer repo root, wired as a `ci:test:php:phpat-subjects` script):
 *
 *     php .build/vendor/magicsunday/coding-standard/bin/check-phpat-subjects.php .
 *
 * Exit 0 = every liveness-checked subject matches a class; 1 = a vacuous or
 * unparseable subject; 2 = bad arguments or no ArchitectureTest found.
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

// --- Extract each #[TestRule] method's subject selector ---

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
// whose LAST name segment is `TestRule`, it takes the next `function`, its name, and
// the body between the matching braces. Brace counting over tokens is what bounds the
// body — a `{` inside a string or a heredoc is one token, not a delimiter — so a
// malformed rule cannot run past its own method and adopt a following helper's
// selector.
$ruleMethods  = [];
$ruleTokens   = token_get_all($source);
$ruleCount    = count($ruleTokens);
$sawTestRule  = false;
$attributeSum = 0;

for ($index = 0; $index < $ruleCount; ++$index) {
    $token = $ruleTokens[$index];

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

                if (end($segments) === 'TestRule') {
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

    $name = null;

    for ($ahead = $index + 1; $ahead < $ruleCount; ++$ahead) {
        $next = $ruleTokens[$ahead];

        if (is_array($next) && ($next[0] === \T_WHITESPACE)) {
            continue;
        }

        if (is_array($next) && ($next[0] === \T_STRING)) {
            $name = $next[1];
        }

        break;
    }

    if (($name === null) || !$sawTestRule) {
        $sawTestRule = false;

        continue;
    }

    $sawTestRule = false;

    // The body, by brace depth over DELIMITER tokens only.
    //
    // A real delimiter is always a single-character CHAR token. Reading `$inner[1]` of
    // an array token as well was wrong in both directions, measured on the shipped
    // binary: `"$a{"` lexes the brace as T_ENCAPSED_AND_WHITESPACE whose text is
    // exactly `{`, so one added character inside a string made a vacuous rule's body
    // run past its own method and adopt the following helper's live subject — the gate
    // printed OK. The mirror, `"a $what}"`, cut a correct body short and reported a
    // live rule as unparseable.
    //
    // The two interpolation openers are the exception and must still count, because
    // their CLOSING brace is an ordinary CHAR token: `{$a}` opens with T_CURLY_OPEN
    // and `${a}` with T_DOLLAR_OPEN_CURLY_BRACES, both carrying text that is not a
    // bare `{`. Counting only CHAR tokens without them leaves the `}` decrementing
    // against nothing.
    //
    // An abstract or interface method ends on a CHAR `;` before any `{` and carries no
    // subject to read.
    $body  = '';
    $depth = 0;

    for ($ahead = $index + 1; $ahead < $ruleCount; ++$ahead) {
        $inner = $ruleTokens[$ahead];
        $text  = is_array($inner) ? $inner[1] : $inner;

        if (is_array($inner)) {
            if (($inner[0] === \T_CURLY_OPEN) || ($inner[0] === \T_DOLLAR_OPEN_CURLY_BRACES)) {
                ++$depth;
            }
        } elseif (($depth === 0) && ($inner === ';')) {
            break;
        } elseif ($inner === '{') {
            ++$depth;

            if ($depth === 1) {
                continue;
            }
        } elseif ($inner === '}') {
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
    $violations[] = 'no #[TestRule] methods found — the ArchitectureTest defines no rules.';
}

// The emptiness check above asks whether the RECOGNISED set is empty, which is not
// the same question as whether every TestRule attribute was recognised. One written on
// a property or a class attaches to no method, so the walk cannot read a subject from
// it — and the count says so rather than passing over it. Only TestRule attributes are
// counted: totalling every attribute would red an ArchitectureTest carrying an ordinary
// `#[CoversNothing]` beside its rules.
if ($attributeSum > count($ruleMethods)) {
    $violations[] = sprintf(
        '%d #[TestRule] attribute(s) found but only %d resolved to a method — an attribute this gate cannot attach to a method is a rule it cannot check.',
        $attributeSum,
        count($ruleMethods)
    );
}

foreach ($ruleMethods as [$ruleName, $methodBody]) {

    // The subject is the FIRST Selector::…(…) inside the FIRST ->classes(…) after
    // PHPat::rule(). Slice up to the first ->should/->shouldNot within the method.
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

    if ($namespaceRoot !== null && preg_match('/^self::NAMESPACE_ROOT(?:\s*\.\s*\'([^\']*)\')?$/', $argument, $am) === 1) {
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
