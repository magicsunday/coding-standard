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
use MagicSunday\CodingStandard\Fixture\CheckedExceptions\Exception\FixtureLogicException;

/**
 * Deliberately paired/discriminating methods proving the checked-exceptions
 * config (phpstan/base.neon, GH-139, promoted from strict.neon by GH-144)
 * fires on an undocumented throw, stays
 * quiet on a correctly documented one, exempts unchecked exceptions by
 * namespace and — separately, via a different mechanism — by inheritance,
 * and still reports a stale throws annotation. Each method's own docblock
 * states the specific case it proves; a config that only fires would pass a
 * plain "it reports something" check while a stray extra finding elsewhere
 * in the fixture would go unnoticed, which is why each case gets its own
 * paired discriminating assertion in CheckCheckedExceptionsTest rather than
 * one blanket check.
 *
 * undocumentedThrow()/documentedThrow()/staleThrows() throw FixtureException
 * (Exception\ sub-namespace, below) rather than a plain SPL exception:
 * `checkedExceptionRegexes: ['#^MagicSunday\\#']` in base.neon only treats
 * a MagicSunday-namespaced class as checked — a third-party/SPL exception is
 * unchecked purely by not matching the regex, with no separate "third-party"
 * concept in PHPStan itself (see README's "Checked exceptions" section) — an
 * SPL exception here would prove nothing for those three methods.
 * uncheckedProgrammerError() and uncheckedByInheritanceOnly() deliberately
 * throw different exception shapes instead — see their own docblocks.
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
     * Throws a first-party checked exception with no throws annotation at all.
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
     * even without a throws annotation.
     *
     * @param int $value The value to validate.
     *
     * @return void
     */
    public function uncheckedProgrammerError(int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException('unchecked, no throws annotation needed');
        }
    }

    /**
     * A MagicSunday-namespaced exception that is ALSO a LogicException — the
     * case uncheckedExceptionClasses's inheritance matching actually needs to
     * discriminate. Unlike uncheckedProgrammerError() above (a plain SPL
     * InvalidArgumentException, already unchecked purely by not matching
     * checkedExceptionRegexes — deleting `uncheckedExceptionClasses:
     * ['LogicException']` from base.neon would NOT turn that test red),
     * this one lives inside `#^MagicSunday\\#` and would be flagged as
     * missingCheckedExceptionInThrows without the exemption.
     *
     * @return void
     */
    public function uncheckedByInheritanceOnly(): void
    {
        throw new FixtureLogicException('checked-namespace, unchecked only by LogicException inheritance');
    }

    /**
     * A stale throws annotation — the body no longer raises FixtureException,
     * as if after a refactor. Proves `tooWideThrowType`, the OTHER direction
     * from undocumentedThrow() above: this class is final, so it stays
     * checkable regardless of `checkTooWideThrowTypesInProtectedAndPublicMethods`
     * (see README's "Checked exceptions" section on why a non-final class's
     * first-declared method would NOT be checkable here).
     *
     * @throws FixtureException Never thrown — the tag is deliberately stale, to prove tooWideThrowType.
     *
     * @return void
     */
    public function staleThrows(): void
    {
        // Deliberately empty — proves the stale-tag direction, not the
        // undocumented-throw direction above.
    }
}
