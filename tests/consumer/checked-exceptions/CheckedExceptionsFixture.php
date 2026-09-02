<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CheckedExceptions;

use InvalidArgumentException;
use MagicSunday\CodingStandard\Fixture\CheckedExceptions\Exception\FixtureException;

/**
 * Two deliberately paired methods proving the checked-exceptions config
 * (phpstan/strict.neon, GH-139) both fires on an undocumented throw and
 * stays quiet on a correctly documented one — a config that only fires
 * would pass a plain "it reports something" check while a stray extra
 * finding elsewhere in the fixture would go unnoticed.
 *
 * Throws FixtureException (Exception\ sub-namespace, below) rather than a
 * plain SPL exception: `checkedExceptionRegexes: ['#^MagicSunday\\#']` in
 * strict.neon only treats a MagicSunday-namespaced class as checked,
 * matching the decision table's row 5 (a third-party/SPL exception is
 * unchecked purely by not matching the regex, with no separate
 * "third-party" concept in PHPStan itself) — an SPL exception here would
 * prove nothing.
 *
 * It lives outside `src/` for the same reason `case-folding/` does: the
 * php-cs-fixer and Rector consumer smoke runs are scoped to `src/`, so this
 * fixture cannot disturb them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class CheckedExceptionsFixture
{
    /**
     * Throws a first-party checked exception with no @throws tag at all.
     *
     * @return void
     */
    public function undocumentedThrow(): void
    {
        throw new FixtureException('undocumented on purpose');
    }

    /**
     * The correctly documented mirror of undocumentedThrow() — must not be reported.
     *
     * @throws FixtureException Always, on purpose.
     *
     * @return void
     */
    public function documentedThrow(): void
    {
        throw new FixtureException('documented on purpose');
    }

    /**
     * A programmer-error exception, unchecked by config — must not be reported
     * even without a @throws tag.
     *
     * @param int $value The value to validate.
     *
     * @return void
     */
    public function uncheckedProgrammerError(int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException('unchecked, no @throws needed');
        }
    }
}
