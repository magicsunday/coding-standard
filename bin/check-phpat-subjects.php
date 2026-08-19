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

/**
 * A PHP identifier, as the language defines it: ASCII letters and underscore, plus
 * every byte from \x80 up, which is how PHP admits non-ASCII names without the
 * pattern needing `/u` (and `/u` would make it reject a file carrying invalid UTF-8
 * outright). Written once because it was spelled inline at each site, and the last
 * defect here was one class narrower than the other two.
 */
const PHP_IDENTIFIER = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

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
 * Strips comments and doc-comments from the ArchitectureTest source, so the text-based
 * rule scan below never treats a commented-out `#[TestRule]` example (the canonical
 * template ships one) as real. Whitespace is preserved so line-anchored patterns still
 * behave. The class inventory does not use this — it walks tokens, which need no
 * stripping.
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

// Capped at the read, the way bin/check-consumer-config.php caps its own. Both
// this file and every `src/*.php` below are pull-request content in the CONSUMER's
// CI, and an uncapped read of one ends in `Allowed memory size exhausted` — exit
// 255, no gate diagnostic. Reproduced with a 196 MB file at memory_limit=128M.
$sourceRaw = file_get_contents($architectureTest, false, null, 0, MAX_SOURCE_BYTES + 1);

if (($sourceRaw === false) || (strlen($sourceRaw) > MAX_SOURCE_BYTES)) {
    fwrite(\STDERR, sprintf(
        "check-phpat-subjects: %s is unreadable or larger than the %d bytes this gate reads.\n",
        safeReportValue($architectureTest),
        MAX_SOURCE_BYTES
    ));

    exit(1);
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
    $sourceFile = file_get_contents($file->getPathname(), false, null, 0, MAX_SOURCE_BYTES + 1);

    if (($sourceFile === false) || (strlen($sourceFile) > MAX_SOURCE_BYTES)) {
        $violations[] = sprintf(
            '%s is unreadable or larger than the %d bytes this gate reads, so its classes are not in the inventory.',
            safeReportValue($file->getPathname()),
            MAX_SOURCE_BYTES
        );

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
/** @var list<string> $violations */
$violations = [];

// Each rule method: `#[TestRule] … public function <name>(): Rule { … }`. Capture the
// name and the body up to the matching close so the subject can be read from it.
// `[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*`, not `\w+`. A PHP identifier may legally
// carry bytes above 0x7F — `public function prüfeSchichten(): Rule` is valid — and
// `\w` without `/u` stops at ASCII, so such a #[TestRule] method matched nothing and
// was skipped in silence. This file's own header says it fails CLOSED; a subject it
// never sees is the one way it did not. `/u` is not the fix here: it would make the
// pattern reject a source file carrying invalid UTF-8 outright.
preg_match_all('/#\[TestRule\][^;{]*?public\s+function\s+(' . PHP_IDENTIFIER . ')\s*\([^)]*\)\s*:\s*Rule\s*\{/', $source, $methodHeads, \PREG_OFFSET_CAPTURE);

if (count($methodHeads[0]) === 0) {
    $violations[] = 'no #[TestRule] methods found — the ArchitectureTest defines no rules.';
}

// The emptiness check above only fires when the RECOGNISED set is empty, which is
// not the same question as whether every rule was recognised. The head pattern
// spells the return type as the bare `Rule`, so a method returning `\PHPat\Test\Rule`
// or an aliased spelling is not seen — and with one conventional rule beside it the
// gate exits 0 having never looked at the other. That is the one way a gate whose
// header says it fails CLOSED does not.
//
// Counting the attribute is independent of every spelling of the method it sits on,
// so this closes the class rather than the two spellings already known (GH-50).
$attributeCount = preg_match_all('/#\[TestRule\]/', $source);

if (($attributeCount !== false) && ($attributeCount > count($methodHeads[0]))) {
    $violations[] = sprintf(
        '%d #[TestRule] attribute(s) found but only %d rule method(s) recognised — a rule this gate cannot parse is a rule it cannot check. The head it matches is `public function <name>(…): Rule {`.',
        $attributeCount,
        count($methodHeads[0])
    );
}

// The offset of every method declaration (rule or plain helper), so each rule's subject
// search is bounded by the NEXT method — not the next #[TestRule] — and a malformed rule
// missing a `->should(...)` cannot scan through a following helper method and adopt its
// `->classes(Selector::...)` as the subject (which would break the fail-closed contract).
preg_match_all('/\bfunction\s+' . PHP_IDENTIFIER . '\s*\(/', $source, $methodDecls, \PREG_OFFSET_CAPTURE);
$methodOffsets = array_column($methodDecls[0], 1);

// Both lists are in ascending source order and `$bodyStart` only moves forward, so
// the search for the next declaration resumes where the previous rule left off. It
// used to restart at index 0 per rule — quadratic by inspection, over an input this
// gate reads unbounded and that is pull-request content in the CONSUMER's CI.
//
// The cost is stated as a shape, not a number, because the number did not reproduce:
// a reviewer measured seconds at 32000 rules, and a 9.5 MB / 32000-rule fixture ran
// flat at ~47 ms here under php 8.5 either way. Whatever bounds it in practice is not
// this loop, so the cursor is kept for the complexity class rather than a speedup —
// re-derive before citing a figure:
//
//     time php bin/check-phpat-subjects.php <a repo with N generated #[TestRule] methods>
$methodCursor = 0;

foreach ($methodHeads[1] as $index => $nameMatch) {
    $ruleName  = $nameMatch[0];
    $bodyStart = $methodHeads[0][$index][1] + strlen($methodHeads[0][$index][0]);

    // Bound the search to THIS method: from its head up to the next method declaration
    // of any kind (or EOF for the last one).
    while (($methodCursor < count($methodOffsets)) && ($methodOffsets[$methodCursor] < $bodyStart)) {
        ++$methodCursor;
    }

    $bodyEnd    = $methodOffsets[$methodCursor] ?? strlen($source);
    $methodBody = substr($source, $bodyStart, $bodyEnd - $bodyStart);

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

    if ($selector === 'inNamespace') {
        if (!$namespaceHasClass($inventory, $resolved)) {
            $violations[] = sprintf('%s: subject inNamespace(%s) matches no class — a vacuous rule (a trait-only or empty namespace enforces nothing).', safeReportValue($ruleName), safeReportValue($resolved));
        }

        continue;
    }

    if ($selector === 'classname') {
        $kind = $inventory[$resolved] ?? null;

        if (($kind !== 'class') && ($kind !== 'abstract-class')) {
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

fwrite(\STDERR, sprintf("check-phpat-subjects: %d vacuous or unparseable rule subject(s):\n", count($violations)));

foreach ($violations as $violation) {
    fwrite(\STDERR, sprintf("  - %s\n", $violation));
}

fwrite(\STDERR, "\nA rule whose subject matches nothing passes green while enforcing nothing.\n");
exit(1);
