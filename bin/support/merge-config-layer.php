<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines mergeConfigLayer() for the PHP gates that fold an `extends` chain
 * into an effective config (GH-36). Extracted out of bin/check-consumer-config.php
 * (GH-116) so a test can decode a base + overlay pair and assert on the merged
 * structure directly, rather than only through the gate's accept/reject CLI
 * interface — re-derive which gate(s) require this rather than trusting a list
 * here: `grep -rln "^require_once .*merge-config-layer" bin`. Node cannot require
 * a PHP file, and has its own ES module sibling instead
 * (bin/support/merge-config-layer.mjs, re-derive its importers with
 * `grep -rl "from '\./support/merge-config-layer.mjs'" bin`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Deep-merges two decoded JSON/JSONC documents the way a real tool folds an
 * `extends` chain: nested objects merge key-by-key, `overrides` arrays
 * CONCATENATE rather than replace, and every other value in $overlay wins over
 * $base outright — matching a later `extends` entry (or the document itself)
 * overriding an earlier one.
 *
 * Verified against Biome 2.5.5, not assumed: a document's own `overrides` entry
 * and a local `extends` target's `overrides` entry both applied to their
 * respective file globs in the same run, so `overrides` accumulates rather than
 * being replaced — unlike every other array-valued key. `files.includes`
 * measured the opposite way: a later layer's list REPLACES an earlier one
 * wholesale, so the general rule stays "overlay wins outright" and `overrides`
 * is the one named exception.
 *
 * **An empty JSON object is indistinguishable from an empty JSON array once
 * decoded** — `json_decode('{}', true)` and `json_decode('[]', true)` are both
 * `[]`, and `array_is_list([])` is `true` (verified: PHP has no third state for
 * "empty associative array"). Naively requiring `!array_is_list($value)` on
 * BOTH sides to recurse therefore mis-classifies an empty overlay OBJECT
 * (`{"linter": {}}`) as a list and falls through to whole-value replacement —
 * wiping every key the layers below it set, rather than leaving them untouched
 * as a real merge-patch would. Verified against Biome 2.5.5: with a chain that
 * disables the linter and then re-enables it via a later `extends` entry, an
 * empty `"linter": {}` on the document itself still reports the lint findings
 * the re-enable restored — an empty object contributes nothing, it does not
 * clear what came before. The list/object decision below therefore asks
 * "is either the NON-EMPTY side a list" rather than "is the overlay a list":
 * an empty side carries no signal either way, so it defers to whichever side
 * actually has content, and when both sides are empty the two candidate
 * results (recurse vs. replace) are identical (`[]`) and the ambiguity is
 * moot. `overrides` needs no such guard — concatenating an empty list with a
 * non-empty one already produces the non-empty one on either side, so the
 * ambiguity never changes its result.
 *
 * The choice above (defer to the non-empty side) has one residual asymmetry
 * with the JS mirror, found and verified during GH-36's own audit round: a
 * genuinely EMPTY JSON ARRAY on a key whose valid schema type is always an
 * object — `"linter": []`, never a real Biome/tsc shape — recurses here (base
 * preserved) rather than replacing (JS's `Array.isArray([])` is unconditionally
 * true, so it replaces). Not fixed: Biome itself hard-rejects this shape before
 * either gate's verdict would matter — verified against 2.5.5, `linter has an
 * incorrect type, expected an object, but received an array` kills the whole
 * config load — so a config that reaches this divergence never successfully
 * loads for a real consumer in the first place. Resolving it would mean
 * preserving the object/array distinction through every decode in the calling
 * gate — PHP's `json_decode(..., true)` collapses both shapes to the SAME
 * `array` type (only an EMPTY object/array is literally the identical `[]`
 * value; a non-empty one keeps distinguishable content, but the type itself
 * still carries no object/array tag either way) — not a local fix to this
 * function.
 *
 * **A second, distinct residual asymmetry** (GH-138): a JSON OBJECT whose keys
 * happen to be the sequential strings `"0"`, `"1"`, `"2"`, … decodes in PHP to
 * an array indexed by the equivalent INTEGERS — the same long-standing PHP
 * coercion the `extends`-array note in `bin/check-consumer-config.php`
 * documents — and `array_is_list()` then reports `true` for it, so this
 * function replaces such an object wholesale instead of recursing into it.
 * Unlike that `extends` case, neither tsc nor Biome schema-rejects this shape
 * outright (a `compilerOptions.paths` object's keys are unconstrained glob
 * patterns to tsc), so it is not provably unreachable the same way. Left
 * unfixed anyway: no key this gate enforces a policy verdict on (`strict`,
 * `noUncheckedIndexedAccess`, `skipLibCheck`, …) is itself an object of this
 * shape, so a divergent merge here only changes the internal
 * effective-document preview, never a PASS/FAIL verdict — and no real-world
 * `paths`-like mapping is ever keyed by bare sequential digits rather than
 * glob-like patterns. `tests/MergeConfigLayerTest.php` pins the current
 * (accepted) wholesale-replace behaviour for this shape.
 *
 * @param array<array-key, mixed> $base    The lower-precedence layer.
 * @param array<array-key, mixed> $overlay The higher-precedence layer.
 *
 * @return array<array-key, mixed>
 */
function mergeConfigLayer(array $base, array $overlay): array
{
    foreach ($overlay as $key => $value) {
        if (
            ($key === 'overrides')
            && is_array($value) && array_is_list($value)
            && is_array($base[$key] ?? null) && array_is_list($base[$key])
        ) {
            $base[$key] = [...$base[$key], ...$value];

            continue;
        }

        if (is_array($value) && is_array($base[$key] ?? null)) {
            $baseIsList    = ($base[$key] !== []) && array_is_list($base[$key]);
            $overlayIsList = ($value !== []) && array_is_list($value);

            if (!$baseIsList && !$overlayIsList) {
                $base[$key] = mergeConfigLayer($base[$key], $value);

                continue;
            }
        }

        $base[$key] = $value;
    }

    return $base;
}
