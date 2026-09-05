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
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function array_filter;
use function count;
use function dirname;
use function explode;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;

// scrubReportControlBytes() — the control-byte-strip + legacy-`##[`-break core
// this class's own scrubbedForDiagnostic() shares rather than duplicating; every
// concrete subclass (tests/CheckJsConfigsTest.php and
// tests/CheckJsConfigsManifestTest.php included) reuses that inherited method
// rather than requiring this file a second time.
require_once __DIR__ . '/../bin/support/safe-report-value.php';

/**
 * Base test case for every suite migrated off tests/harness.sh. Provides a
 * per-test fixture directory and the five accept/reject/usage-error/
 * report-is-inert/reports-once decisions as real PHPUnit assertions —
 * ported from tests/harness.sh's harness_decide_* functions. A failed
 * decision throws a real AssertionFailedError; there is no counter to wire
 * up and no bookkeeping self-probe to write, unlike the bash original.
 *
 * Deliberately named without the house `Abstract*` prefix: it extends and
 * plays the same role as PHPUnit's own TestCase (installed under
 * .build/vendor/phpunit/phpunit, itself abstract) — an abstract base every
 * concrete test class extends, without that prefix either.
 *
 * assertGateReportIsInert() and assertReportCarries() below drive their
 * containment/regex checks against $result->output by hand
 * (str_contains()/preg_match() + self::fail()), never
 * assertStringContainsString()/assertStringNotContainsString()/
 * assertDoesNotMatchRegularExpression(): $result->output is exactly the
 * value under test for a forged workflow command, and a concrete test class
 * extending this one — confirmed for CheckConsumerConfigTest, via genuinely
 * poisoned fixtures such as a `##[error]forged` devDependency name; re-derive
 * the current full set with `grep -rl "extends GateTestCase" tests/` rather
 * than trusting this list to stay exhaustive — can and does hand it
 * deliberately-poisoned content. PHPUnit's own
 * Constraint::fail()/failureDescription() mechanism — dated and detailed in
 * tests/CheckJsConfigsTest.php's own
 * assertMessageDoesNotForgeWorkflowCommand() docblock, not repeated here —
 * unconditionally re-embeds the FULL, RAW
 * haystack into a failed assertion's own exception message, so a real
 * failure of one of those PHPUnit constraints here would forge, in PHPUnit's
 * own failure output, the very annotation these two methods exist to prove
 * is prevented.
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
     * The process runner used to invoke every gate under test, created lazily by
     * gateProcess() and shared across calls within the same test; null until
     * first requested.
     */
    private ?GateProcess $gateProcess = null;

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
     * @return GateProcess This test's process runner, created lazily and shared across calls within one test.
     */
    private function gateProcess(): GateProcess
    {
        return $this->gateProcess ??= new GateProcess();
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
        $this->runAndAssertVerdict($command, $fixtureDir, 0, 'accept', $message);
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
        $result = $this->runAndAssertDriftVerdict($command, $fixtureDir, $message);

        $this->assertReportCarries(
            $result,
            $expectedSubstring,
            $message,
            "Rejected, but not for the tested reason; expected to find: {$expectedSubstring}",
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
        $result = $this->runAndAssertVerdict($command, $fixtureDir, 2, 'the usage exit', $message);

        $this->assertReportCarries(
            $result,
            $expectedSubstring,
            $message,
            "Refused, but not for the tested reason; expected to find: {$expectedSubstring}",
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
        $result = $this->runAndAssertDriftVerdict($command, $fixtureDir, $message);

        if (str_contains($result->output, "\x1B")) {
            self::fail(self::diagnosticMessage('An ANSI escape from a consumer value reached the report.', $result->output));
        }

        // This regex carries no `u` modifier, so a lead byte outside ASCII
        // whitespace is not admitted here either — the same known,
        // deliberately-left-open gap tests/harness.sh documents for its
        // analogous `::` check (lines ~479-495). See GateResult::isDegraded()'s
        // docblock for the re-derivation command.
        if (preg_match('/^[[:space:]]*::[A-Za-z0-9_-]+/m', $result->output) === 1) {
            self::fail(self::diagnosticMessage('A consumer value forged a `::` workflow command.', $result->output));
        }

        if (str_contains($result->output, '##[')) {
            self::fail(self::diagnosticMessage('A consumer value forged the legacy workflow-command prefix.', $result->output));
        }

        if (str_contains($result->output, "\r")) {
            self::fail(self::diagnosticMessage('A consumer value carried a bare carriage return, which opens a line to the runner.', $result->output));
        }

        // grep -c . counts NON-EMPTY lines — a blank line must not count toward the limit.
        $nonEmptyLines = array_filter(explode("\n", $result->output), static fn (string $line): bool => $line !== '');
        self::assertLessThanOrEqual(4, count($nonEmptyLines), 'A consumer value split the report across too many lines.');

        if ($expectedScrubbedSubstring !== null) {
            $this->assertReportCarries(
                $result,
                $expectedScrubbedSubstring,
                $message,
                'The scrubbed value never reached the report — inert by omission, not by scrubbing.',
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
        $result = $this->runAndAssertDriftVerdict($command, $fixtureDir, $message);

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

    /**
     * Runs the gate and asserts the exitCode/isDegraded precondition every
     * decision starts from, returning the result for the caller's own
     * remaining checks. Shared by all five assertGate* decisions so a change
     * to this precondition is made once, not five times.
     *
     * @param list<string> $command          The interpreter and gate script.
     * @param string       $fixtureDir       The directory to run the gate against.
     * @param int          $expectedExitCode The exit code this decision expects.
     * @param string       $exitCodeLabel    Describes the expected verdict, for the default exit-code message.
     * @param string       $message          An optional assertion message shared by both checks.
     *
     * @return GateResult The captured run, for the caller's remaining checks.
     *
     * @throws AssertionFailedError        If the gate ran degraded or exited unexpectedly.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    private function runAndAssertVerdict(
        array $command,
        string $fixtureDir,
        int $expectedExitCode,
        string $exitCodeLabel,
        string $message,
    ): GateResult {
        $result = $this->gateProcess()->run($command, $fixtureDir);

        // A real, unconditional assertion, unlike the exit-code check below:
        // isDegraded() reduces $result->output to a plain bool, and neither
        // this call's default message nor PHPUnit's own auto-generated
        // failure description for a boolean comparison ever re-embeds the
        // raw output, so there is nothing here for a poisoned fixture to
        // forge through.
        self::assertFalse($result->isDegraded(), $message !== '' ? $message : 'The gate ran degraded — it emitted a diagnostic.');

        if ($result->exitCode !== $expectedExitCode) {
            self::fail(
                ($message !== '' ? $message : "Expected {$exitCodeLabel}, got exit {$result->exitCode}.")
                . "\n" . self::scrubbedForDiagnostic($result->output),
            );
        }

        return $result;
    }

    /**
     * The drift-verdict shape shared by assertGateRejects(),
     * assertGateReportIsInert() and assertGateReportsOnce(): exit 1, not
     * degraded. Names the (1, 'the drift verdict') pair once instead of
     * repeating it at all three call sites.
     *
     * @param list<string> $command    The interpreter and gate script.
     * @param string       $fixtureDir The directory to run the gate against.
     * @param string       $message    An optional assertion message.
     *
     * @return GateResult The captured run, for the caller's remaining checks.
     *
     * @throws AssertionFailedError        If the gate ran degraded or did not exit 1.
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    private function runAndAssertDriftVerdict(array $command, string $fixtureDir, string $message): GateResult
    {
        return $this->runAndAssertVerdict($command, $fixtureDir, 1, 'the drift verdict', $message);
    }

    /**
     * Guards against a must-carry argument that is empty — an empty needle
     * would make the subsequent containment check assert nothing — then
     * asserts the report carries it. Shared by every assertGate* decision
     * that has a must-carry substring, so the guard-then-assert pairing is
     * made once, not at every call site.
     *
     * The containment check itself is a manual str_contains() + self::fail(),
     * never assertStringContainsString() — see this class's own docblock
     * above for why: $result->output is exactly the value a forged-workflow-
     * command fixture poisons, and CheckConsumerConfigTest calls this
     * (transitively, via assertGateRejects()/assertGateUsageError()/
     * assertGateReportIsInert()) with genuinely poisoned $expectedSubstring
     * values.
     *
     * @param GateResult $result            The captured run to check.
     * @param string     $expectedSubstring The substring the report must carry.
     * @param string     $message           An optional assertion message; used as the failure prefix when non-empty, followed either way by the scrubbed report content.
     * @param string     $defaultMessage    The message used when $message is empty.
     *
     * @return void
     *
     * @throws AssertionFailedError If $expectedSubstring is empty, or the report does not carry it.
     */
    private function assertReportCarries(
        GateResult $result,
        string $expectedSubstring,
        string $message,
        string $defaultMessage,
    ): void {
        self::assertNotSame('', $expectedSubstring, 'The must-carry argument is empty, so it would assert nothing.');

        if (str_contains($result->output, $expectedSubstring)) {
            return;
        }

        self::fail(($message !== '' ? $message : $defaultMessage) . "\n" . self::scrubbedForDiagnostic($result->output));
    }

    /**
     * Reduces $value to something safe to embed in a self::fail() diagnostic
     * when $value may itself be exactly the forged CI annotation the calling
     * assertion exists to catch — see this class's own docblock above for
     * why assertGateReportIsInert() and assertReportCarries() need this
     * rather than a PHPUnit string-containment/regex constraint. Shares
     * scrubReportControlBytes()'s control-byte strip and legacy `##[` break
     * (bin/support/safe-report-value.php, required near the top of this
     * file), then additionally breaks every `::` occurrence for the same
     * reason: scrubReportControlBytes() deliberately leaves `::` alone (a
     * namespaced identifier is legitimate report content), but every
     * diagnostic this method feeds places $value directly after a literal
     * `\n`, i.e. at true column 0 of a new line — exactly the placement a
     * `::cmd::` workflow command needs.
     *
     * `protected`, not `private`: both tests/CheckJsConfigsManifestTest.php's
     * own assertManifestRejects() and tests/CheckJsConfigsTest.php extend this
     * class and reuse this one method directly for the identical scrub,
     * rather than each growing its own private copy — CheckJsConfigsTest.php
     * carried such a copy (safeSubprocessOutput()) before it was recognised
     * as a byte-for-byte duplicate of this method and deleted.
     *
     * @param string $value The raw value to scrub before embedding in a self::fail() message.
     *
     * @return string The value scrubbed per scrubReportControlBytes(), with every `::` occurrence that opens a line broken.
     */
    protected static function scrubbedForDiagnostic(string $value): string
    {
        return str_replace('::', ':?:', scrubReportControlBytes($value));
    }

    /**
     * Composes a self::fail()-ready diagnostic message: $label followed by a
     * newline and $output scrubbed through self::scrubbedForDiagnostic().
     * Collapses the `<label> . "\n" . self::scrubbedForDiagnostic($output)`
     * shape repeated at nearly every PR-editable-content diagnostic in this
     * class and its subclasses into one call, rather than each site pairing
     * the newline and the scrub call by hand.
     *
     * @param string $label  The failure label, used verbatim.
     * @param string $output The raw value to scrub before appending.
     *
     * @return string $label, a newline, then $output scrubbed.
     */
    protected static function diagnosticMessage(string $label, string $output): string
    {
        return $label . "\n" . self::scrubbedForDiagnostic($output);
    }

    /**
     * Resolves an optional caller-supplied assertion $message against a
     * scrubbed default: $message verbatim when non-empty, otherwise
     * diagnosticMessage()'s $default label followed by $output scrubbed.
     * Collapses the
     * `$message !== '' ? $message : <default> . "\n" . self::scrubbedForDiagnostic($output)`
     * shape repeated at every optional-message call site in this class and
     * its subclasses into one call. Not a fit for a call site where the
     * scrubbed output must be appended regardless of whether $message is
     * empty — that is a different shape and stays hand-written.
     *
     * @param string $message The caller-supplied message, used verbatim when non-empty.
     * @param string $default The failure label used when $message is empty.
     * @param string $output  The raw value to scrub before appending, when $message is empty.
     *
     * @return string $message verbatim, or $default plus $output scrubbed when $message is empty.
     */
    protected static function messageOrDefault(string $message, string $default, string $output): string
    {
        return $message !== '' ? $message : self::diagnosticMessage($default, $output);
    }

    /**
     * Builds a JSON document of EXACTLY $bound bytes: $body's closing brace
     * is replaced with a padding key, so the document stays valid JSON and
     * every other key in $body survives untouched for the gate under test to
     * inspect. Ported from tests/harness.sh's harness_pad_json_to_cap();
     * shared here once a second caller (CheckConsumerConfigTest, #78) needed
     * it — tests/CheckVersionLockstepTest.php's own copy (#80) predates that
     * and carried a private, non-shared reimplementation because it was the
     * only caller at the time.
     *
     * @param int    $bound The exact byte length the returned document must have.
     * @param string $body  A valid JSON object document ending in `}`.
     *
     * @return string The padded JSON document, exactly $bound bytes.
     */
    protected static function padJsonToCap(int $bound, string $body): string
    {
        $pad = $bound - strlen($body) - 8;
        $out = substr($body, 0, -1) . ',"//":"' . str_repeat('p', $pad) . '"}';

        self::assertSame($bound, strlen($out), sprintf('fixture is %d bytes, not the cap of %d', strlen($out), $bound));

        return $out;
    }

    /**
     * @return string Absolute path to the repository root.
     */
    protected static function root(): string
    {
        return dirname(__DIR__);
    }
}
