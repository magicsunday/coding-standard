<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\SymplifyModern;

/**
 * A deliberately non-conformant class: it uses a trait and takes a parameter by
 * reference, the two constructs `symplify.forbiddenTrait` and `symplify.noReference`
 * report. From symplify/phpstan-rules 14.19 on both rules sit in the `modern`
 * group, so the self-test asserting that they fire proves `strict.neon` switches
 * that group on.
 *
 * It lives outside `src/` on purpose: the php-cs-fixer and Rector consumer smoke
 * runs are scoped to `src/`, so this fixture cannot disturb them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final class ModernRulesFixture
{
    use ModernRulesTrait;

    /**
     * @param int $counter
     *
     * @return void
     */
    public function increment(int &$counter): void
    {
        ++$counter;
    }
}
