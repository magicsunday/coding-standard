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

// $safeReportValue — shared with bin/check-consumer-config.php. A consumer's phpat
// subject expression reaches this gate's report, on the same trust boundary.
require_once __DIR__ . '/support/safe-report-value.php';

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
 * Strips comments and doc-comments from PHP source so the text-based scans below never
 * treat a commented-out `#[TestRule]` example (the canonical ArchitectureTest template
 * ships one) or a `class` inside a block comment as real. Whitespace is preserved so
 * line-anchored patterns still behave.
 *
 * @param string $code The raw PHP source.
 *
 * @return string The source with every comment token blanked out.
 */
$stripComments = static function (string $code): string {
    $result = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
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

$source = $stripComments((string) file_get_contents($architectureTest));

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
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $code = $stripComments((string) file_get_contents($file->getPathname()));

    $namespace = '';

    if (preg_match('/^namespace\s+([^;]+);/m', $code, $nm) === 1) {
        $namespace = trim($nm[1]);
    }

    // Any run of class modifiers may precede the keyword (final, abstract, readonly,
    // in any order) — `final readonly class` is the standard value-object form, so the
    // modifier run must be matched loosely or such a class is missed and its rule is
    // wrongly reported vacuous.
    if (preg_match('/^((?:(?:final|abstract|readonly)\s+)*)(class|trait|interface|enum)\s+(\w+)/m', $code, $tm) === 1) {
        $modifiers = $tm[1];
        $kind      = $tm[2];
        $name      = $tm[3];
        $fqcn      = ($namespace !== '') ? $namespace . '\\' . $name : $name;

        $isAbstractClass = ($kind === 'class') && str_contains($modifiers, 'abstract');

        $inventory[$fqcn] = $isAbstractClass ? 'abstract-class' : $kind;
    }
}

/**
 * Reports whether at least one concrete or abstract CLASS (not a trait, interface or
 * enum) lives in the given namespace or a sub-namespace of it — the condition phpat's
 * `InClassNode` needs for an `inNamespace` subject to match anything.
 *
 * @param array<string, string> $inventory
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
preg_match_all('/#\[TestRule\][^;{]*?public\s+function\s+(\w+)\s*\([^)]*\)\s*:\s*Rule\s*\{/', $source, $methodHeads, \PREG_OFFSET_CAPTURE);

if (count($methodHeads[0]) === 0) {
    $violations[] = 'no #[TestRule] methods found — the ArchitectureTest defines no rules.';
}

// The offset of every method declaration (rule or plain helper), so each rule's subject
// search is bounded by the NEXT method — not the next #[TestRule] — and a malformed rule
// missing a `->should(...)` cannot scan through a following helper method and adopt its
// `->classes(Selector::...)` as the subject (which would break the fail-closed contract).
preg_match_all('/\bfunction\s+\w+\s*\(/', $source, $methodDecls, \PREG_OFFSET_CAPTURE);
$methodOffsets = array_column($methodDecls[0], 1);

foreach ($methodHeads[1] as $index => $nameMatch) {
    $ruleName  = $nameMatch[0];
    $bodyStart = $methodHeads[0][$index][1] + strlen($methodHeads[0][$index][0]);

    // Bound the search to THIS method: from its head up to the next method declaration
    // of any kind (or EOF for the last one).
    $bodyEnd = strlen($source);

    foreach ($methodOffsets as $offset) {
        if ($offset >= $bodyStart) {
            $bodyEnd = $offset;

            break;
        }
    }
    $methodBody  = substr($source, $bodyStart, $bodyEnd - $bodyStart);

    // The subject is the FIRST Selector::…(…) inside the FIRST ->classes(…) after
    // PHPat::rule(). Slice up to the first ->should/->shouldNot within the method.
    $stop = preg_match('/->should(?:Not)?\s*\(/', $methodBody, $sm, \PREG_OFFSET_CAPTURE) === 1 ? $sm[0][1] : strlen($methodBody);
    $head = substr($methodBody, 0, $stop);

    if (preg_match('/->classes\s*\(\s*Selector::(\w+)\s*\(([^)]*)\)/', $head, $subj) !== 1) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', $ruleName);

        continue;
    }

    $selector = $subj[1];
    $argument = trim($subj[2]);

    if ($selector === 'isAbstract') {
        // Conditional naming guard — legitimately empty until an abstract class exists.
        fwrite(\STDOUT, sprintf("  %s: isAbstract() subject — conditional guard, liveness not checked.\n", $ruleName));

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
        $violations[] = sprintf('%s: could not resolve the %s() argument `%s` (fail-closed).', $ruleName, $selector, safeReportValue($argument));

        continue;
    }

    if ($selector === 'inNamespace') {
        if (!$namespaceHasClass($inventory, $resolved)) {
            $violations[] = sprintf('%s: subject inNamespace(%s) matches no class — a vacuous rule (a trait-only or empty namespace enforces nothing).', $ruleName, safeReportValue($resolved));
        }

        continue;
    }

    if ($selector === 'classname') {
        $kind = $inventory[$resolved] ?? null;

        if (($kind !== 'class') && ($kind !== 'abstract-class')) {
            $violations[] = sprintf('%s: subject classname(%s) matches no class — renamed, moved or mistyped, so the rule enforces nothing.', $ruleName, safeReportValue($resolved));
        }

        continue;
    }

    $violations[] = sprintf('%s: unhandled subject selector Selector::%s() (fail-closed).', $ruleName, $selector);
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
