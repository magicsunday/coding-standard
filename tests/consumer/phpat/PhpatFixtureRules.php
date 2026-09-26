<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\Phpat;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The one phpat rule the preset's self-test registers (GH-183): a structural
 * invariant — every class under Leaf\ is final — which is the kind of rule
 * phpstan/phpat.neon exists for, since Deptrac has no notion of a class modifier.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final class PhpatFixtureRules
{
    /**
     * Structural invariant (Deptrac cannot inspect modifiers): leaf classes are final.
     *
     * @return Rule
     */
    #[TestRule]
    public function leafClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\CodingStandard\Fixture\Phpat\Leaf'))
            ->should()->beFinal()
            ->because('the preset self-test requires leaf classes to be final.');
    }
}
