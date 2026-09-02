<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bin/support/merge-config-layer.php';

/**
 * Tests for the global mergeConfigLayer() function
 * (bin/support/merge-config-layer.php), extracted per GH-116 so the merge
 * behaviour can be asserted directly on a decoded base + overlay pair,
 * instead of only through CheckConsumerConfigTest's accept/reject CLI
 * interface. The behaviour itself is not new — see that function's own
 * docblock for the Biome 2.5.5 measurements it is based on — this suite adds
 * the regression guard GH-116 was filed for: an empty overlay OBJECT must
 * leave an inherited GOOD value untouched rather than wiping it, a property
 * no CLI-level fixture in CheckConsumerConfigTest can observe because every
 * violation check there fires only on an explicit bad value, never on an
 * ABSENT key.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversFunction('mergeConfigLayer')]
final class MergeConfigLayerTest extends TestCase
{
    /**
     * The regression this suite exists for: an empty overlay OBJECT
     * (`"linter": {}`, decoded to `[]` same as an empty array) must leave an
     * inherited GOOD value untouched — not wipe it, the way naive
     * "is either side a list" logic would.
     */
    #[Test]
    public function emptyOverlayObjectPreservesInheritedGoodValue(): void
    {
        $base    = ['linter' => ['enabled' => true]];
        $overlay = ['linter' => []];

        self::assertSame(
            ['linter' => ['enabled' => true]],
            mergeConfigLayer($base, $overlay),
            'an empty overlay object must preserve the inherited value, not wipe it'
        );
    }

    /**
     * The mirror image of the regression case above: an empty BASE merged
     * with a non-empty overlay object takes the overlay's content, not an
     * empty result.
     */
    #[Test]
    public function emptyBaseWithNonEmptyOverlayTakesOverlayContent(): void
    {
        $base    = ['linter' => []];
        $overlay = ['linter' => ['enabled' => false]];

        self::assertSame(
            ['linter' => ['enabled' => false]],
            mergeConfigLayer($base, $overlay)
        );
    }

    /**
     * `overrides` is the one named exception to "overlay replaces wholesale":
     * it CONCATENATES rather than replaces, verified against Biome 2.5.5 per
     * the function's own docblock.
     */
    #[Test]
    public function overridesArraysConcatenateInsteadOfReplacing(): void
    {
        $base    = ['overrides' => [['includes' => ['src/**']]]];
        $overlay = ['overrides' => [['includes' => ['tests/**']]]];

        self::assertSame(
            [
                'overrides' => [
                    ['includes' => ['src/**']],
                    ['includes' => ['tests/**']],
                ],
            ],
            mergeConfigLayer($base, $overlay)
        );
    }

    /**
     * Every other array-valued key (not `overrides`) replaces wholesale — a
     * later layer's list is not merged element-by-element with an earlier
     * one, matching the measured Biome behaviour for `files.includes`.
     */
    #[Test]
    public function nonOverridesListReplacesWholesale(): void
    {
        $base    = ['files' => ['includes' => ['src/**']]];
        $overlay = ['files' => ['includes' => ['tests/**']]];

        self::assertSame(
            ['files' => ['includes' => ['tests/**']]],
            mergeConfigLayer($base, $overlay)
        );
    }

    /**
     * Nested objects merge key-by-key: a key the overlay does not mention
     * survives from the base, one it does mention is overridden.
     */
    #[Test]
    public function nestedObjectsMergeKeyByKey(): void
    {
        $base    = ['linter' => ['enabled' => true, 'ignore' => ['dist']]];
        $overlay = ['linter' => ['enabled' => false]];

        self::assertSame(
            ['linter' => ['enabled' => false, 'ignore' => ['dist']]],
            mergeConfigLayer($base, $overlay)
        );
    }

    /**
     * A scalar overlay value wins over the base outright, the general rule
     * every array-valued key deviates from only via the two guards above.
     */
    #[Test]
    public function scalarOverlayValueWinsOverBase(): void
    {
        self::assertSame(
            ['formatter' => 'enabled'],
            mergeConfigLayer(['formatter' => 'disabled'], ['formatter' => 'enabled'])
        );
    }

    /**
     * A key the overlay never mentions at all is untouched, regardless of
     * its own value's shape.
     */
    #[Test]
    public function keyAbsentFromOverlayIsUntouched(): void
    {
        self::assertSame(
            ['a' => 1, 'b' => ['c' => 2]],
            mergeConfigLayer(['a' => 1, 'b' => ['c' => 2]], [])
        );
    }
}
