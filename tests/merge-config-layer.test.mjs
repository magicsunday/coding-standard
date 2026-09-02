/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * Tests for mergeConfigLayer() (bin/support/merge-config-layer.mjs),
 * extracted per GH-116 so the merge behaviour can be asserted directly on a
 * decoded base + overlay pair, instead of only through
 * tests/check-js-configs.sh's accept/reject CLI interface. Mirrors
 * tests/MergeConfigLayerTest.php's PHP cases; see that function's own
 * docblock (bin/support/merge-config-layer.mjs) for the Biome 2.5.5
 * measurements this behaviour is based on.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { mergeConfigLayer } from '../bin/support/merge-config-layer.mjs';

test('an empty overlay object preserves the inherited good value', () => {
    const base = { linter: { enabled: true } };
    const overlay = { linter: {} };

    assert.deepStrictEqual(mergeConfigLayer(base, overlay), { linter: { enabled: true } });
});

test('an empty base with a non-empty overlay object takes the overlay content', () => {
    const base = { linter: {} };
    const overlay = { linter: { enabled: false } };

    assert.deepStrictEqual(mergeConfigLayer(base, overlay), { linter: { enabled: false } });
});

test('overrides arrays concatenate instead of replacing', () => {
    const base = { overrides: [{ includes: ['src/**'] }] };
    const overlay = { overrides: [{ includes: ['tests/**'] }] };

    assert.deepStrictEqual(mergeConfigLayer(base, overlay), {
        overrides: [{ includes: ['src/**'] }, { includes: ['tests/**'] }],
    });
});

test('overrides absent from the base replaces wholesale instead of concatenating', () => {
    const overlay = { overrides: [{ includes: ['tests/**'] }] };

    assert.deepStrictEqual(mergeConfigLayer({}, overlay), overlay);
});

test('a non-overrides list replaces wholesale', () => {
    const base = { files: { includes: ['src/**'] } };
    const overlay = { files: { includes: ['tests/**'] } };

    assert.deepStrictEqual(mergeConfigLayer(base, overlay), { files: { includes: ['tests/**'] } });
});

test('nested objects merge key by key', () => {
    const base = { linter: { enabled: true, ignore: ['dist'] } };
    const overlay = { linter: { enabled: false } };

    assert.deepStrictEqual(mergeConfigLayer(base, overlay), {
        linter: { enabled: false, ignore: ['dist'] },
    });
});

test('a scalar overlay value wins over the base', () => {
    assert.deepStrictEqual(mergeConfigLayer({ formatter: 'disabled' }, { formatter: 'enabled' }), {
        formatter: 'enabled',
    });
});

test('a key absent from the overlay is untouched', () => {
    const base = { a: 1, b: { c: 2 } };

    assert.deepStrictEqual(mergeConfigLayer(base, {}), { a: 1, b: { c: 2 } });
});

// One case per key, not one shared fixture carrying all three: a shared
// fixture only ever exercises the FIRST guard a short-circuiting `||` chain
// reaches for a given input, so removing or misspelling the `constructor`/
// `prototype` arms individually would still leave a single combined test
// green (found by an independent cross-model review of this change).
for (const key of ['__proto__', 'constructor', 'prototype']) {
    test(`a ${key} overlay key is skipped, never assigned onto the merged object`, () => {
        const overlay = JSON.parse(`{"${key}": {"polluted": true}, "safe": 1}`);

        const merged = mergeConfigLayer({}, overlay);

        assert.strictEqual(Object.prototype.hasOwnProperty.call(merged, key), false);
        assert.strictEqual(merged.polluted, undefined);
        assert.strictEqual(merged.safe, 1);
    });
}
