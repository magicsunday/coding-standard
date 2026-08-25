<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;

/**
 * Tests for GateProcess::run(), verifying exit-code capture, stdout capture,
 * the fixture-directory positional argument contract and best-effort
 * chronological stdout/stderr interleaving.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversClass(GateProcess::class)]
#[UsesClass(GateResult::class)]
final class GateProcessTest extends TestCase
{
    /**
     * Asserts that the child process's exit code is captured unchanged.
     */
    #[Test]
    public function runCapturesExitCode(): void
    {
        $process = new GateProcess();
        $result  = $process->run(['php', '-r', 'exit(7);'], sys_get_temp_dir());

        self::assertSame(7, $result->exitCode);
    }

    /**
     * Asserts that text written to the child's stdout appears in the captured output.
     */
    #[Test]
    public function runCapturesStdout(): void
    {
        $process = new GateProcess();
        $result  = $process->run(['php', '-r', 'fwrite(STDOUT, "hello\n");'], sys_get_temp_dir());

        self::assertStringContainsString('hello', $result->output);
    }

    /**
     * Asserts that the fixture directory is passed as the sole positional argument.
     */
    #[Test]
    public function runPassesTheFixtureDirectoryAsTheSolePositionalArgument(): void
    {
        $process = new GateProcess();
        // $argv[0] is the script name (`Standard input code` for `php -r`); the
        // fixture directory must land at $argv[1] — verifying this is verifying
        // the contract every migrated suite's assertGate*() call depends on.
        $result = $process->run(['php', '-r', 'fwrite(STDOUT, $argv[1]);'], '/a/fixture/dir');

        self::assertSame('/a/fixture/dir', $result->output);
    }

    /**
     * Asserts that stdout and stderr writes on the same run are captured in true arrival order.
     */
    #[Test]
    public function runInterleavesStdoutAndStderrInTrueArrivalOrder(): void
    {
        $process = new GateProcess();
        // Deliberately interleaved so a naive getOutput().getErrorOutput()
        // concatenation (stdout-then-stderr, regardless of real timing) would
        // produce "ACB" while the true arrival order — what this asserts —
        // produces "ABC" only if the streaming callback is genuinely used.
        // Both writes are on the SAME call, which is what makes a REGRESSION
        // to that naive concatenation reliably caught — not a claim that this
        // test itself can never flake: see GateProcess's own docblock for why
        // the ordering guarantee is best-effort, not absolute. The usleep()
        // gaps are load-bearing, not decorative: they move each write into
        // its own poll cycle, which is what actually exercises the streaming
        // callback's ordering rather than the reader's fixed pipe-check order
        // (verified in this repo's buildbox: a zero-delay version of this
        // script reordered to "ACB" in the majority of 20 trials).
        $script = 'fwrite(STDOUT, "A"); usleep(5000); fwrite(STDERR, "B"); usleep(5000); fwrite(STDOUT, "C");';
        $result = $process->run(['php', '-r', $script], sys_get_temp_dir());

        self::assertSame('ABC', $result->output);
    }
}
