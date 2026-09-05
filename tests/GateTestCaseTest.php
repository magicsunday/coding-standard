<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use LogicException;
use MagicSunday\CodingStandard\Test\Support\FixtureDirectory;
use MagicSunday\CodingStandard\Test\Support\GateProcess;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;

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
#[UsesClass(FixtureDirectory::class)]
#[UsesClass(GateProcess::class)]
#[UsesClass(GateResult::class)]
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
     * Verifies that assertGateReportsOnce counts only genuine `- $filePrefix:`
     * violation lines, not a bare mention of the file prefix inside an
     * unrelated line's message — the needle is `"- {$filePrefix}:"`, not a
     * plain substring search, and a mutation dropping the `- `/`:` shape
     * would still count 1 on this fixture without this test to catch it.
     */
    #[Test]
    public function assertGateReportsOncePassesWhenTheFilePrefixAppearsOnlyInAnUnrelatedMessage(): void
    {
        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - biome.json: x\n  - other.json: mentions biome.json in passing\n"); exit(1);'],
            $this->fixture()->path(),
            'biome.json',
        );
    }

    /**
     * Verifies that assertGateReportsOnce's needle requires BOTH the leading
     * `- ` and the trailing `:` around the file prefix, not just their
     * combination — assertGateReportsOncePassesWhenTheFilePrefixAppearsOnlyInAnUnrelatedMessage
     * only proves a mutation dropping BOTH halves at once is caught. A decoy
     * sharing the prefix as a string prefix (`biome.json` inside
     * `biome.jsonc`) kills a colon-only drop; a decoy carrying the prefix
     * with no leading dash kills a dash-only drop. Neither half is provable
     * by the other.
     */
    #[Test]
    public function assertGateReportsOncePassesWhenTheFilePrefixNeedleShapeIsOnlyPartiallyPresentElsewhere(): void
    {
        $this->assertGateReportsOnce(
            [
                'php',
                '-r',
                'fwrite(STDOUT, "  - biome.json: x\n  - biome.jsonc: y\nNote: biome.json: drifted\n"); exit(1);',
            ],
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
     * Verifies that assertGateReportsOnce fails when zero lines match the
     * file prefix — the assertCount(1, ...) check is an exact-one contract,
     * not merely an upper bound, and this is the only test exercising that
     * lower edge; assertGateReportsOnceFailsOnTwoMatchingLines alone cannot
     * catch a regression to an at-most-one check.
     */
    #[Test]
    public function assertGateReportsOnceFailsOnZeroMatchingLines(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - other.json: x\n"); exit(1);'],
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
     * Verifies that assertGateReportIsInert fails when a forged `::` workflow
     * command sits on a line AFTER the first one — every other forged-command
     * fixture places the forgery at output offset 0, where `^` matches even
     * without the `/m` modifier because string-start and line-start coincide
     * there. Dropping `/m` would leave every other fixture green; only a
     * forgery on a later line proves `^` is re-anchored per line, which is
     * what makes this check catch a forged command anywhere in a real,
     * multi-line report, not only as its very first bytes.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAForgedWorkflowCommandOnASubsequentLine(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "  - x: legitimate first line\n::error::forged\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a forged `::` workflow
     * command's name starts with a hyphen — the character class
     * `[A-Za-z0-9_-]+` admits it, but every other forged-command fixture's
     * name starts with a letter, so at least one alphanumeric character
     * already satisfies the `+` quantifier before a hyphen is ever reached;
     * none of them would fail if the hyphen were dropped from the class.
     * A name that CANNOT match without the hyphen is the only fixture that
     * proves the hyphen is actually load-bearing in the class.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAForgedWorkflowCommandWithAHyphenLedName(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "::-mask::forged\n"); exit(1);'],
            $this->fixture()->path(),
        );
    }

    /**
     * Verifies that assertGateReportIsInert fails when a forged `::` workflow
     * command has NO leading whitespace at all — the regex's leading
     * `[[:space:]]*` is zero-or-more, and the vertical-tab fixture above
     * only proves the one-or-more case; a narrowing to `[[:space:]]+` would
     * still admit that fixture and pass this test undetected without this one.
     */
    #[Test]
    public function assertGateReportIsInertFailsOnAForgedWorkflowCommandWithNoLeadingWhitespace(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "::error::forged\n"); exit(1);'],
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
     * Verifies that assertGateReportIsInert passes on exactly four non-empty
     * lines — the boundary itself, not just its neighbours (3 passes, 5
     * fails), so a narrowing to assertLessThan(4, ...) fails this test
     * instead of passing it undetected.
     */
    #[Test]
    public function assertGateReportIsInertPassesOnExactlyFourNonEmptyLines(): void
    {
        $this->assertGateReportIsInert(
            ['php', '-r', 'fwrite(STDOUT, "a\nb\nc\nd\n"); exit(1);'],
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

    // The regression the whole assertGateReportIsInert()/assertReportCarries()
    // defect class boils down to: their own FAILURE message must not forge
    // the very CI annotation the check exists to catch. Every
    // assertGateReportIsInertFailsOn*() test above (re-derive the current set
    // with `grep -n 'functio[n] assertGateReportIsInertFailsOn' tests/GateTestCaseTest.php`
    // — anchored on "function", with the "n" bracket-split so this citation's
    // own copy of the command text does not also match)
    // only proves AN AssertionFailedError was thrown (expectException()),
    // never what that exception's own message carries — reverting
    // GateTestCase's own
    // scrubbedForDiagnostic() wrap at any of its self::fail() call sites (or a
    // regression back to assertStringNotContainsString()/
    // assertDoesNotMatchRegularExpression(), which is exactly what those
    // call sites replaced) would leave every one of them still green, because
    // none of them ever inspects the caught exception's own message.
    //
    // Five independent self::fail() call sites reach $result->output/the
    // report content this way — the ESC-byte check, the modern `::` check,
    // the legacy `##[` check and the bare-CR check inside
    // assertGateReportIsInert() itself, plus assertReportCarries()'s own
    // must-carry check — and a single combined fixture carrying every forgery
    // at once would only ever discriminate the FIRST one in that order (the
    // ESC-byte check runs first and self::fail()s immediately), leaving the
    // other four's own scrubbing completely unproven. re-derive via
    // `grep -nE '^[[:space:]]*self::fail\(' tests/GateTestCase.php` (anchored
    // on the leading whitespace so it counts only real call sites, not this
    // comment's own mentions of self::fail()) if this method's own
    // check order ever changes — that command currently returns SIX matches
    // for the whole file, not five: it also catches runAndAssertVerdict()'s
    // own unrelated exit-code self::fail(), between assertGateReportIsInert()
    // and assertReportCarries() in file order, so scope the count to the two
    // methods this comment describes, not the file-wide grep result. Each
    // test below therefore drives exactly ONE
    // branch in isolation, with a fixture carrying no earlier-checked forgery
    // that would short-circuit past it — via
    // assertOwnFailureMessageDoesNotForgeWorkflowCommand() below, which every
    // one of them shares.

    /**
     * The shared "own failure message must not forge a workflow command"
     * shape the tests below each repeated independently before this existed:
     * invoke a fixture-driving assertion that is EXPECTED to reject, capture
     * the thrown AssertionFailedError, then check whether that exception's
     * OWN message still carries the forged sequence it was poisoned with.
     * $isForged and $redact are callables rather than a plain needle string
     * because one call site (the modern `::` prefix) discriminates via a
     * regex anchored to line start, not a plain str_contains() — every other
     * call site's needle-based check fits the same two-callable shape; the
     * three legacy `##[` call sites share theirs via
     * legacyPrefixSurvivedInMessage()/redactLegacyPrefixInMessage() below
     * rather than repeating the pair inline.
     *
     * @param callable(): void         $invoke          Runs the fixture-driving assertion expected to throw.
     * @param string                   $rejectedMessage The assertNotNull() message if $invoke did not throw at all.
     * @param callable(string): bool   $isForged        Given the caught exception's message, returns whether the forgery survived.
     * @param callable(string): string $redact          Given that message, returns a safe-to-print, redacted copy for self::fail().
     * @param string                   $forgesMessage   The self::fail() prefix, used only when $isForged() reports true.
     *
     * @return void
     */
    private function assertOwnFailureMessageDoesNotForgeWorkflowCommand(
        callable $invoke,
        string $rejectedMessage,
        callable $isForged,
        callable $redact,
        string $forgesMessage,
    ): void {
        $thrown = self::assertThrows(
            $invoke,
            AssertionFailedError::class,
            $rejectedMessage,
        );

        $message = $thrown->getMessage();

        if ($isForged($message)) {
            self::fail("{$forgesMessage}\n" . $redact($message));
        }
    }

    /**
     * The shared $isForged check for the three legacy `##[` call sites of
     * assertOwnFailureMessageDoesNotForgeWorkflowCommand() above
     * (assertGateReportIsInertFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheLegacyPrefixBranch(),
     * assertReportCarriesFailsWithoutForgingAWorkflowCommandInItsOwnMessage(),
     * assertGateAcceptsFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheWrongExitCode()),
     * each of which repeated this check inline before it was extracted here.
     *
     * @param string $message The caught exception's message.
     *
     * @return bool Whether the legacy `##[` workflow-command prefix survived.
     */
    private static function legacyPrefixSurvivedInMessage(string $message): bool
    {
        return str_contains($message, '##[');
    }

    /**
     * The matching redaction for legacyPrefixSurvivedInMessage() above — see
     * that method's own docblock for the three call sites sharing this pair.
     *
     * @param string $message The message to redact before self::fail() prints it.
     *
     * @return string The message with the legacy `##[` prefix broken.
     */
    private static function redactLegacyPrefixInMessage(string $message): string
    {
        return str_replace('#[', '#?[', $message);
    }

    /**
     * Isolates the ESC-byte self::fail() call site — see this class's own
     * docblock above for why a combined fixture cannot prove this branch.
     */
    #[Test]
    public function assertGateReportIsInertFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheEscapeByteBranch(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateReportIsInert(
                ['php', '-r', 'fwrite(STDOUT, "\x1B[31mred\x1B[0m\n"); exit(1);'],
                $this->fixture()->path(),
            ),
            'assertGateReportIsInert() did not reject the ESC-byte fixture.',
            static fn (string $message): bool => str_contains($message, "\x1B"),
            static fn (string $message): string => str_replace("\x1B", '?', $message),
            "assertGateReportIsInert()'s own ESC-byte failure message still carries a raw ANSI escape.",
        );
    }

    /**
     * Isolates the modern `::` self::fail() call site — see this class's own
     * docblock above for why a combined fixture cannot prove this branch.
     */
    #[Test]
    public function assertGateReportIsInertFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheModernPrefixBranch(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateReportIsInert(
                ['php', '-r', 'fwrite(STDOUT, "::error::forged\n"); exit(1);'],
                $this->fixture()->path(),
            ),
            'assertGateReportIsInert() did not reject the `::`-forged fixture.',
            static fn (string $message): bool => preg_match('/^[ \t]*::/m', $message) === 1,
            static fn (string $message): string => str_replace('::', ':?:', $message),
            "assertGateReportIsInert()'s own `::`-branch failure message still carries a `::` workflow command.",
        );
    }

    /**
     * Isolates the legacy `##[` self::fail() call site — see this class's own
     * docblock above for why a combined fixture cannot prove this branch.
     */
    #[Test]
    public function assertGateReportIsInertFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheLegacyPrefixBranch(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateReportIsInert(
                ['php', '-r', 'fwrite(STDOUT, "##[error]forged\n"); exit(1);'],
                $this->fixture()->path(),
            ),
            'assertGateReportIsInert() did not reject the `##[`-forged fixture.',
            self::legacyPrefixSurvivedInMessage(...),
            self::redactLegacyPrefixInMessage(...),
            "assertGateReportIsInert()'s own `##[`-branch failure message still carries a legacy `##[` workflow command.",
        );
    }

    /**
     * Isolates the bare-CR self::fail() call site — see this class's own
     * docblock above for why a combined fixture cannot prove this branch.
     */
    #[Test]
    public function assertGateReportIsInertFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheBareCarriageReturnBranch(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateReportIsInert(
                ['php', '-r', 'fwrite(STDOUT, "line one\rline two\n"); exit(1);'],
                $this->fixture()->path(),
            ),
            'assertGateReportIsInert() did not reject the bare-CR fixture.',
            static fn (string $message): bool => str_contains($message, "\r"),
            static fn (string $message): string => str_replace("\r", '?', $message),
            "assertGateReportIsInert()'s own bare-CR failure message still carries a raw carriage return.",
        );
    }

    /**
     * Isolates assertReportCarries()'s OWN self::fail() call site — the fifth
     * of the five this class's own docblock above enumerates, and the one
     * assertGateReportIsInert() cannot reach with a poisoned fixture at all,
     * because a report carrying `##[`/`::`/an ESC byte/a bare CR is always
     * caught by one of the four checks above it first. assertGateRejects()
     * reaches assertReportCarries() WITHOUT running any of those four checks,
     * so a `##[`-forged report that simply never carries the expected
     * substring drives this branch directly.
     */
    #[Test]
    public function assertReportCarriesFailsWithoutForgingAWorkflowCommandInItsOwnMessage(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateRejects(
                ['php', '-r', 'fwrite(STDOUT, "  - x: ##[error]forged\n"); exit(1);'],
                $this->fixture()->path(),
                'a substring the report never prints',
            ),
            'assertGateRejects() did not reject the fixture missing the must-carry substring.',
            self::legacyPrefixSurvivedInMessage(...),
            self::redactLegacyPrefixInMessage(...),
            "assertReportCarries()'s own failure message still carries a legacy `##[` workflow command.",
        );
    }

    /**
     * The identical regression, one level up: runAndAssertVerdict() is the
     * shared precondition every assertGate*() decision calls FIRST (before
     * assertGateReportIsInert()'s own four checks ever run), and its
     * exit-code-mismatch self::fail() call site needs the same proof
     * independently of assertGateReportIsInert()'s own. (Its OTHER check,
     * isDegraded(), stayed a real, unconditional assertFalse() rather than a
     * manual self::fail() — isDegraded() reduces $result->output to a plain
     * bool, and neither that call's message nor PHPUnit's own
     * auto-generated failure description for a boolean comparison ever
     * re-embeds raw output, so there is nothing there for a poisoned fixture
     * to forge through, and no reason to sacrifice the real assertion.)
     * Drives assertGateAccepts() (which expects exit 0) with a fixture that
     * exits with the WRONG code while carrying a forged legacy `##[`
     * sequence, discriminating the exit-code branch specifically.
     */
    #[Test]
    public function assertGateAcceptsFailsWithoutForgingAWorkflowCommandInItsOwnMessageOnTheWrongExitCode(): void
    {
        $this->assertOwnFailureMessageDoesNotForgeWorkflowCommand(
            fn () => $this->assertGateAccepts(
                ['php', '-r', 'fwrite(STDOUT, "##[error]forged\n"); exit(1);'],
                $this->fixture()->path(),
            ),
            'assertGateAccepts() did not reject the wrong-exit-code fixture.',
            self::legacyPrefixSurvivedInMessage(...),
            self::redactLegacyPrefixInMessage(...),
            "runAndAssertVerdict()'s own wrong-exit-code failure message still carries a legacy `##[` workflow command.",
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

    /**
     * Verifies that fixture() caches its FixtureDirectory across calls within
     * one test rather than creating a fresh one every time — the contract
     * this class's own docblock documents for the private ??= assignment.
     */
    #[Test]
    public function fixtureIsCachedAcrossCallsWithinTheSameTest(): void
    {
        self::assertSame($this->fixture()->path(), $this->fixture()->path());
    }

    /**
     * Expects the next assertion to throw an AssertionFailedError whose
     * message starts with $expectedMessage — shared by every "$message
     * overrides the generated default" test below.
     *
     * @param string $expectedMessage The custom message the caller passed in.
     *
     * @return void
     */
    private function expectAssertionFailedWithMessage(string $expectedMessage): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($expectedMessage, '/') . '/');
    }

    /**
     * Verifies that a custom $message overrides the generated default when
     * assertGateAccepts fails because the process ran degraded — the shared
     * ternary in runAndAssertVerdict() that every assertGate* decision relies on.
     */
    #[Test]
    public function assertGateAcceptsFailsWhenDegradedWithACustomMessage(): void
    {
        $this->expectAssertionFailedWithMessage('a custom degraded message');

        $this->assertGateAccepts(
            ['php', '-r', 'fwrite(STDERR, "PHP Warning:  x"); exit(0);'],
            $this->fixture()->path(),
            'a custom degraded message',
        );
    }

    /**
     * Verifies that a custom $message overrides the generated default when
     * assertGateAccepts fails on the wrong exit code — the shared ternary in
     * runAndAssertVerdict() that every assertGate* decision relies on.
     */
    #[Test]
    public function assertGateAcceptsFailsOnNonZeroExitWithACustomMessage(): void
    {
        $this->expectAssertionFailedWithMessage('a custom exit-code message');

        $this->assertGateAccepts(
            ['php', '-r', 'exit(1);'],
            $this->fixture()->path(),
            'a custom exit-code message',
        );
    }

    /**
     * Verifies that a custom $message overrides the generated default when
     * assertGateRejects fails on the wrong reason — the shared ternary in
     * assertReportCarries() that every must-carry decision relies on.
     */
    #[Test]
    public function assertGateRejectsFailsOnTheWrongReasonWithACustomMessage(): void
    {
        $this->expectAssertionFailedWithMessage('a custom must-carry message');

        $this->assertGateRejects(
            ['php', '-r', 'fwrite(STDOUT, "  - x: a drift verdict\n"); exit(1);'],
            $this->fixture()->path(),
            'a substring the report never prints',
            'a custom must-carry message',
        );
    }

    /**
     * Verifies that a custom $message overrides the generated default when
     * assertGateReportsOnce fails on the wrong match count — its own inline
     * ternary, not shared with any other decision.
     */
    #[Test]
    public function assertGateReportsOnceFailsOnZeroMatchingLinesWithACustomMessage(): void
    {
        $this->expectAssertionFailedWithMessage('a custom reports-once message');

        $this->assertGateReportsOnce(
            ['php', '-r', 'fwrite(STDOUT, "  - y: unrelated\n"); exit(1);'],
            $this->fixture()->path(),
            'x',
            'a custom reports-once message',
        );
    }

    /**
     * A direct unit test of messageOrDefault()'s/diagnosticMessage()'s/
     * messageWithOutput()'s own composition semantics — otherwise only
     * exercised indirectly through every other test in this class and its
     * siblings, which pins call-site behaviour but never the composition
     * logic itself: a prior double-append regression in exactly this
     * composition was only caught by manual re-reading, not a test.
     * messageOrDefault() and messageWithOutput() differ in exactly one way
     * — whether the output is appended when $message is non-empty — so this
     * asserts both sides of that difference explicitly.
     *
     * The messageOrDefault()-delegates-to-diagnosticMessage() assertion below
     * reuses diagnosticMessage() itself to build its own expected value, so a
     * mutation inside diagnosticMessage()'s own composition (e.g. doubling
     * the "\n") changes both sides identically and that assertion alone would
     * stay green — it pins the delegation relationship, not
     * diagnosticMessage()'s own contract. The companion assertion
     * immediately below it instead builds its expected value from a hand-
     * written literal, independent of diagnosticMessage(), so it is the one
     * that actually pins that contract; verified live by temporarily
     * doubling diagnosticMessage()'s own "\n" — the literal-based assertion
     * goes red, the reused-implementation one does not.
     */
    #[Test]
    public function theMessageCompositionHelpersComposeAsDocumented(): void
    {
        self::assertSame(
            'custom',
            self::messageOrDefault('custom', 'default', 'raw with ::error::x::y'),
            'messageOrDefault() must return a non-empty $message verbatim, with no scrub applied.',
        );

        self::assertSame(
            self::diagnosticMessage('default', 'raw with ::error::x::y'),
            self::messageOrDefault('', 'default', 'raw with ::error::x::y'),
            'messageOrDefault() must fall back to diagnosticMessage()\'s own composition when $message is empty.',
        );

        self::assertSame(
            'default' . "\n" . self::scrubbedForDiagnostic('raw with ::error::x::y'),
            self::diagnosticMessage('default', 'raw with ::error::x::y'),
            'diagnosticMessage() must compose label + newline + scrubbed output exactly once.',
        );

        self::assertSame(
            'default' . "\n" . self::scrubbedForDiagnostic('raw with ::error::x::y'),
            self::messageWithOutput('', 'default', 'raw with ::error::x::y'),
            'messageWithOutput() must append the scrubbed output when $message is empty.',
        );

        self::assertSame(
            'custom' . "\n" . self::scrubbedForDiagnostic('raw with ::error::x::y'),
            self::messageWithOutput('custom', 'default', 'raw with ::error::x::y'),
            'messageWithOutput() must append the scrubbed output even when $message is non-empty, unlike messageOrDefault().',
        );
    }

    /**
     * assertThrows()'s own rethrow branch: re-derive via
     * `grep -rn "self::assert[T]hrows(" tests/` that every OTHER real call
     * site only ever exercises the matching-type path (an $invoke that
     * throws exactly $exceptionClass) — this is the one call site that
     * deliberately does not, so without it a mutation dropping the
     * `instanceof` guard — accepting ANY caught Throwable as satisfying ANY
     * requested $exceptionClass — would leave the whole suite green, silently
     * reintroducing the exact false-pass ("wrong exception type read as not
     * thrown at all") this helper was extracted to rule out.
     */
    #[Test]
    public function assertThrowsPropagatesAMismatchedExceptionTypeUncaught(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote('wrong exception type', '/') . '$/');

        self::assertThrows(
            static fn () => throw new LogicException('wrong exception type'),
            RuntimeException::class,
            'assertThrows() did not run $invoke at all.',
        );
    }

    /**
     * assertThrows()'s own "did not throw at all" branch: an $invoke that
     * returns normally must fail via assertNotNull()'s own
     * AssertionFailedError, whose message LEADS with $rejectedMessage
     * verbatim (assertNotNull() itself appends its own "Failed asserting
     * that null is not null." beneath it, so only the leading line — the
     * contract this helper's $rejectedMessage parameter documents — is
     * pinned here) rather than returning null or silently passing — the
     * counterpart to assertThrowsPropagatesAMismatchedExceptionTypeUncaught()
     * above, which covers the wrong-type branch of the same method.
     */
    #[Test]
    public function assertThrowsFailsWhenInvokeDoesNotThrowAtAll(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote('did not throw', '/') . '/');

        self::assertThrows(static fn () => null, RuntimeException::class, 'did not throw');
    }
}
