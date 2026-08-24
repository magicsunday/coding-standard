<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * Invokes a gate (bin/check-consumer-config.php or bin/check-js-config.mjs)
 * as a real subprocess, array argv only (never a shell string), and captures
 * stdout+stderr combined into one string in TRUE chronological arrival order —
 * matching tests/harness.sh's `2>&1` semantics. Both gates can write to stdout
 * and stderr in the same run (an accept/reject message on one stream, a PHP
 * warning or Node diagnostic concurrently on the other), so
 * Process::run()'s streaming callback is used deliberately instead of
 * getOutput().getErrorOutput() concatenation, which would silently discard
 * the real interleave order.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class GateProcess
{
    /**
     * Runs `<command...> <fixtureDir>` and returns its captured result.
     *
     * @param list<string> $command    The interpreter and gate script, e.g.
     *                                 `['php', 'bin/check-consumer-config.php']`
     *                                 or `['node', 'bin/check-js-config.mjs']`.
     * @param string       $fixtureDir The sole positional argument passed to the gate.
     *
     * @return GateResult
     *
     * @throws ProcessStartFailedException If the process could not be started.
     */
    public function run(array $command, string $fixtureDir): GateResult
    {
        $process = new Process([...$command, $fixtureDir]);
        $output  = '';

        $process->run(static function (string $type, string $buffer) use (&$output): void {
            $output .= $buffer;
        });

        return new GateResult($output, $process->getExitCode() ?? -1);
    }
}
