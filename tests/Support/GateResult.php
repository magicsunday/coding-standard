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
     * `[[:space:]]` never matches it, but real grep does — BusyBox (the
     * buildbox shell) and GNU grep (the CI runner's shell) both match it under
     * every locale tested (`C.UTF-8`, `en_US.UTF-8`, default), so this is a
     * genuine narrowing this port introduces relative to the bash original's
     * real behaviour, not an inherited one. Re-derive rather than trust this
     * note — and in a container, never on this host, whose own grep (ugrep)
     * is Unicode-aware in a way neither of the two environments above are
     * guaranteed to match:
     *
     *     printf '\xe2\x80\x80at x\n' | grep -qE '^[[:space:]]+at ' && echo MATCH || echo NO-MATCH
     *
     * See tests/harness.sh (~lines 479-495) for the bash side's own fuller
     * discussion of the analogous, deliberately-left-open gap in its `::`
     * workflow-command check.
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
