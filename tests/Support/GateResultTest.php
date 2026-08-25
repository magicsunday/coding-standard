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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GateResult::isDegraded(). The PHP-diagnostic fixtures in
 * degradedOutputProvider() are ported verbatim from tests/harness.sh's
 * degraded() self-probe loops (lines ~135-173 — that file is what this whole
 * migration is moving suites off of, so re-derive with
 * `grep -n "^for harness_degraded_probe\|^done$" tests/harness.sh` rather
 * than trusting the range once it shifts). The Node-related fixtures are
 * NOT a literal port: tests/harness.sh probes two of this regex's arms
 * (`^[[:space:]]+at ` and `^\[eval\]:[0-9]`) with the real, dynamic output of
 * `node -e 'throw ...'`, which is non-deterministic across Node versions, so
 * this suite instead hand-authors static fixtures targeting those two arms,
 * plus one more covering the shared "Uncaught" alternative that these two
 * probes do not exercise on the Node version this was last checked against —
 * re-derive rather than trust a specific verdict:
 *
 *     node -e 'throw new Error("boom")' 2>&1 | grep -c '^Uncaught'
 *     node -e 'throw "a bare string"' 2>&1 | grep -c '^Uncaught'
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversClass(GateResult::class)]
final class GateResultTest extends TestCase
{
    /**
     * A real diagnostic each row must trip: verbatim ports of tests/harness.sh's
     * degraded() PHP-diagnostic literals, plus hand-authored Node-diagnostic
     * fixtures — two targeting the regex arms its dynamic `node -e` probes
     * actually trip, one covering the shared "Uncaught" alternative they don't.
     *
     * @return array<string, array{0: string}>
     */
    public static function degradedOutputProvider(): array
    {
        return [
            'PHP fatal error'             => ['PHP Fatal error:  Uncaught Error: x'],
            'PHP recoverable fatal error' => ['PHP Recoverable fatal error:  Argument 1 passed to f()'],
            'PHP parse error'             => ['PHP Parse error:  syntax error, unexpected token ";"'],
            'PHP warning'                 => ['Warning: Undefined array key 0 in /x on line 1'],
            'PHP notice'                  => ['Notice: Only variables should be passed by reference in /x on line 1'],
            'PHP deprecated'              => ['Deprecated: Implicit conversion in /x on line 1'],
            'PHP uncaught type error'     => ['Uncaught TypeError: f(): Argument #1 must be of type string'],
            'Node Error stack frame'      => ["Uncaught Error: boom\n    at Object.<anonymous> (/x.js:1:1)"],
            // Vertical-tab-led and with no "Uncaught" prefix, so this trips the
            // `^[[:space:]]+at ` arm itself rather than the "Uncaught" alternative
            // above — a narrowing of that arm back to `[ \t]` fails this row.
            'Node stack frame led by a vertical tab' => ["\x0Bat Object.<anonymous> (/x.js:1:1)"],
            'Node eval-mode marker'       => ['[eval]:1'],
            // Every row above places its diagnostic at byte offset 0, so none
            // of them proves the regex's /m modifier is load-bearing — a
            // combined stdout+stderr capture can carry ordinary output
            // before the diagnostic, and only isDegraded()'s own /m
            // modifier re-anchors `^` there instead of at string-start.
            // Dropping /m would leave every other row in this provider
            // green; only a diagnostic on a later line proves it.
            'stack frame on a line after other output' => ["some normal output\n    at Object.<anonymous> (/x.js:1:1)"],
        ];
    }

    /**
     * Report text that mentions a trigger word but is not a real diagnostic.
     *
     * @return array<string, array{0: string}>
     */
    public static function cleanOutputProvider(): array
    {
        return [
            'a report line mentioning "Warning" mid-sentence' => ['  - phpunit.xml: Warning is not a strict flag'],
            'a report line quoting "at the start"'            => ['INFO     peer: "   at the start"'],
            'a report line quoting an eval-shaped fragment'   => ['INFO     peer: "[eval]:1"'],
        ];
    }

    /**
     * Asserts that a known diagnostic shape is recognised as degraded.
     */
    #[Test]
    #[DataProvider('degradedOutputProvider')]
    public function isDegradedRecognisesADiagnostic(string $output): void
    {
        $result = new GateResult($output, 1);

        self::assertTrue($result->isDegraded(), $output);
    }

    /**
     * Asserts that ordinary report text mentioning a trigger word is not misread as degraded.
     */
    #[Test]
    #[DataProvider('cleanOutputProvider')]
    public function isDegradedDoesNotMisreadOrdinaryReportText(string $output): void
    {
        $result = new GateResult($output, 1);

        self::assertFalse($result->isDegraded(), $output);
    }
}
