/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * The node counterpart of bin/support/merge-config-layer.php — see
 * mergeConfigLayer()'s own docblock below (and that PHP function's) for the
 * shared merge-shape rationale both languages need. The prototype-pollution
 * guard below is JS-only and has no PHP counterpart to point at — PHP arrays
 * have no prototype chain, so that PHP docblock never discusses it.
 * Required rather than duplicated inline for the same reason the PHP gate
 * requires its copy instead of retyping it: bin/check-js-config.mjs and any
 * later node-side gate share ONE definition. Extracted (GH-116) so a small
 * Node test can decode a base + overlay pair and assert on the merged
 * structure directly.
 */

/**
 * True for anything `json_decode(..., true)` would answer `is_array()` true
 * for: a JSON array AND a JSON object alike, since PHP's associative array
 * does not distinguish them. Every `is_array($x)` check ported from
 * bin/check-consumer-config.php needs this, not `Array.isArray` — a JS
 * `Array.isArray` is false for a plain object, which silently narrows a PHP
 * "either shape" check to "array shape only". Pair with `Object.values()`
 * (uniform over an array's elements and an object's values) wherever the PHP
 * side then iterates the checked value with `foreach`.
 *
 * @param {*} value
 *
 * @returns {boolean}
 */
export function isArrayLike(value) {
    return value !== null && typeof value === 'object';
}

/**
 * Deep-merges two decoded JSON/JSONC documents the way a real tool folds an
 * `extends` chain. Mirrors mergeConfigLayer() in
 * bin/support/merge-config-layer.php — see that function's docblock for the
 * Biome 2.5.5 measurements behind the `overrides`-concatenates /
 * everything-else-replaces split. PHP's docblock there also explains an
 * `array_is_list([])` ambiguity (an empty JSON object and an empty JSON array
 * both decode to the identical PHP `[]`) that needs a "check whichever side
 * is non-empty" guard — that ambiguity has no JS equivalent, since
 * `JSON.parse('{}')` and `JSON.parse('[]')` decode to distinguishable values
 * here (`Array.isArray` tells them apart), so the plain
 * `isArrayLike(value) && !Array.isArray(value)` check below is already
 * correct on both sides without that guard — with one residual asymmetry
 * against the PHP guard, found during GH-36's own audit round and left
 * unfixed there for the reason recorded on `mergeConfigLayer()`: a genuinely
 * empty JSON ARRAY on a key whose valid schema shape is always an object
 * (`"linter": []`, never real Biome/tsc config) replaces here (this file's
 * `Array.isArray([])` is unconditionally `true`) while PHP's `array_is_list`
 * ambiguity makes it recurse instead. Not reachable by a config that
 * successfully loads in the real tool — Biome answers that exact shape with
 * `linter has an incorrect type, expected an object, but received an array`
 * before either gate's verdict would matter.
 *
 * `__proto__`/`constructor`/`prototype` are skipped outright — a
 * `JSON.parse`'d document with a literal `"__proto__"` key creates a real OWN
 * property (JSON.parse does not special-case it), but `merged[key]` on the
 * READ side falls through to the inherited accessor once `merged` carries no
 * own `__proto__`, returning the object's actual prototype rather than
 * `undefined` — the "both sides are objects, recurse" branch then treats that
 * prototype as ordinary data and the resulting assignment performs a real
 * `[[SetPrototypeOf]]` on the merged object. None of the three names is a
 * legitimate Biome/tsc config key, so skipping them changes no accepted
 * config's effective result.
 *
 * @param {object} base    The lower-precedence layer.
 * @param {object} overlay The higher-precedence layer.
 *
 * @returns {object}
 */
export function mergeConfigLayer(base, overlay) {
    const merged = { ...base };

    for (const [key, value] of Object.entries(overlay)) {
        if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
            continue;
        }

        const baseValue = merged[key];

        if (key === 'overrides' && Array.isArray(value) && Array.isArray(baseValue)) {
            merged[key] = [...baseValue, ...value];

            continue;
        }

        if (
            isArrayLike(value) && !Array.isArray(value)
            && isArrayLike(baseValue) && !Array.isArray(baseValue)
        ) {
            merged[key] = mergeConfigLayer(baseValue, value);

            continue;
        }

        merged[key] = value;
    }

    return merged;
}
