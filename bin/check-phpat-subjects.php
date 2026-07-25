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
 * method's subject, collects the POSITIVE selectors it resolves to, and asserts at
 * least one of them matches a real class in `src/`:
 *   - `Selector::inNamespace(NS)`  → a class phpat honours (a concrete or abstract class,
 *                                     or an enum) exists in NS. Traits and interfaces do
 *                                     NOT count: the trait no-op is the manifested bug, so
 *                                     a trait-only namespace fails here;
 *   - `Selector::classname(FQCN)`  → that class/enum exists (a renamed or mistyped target
 *                                     fails here);
 *   - `Selector::AnyOf(…)` (and a top-level varargs list) → a union: live if any positive is;
 *   - `Selector::AllOf(…)`         → an intersection of one positive namespace/class with
 *                                     `Selector::Not(…)` exclusions. Liveness requires a class
 *                                     in the positive that no direct exclusion removes (a
 *                                     namespace exclusion removes its subtree, a classname
 *                                     exclusion removes one class), so a positive whose every
 *                                     class is excluded is correctly vacuous; two positives are
 *                                     an intersection the checker cannot model and fail closed.
 *                                     A `...array_map(Selector::Not(…), …)` exclusion splat is
 *                                     narrowing-only (it excludes strict sub-namespaces);
 *   - `...$this->helper()` splat   → the helper is inlined and its
 *                                     `array_map(fn => Selector::inNamespace(ROOT . '\Sub\' . $x), self::CONST)`
 *                                     shape expanded so each ROOT\Sub namespace is checked;
 *                                     a `...array_map(Selector::Not(…), …)` exclusion splat
 *                                     contributes no positive;
 *   - `Selector::isAbstract()`     → as a BARE top-level subject member it is NOT
 *                                     liveness-checked: it is a conditional naming guard
 *                                     that legitimately matches nothing until an abstract
 *                                     class is added, so an empty match is correct, not a
 *                                     bug. (Inside an `AllOf`, see the limits below.)
 *
 * A subject with no resolvable positive selector, or a shape the checker does not model,
 * fails CLOSED rather than being assumed live.
 *
 * It is a STATIC check — it does not run PHPStan — so it verifies the one invariant the
 * vacuous-rule trap violates (the subject is non-empty), not the full rule mechanics.
 * Direct `Selector::Not(...)` exclusions whose target is a `self::NAMESPACE_ROOT`-based or
 * bare-literal namespace/class ARE modelled precisely (inventory-aware). Two limits follow:
 *   - a `...array_map(Selector::Not(...), <runtime source>)` exclusion splat cannot be
 *     resolved without executing the source, so it is treated as narrowing-only; a
 *     degenerate AllOf whose positive namespace holds classes ONLY in such a splat-excluded
 *     sub-namespace is not flagged;
 *   - an AllOf whose exclusion target is a `<Class>::class` constant or phpat's two-argument
 *     regex `classname('#...#', true)` selector is not modelled and the AllOf fails closed
 *     (safe, never a false green) — supporting those idioms is a tracked follow-up.
 * Anything else inside an AllOf (a nested AllOf/AnyOf, a positive splat, a flag selector
 * such as `isAbstract()`/`isFinal()`/`isEnum()`/`isTrait()`) fails closed. Likewise a
 * top-level subject that MIXES a splat with a sibling positive
 * (`...$this->helper(), Selector::classname(X)`) is not split and fails closed. All three
 * are fail-closed-safe (never a false green); no consumer wires these shapes today, so
 * modelling them is deferred rather than growing the guard's surface.
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
 * The inventory kinds phpat's `InClassNode` actually fires for as a subject: concrete
 * and abstract classes, and enums. phpat's rules are `Rule<InClassNode>` and enums are
 * class-like nodes it visits (verified with a break-probe: a forbidden dependency
 * injected into an enum triggers the rule), so an enum-only namespace is LIVE, not
 * vacuous. Traits stay out — their `InClassNode` no-op is the original manifested bug —
 * and interfaces stay out pending a verified consumer that needs an interface subject.
 *
 * @var list<string>
 */
const PHPAT_LIVE_KINDS = ['class', 'abstract-class', 'enum'];

/**
 * Returns the substring inside the balanced bracket pair whose opening bracket sits at
 * $open in $s, or null when the brackets never balance. Handles both `()` and `{}` so the
 * same routine slices a `->classes(...)` argument list and a helper-method body.
 *
 * @param int    $open    Offset of the opening bracket.
 * @param string $openCh  The opening bracket character.
 * @param string $closeCh The matching closing bracket character.
 */
function phpatBalanced(string $s, int $open, string $openCh = '(', string $closeCh = ')'): ?string
{
    $depth = 0;
    $len   = strlen($s);

    for ($i = $open; $i < $len; ++$i) {
        if ($s[$i] === $openCh) {
            ++$depth;
        } elseif ($s[$i] === $closeCh) {
            --$depth;

            if ($depth === 0) {
                return substr($s, $open + 1, $i - $open - 1);
            }
        }
    }

    return null;
}

/**
 * Splits a selector argument list at top-level commas only — a comma nested inside `()`
 * or `[]` is part of an argument, not a separator. A trailing empty segment (from a
 * dangling comma before the closing bracket) is dropped.
 *
 * @return list<string>
 */
function phpatSplitArgs(string $s): array
{
    $args  = [];
    $depth = 0;
    $buf   = '';
    $len   = strlen($s);

    for ($i = 0; $i < $len; ++$i) {
        $ch = $s[$i];

        if (($ch === '(') || ($ch === '[')) {
            ++$depth;
        } elseif (($ch === ')') || ($ch === ']')) {
            --$depth;
        }

        if (($ch === ',') && ($depth === 0)) {
            if (trim($buf) !== '') {
                $args[] = trim($buf);
            }

            $buf = '';

            continue;
        }

        $buf .= $ch;
    }

    if (trim($buf) !== '') {
        $args[] = trim($buf);
    }

    return $args;
}

/**
 * Normalises the content of a matched single-quoted namespace literal to the
 * single-backslash form the `namespace` declarations in the class inventory use: a
 * literal may be written with single or escaped (`\\`) backslashes.
 */
function phpatUnescape(string $literal): string
{
    return str_replace('\\\\', '\\', $literal);
}

/**
 * Resolves a selector ARGUMENT (the text inside `inNamespace()` / `classname()`) to an
 * FQCN, or null when it is not one of the two modelled shapes: `self::NAMESPACE_ROOT`
 * optionally concatenated with a single quoted `'\Suffix'` literal, or a bare quoted
 * literal. A composed expression the checker does not model (a second constant, a
 * variable) returns null so the caller fails closed instead of testing the wrong target.
 */
function phpatResolveArg(string $argument, ?string $namespaceRoot): ?string
{
    $argument = trim($argument);

    if (($namespaceRoot !== null) && (preg_match('/^self::NAMESPACE_ROOT(?:\s*\.\s*\'([^\']*)\')?$/', $argument, $m) === 1)) {
        return $namespaceRoot . (isset($m[1]) ? phpatUnescape($m[1]) : '');
    }

    if (preg_match('/^\'([^\']+)\'$/', $argument, $m) === 1) {
        return phpatUnescape($m[1]);
    }

    return null;
}

/**
 * Resolves a `private const array NAME = ['A', 'B', …];` declaration in the source to its
 * list of string elements, or null when no such constant is declared.
 *
 * @return list<string>|null
 */
function phpatResolveConstArray(string $constName, string $source): ?array
{
    if (preg_match('/const\s+(?:array\s+)?' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\]\s*;/s', $source, $m) !== 1) {
        return null;
    }

    preg_match_all('/\'([^\']*)\'/', $m[1], $em);

    return array_map(static fn (string $e): string => phpatUnescape($e), $em[1]);
}

/**
 * Expands an `array_map(static fn (…) => Selector::inNamespace(<template>), self::CONST)`
 * expression into its list of positive selectors. The template is either a directly
 * resolvable argument (constant per element) or `self::NAMESPACE_ROOT . '\Suffix\' . $var`
 * driven by a class-constant string array, in which case each element is appended.
 *
 * @return array{selectors: list<array{type: string, resolved: string}>, error: string|null}
 */
function phpatExpandArrayMap(string $arrayMapExpr, ?string $namespaceRoot, string $source): array
{
    $fail = ['selectors' => [], 'error' => 'could not resolve the splat subject (fail-closed)'];
    $open = strpos($arrayMapExpr, '(');

    if ($open === false) {
        return $fail;
    }

    $inner = phpatBalanced($arrayMapExpr, $open);

    if ($inner === null) {
        return $fail;
    }

    $mapArgs   = phpatSplitArgs($inner);
    $callback  = $mapArgs[0] ?? '';
    $sourceArg = $mapArgs[1] ?? '';

    // A callback that wraps its selector in an AllOf/AnyOf composite is a shape this simple
    // first-selector extraction would misread, so fail closed rather than model it wrongly.
    if (preg_match('/Selector::(AllOf|AnyOf)\s*\(/', $callback) === 1) {
        return $fail;
    }

    if (preg_match('/Selector::(inNamespace|classname)\s*\(/', $callback, $sm, \PREG_OFFSET_CAPTURE) !== 1) {
        return $fail;
    }

    $type    = $sm[1][0];
    $argExpr = phpatBalanced($callback, $sm[0][1] + strlen($sm[0][0]) - 1);

    if ($argExpr === null) {
        return $fail;
    }

    $argExpr = trim($argExpr);

    // A per-element template `self::NAMESPACE_ROOT . '\Suffix\' . $var` — expand the
    // class-constant array named as array_map's second argument.
    if (($namespaceRoot !== null) && (preg_match('/^self::NAMESPACE_ROOT\s*\.\s*\'([^\']*)\'\s*\.\s*\$\w+$/', $argExpr, $tm) === 1)) {
        // The map source must be the class-constant array itself, ANCHORED — a transformed
        // source (`array_slice(self::SUBS, 1)`, a merge, a slice) would expand the wrong
        // element set, so fail closed rather than test a different array than the rule maps.
        if (preg_match('/^self::(\w+)$/', trim($sourceArg), $cm) !== 1) {
            return $fail;
        }

        $elements = phpatResolveConstArray($cm[1], $source);

        if ($elements === null) {
            return $fail;
        }

        $prefix    = $namespaceRoot . phpatUnescape($tm[1]);
        $selectors = [];

        foreach ($elements as $element) {
            $selectors[] = ['type' => $type, 'resolved' => $prefix . $element];
        }

        return ['selectors' => $selectors, 'error' => null];
    }

    // A constant template (no per-element variable) — one distinct positive.
    $resolved = phpatResolveArg($argExpr, $namespaceRoot);

    if ($resolved === null) {
        return $fail;
    }

    return ['selectors' => [['type' => $type, 'resolved' => $resolved]], 'error' => null];
}

/**
 * Inlines a `...$this->helper()` splat by locating the helper method and expanding the
 * `array_map(...)` it returns. A helper whose body does not match that modelled shape
 * fails closed.
 *
 * @return array{selectors: list<array{type: string, resolved: string}>, error: string|null}
 */
function phpatExpandSplatHelper(string $method, ?string $namespaceRoot, string $source): array
{
    $fail = ['selectors' => [], 'error' => sprintf('could not resolve the splat helper `%s()` (fail-closed)', $method)];

    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:[^{]*\{/', $source, $hm, \PREG_OFFSET_CAPTURE) !== 1) {
        return $fail;
    }

    $bodyOpen = $hm[0][1] + strlen($hm[0][0]) - 1;
    $body     = phpatBalanced($source, $bodyOpen, '{', '}');

    if (($body === null) || (($mapPos = strpos($body, 'array_map')) === false)) {
        return $fail;
    }

    $mapExpr = substr($body, $mapPos);

    // A helper mapping `Selector::Not(...)` over its source yields EXCLUSIONS, not positives —
    // exactly as the direct `...array_map(Selector::Not(...), …)` splat branch treats it. The
    // expander would otherwise promote the `inNamespace`/`classname` wrapped inside the `Not`
    // to a positive and report the subject LIVE (fail-open), so reject it here for parity.
    if (preg_match('/Selector::Not\s*\(/', $mapExpr) === 1) {
        return $fail;
    }

    $expansion = phpatExpandArrayMap($mapExpr, $namespaceRoot, $source);

    if ($expansion['error'] !== null) {
        return $fail;
    }

    return $expansion;
}

/**
 * Collects and merges the positive selectors of every argument in a disjunction list
 * (a top-level varargs list, or the members of an AllOf/AnyOf), short-circuiting on the
 * first argument that fails to resolve.
 *
 * @param list<string> $args
 *
 * @return array{selectors: list<array{type: string, resolved: string}>, error: string|null}
 */
function phpatCollectFrom(array $args, ?string $namespaceRoot, string $source): array
{
    $selectors = [];

    foreach ($args as $arg) {
        $sub = phpatCollectPositives($arg, $namespaceRoot, $source);

        if ($sub['error'] !== null) {
            return ['selectors' => [], 'error' => $sub['error']];
        }

        $selectors = array_merge($selectors, $sub['selectors']);
    }

    return ['selectors' => $selectors, 'error' => null];
}

/**
 * Collects the targets excluded by a direct `Selector::Not(Selector::inNamespace(Y))` or
 * `Selector::Not(Selector::classname(Y))` argument of an AllOf, keeping the selector kind
 * so a namespace exclusion and a class exclusion are matched differently. Any other direct
 * `Not(...)` shape (wrapping an AllOf/AnyOf, or an unresolvable target) fails closed rather
 * than silently dropping the exclusion. A `...array_map(Selector::Not(...), …)` exclusion
 * splat is NOT a direct Not and is treated as narrowing-only (see the AllOf branch).
 *
 * @param list<string> $args
 *
 * @return array{exclusions: list<array{type: string, target: string}>, error: string|null}
 */
function phpatAllOfExclusions(array $args, ?string $namespaceRoot): array
{
    $exclusions = [];
    $fail       = ['exclusions' => [], 'error' => 'cannot model a Not(...) exclusion (fail-closed)'];

    foreach ($args as $arg) {
        $arg = trim($arg);

        if (preg_match('/^Selector::Not\s*\(/', $arg) !== 1) {
            // Not a direct exclusion: a positive selector, or a `...array_map(Not(...), …)`
            // exclusion splat handled as narrowing-only elsewhere.
            continue;
        }

        $wrapped = phpatBalanced($arg, (int) strpos($arg, '('));

        if ($wrapped === null) {
            return $fail;
        }

        // The Not must DIRECTLY wrap an inNamespace/classname selector — a Not around an
        // AllOf/AnyOf or any other shape is an exclusion the checker cannot model, and an
        // unresolvable target must fail closed rather than being silently dropped (which
        // would ignore an exclusion that may empty the positive).
        $wrapped = trim($wrapped);

        if (preg_match('/^Selector::(inNamespace|classname)\s*\(/', $wrapped, $km) !== 1) {
            return $fail;
        }

        $target = phpatBalanced($wrapped, (int) strpos($wrapped, '('));

        if ($target === null) {
            return $fail;
        }

        $resolved = phpatResolveArg(trim($target), $namespaceRoot);

        if ($resolved === null) {
            return $fail;
        }

        $exclusions[] = ['type' => $km[1], 'target' => $resolved];
    }

    return ['exclusions' => $exclusions, 'error' => null];
}

/**
 * Reports whether a class FQCN is removed by one of the AllOf exclusions: a namespace
 * exclusion removes the class when it equals or is nested under that namespace; a
 * classname exclusion removes only that exact class.
 *
 * @param list<array{type: string, target: string}> $exclusions
 */
function phpatClassExcluded(string $fqcn, array $exclusions): bool
{
    foreach ($exclusions as $exclusion) {
        if ($exclusion['type'] === 'classname') {
            if ($fqcn === $exclusion['target']) {
                return true;
            }

            continue;
        }

        if (($fqcn === $exclusion['target']) || str_starts_with($fqcn, $exclusion['target'] . '\\')) {
            return true;
        }
    }

    return false;
}

/**
 * Reports whether a positive subject selector matches at least one class phpat honours
 * (see PHPAT_LIVE_KINDS) that survives the positive's AllOf exclusions. An `inNamespace`
 * positive needs a class in its namespace subtree; a `classname` positive needs that class
 * to exist; either way a class removed by an exclusion (see phpatClassExcluded) does not
 * count. This computes the actual subject membership, so an intersection whose positive is
 * entirely excluded is correctly seen as empty.
 *
 * @param array<string, string>                                             $inventory
 * @param array{type: string, resolved: string, exclusions?: list<array{type: string, target: string}>} $positive
 */
function phpatPositiveIsLive(array $inventory, array $positive): bool
{
    $exclusions = $positive['exclusions'] ?? [];

    foreach ($inventory as $fqcn => $kind) {
        if (!in_array($kind, \PHPAT_LIVE_KINDS, true)) {
            continue;
        }

        $matches = ($positive['type'] === 'inNamespace')
            ? (($fqcn === $positive['resolved']) || str_starts_with($fqcn, $positive['resolved'] . '\\'))
            : ($fqcn === $positive['resolved']);

        if ($matches && !phpatClassExcluded($fqcn, $exclusions)) {
            return true;
        }
    }

    return false;
}

/**
 * Walks a `->classes(...)` subject expression and collects the POSITIVE
 * inNamespace/classname selectors that determine whether the rule can match anything.
 * Recurses into `Selector::AllOf(...)` / `Selector::AnyOf(...)`, skips `Selector::Not(...)`
 * exclusions (and a `...array_map(Selector::Not(...), …)` exclusion splat), and inlines a
 * `...$this->helper()` positive splat.
 *
 * @return array{selectors: list<array{type: string, resolved: string}>, error: string|null, conditional: bool}
 */
function phpatCollectPositives(string $expr, ?string $namespaceRoot, string $source): array
{
    $expr = trim($expr);

    // A splat subject: `...$this->helper()` (positive) or `...array_map(...)` (which is an
    // exclusion set when its callback wraps Selector::Not, contributing no positive).
    if (preg_match('/^\.\.\.\s*(.+)$/s', $expr, $m) === 1) {
        $splat = trim($m[1]);

        if (preg_match('/^\$this->(\w+)\s*\(\s*\)$/', $splat, $mm) === 1) {
            return phpatExpandSplatHelper($mm[1], $namespaceRoot, $source) + ['conditional' => false];
        }

        if (preg_match('/^array_map\s*\(/', $splat) === 1) {
            if (preg_match('/Selector::Not\s*\(/', $splat) === 1) {
                return ['selectors' => [], 'error' => null, 'conditional' => false];
            }

            return phpatExpandArrayMap($splat, $namespaceRoot, $source) + ['conditional' => false];
        }

        return ['selectors' => [], 'error' => 'could not resolve the splat subject (fail-closed)', 'conditional' => false];
    }

    // A top-level varargs disjunction: `Selector::a(…), Selector::b(…)` — phpat ORs the
    // arguments, so the subject is live if any of them is.
    $args = phpatSplitArgs($expr);

    if (count($args) > 1) {
        return phpatCollectFrom($args, $namespaceRoot, $source) + ['conditional' => false];
    }

    if (preg_match('/^Selector::(\w+)\s*\(/', $expr, $m) !== 1) {
        return ['selectors' => [], 'error' => 'could not identify a subject selector', 'conditional' => false];
    }

    $selector = $m[1];
    $inner    = phpatBalanced($expr, (int) strpos($expr, '('));

    if ($inner === null) {
        return ['selectors' => [], 'error' => 'could not identify a subject selector', 'conditional' => false];
    }

    $inner = trim($inner);

    // isAbstract() is a conditional naming guard that legitimately matches nothing until
    // an abstract class is added — liveness is not checked for it.
    if ($selector === 'isAbstract') {
        return ['selectors' => [], 'error' => null, 'conditional' => true];
    }

    // Not() is an exclusion — it narrows a subject, it is never itself a positive.
    if ($selector === 'Not') {
        return ['selectors' => [], 'error' => null, 'conditional' => false];
    }

    // AnyOf is a union — live if any member is (the same rule as a top-level disjunction).
    if ($selector === 'AnyOf') {
        return phpatCollectFrom(phpatSplitArgs($inner), $namespaceRoot, $source) + ['conditional' => false];
    }

    // AllOf is an intersection the checker models ONLY in its one realistic shape: a single
    // positive namespace/class narrowed by direct Selector::Not(...) exclusions and/or a
    // `...array_map(Selector::Not(...), …)` exclusion splat. To keep that model sound against
    // arbitrary nesting, every AllOf argument must be one of exactly those three kinds — any
    // other member (a nested AllOf/AnyOf, a positive splat, isAbstract, a bare variable) is an
    // intersection term the checker cannot reduce, so the whole AllOf fails closed rather than
    // let a term it silently ignores hide a vacuous rule.
    if ($selector === 'AllOf') {
        $allOfArgs = phpatSplitArgs($inner);
        $positives = [];

        foreach ($allOfArgs as $arg) {
            $arg = trim($arg);

            // An exclusion: a direct Not(...), or a ...array_map(Selector::Not(...), …) splat.
            // Both contribute no positive; they are resolved by phpatAllOfExclusions below (a
            // runtime-source splat stays narrowing-only, the one documented limitation). The
            // splat's callback must be a DIRECT Not — a callback that wraps its Not in an
            // AllOf/AnyOf composite is a term this simple classification would misread, so it
            // is NOT treated as an exclusion and falls through to the fail-closed branch.
            $isExclusionSplat = (preg_match('/^\.\.\.\s*array_map\s*\(/', $arg) === 1)
                && (preg_match('/Selector::Not\s*\(/', $arg) === 1)
                && (preg_match('/Selector::(AllOf|AnyOf)\s*\(/', $arg) !== 1);

            if ((preg_match('/^Selector::Not\s*\(/', $arg) === 1) || $isExclusionSplat) {
                continue;
            }

            // A positive inNamespace/classname — collect it.
            if (preg_match('/^Selector::(inNamespace|classname)\s*\(/', $arg) === 1) {
                $sub = phpatCollectPositives($arg, $namespaceRoot, $source);

                if ($sub['error'] !== null) {
                    return $sub + ['conditional' => false];
                }

                $positives = array_merge($positives, $sub['selectors']);

                continue;
            }

            return ['selectors' => [], 'error' => 'cannot model an AllOf argument (fail-closed)', 'conditional' => false];
        }

        if (count($positives) > 1) {
            return ['selectors' => [], 'error' => 'cannot model an AllOf intersection of multiple positive selectors (fail-closed)', 'conditional' => false];
        }

        // Carry the direct Not(...) exclusions on the surviving positive so the liveness check
        // requires a class the exclusions do not remove — an intersection whose positive is
        // entirely excluded is then correctly seen as empty. A `...array_map(Selector::Not(...),
        // <source>)` splat is NOT resolved into exclusions: its <source> is typically a runtime
        // expression (`$this->dtoSelectors()`) a static checker cannot evaluate, and the
        // realistic rule always keeps a class outside the excluded sub-namespaces, so it is
        // narrowing-only. The residual gap — a degenerate positive whose every class sits in a
        // splat-excluded sub-namespace — is accepted as a documented limit.
        $exclusionResult = phpatAllOfExclusions($allOfArgs, $namespaceRoot);

        if ($exclusionResult['error'] !== null) {
            return ['selectors' => [], 'error' => $exclusionResult['error'], 'conditional' => false];
        }

        $exclusions = $exclusionResult['exclusions'];
        $selectors  = array_map(
            static fn (array $positive): array => $positive + ['exclusions' => $exclusions],
            $positives,
        );

        return ['selectors' => $selectors, 'error' => null, 'conditional' => false];
    }

    if (($selector === 'inNamespace') || ($selector === 'classname')) {
        $resolved = phpatResolveArg($inner, $namespaceRoot);

        if ($resolved === null) {
            return ['selectors' => [], 'error' => sprintf('could not resolve the %s() argument `%s` (fail-closed)', $selector, $inner), 'conditional' => false];
        }

        return ['selectors' => [['type' => $selector, 'resolved' => $resolved]], 'error' => null, 'conditional' => false];
    }

    return ['selectors' => [], 'error' => sprintf('unhandled subject selector Selector::%s() (fail-closed)', $selector), 'conditional' => false];
}

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

    // The subject is the argument to the FIRST ->classes(…) after PHPat::rule(). Slice up
    // to the first ->should/->shouldNot within the method, then balance-extract the whole
    // argument so a composite (AllOf/AnyOf), a varargs disjunction or a `...$this->helper()`
    // splat is captured in full — not just its leading token.
    $stop = preg_match('/->should(?:Not)?\s*\(/', $methodBody, $sm, \PREG_OFFSET_CAPTURE) === 1 ? $sm[0][1] : strlen($methodBody);
    $head = substr($methodBody, 0, $stop);

    // `->classes` may carry whitespace before its `(` (`->classes (…)` is valid PHP), so
    // locate the call by pattern rather than the literal `->classes(`.
    if (preg_match('/->classes\s*\(/', $head, $cm, \PREG_OFFSET_CAPTURE) !== 1) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', $ruleName);

        continue;
    }

    $classesArg = phpatBalanced($head, $cm[0][1] + strlen($cm[0][0]) - 1);

    if (($classesArg === null) || (trim($classesArg) === '')) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', $ruleName);

        continue;
    }

    $collected = phpatCollectPositives($classesArg, $namespaceRoot, $source);

    if ($collected['conditional']) {
        // Conditional naming guard (isAbstract) — legitimately empty until an abstract
        // class exists.
        fwrite(\STDOUT, sprintf("  %s: isAbstract() subject — conditional guard, liveness not checked.\n", $ruleName));

        continue;
    }

    if ($collected['error'] !== null) {
        $violations[] = sprintf('%s: %s.', $ruleName, $collected['error']);

        continue;
    }

    $positives = $collected['selectors'];

    if (count($positives) === 0) {
        $violations[] = sprintf('%s: could not identify a subject selector (fail-closed).', $ruleName);

        continue;
    }

    // The subject is LIVE if at least one positive selector matches a class phpat honours
    // that survives its exclusions (a disjunction needs one match; an AllOf's single
    // positive must have a class no Not() exclusion removes).
    $live = false;

    foreach ($positives as $positive) {
        if (phpatPositiveIsLive($inventory, $positive)) {
            $live = true;

            break;
        }
    }

    if ($live) {
        continue;
    }

    if (count($positives) === 1) {
        $positive = $positives[0];

        if (($positive['exclusions'] ?? []) !== []) {
            $violations[] = sprintf('%s: the AllOf positive %s(%s) matches no class outside its Not(...) exclusions — a vacuous rule (fail-closed).', $ruleName, $positive['type'], $positive['resolved']);

            continue;
        }

        $violations[] = ($positive['type'] === 'inNamespace')
            ? sprintf('%s: subject inNamespace(%s) matches no class — a vacuous rule (a trait-only or empty namespace enforces nothing).', $ruleName, $positive['resolved'])
            : sprintf('%s: subject classname(%s) matches no class — renamed, moved or mistyped, so the rule enforces nothing.', $ruleName, $positive['resolved']);

        continue;
    }

    $rendered = implode(', ', array_map(static fn (array $p): string => sprintf('%s(%s)', $p['type'], $p['resolved']), $positives));
    $violations[] = sprintf('%s: subject matches no class in any of [%s] — a vacuous rule.', $ruleName, $rendered);
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
