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
 * Tests for GateResult::isDegraded(), ported from tests/harness.sh's degraded()
 * self-probe loops (lines 135-173), which is where these exact literals came from
 * and why each one is here rather than a freshly invented example.
 */
#[CoversClass(GateResult::class)]
final class GateResultTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function degradedOutputProvider(): array
    {
        return [
            'PHP fatal error' => ['PHP Fatal error:  Uncaught Error: x'],
            'PHP recoverable fatal error' => ['PHP Recoverable fatal error:  Argument 1 passed to f()'],
            'PHP parse error' => ['PHP Parse error:  syntax error, unexpected token ";"'],
            'PHP warning' => ['Warning: Undefined array key 0 in /x on line 1'],
            'PHP notice' => ['Notice: Only variables should be passed by reference in /x on line 1'],
            'PHP deprecated' => ['Deprecated: Implicit conversion in /x on line 1'],
            'PHP uncaught type error' => ['Uncaught TypeError: f(): Argument #1 must be of type string'],
            'Node Error stack frame' => ["Uncaught Error: boom\n    at Object.<anonymous> (/x.js:1:1)"],
            'Node eval-mode marker' => ['[eval]:1'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cleanOutputProvider(): array
    {
        return [
            'a report line mentioning "Warning" mid-sentence' => ['  - phpunit.xml: Warning is not a strict flag'],
            'a report line quoting "at the start"' => ['INFO     peer: "   at the start"'],
            'a report line quoting an eval-shaped fragment' => ['INFO     peer: "[eval]:1"'],
        ];
    }

    #[Test]
    #[DataProvider('degradedOutputProvider')]
    public function isDegradedRecognisesADiagnostic(string $output): void
    {
        $result = new GateResult($output, 1);

        self::assertTrue($result->isDegraded(), $output);
    }

    #[Test]
    #[DataProvider('cleanOutputProvider')]
    public function isDegradedDoesNotMisreadOrdinaryReportText(string $output): void
    {
        $result = new GateResult($output, 1);

        self::assertFalse($result->isDegraded(), $output);
    }
}
