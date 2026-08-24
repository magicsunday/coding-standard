<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function dirname;
use function file_get_contents;
use function file_put_contents;

/**
 * Meta-tests proving GateTestCase's own five decisions are wired correctly —
 * the PHPUnit-native replacement for tests/harness.sh's
 * harness_probe_assert_shapes/harness_probe_inert_shapes, which existed only
 * because bash's manually-incremented counter needed proving; here a failed
 * assertion is a real thrown AssertionFailedError, which IS the proof.
 *
 * Every scenario drives a stub `php -r '...'` command rather than a real
 * gate — this class tests the DECISION logic, not any gate's behaviour.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversClass(GateTestCase::class)]
final class GateTestCaseTest extends GateTestCase
{
    /**
     * Verifies that assertGateAccepts passes on a clean exit 0.
     */
    #[Test]
    public function assertGateAcceptsPassesOnCleanExitZero(): void
    {
        $this->assertGateAccepts(['php', '-r', 'exit(0);'], $this->fixture()->path());
    }

    /**
     * Verifies that assertGateAccepts fails on a non-zero exit code.
     */
    #[Test]
    public function assertGateAcceptsFailsOnNonZeroExit(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateAccepts(['php', '-r', 'exit(1);'], $this->fixture()->path());
    }

    /**
     * Verifies that assertGateAccepts fails when the process ran degraded, even on exit 0.
     */
    #[Test]
    public function assertGateAcceptsFailsWhenDegraded(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateAccepts(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); exit(0);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateRejects passes on exit 1 carrying the expected substring.
     */
    #[Test]
    public function assertGateRejectsPassesOnExitOneCarryingTheSubstring(): void
    {
        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            'drift',
        );
    }

    /**
     * Verifies that assertGateRejects fails when the report never carries the expected reason.
     */
    #[Test]
    public function assertGateRejectsFailsOnTheWrongReason(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            'a substring the report never prints',
        );
    }

    /**
     * Verifies that assertGateRejects fails when the must-carry argument is an empty string.
     */
    #[Test]
    public function assertGateRejectsFailsOnAnEmptyMustCarryArgument(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            '',
        );
    }

    /**
     * Verifies that assertGateRejects fails when the process exited with the
     * usage code instead of the drift code, even though the report carries
     * the expected substring — the exit-code check must fire on its own,
     * not only as a side effect of the substring check.
     */
    #[Test]
    public function assertGateRejectsFailsOnTheUsageExitCode(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(2);'],
            $this->fixture()->path(),
            'drift',
        );
    }

    /**
     * Verifies that assertGateRejects fails when the process ran degraded, even though it exited 1 carrying the expected substring.
     */
    #[Test]
    public function assertGateRejectsFailsWhenDegraded(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            'drift',
        );
    }

    /**
     * Verifies that assertGateUsageError passes on exit 2 carrying the expected substring.
     */
    #[Test]
    public function assertGateUsageErrorPassesOnExitTwoCarryingTheSubstring(): void
    {
        $this->assertGateUsageError(
            ['php', '-r', 'fwrite(STDOUT, "  - x: refused to run\n"); exit(2);'],
            $this->fixture()->path(),
            'refused',
        );
    }

    /**
     * Verifies that assertGateUsageError fails when the process exited with the drift code instead of the usage code.
     */
    #[Test]
    public function assertGateUsageErrorFailsOnTheDriftExitCode(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateUsageError(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            'drift',
        );
    }

    /**
     * Verifies that assertGateUsageError fails when the report never carries
     * the expected reason, even though it exited 2 and is not degraded — the
     * substring check must fire on its own, not be masked by an earlier check.
     */
    #[Test]
    public function assertGateUsageErrorFailsOnTheWrongReason(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateUsageError(
            ['php', '-r', 'fwrite(STDOUT, "  - x: refused to run\n"); exit(2);'],
            $this->fixture()->path(),
            'a substring the report never prints',
        );
    }

    /**
     * Verifies that assertGateUsageError fails when the must-carry argument is an empty string.
     */
    #[Test]
    public function assertGateUsageErrorFailsOnAnEmptyMustCarryArgument(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateUsageError(
            ['php', '-r', 'fwrite(STDOUT, "  - x: refused to run\n"); exit(2);'],
            $this->fixture()->path(),
            '',
        );
    }

    /**
     * Verifies that assertGateUsageError fails when the process ran degraded, even though it exited 2 carrying the expected substring.
     */
    #[Test]
    public function assertGateUsageErrorFailsWhenDegraded(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateUsageError(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); fwrite(STDOUT, "  - x: refused to run\n"); exit(2);'],
            $this->fixture()->path(),
            'refused',
        );
    }

    /**
     * Verifies that assertGateReportsOnce passes when exactly one line matches.
     */
    #[Test]
    public function assertGateReportsOncePassesOnExactlyOneMatchingLine(): void
    {
        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - biome.json: x\n"); exit(1);'],
            $this->fixture()->path(),
            'biome.json',
        );
    }

    /**
     * Verifies that assertGateReportsOnce fails when two lines match the same file prefix.
     */
    #[Test]
    public function assertGateReportsOnceFailsOnTwoMatchingLines(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - biome.json: x\n  - biome.json: y\n"); exit(1);'],
            $this->fixture()->path(),
            'biome.json',
        );
    }

    /**
     * Verifies that assertGateReportsOnce fails when the process ran degraded, even with exactly one matching line.
     */
    #[Test]
    public function assertGateReportsOnceFailsWhenDegraded(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); fwrite(STDOUT, "  - biome.json: x\n"); exit(1);'],
            $this->fixture()->path(),
            'biome.json',
        );
    }

    /**
     * Verifies that assertGateReportsOnce fails when the process exited with
     * the wrong code, even though exactly one line matches — the exit-code
     * check must fire on its own, not only as a side effect of the count check.
     */
    #[Test]
    public function assertGateReportsOnceFailsOnTheWrongExitCode(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - biome.json: x\n"); exit(2);'],
            $this->fixture()->path(),
            'biome.json',
        );
    }

    /**
     * Verifies that assertGateReportIsInert passes on a plain, unremarkable report.
     */
    #[Test]
    public function assertGateReportIsInertPassesOnAPlainReport(): void
    {
        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: nothing wrong here\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert passes when the report genuinely carries the expected scrubbed substring.
     */
    #[Test]
    public function assertGateReportIsInertPassesWhenTheExpectedScrubbedSubstringIsPresent(): void
    {
        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: contains a scrubbed-value-xyz reference\n"); exit(1);'],
            $this->fixture()->path(),
            'scrubbed-value-xyz',
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when the expected scrubbed substring never reached the report.
     */
    #[Test]
    public function assertGateReportIsInertFailsWhenTheExpectedScrubbedSubstringIsAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: nothing to see here\n"); exit(1);'],
            $this->fixture()->path(),
            'scrubbed-value-xyz',
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when the must-carry argument is an empty string.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAnEmptyMustCarryArgument(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: nothing wrong here\n"); exit(1);'],
            $this->fixture()->path(),
            '',
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when the process ran
     * degraded, even though the report itself is otherwise clean.
     */
    #[Test]
    public function assertGateReportIsInertFailsWhenDegraded(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); fwrite(STDOUT, "  - x: nothing wrong here\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when the process exited
     * with a code other than the drift verdict, even though the report is
     * otherwise clean — the exit-code check must fire on its own.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnTheWrongExitCode(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: nothing wrong here\n"); exit(2);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a consumer value forges a legacy `##[…]` workflow command.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnALegacyWorkflowCommand(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: ##[error]forged\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a consumer value forges a `::` workflow command.
     *
     * The lead byte is a vertical tab, not a plain space — bash's `[[:space:]]`
     * admits it and this regex must too, so a narrowing back to `[ \t]` (the
     * gap this class exists to close) fails this test instead of passing it.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAForgedWorkflowCommand(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "\x0B::error::forged\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a consumer value carries a raw ANSI escape byte.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAnEscapeByte(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: \x1B[31mred\x1B[0m\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a consumer value carries a bare carriage return.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnABareCarriageReturn(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: line one\rline two\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when the report exceeds four non-empty lines.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnMoreThanFourNonEmptyLines(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "a\nb\nc\nd\ne\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert tolerates blank lines when counting the non-empty-line limit.
     */
    #[Test]
    public function assertGateReportIsInertToleratesBlankLinesWhenCountingTheLimit(): void
    {
        // grep -c . counts non-empty lines only — 3 non-empty + 3 blank must
        // still pass, proving the PHP port did not switch to a raw line count.
        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "a\n\nb\n\nc\n\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateAccepts, driven end-to-end, accepts the real
     * PHP gate against a fixture carrying the shared canonical phpunit.xml.
     */
    #[Test]
    public function assertGateAcceptsDrivesTheRealPhpGateEndToEnd(): void
    {
        // No suite migration happens in this issue — this is the one proof
        // that GateProcess really invokes a real interpreter, not a stub.
        // phpunit.xml is the sole file check-consumer-config.php declares
        // REQUIRED (verified empirically: a plain empty fixture directory is
        // rejected with "phpunit.xml: missing — the strict PHPUnit config is
        // required."), so the fixture must carry a copy of the shared
        // template for the real gate to have nothing to report on and accept.
        $repoRoot = dirname(__DIR__);
        $template = file_get_contents($repoRoot . '/templates/phpunit.xml.dist');
        self::assertNotFalse($template);

        file_put_contents($this->fixture()->path() . '/phpunit.xml.dist', $template);

        $this->assertGateAccepts(
            ['php', $repoRoot . '/bin/check-consumer-config.php'],
            $this->fixture()->path(),
        );
    }
}
