<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractConsumerPhpstanGateTestCase;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function substr_count;

/**
 * Proves phpstan/base.neon's checked-exceptions config (GH-139, promoted out
 * of the opt-in strict tier by GH-144): the direction it genuinely ENABLES —
 * an undocumented exception type (missingCheckedExceptionInThrows, off by
 * default in PHPStan) — fires on every consumer of base.neon and stays quiet
 * on correctly documented or deliberately unchecked methods; and, separately,
 * that the OTHER direction (a stale/wrong exception type, PHPStan's own
 * tooWideThrowType default) keeps firing rather than being accidentally
 * suppressed. Run through base.neon exactly as a consumer installs it — not
 * through a hand-rebuilt config, which could drift from what base.neon
 * actually ships. A single additional test proves the strict tier (which
 * includes base.neon) still carries the same behaviour through rather than
 * losing it — before GH-144 this class contrasted a "control" (base.neon,
 * expected quiet) run against a "strict" (expected to fire) run; promoting
 * the config to base.neon collapsed that contrast, since both configs now
 * behave identically for this concern.
 *
 * Migrated pattern from tests/CheckDisallowedCallsTest.php; see
 * AbstractConsumerPhpstanGateTestCase for why every test self-skips via setUp()
 * until tests/consumer is installed, rather than failing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckCheckedExceptionsTest extends AbstractConsumerPhpstanGateTestCase
{
    /**
     * Memoized across every test in this class; see baseResult().
     */
    private static ?GateResult $baseResult = null;

    /**
     * Memoized across every test in this class; see strictResult().
     */
    private static ?GateResult $strictResult = null;

    /**
     * base.neon must report the undocumented throw
     * (missingCheckedExceptionInThrows, identifier missingType.checkedException).
     * Matched on the message text, not the identifier: `--error-format=raw`
     * (used here, same as CheckDisallowedCallsTest) does not carry identifiers
     * at all, only file:line:message (observed 2026-09-02 against
     * phpstan/phpstan 2.2.12 — re-verify with `--error-format=json` if this
     * stops holding after a version bump).
     *
     * @return void
     */
    #[Test]
    public function baseRunReportsTheUndocumentedThrow(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'undocumentedThrow() throws checked exception',
            $result->output,
            "base.neon did not report the undocumented throw in undocumentedThrow().\n{$result->output}",
        );
        self::assertStringContainsString(
            'missing from the PHPDoc @throws tag',
            $result->output,
            "the report did not carry the expected missing-@throws message.\n{$result->output}",
        );
    }

    /**
     * The correctly documented sibling method must NOT be reported — proves
     * the config discriminates rather than flagging every throw regardless
     * of its throws annotation.
     *
     * @return void
     */
    #[Test]
    public function baseRunDoesNotReportTheDocumentedThrow(): void
    {
        $result = self::baseResult();

        // "::documentedThrow()", not "documentedThrow()" — the latter is
        // also a substring of "undocumentedThrow()" and would false-positive
        // against the previous test's own, expected finding.
        self::assertResultIsNotDegraded($result);
        self::assertStringNotContainsString(
            '::documentedThrow()',
            $result->output,
            "the correctly documented throw in documentedThrow() was reported anyway.\n{$result->output}",
        );
    }

    /**
     * The unchecked programmer-error exception must NOT be reported either.
     * This does NOT discriminate uncheckedExceptionClasses's inheritance
     * matching by itself: uncheckedProgrammerError()'s InvalidArgumentException
     * is a plain SPL class outside checkedExceptionRegexes, so it would stay
     * unreported even with `uncheckedExceptionClasses: ['LogicException']`
     * deleted from base.neon entirely — see
     * baseRunDoesNotReportTheUncheckedByInheritanceOnly() below for the
     * fixture shape that actually needs the inheritance match.
     *
     * @return void
     */
    #[Test]
    public function baseRunDoesNotReportTheUncheckedProgrammerError(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringNotContainsString(
            '::uncheckedProgrammerError()',
            $result->output,
            "the unchecked InvalidArgumentException in uncheckedProgrammerError() was reported anyway.\n{$result->output}",
        );
    }

    /**
     * The genuinely discriminating case for `uncheckedExceptionClasses:
     * ['LogicException']`'s inheritance matching: uncheckedByInheritanceOnly()
     * throws a MagicSunday-namespaced exception (so it IS inside
     * checkedExceptionRegexes, unlike uncheckedProgrammerError() above) that
     * also extends LogicException (so it is exempted only by the inheritance
     * match). Deleting the uncheckedExceptionClasses line from base.neon
     * turns this test red; it does not affect
     * baseRunDoesNotReportTheUncheckedProgrammerError() above at all.
     *
     * @return void
     */
    #[Test]
    public function baseRunDoesNotReportTheUncheckedByInheritanceOnly(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringNotContainsString(
            '::uncheckedByInheritanceOnly()',
            $result->output,
            "the exception in uncheckedByInheritanceOnly() was reported anyway — uncheckedExceptionClasses no longer covers it by inheritance.\n{$result->output}",
        );
    }

    /**
     * base.neon must report the OTHER direction — a stale documented
     * exception type (diagnostic identifier throws.unusedType), the
     * fixture's staleThrows() method. This is PHPStan's own default
     * (`exceptions.check.tooWideThrowType`, independent of this config,
     * see phpstan/base.neon's own comment) — staleThrows() is a `final`
     * method, so `checkTooWideThrowTypesInProtectedAndPublicMethods` (this
     * config's only genuine contribution to this direction) does not affect
     * it either way — the override case that flag DOES affect is proven
     * separately by baseRunReportsTheStaleOverride() below; the actual known
     * gap (a non-final class's own first-declared method) has no fixture
     * here, matching README.
     *
     * @return void
     */
    #[Test]
    public function baseRunReportsTheStaleThrows(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'staleThrows() has',
            $result->output,
            "base.neon did not report the stale @throws in staleThrows() — tooWideThrowType may no longer default to true; re-verify phpstan/base.neon's comment against the installed PHPStan version.\n{$result->output}",
        );
        self::assertStringContainsString(
            "but it's not thrown",
            $result->output,
            "the report did not carry the expected stale-@throws message.\n{$result->output}",
        );
    }

    /**
     * base.neon must report EXACTLY one missing-throws-annotation finding
     * (undocumentedThrow()) and exactly two stale-throws-annotation findings
     * (staleThrows() and overriddenStaleThrows()) — a stray extra one (the
     * documented or unchecked method getting flagged too, or either
     * direction firing an unexpected additional time) would pass the
     * assertions above individually while still being wrong.
     *
     * @return void
     */
    #[Test]
    public function baseRunReportsExactlyTheExpectedFindingCounts(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertSame(
            1,
            substr_count($result->output, 'missing from the PHPDoc @throws tag'),
            "expected exactly one missing-@throws report, the fixture and the config have drifted.\n{$result->output}",
        );
        self::assertSame(
            2,
            substr_count($result->output, "but it's not thrown"),
            "expected exactly two stale-@throws reports (staleThrows() and overriddenStaleThrows()), the fixture and the config have drifted.\n{$result->output}",
        );
    }

    /**
     * base.neon must report CheckedExceptionsOverrideFixture's stale
     * override — the one case that isolates
     * checkTooWideThrowTypesInProtectedAndPublicMethods's own genuine effect:
     * a non-final class's OVERRIDE of a base declaration becomes checkable,
     * unlike staleThrows() above (checkable regardless, because that class is
     * final) or a non-final class's own first-declared method (stays
     * uncheckable regardless, per README's documented gap).
     *
     * @return void
     */
    #[Test]
    public function baseRunReportsTheStaleOverride(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'overriddenStaleThrows() has',
            $result->output,
            "base.neon did not report the stale override in overriddenStaleThrows() — checkTooWideThrowTypesInProtectedAndPublicMethods may not be reaching overrides any more.\n{$result->output}",
        );
        self::assertStringContainsString(
            "but it's not thrown",
            $result->output,
            "the report did not carry the expected stale-@throws message.\n{$result->output}",
        );
    }

    /**
     * The strict tier includes base.neon, so it must inherit this behaviour
     * rather than losing it — the one sanity check proving the strict tier's
     * own additions (shipmonk, symplify, the extra-strict report parameters)
     * do not accidentally suppress or shadow a config base.neon already sets.
     * Not a repeat of the full base.neon assertion battery above: that would
     * only re-prove base.neon's own behaviour a second time through a
     * different config path.
     *
     * @return void
     */
    #[Test]
    public function strictTierInheritsTheUndocumentedThrowCheck(): void
    {
        $result = self::strictResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'undocumentedThrow() throws checked exception',
            $result->output,
            "the strict tier did not report the undocumented throw in undocumentedThrow() — base.neon's checked-exceptions config may no longer be reaching consumers of the strict tier.\n{$result->output}",
        );
    }

    /**
     * @return GateResult The base.neon run against the checked-exceptions fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function baseResult(): GateResult
    {
        return self::$baseResult ??= self::runPhpstan(
            self::consumer() . '/phpstan.neon',
            'checked-exceptions',
        );
    }

    /**
     * @return GateResult The strict.neon run against the checked-exceptions fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function strictResult(): GateResult
    {
        return self::$strictResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-strict.neon',
            'checked-exceptions',
        );
    }
}
