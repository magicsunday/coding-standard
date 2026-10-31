<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractConsumerConfigTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;
use function file_put_contents;
use function rtrim;
use function str_repeat;
use function str_replace;

/**
 * Fixture-driven cases for bin/consumer-checks/check-agents-md.php — the
 * optional AGENTS.md contract: a file that exists carries the shared testing
 * policy block exactly once, between versioned markers, with the text of the
 * template of that version. Text outside the markers is free. PHP gate only.
 * See AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigAgentsMdTest extends AbstractConsumerConfigTestCase
{
    private const string TEMPLATE = '/templates/testing-policy.v1.md';

    /**
     * @return string The policy text of version 1 as templates/ ships it, without a trailing newline.
     */
    private static function policy(): string
    {
        return rtrim((string) file_get_contents(self::root() . self::TEMPLATE), "\n");
    }

    /**
     * @param string $body    The text between the markers.
     * @param string $version The version named in the begin marker.
     *
     * @return string The block with its markers.
     */
    private static function block(string $body, string $version = '1'): string
    {
        return "<!-- testing-policy:begin v{$version} -->\n{$body}\n<!-- testing-policy:end -->";
    }

    /**
     * Writes an AGENTS.md into a fresh case directory.
     *
     * @param string $contents The file contents.
     *
     * @return string The case directory.
     */
    private function caseWithAgentsMd(string $contents): string
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/AGENTS.md', $contents);

        return $dir;
    }

    /**
     * A repository without an AGENTS.md has no agent instructions to check, so
     * the contract is skipped like every other optional config.
     */
    #[Test]
    public function acceptsARepositoryWithoutAnAgentsMd(): void
    {
        $this->assertGateAccepts(self::phpGate(), $this->mkCase(), 'no AGENTS.md');
    }

    /**
     * The block as the template ships it, surrounded by repository text.
     */
    #[Test]
    public function acceptsAgentsMdWithTheCurrentPolicyBlock(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\nSome rules.\n\n" . self::block(self::policy()) . "\n\n## Other\n\nMore rules.\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'AGENTS.md with the policy block');
    }

    /**
     * Prose that names the markers inline, in the middle of a line, is the
     * repository's own text and does not count as a marker.
     */
    #[Test]
    public function acceptsAgentsMdThatMentionsTheMarkersInlineOutsideTheBlock(): void
    {
        $prose = "Wrap the policy in `<!-- testing-policy:begin v1 -->` and `<!-- testing-policy:end -->`, as the shared template says.\n"
            . "<!-- testing-policy:begin v1 --> starts a block.\n"
            . "<!-- testing-policy:end --> ends it.\n"
            . "A block starts with <!-- testing-policy:begin v1 -->\n"
            . "A block ends with <!-- testing-policy:end -->\n";
        $dir = $this->caseWithAgentsMd("# Project\n\n{$prose}\n" . self::block(self::policy()) . "\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'AGENTS.md that names the markers inline');
    }

    /**
     * Windows line endings do not make an identical block drift.
     */
    #[Test]
    public function acceptsAgentsMdWithCrlfLineEndings(): void
    {
        $text = str_replace("\n", "\r\n", "# Project\n\n" . self::block(self::policy()) . "\n");
        $dir  = $this->caseWithAgentsMd($text);

        $this->assertGateAccepts(self::phpGate(), $dir, 'AGENTS.md with CRLF line endings');
    }

    /**
     * A UTF-8 byte order mark in front of a begin marker on the first line does
     * not hide the marker.
     */
    #[Test]
    public function acceptsAgentsMdThatStartsWithAByteOrderMarkAndTheBlock(): void
    {
        $dir = $this->caseWithAgentsMd("\xEF\xBB\xBF" . self::block(self::policy()) . "\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'AGENTS.md with a byte order mark in front of the block');
    }

    /**
     * Carriage returns alone, without a line feed, also separate the lines.
     */
    #[Test]
    public function acceptsAgentsMdWithLoneCarriageReturnLineEndings(): void
    {
        $text = str_replace("\n", "\r", "# Project\n\n" . self::block(self::policy()) . "\n");
        $dir  = $this->caseWithAgentsMd($text);

        $this->assertGateAccepts(self::phpGate(), $dir, 'AGENTS.md with carriage return line endings');
    }

    /**
     * An AGENTS.md above the text size cap is reported once, as itself, and no
     * block finding is fabricated on top of it.
     */
    #[Test]
    public function reportsAnOversizedAgentsMdOnceAsItself(): void
    {
        $dir = $this->caseWithAgentsMd(str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'AGENTS.md', 'an oversized AGENTS.md is reported once, as itself');
    }

    /**
     * An AGENTS.md without the block is the drift the check exists for.
     */
    #[Test]
    public function rejectsAgentsMdWithoutThePolicyBlock(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\nSome rules.\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'AGENTS.md', 'AGENTS.md without the policy block');
    }

    /**
     * One changed word inside the block is drift from the template.
     */
    #[Test]
    public function rejectsAgentsMdWhoseBlockWasEdited(): void
    {
        $edited = str_replace('not a coverage target', 'a coverage target', self::policy());

        self::assertNotSame(self::policy(), $edited, 'the edit changed nothing, so this case proves nothing');

        $dir = $this->caseWithAgentsMd("# Project\n\n" . self::block($edited) . "\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'differs from templates/testing-policy.v1.md', 'AGENTS.md with an edited block');
    }

    /**
     * A version no template exists for cannot be verified, so it is rejected.
     */
    #[Test]
    public function rejectsAgentsMdWithAnUnknownPolicyVersion(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\n" . self::block(self::policy(), '99') . "\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'unknown', 'AGENTS.md naming a version without a template');
    }

    /**
     * The block exists once. A second copy is rejected so a stale copy cannot
     * hide behind a current one.
     */
    #[Test]
    public function rejectsAgentsMdWithTwoPolicyBlocks(): void
    {
        $block = self::block(self::policy());
        $dir   = $this->caseWithAgentsMd("# Project\n\n{$block}\n\n{$block}\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'more than once', 'AGENTS.md with two policy blocks');
    }

    /**
     * A second end marker after one block is rejected as well, so text after
     * the first end marker cannot hide behind a block that looks complete.
     */
    #[Test]
    public function rejectsAgentsMdWithOneBeginAndTwoEndMarkers(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\n" . self::block(self::policy()) . "\n\n<!-- testing-policy:end -->\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'more than once', 'AGENTS.md with one begin and two end markers');
    }

    /**
     * A begin marker without its end marker leaves the block unbounded.
     */
    #[Test]
    public function rejectsAgentsMdWhoseBlockHasNoEndMarker(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\n<!-- testing-policy:begin v1 -->\n" . self::policy() . "\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'end marker', 'AGENTS.md whose block is not closed');
    }

    /**
     * An end marker in front of the begin marker is not a block either.
     */
    #[Test]
    public function rejectsAgentsMdWhoseMarkersAreReversed(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\n<!-- testing-policy:end -->\n" . self::policy() . "\n<!-- testing-policy:begin v1 -->\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'end marker', 'AGENTS.md with reversed markers');
    }

    /**
     * A marker spelled with a non-numeric version is no marker at all, and the
     * report must not echo what the file wrote there.
     */
    #[Test]
    public function reportsAMalformedBeginMarkerWithoutEchoingIt(): void
    {
        $dir = $this->caseWithAgentsMd("# Project\n\n<!-- testing-policy:begin v::error::forged -->\n" . self::policy() . "\n<!-- testing-policy:end -->\n");

        $this->assertGateReportIsInert(self::phpGate(), $dir, null, 'AGENTS.md with a malformed begin marker');
    }
}
