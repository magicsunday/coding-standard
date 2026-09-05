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

use function array_slice;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function token_get_all;

use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_ENCAPSED_AND_WHITESPACE;

/**
 * A structural, grep-shaped regression guard against a PHPUnit assertion
 * whose own call — subject/actual argument OR a hand-written custom
 * message — carries a raw
 * `$result->output`/`->getOutput()`/`->getErrorOutput()` access, run against
 * PR-editable content (this repository's own biome/base.json,
 * tsconfig/base.json, package.json, templates/jscpd.json, or subprocess
 * output produced against them). All six of self::RISKY_ASSERTIONS leak the
 * raw subject/actual operand on a failure, but through TWO DIFFERENT
 * PHPUnit mechanisms, and wrapping only a custom $message in
 * self::scrubbedForDiagnostic() suppresses neither: the first four embed
 * the raw operand straight into getMessage(); assertSame()/assertEquals()
 * on two STRING operands instead attach a
 * SebastianBergmann\Comparator\ComparisonFailure that only PHPUnit's own
 * CLI/text printer renders, never getMessage() — EXCEPT a
 * TYPE-MISMATCHED comparison (e.g. one operand `null`), which reaches
 * getMessage() by a third path instead. Both dated observations, their
 * re-derivation commands, and that exception live in
 * tests/CheckJsConfigsTest.php's own assertMessageDoesNotForgeWorkflowCommand()
 * and readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand()
 * docblocks respectively, not repeated here. Every real
 * assertSame()/assertEquals() call site self::RISKY_ASSERTIONS scans for in
 * this codebase compares same-typed (string) operands, so this guard's own
 * scope does not currently need to police that third path — but a future
 * `assertSame($stringOrNull, $poisonedString)`-shaped call would need the
 * same manual self::fail() treatment even though it builds no
 * ComparisonFailure.
 *
 * This is a BEST-EFFORT static grep-shaped guard, not a real PHP parser —
 * documented limitations:
 * - It balances parentheses via self::stringLiteralMask() (built once per
 *   scan from token_get_all(), the same primitive self::stripComments()
 *   already relies on) so a `(`/`)` character inside a T_CONSTANT_ENCAPSED_STRING
 *   or T_ENCAPSED_AND_WHITESPACE token never affects a call's own depth
 *   count — only a real structural paren token does. Scoped to those two
 *   token kinds: a heredoc/nowdoc body is not masked, but no risky-assertion
 *   or sanctioned-wrap argument in this codebase's history has used one.
 * - It recognises exactly four "sanctioned wrap" call names
 *   (scrubbedForDiagnostic/diagnosticMessage/messageOrDefault/messageWithOutput)
 *   by their bare name, not by resolving `self::`/`GateTestCase::`/an inherited call to the
 *   same method — a differently-named future helper wrapping the identical
 *   scrub would need adding to self::SAFE_WRAP_CALLS below, or this guard
 *   would false-positive on it.
 * - self::fail() call sites (the manual `if (...) { self::fail(...) }`
 *   shape the rest of this suite uses instead of a risky assertion) are
 *   deliberately OUT OF SCOPE: self::fail() takes a single literal string
 *   with no re-export mechanism, so a raw value reaching it is a DIFFERENT,
 *   already-covered concern (the message itself must be built with the
 *   scrub wrap, which is a per-call-site fix, not a PHPUnit-mechanism leak
 *   this guard exists to catch).
 * - self::RAW_OUTPUT_PATTERN also flags a regex-capture variable
 *   (`$matches[`) and an array-key access shaped like subprocess output
 *   (`['stdout']`/`['stderr']`), on top of the direct `->output`/
 *   `->getOutput()`/`->getErrorOutput()` accessors — but it still matches by
 *   fixed literal shape, not real data-flow, so a raw value reaching a risky
 *   assertion through a differently-named variable or a deeper array/object
 *   path is not detected.
 * - Source is scanned with every T_COMMENT/T_DOC_COMMENT token blanked out
 *   first (self::stripComments()), so a comment or docblock merely quoting a
 *   risky-assertion call as illustrative prose is not flagged; a call
 *   embedded inside a string literal (e.g. a heredoc fixture, as
 *   detectsAnIntentionallyReintroducedRawOutputAssertion() below builds) is
 *   still real PHP source to the tokenizer either way and is scanned
 *   normally.
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
     * leaks raw on a failure — via failureDescription() into getMessage()
     * for the first four, via a raw ComparisonFailure PHPUnit's CLI/text
     * printer renders (never getMessage(), for two string operands — see
     * this class's own docblock above for the type-mismatch exception) for
     * the last two; see this class's own docblock above for the distinction.
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
        'messageWithOutput',
    ];

    /**
     * The shapes a raw, unscrubbed value under test takes in this codebase:
     * a `$result->output` property access, one of the two Process accessor
     * methods (`->getOutput()`/`->getErrorOutput()`), a regex-capture
     * variable (`$matches[`), or an array-key access shaped like captured
     * subprocess output (`['stdout']`/`['stderr']`, the shape
     * runBuildToolsSeparated()'s own callers use in this file).
     */
    private const RAW_OUTPUT_PATTERN = '/->output\b|->getOutput\s*\(|->getErrorOutput\s*\(|\$matches\[|\[\'stdout\'\]|\[\'stderr\'\]/';

    /**
     * Every failed accept/reject-pattern regression this file's history
     * fixed lived in exactly the files returned below. A new file added to
     * this suite that repeats the same biomeCi()/runTsc()-against-
     * PR-editable-config shape would need adding here too; this guard only
     * reads what it is told to.
     *
     * tests/Support/GateProcessTest.php's runCapturesStdout() (a plain
     * `self::assertStringContainsString('hello', $result->output)`) and
     * tests/GateTestCaseTest.php's own
     * theMessageCompositionHelpersComposeAsDocumented() (several assertSame()
     * calls against a hand-authored literal carrying `::error::`) share the
     * RISKY_ASSERTIONS shape this guard scans for, yet are deliberately left
     * out of the list below: both fixtures are author-controlled literals a
     * PR can never influence, not PR-editable content, so routing them
     * through the scrub helpers would be unnecessary churn rather than
     * closing a real gap.
     *
     * `tests/CheckCheckedExceptionsTest.php` and
     * `tests/CheckDisallowedCallsTest.php` are peer gate-suite classes
     * (AGENTS.md documents both) that DO carry the same unscrubbed-leak
     * shape today; they are deliberately NOT added below and NOT fixed as
     * part of this guard — that defect is tracked separately as #160.
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
     * Builds a byte-indexed mask over $source, one entry per byte offset,
     * true wherever that offset falls inside a T_CONSTANT_ENCAPSED_STRING or
     * T_ENCAPSED_AND_WHITESPACE token. self::extractBalancedCall() consults
     * this so a `(`/`)` character embedded in a string argument's own text
     * never affects a balanced call's depth count — only a real structural
     * paren token does; see this class's own docblock for the incident this
     * closed and the two token kinds this is scoped to.
     *
     * @param string $source The PHP source to build the mask for.
     *
     * @return list<bool> One entry per byte offset in $source; true means "inside a string-literal token".
     */
    private static function stringLiteralMask(string $source): array
    {
        $mask   = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $mask[$offset] = false;
                ++$offset;

                continue;
            }

            [$id, $text]  = $token;
            $isStringPart = ($id === T_CONSTANT_ENCAPSED_STRING) || ($id === T_ENCAPSED_AND_WHITESPACE);

            for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
                $mask[$offset] = $isStringPart;
                ++$offset;
            }
        }

        return $mask;
    }

    /**
     * Finds the position right after the closing parenthesis balancing the
     * one at $openParenPos, and the text strictly between them. Depth-counts
     * only structural `(`/`)` characters: any offset $mask marks as inside a
     * string-literal token is skipped, so a literal `(`/`)` inside a quoted
     * argument can no longer mis-balance the extent this returns — see this
     * class's own docblock for the incident this closed.
     *
     * @param string     $text         The full text to scan.
     * @param int        $openParenPos The offset of the opening `(` in $text.
     * @param list<bool> $mask         self::stringLiteralMask()'s output, aligned 1:1 with $text by byte offset.
     *
     * @return array{0: string, 1: int} The text strictly inside the balanced parens, and the offset right after the closing `)`.
     */
    private static function extractBalancedCall(string $text, int $openParenPos, array $mask): array
    {
        $depth = 1;
        $i     = $openParenPos + 1;
        $len   = strlen($text);

        while (($i < $len) && ($depth > 0)) {
            if (($mask[$i] ?? false) === false) {
                if ($text[$i] === '(') {
                    ++$depth;
                } elseif ($text[$i] === ')') {
                    --$depth;
                }
            }

            ++$i;
        }

        return [substr($text, $openParenPos + 1, $i - $openParenPos - 2), $i];
    }

    /**
     * Removes every balanced `$funcName(...)` call from $text, including any
     * parens nested inside it, leaving the rest of $text untouched, and
     * returns $mask realigned to the shortened result so a caller can keep
     * stripping further wrap calls without losing string-literal awareness.
     * Used to strip every sanctioned scrub wrap from a risky assertion's own
     * argument list before checking what remains for a raw output access.
     *
     * @param string     $text     The text to strip $funcName(...) calls from.
     * @param list<bool> $mask     self::stringLiteralMask()'s output, aligned 1:1 with $text by byte offset.
     * @param string     $funcName The bare call name to strip (no `self::` prefix — see this class's own docblock).
     *
     * @return array{0: string, 1: list<bool>} The text with every balanced $funcName(...) call removed, and its realigned mask.
     */
    private static function stripBalancedCalls(string $text, array $mask, string $funcName): array
    {
        $needle  = "{$funcName}(";
        $out     = '';
        $outMask = [];
        $pos     = 0;

        while (($start = strpos($text, $needle, $pos)) !== false) {
            $out .= substr($text, $pos, $start - $pos);
            $outMask = [...$outMask, ...array_slice($mask, $pos, $start - $pos)];

            [, $endPos] = self::extractBalancedCall($text, $start + strlen($funcName), $mask);
            $pos        = $endPos;
        }

        $out .= substr($text, $pos);
        $outMask = [...$outMask, ...array_slice($mask, $pos)];

        return [$out, $outMask];
    }

    /**
     * Blanks out every T_COMMENT/T_DOC_COMMENT token in $source, replacing
     * each byte but a literal newline with a space so line numbers and byte
     * offsets into the returned string still line up with $source. Without
     * this, a comment or docblock merely quoting a risky-assertion call as
     * illustrative prose (this class's own docblock is exactly such a case)
     * would false-positive findUnscrubbedRawOutputAssertions() below.
     *
     * @param string $source The PHP source to strip comments from.
     *
     * @return string The source with every comment/docblock token blanked out.
     */
    private static function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out .= $token;

                continue;
            }

            [$id, $text] = $token;

            if (($id === T_COMMENT) || ($id === T_DOC_COMMENT)) {
                $out .= preg_replace('/[^\n]/', ' ', $text);

                continue;
            }

            $out .= $text;
        }

        return $out;
    }

    /**
     * Scans $path for every call to one of self::RISKY_ASSERTIONS and, for
     * each one, strips every self::SAFE_WRAP_CALLS wrap from its own
     * argument list — the message argument included, since a hand-written
     * custom message embedding raw output unscrubbed is the SAME defect
     * class as a hand-rolled message that skips the scrub helper, not merely
     * the PHPUnit auto-export mechanism. Whatever still matches
     * self::RAW_OUTPUT_PATTERN afterwards is a finding. $path is read
     * through self::stripComments() first, so a comment/docblock quoting a
     * risky-assertion call in prose is not scanned. self::stringLiteralMask()
     * is built once per scan and kept aligned with each intermediate string
     * as self::stripBalancedCalls() shortens it.
     *
     * @param string $path Absolute path to the PHP source file to scan.
     *
     * @return list<string> One description per finding, empty when none.
     */
    private static function findUnscrubbedRawOutputAssertions(string $path): array
    {
        $source   = self::stripComments((string) file_get_contents($path));
        $mask     = self::stringLiteralMask($source);
        $findings = [];

        foreach (self::RISKY_ASSERTIONS as $assertionName) {
            $offset = 0;
            $needle = "{$assertionName}(";

            while (($pos = strpos($source, $needle, $offset)) !== false) {
                $parenStart          = $pos + strlen($assertionName);
                [$argsText, $endPos] = self::extractBalancedCall($source, $parenStart, $mask);
                $offset              = $endPos;

                $stripped     = $argsText;
                $strippedMask = array_slice($mask, $parenStart + 1, strlen($argsText));

                foreach (self::SAFE_WRAP_CALLS as $wrap) {
                    [$stripped, $strippedMask] = self::stripBalancedCalls($stripped, $strippedMask, $wrap);
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
     * Writes $phpSource to $filename inside this test's fixture directory,
     * then scans it via self::findUnscrubbedRawOutputAssertions() — the
     * "write a fixture file, scan it" shape every self-test below (this
     * guard's own controls, proving it actually discriminates rather than
     * always returning the same result regardless of input) repeated
     * independently before this existed. Each caller keeps its own distinct
     * $filename/$phpSource/assertion; only this boilerplate collapses.
     *
     * @param string $filename  The fixture file's bare name, written under this test's own fixture directory.
     * @param string $phpSource The PHP source to write into it.
     *
     * @return list<string> One description per finding, empty when none.
     */
    private function findingsFor(string $filename, string $phpSource): array
    {
        $path = "{$this->fixture()->path()}/{$filename}";
        file_put_contents($path, $phpSource);

        return self::findUnscrubbedRawOutputAssertions($path);
    }

    /**
     * The regression guard itself: none of the files self::guardedFiles()
     * lists may call one of self::RISKY_ASSERTIONS with a raw, unscrubbed
     * subprocess-output accessor anywhere in its own argument list. A future
     * call site that
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
        $findings = $this->findingsFor(
            'poisoned-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, "boom\n{$result->output}");
            PHP,
        );

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
        $findings = $this->findingsFor(
            'clean-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, self::diagnosticMessage('label', $result->output));
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a call whose only ->output access is inside a sanctioned scrub wrap.');
    }

    /**
     * The guard's own third control, for the fourth sanctioned wrap:
     * messageWithOutput() was added after self::SAFE_WRAP_CALLS was first
     * written and, like the other three wraps, must not be flagged when it
     * is the ONLY thing carrying a risky assertion's `->output` access —
     * without this, a future edit dropping 'messageWithOutput' back out of
     * self::SAFE_WRAP_CALLS would false-positive on every real call site
     * using it undetected by this suite.
     */
    #[Test]
    public function doesNotFlagAnAssertionScrubbedViaMessageWithOutput(): void
    {
        $findings = $this->findingsFor(
            'clean-message-with-output-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, self::messageWithOutput('', 'label', $result->output));
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a call whose only ->output access is inside self::messageWithOutput().');
    }

    /**
     * self::RAW_OUTPUT_PATTERN's `$matches[` alternative: a risky assertion
     * comparing a regex-capture variable directly (the exact shape
     * assertReadmeToolVersionMatchesDevDependenciesPin() carried before it
     * was converted to a manual mismatch check + self::fail()) must be
     * flagged, not just the `->output`/`->getOutput()`/`->getErrorOutput()`
     * accessors.
     */
    #[Test]
    public function detectsARiskyAssertionUsingARegexCaptureVariable(): void
    {
        $findings = $this->findingsFor(
            'poisoned-matches-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame($matches[1], $actual, 'boom');
            PHP,
        );

        self::assertNotEmpty($findings, 'The guard did not flag a risky assertion using a regex-capture variable ($matches[1]) as its raw operand.');
    }

    /**
     * self::RAW_OUTPUT_PATTERN's `['stdout']`/`['stderr']` alternative: a
     * risky assertion comparing an array-key access shaped like captured
     * subprocess output — the real shape runBuildToolsSeparated()'s own
     * callers use in tests/CheckJsConfigsTest.php — must be flagged too.
     */
    #[Test]
    public function detectsARiskyAssertionUsingAnArrayKeyAccess(): void
    {
        $findings = $this->findingsFor(
            'poisoned-array-key-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame('typescript@5.0.16', trim($result['stdout']));
            PHP,
        );

        self::assertNotEmpty($findings, "The guard did not flag a risky assertion using an array-key access (\$result['stdout']) as its raw operand.");
    }

    /**
     * self::stripComments()'s own control: a docblock or comment merely
     * mentioning a risky-assertion call in prose (exactly the shape this
     * class's own docblock and several method docblocks in this suite use)
     * must NOT be flagged — without stripping comments first, this guard
     * would false-positive on its own documentation.
     */
    #[Test]
    public function doesNotFlagARiskyAssertionMentionedOnlyInAComment(): void
    {
        $findings = $this->findingsFor(
            'commented-mention-fixture.php',
            <<<'PHP'
            <?php

            /**
             * See self::assertSame($matches[1], $result['stdout'], $result->output) for
             * an illustrative example of the shape this guard rejects — never actually
             * called here.
             */
            // Also mentioned in a single-line comment: assertSame($result->output, $x);
            final class CommentedMentionFixture
            {
            }
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a risky-assertion call that only appears inside a comment/docblock, never as real code.');
    }

    /**
     * self::extractBalancedCall()'s own control for an unmatched opening
     * paren inside a sanctioned wrap's OWN string argument: without
     * tokenizing string literals, a `(` embedded in
     * self::scrubbedForDiagnostic()'s own argument text overruns that call's
     * true closing `)` and swallows the rest of the containing risky
     * assertion's argument list — including the trailing, genuinely
     * unwrapped `$result->output` below — as if it were already inside the
     * sanctioned wrap, so self::stripBalancedCalls() strips the whole span
     * and RAW_OUTPUT_PATTERN never sees the real leak. Live-reproduced
     * against this guard before the string-literal-aware balancing existed;
     * see this class's own docblock's "documented limitations" section.
     */
    #[Test]
    public function detectsARiskyAssertionWhoseWrapArgumentCarriesAnUnmatchedOpeningParen(): void
    {
        $findings = $this->findingsFor(
            'unmatched-paren-in-wrap-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::scrubbedForDiagnostic("unbalanced ( paren") . $result->output);
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose sanctioned-wrap argument carries an unmatched opening '
                . 'paren, even though a genuinely unwrapped $result->output follows it in the same argument list.',
        );
    }
}
