<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\FixtureDirectory;
use MagicSunday\CodingStandard\Test\Support\GateProcess;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function array_filter;
use function count;
use function explode;
use function str_contains;

/**
 * Base test case for every suite migrated off tests/harness.sh. Provides a
 * per-test fixture directory and the five accept/reject/usage-error/
 * report-is-inert/reports-once decisions as real PHPUnit assertions —
 * ported from tests/harness.sh's harness_decide_* functions. A failed
 * decision throws a real AssertionFailedError; there is no counter to wire
 * up and no bookkeeping self-probe to write, unlike the bash original.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class GateTestCase extends TestCase
{
    /**
     * This test's throwaway fixture directory, created lazily by fixture()
     * and shared across calls within the same test; null until first requested.
     */
    private ?FixtureDirectory $fixtureDirectory = null;

    /**
     * The process runner used to invoke every gate under test.
     */
    private readonly GateProcess $gateProcess;

    /**
     * Creates this test's GateProcess runner.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->gateProcess = new GateProcess();
    }

    /**
     * Removes this test's fixture directory, if one was created.
     *
     * @return void
     *
     * @throws RuntimeException If the fixture directory or a file inside it cannot be removed.
     */
    protected function tearDown(): void
    {
        $this->fixtureDirectory?->cleanup();
        $this->fixtureDirectory = null;

        parent::tearDown();
    }

    /**
     * @return FixtureDirectory This test's throwaway fixture directory, created lazily and shared across calls within one test.
     *
     * @throws RuntimeException If a fixture directory cannot be created.
     */
    protected function fixture(): FixtureDirectory
    {
        return $this->fixtureDirectory ??= new FixtureDirectory();
    }

    /**
     * The clean-verdict decision: exit 0, not degraded.
     *
     * @param list<string> $command    The interpreter and gate script.
     * @param string       $fixtureDir The directory to run the gate against.
     * @param string       $message    An optional assertion message.
     *
     * @return void
     *
     * @throws AssertionFailedError        If the gate exited non-zero or ran degraded.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected function assertGateAccepts(array $command, string $fixtureDir, string $message = ''): void
    {
        $result = $this->gateProcess->run($command, $fixtureDir);

        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(0, $result->exitCode, $message !== '' ? $message : "Expected accept, got exit {$result->exitCode}.\n{$result->output}");
    }

    /**
     * The drift-verdict decision: exit 1, not degraded, report carries $expectedSubstring.
     *
     * @param list<string> $command           The interpreter and gate script.
     * @param string       $fixtureDir        The directory to run the gate against.
     * @param string       $expectedSubstring The substring the report must carry.
     * @param string       $message           An optional assertion message.
     *
     * @return void
     *
     * @throws AssertionFailedError        If the gate did not reject for the expected reason, or ran degraded.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected function assertGateRejects(
        array $command,
        string $fixtureDir,
        string $expectedSubstring,
        string $message = '',
    ): void {
        $result = $this->gateProcess->run($command, $fixtureDir);

        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(1, $result->exitCode, $message !== '' ? $message : "Expected the drift verdict, got exit {$result->exitCode}.\n{$result->output}");
        self::assertNotSame('', $expectedSubstring, 'The must-carry argument is empty, so it would assert nothing.');
        self::assertStringContainsString(
            $expectedSubstring,
            $result->output,
            $message !== '' ? $message : "Rejected, but not for the tested reason; expected to find: {$expectedSubstring}",
        );
    }

    /**
     * The could-not-run decision: exit 2, not degraded, report carries $expectedSubstring.
     *
     * @param list<string> $command           The interpreter and gate script.
     * @param string       $fixtureDir        The directory to run the gate against.
     * @param string       $expectedSubstring The substring the report must carry.
     * @param string       $message           An optional assertion message.
     *
     * @return void
     *
     * @throws AssertionFailedError        If the gate did not refuse for the expected reason, or ran degraded.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected function assertGateUsageError(
        array $command,
        string $fixtureDir,
        string $expectedSubstring,
        string $message = '',
    ): void {
        $result = $this->gateProcess->run($command, $fixtureDir);

        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(2, $result->exitCode, $message !== '' ? $message : "Expected the usage exit, got exit {$result->exitCode}.\n{$result->output}");
        self::assertNotSame('', $expectedSubstring, 'The must-carry argument is empty, so it would assert nothing.');
        self::assertStringContainsString(
            $expectedSubstring,
            $result->output,
            $message !== '' ? $message : "Refused, but not for the tested reason; expected to find: {$expectedSubstring}",
        );
    }

    /**
     * The report-shape decision for consumer-controlled bytes: exit 1, not
     * degraded, no ESC byte, no `::`-command-at-line-start, no legacy
     * `##[…]` command, no bare CR, at most 4 non-empty lines, and — when
     * $expectedScrubbedSubstring is given — the report carries it.
     * $expectedScrubbedSubstring distinguishes "no must-carry check" (null,
     * the default) from "an explicitly empty must-carry check" ('', itself
     * a bookkeeping failure) — the same distinction the bash original's
     * `"${@:4}"` argument-count check made.
     *
     * @param list<string> $command                   The interpreter and gate script.
     * @param string       $fixtureDir                The directory to run the gate against.
     * @param string|null  $expectedScrubbedSubstring The scrubbed value the report must carry, or null to skip that check.
     * @param string       $message                   An optional assertion message.
     *
     * @return void
     *
     * @throws AssertionFailedError        If any inertness check fails, or the gate ran degraded.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected function assertGateReportIsInert(
        array $command,
        string $fixtureDir,
        ?string $expectedScrubbedSubstring = null,
        string $message = '',
    ): void {
        $result = $this->gateProcess->run($command, $fixtureDir);

        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(1, $result->exitCode, $message !== '' ? $message : "Expected the drift verdict, got exit {$result->exitCode}.\n{$result->output}");
        self::assertStringNotContainsString("\x1B", $result->output, 'An ANSI escape from a consumer value reached the report.');
        self::assertDoesNotMatchRegularExpression(
            '/^[[:space:]]*::[A-Za-z0-9_-]+/m',
            $result->output,
            'A consumer value forged a `::` workflow command.',
        );
        self::assertStringNotContainsString('##[', $result->output, 'A consumer value forged a legacy `##[…]` workflow command.');
        self::assertStringNotContainsString("\r", $result->output, 'A consumer value carried a bare carriage return, which opens a line to the runner.');

        // grep -c . counts NON-EMPTY lines — a blank line must not count toward the limit.
        $nonEmptyLines = array_filter(explode("\n", $result->output), static fn (string $line): bool => $line !== '');
        self::assertLessThanOrEqual(4, count($nonEmptyLines), 'A consumer value split the report across too many lines.');

        if ($expectedScrubbedSubstring !== null) {
            self::assertNotSame('', $expectedScrubbedSubstring, 'The must-carry argument is empty, so it would assert nothing.');
            self::assertStringContainsString(
                $expectedScrubbedSubstring,
                $result->output,
                $message !== '' ? $message : 'The scrubbed value never reached the report — inert by omission, not by scrubbing.',
            );
        }
    }

    /**
     * The "reported exactly once, as itself" decision: exit 1, not degraded,
     * exactly one `- $filePrefix:` line in the report.
     *
     * @param list<string> $command    The interpreter and gate script.
     * @param string       $fixtureDir The directory to run the gate against.
     * @param string       $filePrefix The file label expected to appear exactly once.
     * @param string       $message    An optional assertion message.
     *
     * @return void
     *
     * @throws AssertionFailedError        If the report carries zero or more than one matching line, or the gate ran degraded.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected function assertGateReportsOnce(
        array $command,
        string $fixtureDir,
        string $filePrefix,
        string $message = '',
    ): void {
        $result = $this->gateProcess->run($command, $fixtureDir);

        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(1, $result->exitCode, $message !== '' ? $message : "Expected the drift verdict, got exit {$result->exitCode}.\n{$result->output}");

        $needle = "- {$filePrefix}:";
        // grep -cF counts MATCHING LINES, not raw substring occurrences.
        $matchingLines = array_filter(
            explode("\n", $result->output),
            static fn (string $line): bool => str_contains($line, $needle),
        );

        self::assertCount(
            1,
            $matchingLines,
            $message !== '' ? $message : "Expected exactly one {$filePrefix} violation, got " . count($matchingLines) . '.',
        );
    }
}
