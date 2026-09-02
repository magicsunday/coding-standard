<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateProcess;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function dirname;
use function is_executable;
use function sprintf;
use function substr_count;

/**
 * Proves phpstan/strict.neon's checked-exceptions config (GH-139) both fires
 * on an undocumented @throws and stays quiet on a correctly documented one,
 * through the strict tier exactly as a consumer installs it — not through a
 * hand-rebuilt config, which could drift from what strict.neon actually ships.
 *
 * Migrated pattern from tests/CheckDisallowedCallsTest.php; see that class's
 * docblock for why every test self-skips via setUp() until tests/consumer is
 * installed, rather than failing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckCheckedExceptionsTest extends TestCase
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
     * @return void
     */
    protected function setUp(): void
    {
        if (!is_executable(self::phpstanBinary())) {
            self::markTestSkipped(sprintf(
                '%s is missing — run `composer install` in tests/consumer first.',
                self::phpstanBinary(),
            ));
        }
    }

    /**
     * The control run: base.neon alone carries no exceptions.check config, so
     * the fixture's undocumented throw must NOT be reported here — or the
     * strict run below would prove nothing about the strict tier specifically.
     *
     * @return void
     */
    #[Test]
    public function controlRunWithoutTheStrictTierIsClean(): void
    {
        $result = self::controlResult();

        self::assertResultIsNotDegraded($result);
        self::assertSame(
            0,
            $result->exitCode,
            "base.neon alone reports on the checked-exceptions fixture, so a report in the strict run would not prove strict.neon fired.\n{$result->output}",
        );
    }

    /**
     * The strict tier must report the undocumented throw. Matched on the
     * message text, not the `missingType.checkedException` diagnostic
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
            "missing from the PHPDoc @throws tag",
            $result->output,
            "the report did not carry the expected missing-@throws message.\n{$result->output}",
        );
    }

    /**
     * The correctly documented sibling method must NOT be reported — proves
     * the config discriminates rather than flagging every throw regardless
     * of its @throws tag.
     *
     * @return void
     */
    #[Test]
    public function strictRunDoesNotReportTheDocumentedThrow(): void
    {
        // "::documentedThrow()", not "documentedThrow()" — the latter is
        // also a substring of "undocumentedThrow()" and would false-positive
        // against row 1's own, expected finding.
        self::assertStringNotContainsString(
            '::documentedThrow()',
            self::strictResult()->output,
            "the correctly documented throw in documentedThrow() was reported anyway.\n" . self::strictResult()->output,
        );
    }

    /**
     * The unchecked programmer-error exception must NOT be reported either —
     * proves `uncheckedExceptionClasses: ['LogicException']` in strict.neon
     * exempts InvalidArgumentException by inheritance, without listing it
     * separately.
     *
     * @return void
     */
    #[Test]
    public function strictRunDoesNotReportTheUncheckedProgrammerError(): void
    {
        self::assertStringNotContainsString(
            '::uncheckedProgrammerError()',
            self::strictResult()->output,
            "the unchecked InvalidArgumentException in uncheckedProgrammerError() was reported anyway — uncheckedExceptionClasses no longer covers it by inheritance.\n" . self::strictResult()->output,
        );
    }

    /**
     * The strict run must report EXACTLY one missing-@throws finding — a
     * stray second one (the documented or unchecked method getting flagged
     * too) would pass the three assertions above individually while still
     * being wrong. Scoped to this message specifically, not the whole
     * output's line count: the strict tier's other rule packs (shipmonk,
     * symplify) may legitimately report unrelated findings, same reasoning
     * as CheckDisallowedCallsTest's wiring run not asserting a total count.
     *
     * @return void
     */
    #[Test]
    public function strictRunReportsExactlyOneFinding(): void
    {
        $result = self::strictResult();

        self::assertSame(
            1,
            substr_count($result->output, 'missing from the PHPDoc @throws tag'),
            "expected exactly one missing-@throws report, the fixture and the config have drifted.\n{$result->output}",
        );
    }

    /**
     * @return string Absolute path to the repository root.
     */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return string Absolute path to tests/consumer, an installed consumer layout.
     */
    private static function consumer(): string
    {
        return self::root() . '/tests/consumer';
    }

    /**
     * @return string Absolute path to the phpstan binary tests/consumer installs.
     */
    private static function phpstanBinary(): string
    {
        return self::consumer() . '/.build/bin/phpstan';
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
        return self::$controlResult ??= self::runPhpstan(self::consumer() . '/phpstan.neon');
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
        return self::$strictResult ??= self::runPhpstan(self::consumer() . '/phpstan-strict.neon');
    }

    /**
     * Runs `phpstan analyse --configuration <config> checked-exceptions` from
     * tests/consumer via GateProcess. The `checked-exceptions` positional path
     * argument is unconditional, not conditional on which config is passed —
     * see CheckDisallowedCallsTest::runPhpstan()'s docblock for why: without
     * it, the control config's own `paths: [src]` would silently scope that
     * run away from this fixture entirely.
     *
     * @param string $config Absolute path to the configuration file to analyse with.
     *
     * @return GateResult
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function runPhpstan(string $config): GateResult
    {
        $command = [
            self::phpstanBinary(),
            'analyse',
            '--configuration',
            $config,
            '--error-format=raw',
            '--no-progress',
            '--memory-limit=-1',
        ];

        return (new GateProcess())->run($command, 'checked-exceptions', self::consumer());
    }

    /**
     * @param GateResult $result The captured phpstan run to check.
     *
     * @return void
     */
    private static function assertResultIsNotDegraded(GateResult $result): void
    {
        self::assertFalse($result->isDegraded(), 'phpstan emitted a diagnostic of its own.');
    }
}
