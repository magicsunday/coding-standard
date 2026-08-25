<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use function preg_match;

/**
 * The captured outcome of one gate invocation: the combined stdout+stderr
 * text (in best-effort chronological arrival order — see GateProcess for the
 * guarantee's exact limit — matching the bash harness's `2>&1`) and the
 * process exit code.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class GateResult
{
    /**
     * @param string $output   The combined stdout+stderr text, in arrival order.
     * @param int    $exitCode The process exit code.
     */
    public function __construct(
        public string $output,
        public int $exitCode,
    ) {
    }

    /**
     * True when the interpreter emitted a diagnostic of its own — a PHP
     * warning, notice, parse error or fatal, or a Node stack frame / eval-mode
     * marker. Such a run produced no verdict, whatever it exited with, so no
     * caller may read it as one — ported from tests/harness.sh's degraded().
     * This method's regex carries no `u` modifier, so its `[[:space:]]` is
     * ASCII-only — it does not recognise a stack frame led by non-ASCII
     * whitespace (NBSP, the U+2000 block, U+3000). Whether the bash
     * original's `grep` recognises those bytes depends on the invocation's
     * locale and implementation and is not settled here; tests/harness.sh
     * (~lines 479-495) documents this as a known, deliberately-left-open gap
     * in its analogous `::` workflow-command check, including why closing it
     * isn't simple. Re-derive for your own actual invocation rather than
     * trust a specific verdict:
     *
     *     printf '\xe2\x80\x80at x\n' | grep -qE '^[[:space:]]+at ' && echo MATCH || echo NO-MATCH
     *
     * @return bool
     */
    public function isDegraded(): bool
    {
        return preg_match(
            '/^(PHP )?(Warning|Notice|Deprecated|Recoverable fatal error|Fatal error|Parse error|Uncaught)'
            . '|^[[:space:]]+at '
            . '|^\[eval\]:[0-9]/m',
            $this->output,
        ) === 1;
    }
}
