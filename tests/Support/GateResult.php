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
 * text (in true chronological arrival order, matching the bash harness's
 * `2>&1`) and the process exit code.
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
     * Neither this method's `[[:space:]]` nor the bash original's recognises a
     * stack frame led by NBSP — that gap is genuine and shared, not something
     * this port introduced. The U+2000/U+3000 block is NOT a shared gap: PCRE's
     * `[[:space:]]` never matches it, but whether grep's does is implementation-
     * and locale-dependent (measured: BusyBox grep and GNU grep under `C.UTF-8`
     * match it, GNU grep under `en_US.UTF-8` does not) — re-derive rather than
     * trust this note:
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
