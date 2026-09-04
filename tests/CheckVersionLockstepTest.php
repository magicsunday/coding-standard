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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function dirname;
use function file_put_contents;
use function implode;
use function json_encode;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * Fixture-driven cases for tests/check-version-lockstep.php, migrated off
 * tests/check-version-lockstep-cases.sh (#80).
 *
 * Run against this repository alone, the gate only ever takes the happy
 * path — package.json and both README pins agree, so a green CI is
 * indistinguishable from a gate that cannot fail. These cases put it in
 * each failing state on purpose. Unlike CheckDisallowedCallsTest, this gate
 * is one of this package's own bin/ or tests/ check-*.php scripts and needs
 * no installed consumer fixture, so GateTestCase's accept/reject/usage-error/
 * report-is-inert exit-code contract applies directly, and this class needs
 * no setUp() self-skip.
 *
 * The bash original's bookkeeping self-test (harness_probe_reporters +
 * harness_assert_no_stray_increments, proving assertGateReportIsInert's
 * must-carry argument is not silently ignored) is not ported: GateTestCase's
 * own meta-suite already proves that generically for every caller.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckVersionLockstepTest extends GateTestCase
{
    /**
     * The largest file the gate under test reads, in bytes. Mirrors
     * MAX_LOCKSTEP_BYTES in tests/check-version-lockstep.php.
     */
    private const int MAX_LOCKSTEP_BYTES = 1048576;

    /**
     * The trailing-junk shapes shared by two decisions below: a bare
     * trailing junk pin, and a junk pin sitting beside a matching one.
     *
     * @return array<string, array{0: string}>
     */
    public static function trailingJunkProvider(): array
    {
        return [
            'a trailing word'               => ['final'],
            'a trailing underscore segment' => ['_hotfix'],
            'a trailing slash segment'      => ['/x'],
        ];
    }

    /**
     * The canon: package.json and both documented pins agree.
     */
    #[Test]
    public function acceptsWhenPackageJsonAndEveryReadmePinAgree(): void
    {
        $dir = $this->writeCase('1.7.0', implode("\n", [
            'Install with',
            '',
            '```shell',
            'npm install --save-dev github:magicsunday/coding-standard#1.7.0',
            '```',
            '',
            'which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`.',
        ]));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The release that bumps two of the three copies — the case the gate
     * exists for. A MATCHING pin comes first and the stale one after it,
     * which is the ordering that discriminates: a gate stopping after the
     * first match — printing OK and never reaching the stale pin — passes
     * every other case in this file. Verified by mutation: adding a
     * `break` after the OK branch survives the whole bash suite without
     * this ordering, and is caught with it.
     */
    #[Test]
    public function rejectsAReleaseThatBumpsTwoOfTheThreeCopies(): void
    {
        $dir = $this->writeCase('1.8.0', implode("\n", [
            'install with',
            '',
            '```shell',
            'npm install --save-dev github:magicsunday/coding-standard#1.8.0',
            '```',
            '',
            'which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`.',
        ]));

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  README.md:7 pins #1.7.0');
    }

    /**
     * Two stale pins, so a gate reporting only the first or only the last is caught.
     */
    #[Test]
    public function rejectsBothStalePinsWhenAReadmeDocumentsTwo(): void
    {
        $dir = $this->writeCase('1.8.0', implode("\n", [
            'github:magicsunday/coding-standard#1.7.0',
            'and also',
            'github:magicsunday/coding-standard#1.6.1',
        ]));

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  README.md:1 pins #1.7.0', 'the first of two stale pins is reported');
        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  README.md:3 pins #1.6.1', 'the second of two stale pins is reported as well');
    }

    /**
     * The report has to name the line, or a README with many pins gives
     * the reader nothing to go on. Asserted with the MISMATCH prefix,
     * because the gate prints the same `README.md:<line>` shape on its OK
     * path too.
     */
    #[Test]
    public function rejectsAndNamesTheReadmeLineOfTheMismatch(): void
    {
        $dir = $this->writeCase('1.8.0', implode("\n", [
            'line one',
            '',
            'github:magicsunday/coding-standard#1.7.0',
        ]));

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  README.md:3');
    }

    /**
     * Deleting the instructions must not make the gate pass vacuously.
     */
    #[Test]
    public function rejectsWhenTheReadmeDocumentsNoPinAtAll(): void
    {
        $dir = $this->writeCase('1.7.0', 'The install instructions moved elsewhere.');

        $this->assertGateRejects(self::gate(), $dir, 'documents no');
    }

    /**
     * A package.json carrying no `version` key at all is a usage error, not
     * a mismatch — there is nothing to compare the README pins against.
     */
    #[Test]
    public function reportsUsageErrorWhenPackageJsonHasNoVersion(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"@magicsunday/coding-standard\"\n}\n");
        file_put_contents($dir . '/README.md', "github:magicsunday/coding-standard#1.7.0\n");

        $this->assertGateUsageError(self::gate(), $dir, 'no string `version`');
    }

    /**
     * The neighbouring cause, which used to land in the same message: a
     * package.json that cannot be parsed at all was reported as one
     * carrying no `version` key, telling the reader to add a key to a
     * file that has no keys.
     */
    #[Test]
    public function reportsUsageErrorWhenPackageJsonIsNotValidJson(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', "{\n    \"version\":\n");
        file_put_contents($dir . '/README.md', "github:magicsunday/coding-standard#1.7.0\n");

        $this->assertGateUsageError(self::gate(), $dir, 'package.json is not valid JSON');
    }

    /**
     * An IO failure must report as one rather than as a content defect:
     * without the distinction, a missing README reads as "the README
     * documents no pin".
     */
    #[Test]
    public function reportsUsageErrorWhenReadmeIsMissing(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', "{\n    \"version\": \"1.7.0\"\n}\n");

        $this->assertGateUsageError(self::gate(), $dir, '/README.md.');
    }

    /**
     * The counterpart IO failure: a missing package.json reports as unreadable.
     */
    #[Test]
    public function reportsUsageErrorWhenPackageJsonIsMissing(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/README.md', "github:magicsunday/coding-standard#1.7.0\n");

        $this->assertGateUsageError(self::gate(), $dir, '/package.json.');
    }

    /**
     * The size cap on the package.json read, driven directly: content past
     * the bound is reported as too large before either byte is ever
     * interpreted, and need not even be valid JSON.
     */
    #[Test]
    public function reportsUsageErrorWhenPackageJsonExceedsTheSizeCap(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', str_repeat('x', self::MAX_LOCKSTEP_BYTES + 1));
        file_put_contents($dir . '/README.md', "github:magicsunday/coding-standard#1.7.0\n");

        $this->assertGateUsageError(
            self::gate(),
            $dir,
            'package.json is larger than the ' . self::MAX_LOCKSTEP_BYTES . ' bytes',
        );
    }

    /**
     * The size cap's counterpart on the README read.
     */
    #[Test]
    public function reportsUsageErrorWhenReadmeExceedsTheSizeCap(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', "{\n    \"version\": \"1.7.0\"\n}\n");
        file_put_contents($dir . '/README.md', str_repeat('x', self::MAX_LOCKSTEP_BYTES + 1));

        $this->assertGateUsageError(
            self::gate(),
            $dir,
            'README.md is larger than the ' . self::MAX_LOCKSTEP_BYTES . ' bytes',
        );
    }

    /**
     * The two oversize cases above only prove content one byte PAST the
     * bound is rejected. The counterpart, AT the cap: content that must be
     * read in FULL and matched, so a bound even one byte too small starts
     * rejecting it instead — mirroring the jscpd-at-the-size-cap fixture in
     * CheckConsumerConfigTest, for this gate's own MAX_LOCKSTEP_BYTES.
     */
    #[Test]
    public function acceptsAPackageJsonExactlyAtTheSizeCap(): void
    {
        $dir  = $this->fixture()->path();
        $body = (string) json_encode(['name' => '@magicsunday/coding-standard', 'version' => '1.7.0']);

        file_put_contents($dir . '/package.json', self::padJsonToCap(self::MAX_LOCKSTEP_BYTES, $body));
        file_put_contents($dir . '/README.md', "github:magicsunday/coding-standard#1.7.0\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The at-cap counterpart for the README side.
     */
    #[Test]
    public function acceptsAReadmeExactlyAtTheSizeCap(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/package.json', "{\n    \"version\": \"1.7.0\"\n}\n");
        file_put_contents(
            $dir . '/README.md',
            self::padTextToCap(self::MAX_LOCKSTEP_BYTES, '', 'x', "github:magicsunday/coding-standard#1.7.0\n"),
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The inline forms this repository's own prose uses. `\S` swallowed
     * the trailing backtick, so a correct README reported a mismatch
     * against a pin that only differed by punctuation.
     */
    #[Test]
    public function acceptsAPinWrittenInlineInBackticks(): void
    {
        $dir = $this->writeCase('1.7.0', 'The pin is `github:magicsunday/coding-standard#1.7.0` today.');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The counterpart terminator: a pin written inside parentheses.
     */
    #[Test]
    public function acceptsAPinWrittenInsideParentheses(): void
    {
        $dir = $this->writeCase('1.7.0', 'See the install section (github:magicsunday/coding-standard#1.7.0).');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A documented placeholder is not a pin and must not be compared as
     * one — but a README carrying ONLY a placeholder still has no pin to
     * check, so the vacuity guard is what reports it.
     */
    #[Test]
    public function rejectsAReadmeCarryingOnlyAPlaceholder(): void
    {
        $dir = $this->writeCase('1.7.0', 'Install `github:magicsunday/coding-standard#<tag>` with the tag you want.');

        $this->assertGateRejects(self::gate(), $dir, 'documents no');
    }

    /**
     * A placeholder documented beside a real pin must not suppress it.
     */
    #[Test]
    public function acceptsAPlaceholderDocumentedBesideARealPin(): void
    {
        $dir = $this->writeCase('1.7.0', implode("\n", [
            'Install `github:magicsunday/coding-standard#<tag>`, currently',
            'github:magicsunday/coding-standard#1.7.0.',
        ]));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A prerelease tag is still a version and must compare as one.
     */
    #[Test]
    public function acceptsAPrereleasePin(): void
    {
        $dir = $this->writeCase('1.8.0-rc.1', 'npm install --save-dev github:magicsunday/coding-standard#1.8.0-rc.1');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The combination the plain prerelease case never exercises: a
     * prerelease pin at the end of a sentence. The suffix class used to
     * swallow the period, so a correct README reported a mismatch against
     * itself.
     */
    #[Test]
    public function acceptsAPrereleasePinAtTheEndOfASentence(): void
    {
        $dir = $this->writeCase('1.8.0-rc.1', 'The current prerelease is github:magicsunday/coding-standard#1.8.0-rc.1.');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The same sentence-end shape, for build metadata rather than a
     * prerelease segment.
     */
    #[Test]
    public function acceptsABuildMetadataPinAtTheEndOfASentence(): void
    {
        $dir = $this->writeCase('1.7.0+build.5', 'see github:magicsunday/coding-standard#1.7.0+build.5.');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A pin carrying both a prerelease and build metadata segment.
     */
    #[Test]
    public function acceptsAPinCarryingBothAPrereleaseAndBuildMetadata(): void
    {
        $dir = $this->writeCase('1.2.3-beta.1+build.5', 'github:magicsunday/coding-standard#1.2.3-beta.1+build.5');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The other direction: a pin with trailing junk must not be truncated
     * to a version that happens to match, certifying lockstep for a tag
     * that does not exist. It is not a version, so the vacuity guard is
     * what reports it.
     */
    #[Test]
    #[DataProvider('trailingJunkProvider')]
    public function rejectsAPinWithTrailingJunkInsteadOfTruncatingToABareVersion(string $junk): void
    {
        $dir = $this->writeCase('1.7.0', "npm install --save-dev github:magicsunday/coding-standard#1.7.0{$junk}");

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED');
    }

    /**
     * Only the ONE period a sentence ends on is prose. Stripping the whole
     * run of them reads `#1.7.0..` as the tag `1.7.0`, which certifies
     * lockstep for a pin that is written wrong — the same truncation the
     * trailing-junk cases exist to prevent, arrived at from the side the
     * sentence-end allowance opened. A git ref may not end in a period at
     * all, so what is left after the one strip is junk and is reported as such.
     */
    #[Test]
    public function rejectsAPinFollowedByMoreThanOnePeriod(): void
    {
        $dir = $this->writeCase('1.7.0', 'The pin is github:magicsunday/coding-standard#1.7.0..');

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED');
    }

    /**
     * The configuration the trailing-junk cases structurally cannot reach:
     * a junk pin BESIDE a well-formed one. Dropping an unrecognised pin
     * instead of reporting it is invisible there, because the vacuity
     * guard only fires when no pin is left.
     */
    #[Test]
    #[DataProvider('trailingJunkProvider')]
    public function rejectsAJunkPinEvenBesideAMatchingOne(string $junk): void
    {
        $dir = $this->writeCase('1.7.0', implode("\n", [
            'github:magicsunday/coding-standard#1.7.0',
            "and also github:magicsunday/coding-standard#1.7.0{$junk}",
        ]));

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED  README.md:2');
    }

    /**
     * The discriminator for the shape: a pin that differs only in its last
     * segment must still be caught, so the looser match is not simply truncating.
     */
    #[Test]
    public function rejectsAPinDifferingOnlyInThePatchSegment(): void
    {
        $dir = $this->writeCase('1.7.0', 'npm install --save-dev github:magicsunday/coding-standard#1.7.1');

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH');
    }

    /**
     * Both values this gate echoes come from the pull-request branch, and
     * its report goes to STDERR, which on GitHub Actions doubles as the
     * workflow-command channel. The version's literal `\n` is a valid JSON
     * escape, so json_decode() turns it into a real newline in the
     * decoded value — that real newline is what would open a forged
     * `::error::` line at column 0 if safeReportValue() did not scrub it.
     */
    #[Test]
    public function reportIsInertWhenAPackageJsonVersionAttemptsToForgeAWorkflowCommand(): void
    {
        $dir = $this->writeCase('1.7.0\n  ::Error::forged from a pull request', 'github:magicsunday/coding-standard#1.7.0');

        $this->assertGateReportIsInert(self::gate(), $dir, '1.7.0?  ::Error::forged from a pull request');
    }

    /**
     * A bare CR in a consumer value, which the `::` arm cannot see on its
     * own: grep-style line splitting works on LF, so a CR opens a line
     * that check never examines, while a real line reader treats it as a
     * line break. Dropping \r from safeReportValue's scrub class left this
     * case passing silently before it existed.
     */
    #[Test]
    public function reportIsInertWhenAConsumerValueCarriesABareCarriageReturn(): void
    {
        $dir = $this->writeCase('1.7.0\r::Error::forged from a pull request', 'github:magicsunday/coding-standard#1.7.0');

        $this->assertGateReportIsInert(self::gate(), $dir, '1.7.0?::Error::forged');
    }

    /**
     * The scrub breaks `#[`, the shorter legacy-command prefix, so that a
     * scrubbed value cannot combine with the constant text around it into
     * a legacy command. This pins that shorter break directly; the full
     * `##[` case below follows from it by subsumption.
     */
    #[Test]
    public function reportIsInertWhenAConsumerValueCompletesALegacyPrefixTheReportsOwnHashStarts(): void
    {
        $dir = $this->writeCase('1.7.0#[error]forged from a pull request', 'github:magicsunday/coding-standard#1.7.0');

        $this->assertGateReportIsInert(self::gate(), $dir, '1.7.0#?[error]forged from a pull request');
    }

    /**
     * The full legacy `##[…]` workflow-command shape.
     */
    #[Test]
    public function reportIsInertWhenAPackageJsonVersionAttemptsToForgeALegacyWorkflowCommand(): void
    {
        $dir = $this->writeCase('1.7.0##[error]forged from a pull request', 'github:magicsunday/coding-standard#1.7.0');

        $this->assertGateReportIsInert(self::gate(), $dir, '1.7.0##?[error]forged from a pull request');
    }

    /**
     * A terminal escape carried in a README pin, not the package.json
     * version — the counterpart trust boundary this gate reads from.
     */
    #[Test]
    public function reportIsInertWhenAReadmePinCarriesATerminalEscape(): void
    {
        $dir = $this->writeCase('1.7.0', "github:magicsunday/coding-standard#1.7.0\x1BcHIDDEN");

        $this->assertGateReportIsInert(self::gate(), $dir, '1.7.0?cHIDDEN');
    }

    /**
     * Writes a package.json/README.md pair into this test's fixture
     * directory, mirroring the deleted tests/check-version-lockstep-cases.sh's
     * own mk_case() helper — every literal escape sequence in $version
     * (`\n`, `\r`) is inserted verbatim into the JSON string literal,
     * exactly as bash's single-quoted arguments did, so json_decode() (not
     * this method) is what turns it into a real control character.
     *
     * @param string $version    The package.json `version` field.
     * @param string $readmeBody The full content of README.md, without its trailing newline.
     *
     * @return string The fixture directory's path.
     */
    private function writeCase(string $version, string $readmeBody): string
    {
        $dir = $this->fixture()->path();

        file_put_contents(
            $dir . '/package.json',
            sprintf("{\n    \"name\": \"@magicsunday/coding-standard\",\n    \"version\": \"%s\"\n}\n", $version),
        );
        file_put_contents($dir . '/README.md', $readmeBody . "\n");

        return $dir;
    }

    /**
     * @return string Absolute path to the repository root.
     */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return list<string> The interpreter and gate script under test.
     */
    private static function gate(): array
    {
        return ['php', self::root() . '/tests/check-version-lockstep.php'];
    }

    /**
     * Builds a plain-text document of EXACTLY $bound bytes: $prefix and
     * $suffix are kept byte-for-byte, and the gap between them is filled
     * with $filler repeated enough times to land the whole document on the
     * cap. Ported from tests/harness.sh's harness_pad_text_to_cap().
     *
     * @param int    $bound  The exact byte length the returned document must have.
     * @param string $prefix Content kept byte-for-byte at the start.
     * @param string $filler A single filler character, repeated to fill the gap.
     * @param string $suffix Content kept byte-for-byte at the end.
     *
     * @return string
     */
    private static function padTextToCap(int $bound, string $prefix, string $filler, string $suffix): string
    {
        $pad = $bound - strlen($prefix) - strlen($suffix);
        $out = $prefix . str_repeat($filler, $pad) . $suffix;

        self::assertSame($bound, strlen($out), sprintf('fixture is %d bytes, not the cap of %d', strlen($out), $bound));

        return $out;
    }
}
