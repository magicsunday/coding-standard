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
 * Proves phpstan/strict.neon's checked-exceptions config (GH-139): the
 * direction it genuinely ENABLES — an undocumented exception type
 * (missingCheckedExceptionInThrows, off by default in PHPStan) — fires
 * through the strict tier and stays quiet on correctly documented or
 * deliberately unchecked methods; and, separately, that the OTHER direction
 * (a stale/wrong exception type, PHPStan's own tooWideThrowType default,
 * already active on base.neon alone) keeps firing rather than being
 * accidentally suppressed. Run through the strict tier exactly as a
 * consumer installs it — not through a hand-rebuilt config, which could
 * drift from what strict.neon actually ships.
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
     * Memoized across every test in this class; see controlResult().
     */
    private static ?GateResult $controlResult = null;

    /**
     * Memoized across every test in this class; see strictResult().
     */
    private static ?GateResult $strictResult = null;

    /**
     * The control run: base.neon defaults `missingCheckedExceptionInThrows`
     * to false, so the fixture's undocumented throw must NOT be reported here
     * — or the strict run below would prove nothing about the strict tier
     * specifically for THIS direction.
     *
     * This is deliberately not a "control run is clean" assertion — see
     * controlRunAlreadyReportsTheStaleThrows() below for why base.neon alone
     * is NOT expected to be silent on this fixture.
     *
     * @return void
     */
    #[Test]
    public function controlRunDoesNotReportTheUndocumentedThrow(): void
    {
        $result = self::controlResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringNotContainsString(
            '::undocumentedThrow()',
            $result->output,
            "base.neon alone reports the undocumented throw, so a report in the strict run would not prove missingCheckedExceptionInThrows fired.\n{$result->output}",
        );
    }

    /**
     * base.neon ALONE already reports staleThrows() — PHPStan ships
     * `exceptions.check.tooWideThrowType: true` as its own default (see
     * phpstan/strict.neon's comment on this), independent of
     * checkedExceptionRegexes/uncheckedExceptionClasses. This is
     * deliberately proven here rather than assumed: it is what makes
     * controlRunDoesNotReportTheUndocumentedThrow() above a "not clean, but
     * not reporting THIS specific thing" assertion instead of a blanket
     * exit-code-0 check, and it is the reason strict.neon's own config does
     * NOT set `exceptions.check.tooWideThrowType` — doing so would be a
     * no-op this test would not be able to tell apart from a genuine effect.
     *
     * @return void
     */
    #[Test]
    public function controlRunAlreadyReportsTheStaleThrows(): void
    {
        self::assertStringContainsString(
            "staleThrows() has",
            self::controlResult()->output,
            "base.neon alone did NOT report the stale @throws in staleThrows() — tooWideThrowType may no longer default to true; re-verify phpstan/strict.neon's comment against the installed PHPStan version.\n" . self::controlResult()->output,
        );
    }

    /**
     * The strict tier must report the undocumented throw
     * (missingCheckedExceptionInThrows, identifier
     * missingType.checkedException). Matched on the message text, not the
     * identifier: `--error-format=raw` (used here, same as
     * CheckDisallowedCallsTest) does not carry identifiers at all, only
     * file:line:message.
     *
     * @return void
     */
    #[Test]
    public function strictRunReportsTheUndocumentedThrow(): void
    {
        $result = self::strictResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'undocumentedThrow() throws checked exception',
            $result->output,
            "the strict tier did not report the undocumented throw in undocumentedThrow().\n{$result->output}",
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
    public function strictRunDoesNotReportTheDocumentedThrow(): void
    {
        // "::documentedThrow()", not "documentedThrow()" — the latter is
        // also a substring of "undocumentedThrow()" and would false-positive
        // against the previous test's own, expected finding.
        self::assertResultIsNotDegraded(self::strictResult());
        self::assertStringNotContainsString(
            '::documentedThrow()',
            self::strictResult()->output,
            "the correctly documented throw in documentedThrow() was reported anyway.\n" . self::strictResult()->output,
        );
    }

    /**
     * The unchecked programmer-error exception must NOT be reported either.
     * This does NOT discriminate uncheckedExceptionClasses's inheritance
     * matching by itself: uncheckedProgrammerError()'s InvalidArgumentException
     * is a plain SPL class outside checkedExceptionRegexes, so it would stay
     * unreported even with `uncheckedExceptionClasses: ['LogicException']`
     * deleted from strict.neon entirely — see
     * strictRunDoesNotReportTheUncheckedByInheritanceOnly() below for the
     * fixture shape that actually needs the inheritance match.
     *
     * @return void
     */
    #[Test]
    public function strictRunDoesNotReportTheUncheckedProgrammerError(): void
    {
        self::assertResultIsNotDegraded(self::strictResult());
        self::assertStringNotContainsString(
            '::uncheckedProgrammerError()',
            self::strictResult()->output,
            "the unchecked InvalidArgumentException in uncheckedProgrammerError() was reported anyway.\n" . self::strictResult()->output,
        );
    }

    /**
     * The genuinely discriminating case for `uncheckedExceptionClasses:
     * ['LogicException']`'s inheritance matching: uncheckedByInheritanceOnly()
     * throws a MagicSunday-namespaced exception (so it IS inside
     * checkedExceptionRegexes, unlike uncheckedProgrammerError() above) that
     * also extends LogicException (so it is exempted only by the inheritance
     * match). Deleting the uncheckedExceptionClasses line from strict.neon
     * turns this test red; it does not affect
     * strictRunDoesNotReportTheUncheckedProgrammerError() above at all.
     *
     * @return void
     */
    #[Test]
    public function strictRunDoesNotReportTheUncheckedByInheritanceOnly(): void
    {
        self::assertResultIsNotDegraded(self::strictResult());
        self::assertStringNotContainsString(
            '::uncheckedByInheritanceOnly()',
            self::strictResult()->output,
            "the exception in uncheckedByInheritanceOnly() was reported anyway — uncheckedExceptionClasses no longer covers it by inheritance.\n" . self::strictResult()->output,
        );
    }

    /**
     * The strict tier must keep reporting the OTHER direction — a stale
     * documented exception type (diagnostic identifier throws.unusedType),
     * the fixture's staleThrows() method — not suppress it. Per
     * controlRunAlreadyReportsTheStaleThrows() above, this direction is
     * PHPStan's own default and already fires on base.neon alone;
     * staleThrows() is a `final` method, so
     * `checkTooWideThrowTypesInProtectedAndPublicMethods` (this config's
     * only genuine contribution to this direction) does not affect it
     * either way — that flag's own distinct effect (a non-final override
     * becoming checkable) has no fixture here, and is documented as a known
     * gap in README instead. This test exists so a strict-tier run
     * specifically (not just the control run above) is proven to still
     * carry the stale-throws finding through — this is the only test in
     * this class that asserts on staleThrows().
     *
     * @return void
     */
    #[Test]
    public function strictRunReportsTheStaleThrows(): void
    {
        $result = self::strictResult();

        self::assertResultIsNotDegraded($result);
        self::assertStringContainsString(
            'staleThrows() has',
            $result->output,
            "the strict tier did not report the stale @throws in staleThrows().\n{$result->output}",
        );
        self::assertStringContainsString(
            "but it's not thrown",
            $result->output,
            "the report did not carry the expected stale-@throws message.\n{$result->output}",
        );
    }

    /**
     * The strict run must report EXACTLY one missing-throws-annotation
     * finding and exactly one stale-throws-annotation finding — a stray
     * second one (the
     * documented or unchecked method getting flagged too, or the stale-tag
     * direction firing twice) would pass the assertions above individually
     * while still being wrong. Scoped to these two messages specifically,
     * not the whole output's line count: the strict tier's other rule packs
     * (shipmonk, symplify) may legitimately report unrelated findings, same
     * reasoning as CheckDisallowedCallsTest's wiring run not asserting a
     * total count.
     *
     * @return void
     */
    #[Test]
    public function strictRunReportsExactlyOneFindingPerDirection(): void
    {
        $result = self::strictResult();

        self::assertSame(
            1,
            substr_count($result->output, 'missing from the PHPDoc @throws tag'),
            "expected exactly one missing-@throws report, the fixture and the config have drifted.\n{$result->output}",
        );
        self::assertSame(
            1,
            substr_count($result->output, "but it's not thrown"),
            "expected exactly one stale-@throws report, the fixture and the config have drifted.\n{$result->output}",
        );
    }

    /**
     * @return GateResult The base.neon-alone run against the checked-exceptions fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function controlResult(): GateResult
    {
        return self::$controlResult ??= self::runPhpstan(
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
