<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;
use function file_put_contents;
use function implode;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function substr_count;

/**
 * A structural, grep-shaped regression guard for the defect class GH-79's
 * round 11 fixed: a PHPUnit assertion whose own call — subject/actual
 * argument OR a hand-written custom message — carries a raw
 * `$result->output`/`->getOutput()`/`->getErrorOutput()` access, run against
 * PR-editable content (this repository's own biome/base.json,
 * tsconfig/base.json, package.json, templates/jscpd.json, or subprocess
 * output produced against them). PHPUnit's own
 * Constraint::fail()/failureDescription() mechanism unconditionally
 * re-embeds the RAW subject/actual operand of a failed
 * assertStringContainsString()/assertStringNotContainsString()/
 * assertMatchesRegularExpression()/assertDoesNotMatchRegularExpression()/
 * assertSame()/assertEquals() into the thrown exception's own message —
 * wrapping only a custom $message in self::scrubbedForDiagnostic() does NOT
 * suppress that; see tests/CheckJsConfigsTest.php's own
 * assertMessageDoesNotForgeWorkflowCommand() docblock for the dated
 * observation against the real installed PHPUnit, not repeated here.
 *
 * This is a BEST-EFFORT static grep-shaped guard, not a real PHP parser —
 * documented limitations:
 * - It balances parentheses to find each call's own argument list, but does
 *   NOT tokenize string literals, so a literal `(` or `)` inside a quoted
 *   argument can mis-balance a call's own extent.
 * - It recognises exactly three "sanctioned wrap" call names
 *   (scrubbedForDiagnostic/diagnosticMessage/messageOrDefault) by their bare
 *   name, not by resolving `self::`/`GateTestCase::`/an inherited call to the
 *   same method — a differently-named future helper wrapping the identical
 *   scrub would need adding to self::SAFE_WRAP_CALLS below, or this guard
 *   would false-positive on it.
 * - self::fail() call sites (the manual `if (...) { self::fail(...) }`
 *   shape every fix in this round converged on) are deliberately OUT OF
 *   SCOPE: self::fail() takes a single literal string with no re-export
 *   mechanism, so a raw value reaching it is a DIFFERENT, already-covered
 *   concern (the message itself must be built with the scrub wrap, which is
 *   a per-call-site fix, not a PHPUnit-mechanism leak this guard exists to
 *   catch).
 *
 * A determined future edit can still dodge this guard (e.g. reassigning
 * $result->output to a local variable first, then passing that variable) —
 * it catches the shape every real incident in this file's history actually
 * took, not every conceivable rephrasing of it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class ScrubbedDiagnosticGuardTest extends GateTestCase
{
    /**
     * The PHPUnit assertion functions whose own subject/actual argument
     * PHPUnit's Constraint::fail()/failureDescription() mechanism
     * unconditionally re-embeds raw into a failed assertion's own message.
     */
    private const RISKY_ASSERTIONS = [
        'assertStringContainsString',
        'assertStringNotContainsString',
        'assertMatchesRegularExpression',
        'assertDoesNotMatchRegularExpression',
        'assertSame',
        'assertEquals',
    ];

    /**
     * The call names this guard accepts as already having scrubbed whatever
     * they wrap — see this class's own docblock for why this is a fixed,
     * unresolved name list rather than true call-graph resolution.
     */
    private const SAFE_WRAP_CALLS = [
        'scrubbedForDiagnostic',
        'diagnosticMessage',
        'messageOrDefault',
    ];

    /**
     * The pattern a raw, unscrubbed subprocess-output accessor takes in this
     * codebase: a property access (`->output`) or one of the two Process
     * accessor methods.
     */
    private const RAW_OUTPUT_PATTERN = '/->output\b|->getOutput\s*\(|->getErrorOutput\s*\(/';

    /**
     * Every failed accept/reject-pattern regression this file's history
     * fixed lived in exactly these three files — see GH-79's round 11
     * findings. A new file added to this suite that repeats the same
     * biomeCi()/runTsc()-against-PR-editable-config shape would need adding
     * here too; this guard only reads what it is told to.
     *
     * @return list<string>
     */
    private static function guardedFiles(): array
    {
        $root = self::root();

        return [
            "{$root}/tests/GateTestCase.php",
            "{$root}/tests/CheckJsConfigsTest.php",
            "{$root}/tests/CheckJsConfigsManifestTest.php",
        ];
    }

    /**
     * Finds the position right after the closing parenthesis balancing the
     * one at $openParenPos, and the text strictly between them. Does not
     * understand string literals — a literal `(`/`)` inside a quoted
     * argument can mis-balance the extent this returns; see this class's
     * own docblock.
     *
     * @param string $text         The full text to scan.
     * @param int    $openParenPos The offset of the opening `(` in $text.
     *
     * @return array{0: string, 1: int} The text strictly inside the balanced parens, and the offset right after the closing `)`.
     */
    private static function extractBalancedCall(string $text, int $openParenPos): array
    {
        $depth = 1;
        $i     = $openParenPos + 1;
        $len   = strlen($text);

        while (($i < $len) && ($depth > 0)) {
            if ($text[$i] === '(') {
                ++$depth;
            } elseif ($text[$i] === ')') {
                --$depth;
            }

            ++$i;
        }

        return [substr($text, $openParenPos + 1, $i - $openParenPos - 2), $i];
    }

    /**
     * Removes every balanced `$funcName(...)` call from $text, including any
     * parens nested inside it, leaving the rest of $text untouched. Used to
     * strip every sanctioned scrub wrap from a risky assertion's own
     * argument list before checking what remains for a raw output access.
     *
     * @param string $text     The text to strip $funcName(...) calls from.
     * @param string $funcName The bare call name to strip (no `self::` prefix — see this class's own docblock).
     *
     * @return string $text with every balanced $funcName(...) call removed.
     */
    private static function stripBalancedCalls(string $text, string $funcName): string
    {
        $needle = "{$funcName}(";
        $out    = '';
        $pos    = 0;

        while (($start = strpos($text, $needle, $pos)) !== false) {
            $out .= substr($text, $pos, $start - $pos);
            [, $endPos] = self::extractBalancedCall($text, $start + strlen($funcName));
            $pos        = $endPos;
        }

        return $out . substr($text, $pos);
    }

    /**
     * Scans $path for every call to one of self::RISKY_ASSERTIONS and, for
     * each one, strips every self::SAFE_WRAP_CALLS wrap from its own
     * argument list — the message argument included, since a hand-written
     * custom message embedding raw output unscrubbed is the SAME defect
     * class this round's Fix 4/Fix 5 closed, not merely the PHPUnit
     * auto-export mechanism. Whatever still matches self::RAW_OUTPUT_PATTERN
     * afterwards is a finding.
     *
     * @param string $path Absolute path to the PHP source file to scan.
     *
     * @return list<string> One description per finding, empty when none.
     */
    private static function findUnscrubbedRawOutputAssertions(string $path): array
    {
        $source   = (string) file_get_contents($path);
        $findings = [];

        foreach (self::RISKY_ASSERTIONS as $assertionName) {
            $offset = 0;
            $needle = "{$assertionName}(";

            while (($pos = strpos($source, $needle, $offset)) !== false) {
                $parenStart          = $pos + strlen($assertionName);
                [$argsText, $endPos] = self::extractBalancedCall($source, $parenStart);
                $offset              = $endPos;

                $stripped = $argsText;

                foreach (self::SAFE_WRAP_CALLS as $wrap) {
                    $stripped = self::stripBalancedCalls($stripped, $wrap);
                }

                if (preg_match(self::RAW_OUTPUT_PATTERN, $stripped) === 1) {
                    $line       = substr_count(substr($source, 0, $pos), "\n") + 1;
                    $findings[] = "{$path}:{$line}: {$assertionName}({$argsText})";
                }
            }
        }

        return $findings;
    }

    /**
     * The regression guard itself: after GH-79's round 11 fixes, none of the
     * three files this class's own docblock names may call one of
     * self::RISKY_ASSERTIONS with a raw, unscrubbed subprocess-output
     * accessor anywhere in its own argument list. A future call site that
     * reintroduces the shape (rather than following the
     * self::scrubbedForDiagnostic()/diagnosticMessage()/messageOrDefault()
     * pattern, or the manual `if (...) { self::fail(...) }` shape this
     * guard deliberately does not police — see this class's own docblock
     * for why) fails this test instead of shipping silently.
     */
    #[Test]
    public function noRiskyAssertionCarriesUnscrubbedSubprocessOutput(): void
    {
        $findings = [];

        foreach (self::guardedFiles() as $file) {
            $findings = [...$findings, ...self::findUnscrubbedRawOutputAssertions($file)];
        }

        self::assertSame(
            [],
            $findings,
            "A PHPUnit assertion's own argument list carries a raw, unscrubbed "
                . "subprocess-output accessor (see this class's own docblock for the "
                . "defect class this guards against):\n" . implode("\n", $findings),
        );
    }

    /**
     * The guard's own control: without it, an intentionally reintroduced raw
     * `assertSame(0, $result->exitCode, "…\n{$result->output}")`-shaped call
     * embedded in a throwaway fixture string (never written to a real file,
     * so the actual suite's own content is untouched) would go undetected —
     * proving self::findUnscrubbedRawOutputAssertions() actually discriminates
     * rather than always returning an empty list regardless of input.
     */
    #[Test]
    public function detectsAnIntentionallyReintroducedRawOutputAssertion(): void
    {
        $dir  = $this->fixture()->path();
        $path = "{$dir}/poisoned-fixture.php";

        file_put_contents(
            $path,
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, "boom\n{$result->output}");
            PHP,
        );

        $findings = self::findUnscrubbedRawOutputAssertions($path);

        self::assertNotEmpty($findings, 'The guard did not flag a deliberately unscrubbed assertSame() call — it is not exercising the check it claims to.');
    }

    /**
     * The guard's own second control, for the sanctioned wrap itself: a call
     * whose only `->output` access is inside a
     * self::scrubbedForDiagnostic()/diagnosticMessage()/messageOrDefault()
     * wrap must NOT be flagged — without this, a guard that flagged
     * anything merely mentioning `->output` (rather than an UNWRAPPED one)
     * would fail every legitimate call site in this suite.
     */
    #[Test]
    public function doesNotFlagAProperlyScrubbedAssertion(): void
    {
        $dir  = $this->fixture()->path();
        $path = "{$dir}/clean-fixture.php";

        file_put_contents(
            $path,
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, self::diagnosticMessage('label', $result->output));
            PHP,
        );

        $findings = self::findUnscrubbedRawOutputAssertions($path);

        self::assertSame([], $findings, 'The guard flagged a call whose only ->output access is inside a sanctioned scrub wrap.');
    }
}
