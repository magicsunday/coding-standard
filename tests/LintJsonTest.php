<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateProcess;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function chmod;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function mkdir;
use function posix_getuid;
use function sprintf;
use function str_repeat;
use function strlen;
use function symlink;

/**
 * Fixture-driven cases for tests/lint-json.php, migrated off
 * tests/lint-json-cases.sh (#71).
 *
 * Run against this repository alone, the gate only ever takes the happy
 * path — every shipped JSON file parses — so a green CI is
 * indistinguishable from a gate that cannot fail, or one whose discovery
 * quietly stopped finding anything at all. These cases put it in each
 * failing state on purpose, and prove the DISCOVERY itself — not a
 * hand-kept list, and the defect #41 exists to close — actually finds what
 * it claims to and prunes what it should. Like CheckVersionLockstepTest,
 * this gate is one of this package's own tests/*.php scripts and needs no
 * installed consumer fixture, so GateTestCase's accept/reject/
 * report-is-inert exit-code contract applies directly, and this class needs
 * no setUp() self-skip; it runs as part of the plain `composer
 * ci:test:phpunit` step.
 *
 * The bash original's bookkeeping self-tests (probe_assert_ok_inert_shapes
 * via harness_probe_reporters, and harness_assert_no_stray_increments) are
 * not ported: GateTestCase's own meta-suite already proves its decisions
 * generically, and assertGateAcceptsWithInertReport() below — the one
 * decision local to this class, as assert_ok_report_is_inert was local to
 * the bash file — is a plain sequence of real assertions with no counter
 * left to wire up or arm left that could silently stop deciding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class LintJsonTest extends GateTestCase
{
    /**
     * The largest JSON file the gate reads whole, in bytes. Mirrors
     * MAX_JSON_LINT_BYTES in tests/lint-json.php.
     */
    private const int MAX_JSON_LINT_BYTES = 1048576;

    /**
     * The deepest a discovered directory may sit below the scan root.
     * Mirrors MAX_SCAN_DEPTH in tests/lint-json.php.
     */
    private const int MAX_SCAN_DEPTH = 20;

    /**
     * The name several fixtures below share to prove safeReportValue()
     * wiring at their own report site: it breaks a legacy `##[…]` workflow
     * command the same way CheckVersionLockstepTest's own
     * reportIsInertWhenAPackageJsonVersionAttemptsToForgeALegacyWorkflowCommand
     * case does, and the double `#` is what would survive an unscrubbed
     * report and reach the runner mid-line, not only at column 0.
     */
    private const string FORGED = '1.7.0##[error]forged.json';

    /**
     * FORGED as safeReportValue() scrubs it — what the report must carry.
     */
    private const string SCRUBBED = '1.7.0##?[error]forged.json';

    /**
     * A strict-JSON document every "well-formed" fixture below uses.
     */
    private const string WELL_FORMED = "{\"a\": 1}\n";

    /**
     * A truncated document json_decode() rejects.
     */
    private const string MALFORMED = "{\n    \"a\":\n";

    /**
     * Every entry of PRUNED_DIRECTORY_NAMES in tests/lint-json.php, each
     * proven on its own so a failure names the one entry that stopped
     * pruning.
     *
     * @return array<string, array{0: string}>
     */
    public static function prunedDirectoryProvider(): array
    {
        return [
            '.build'       => ['.build'],
            'vendor'       => ['vendor'],
            'node_modules' => ['node_modules'],
            '.git'         => ['.git'],
        ];
    }

    /**
     * Discovery: the canon.
     */
    #[Test]
    public function acceptsADirectoryCarryingOneWellFormedJsonFile(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/a.json', self::WELL_FORMED);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Vacuity guard: a scan that matches nothing must not read as a clean
     * run. The same failure mode tests/check-version-lockstep.php's own
     * vacuity guard exists to prevent — a README documenting no pin at
     * all — applied here to a directory listing instead of to a regex
     * match.
     */
    #[Test]
    public function rejectsADirectoryWithNoJsonFilesAtAll(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/readme.md', "not json\n");

        $this->assertGateRejects(self::gate(), $dir, 'matched nothing');
    }

    /**
     * Malformed content.
     */
    #[Test]
    public function rejectsAMalformedJsonFile(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/broken.json', self::MALFORMED);

        $this->assertGateRejects(self::gate(), $dir, 'INVALID  broken.json');
    }

    /**
     * Missing: a dangling symlink is discovered but resolves to nothing.
     * The discovery walk lists it as a directory entry named *.json;
     * is_file() resolves the target and reports false. This is the one way
     * "missing" can still occur once the file list is no longer hand-kept —
     * a name in an array that was never created cannot happen when the
     * array itself came from what is actually on disk.
     */
    #[Test]
    public function rejectsADanglingSymlinkNamedJson(): void
    {
        $dir = $this->fixture()->path();
        $this->link($dir . '/does-not-exist', $dir . '/dangling.json');

        $this->assertGateRejects(self::gate(), $dir, 'MISSING  dangling.json');
    }

    /**
     * Unreadable: permissions revoked. Skipped for uid 0, the same as
     * the CheckConsumerConfig*Test classes' own unreadable-config cases: root bypasses
     * DAC, so mode 000 stays readable and this would read as a false
     * regression rather than a caught violation. CI runs non-root, so the
     * branch stays exercised there.
     */
    #[Test]
    public function rejectsAJsonFileWithNoReadPermission(): void
    {
        $this->skipIfRunningAsRoot();

        $dir  = $this->fixture()->path();
        $file = $dir . '/locked.json';
        $this->write($file, self::WELL_FORMED);
        chmod($file, 0o000);

        try {
            $this->assertGateRejects(self::gate(), $dir, 'UNREADABLE  locked.json');
        } finally {
            chmod($file, 0o644);
        }
    }

    /**
     * Pruning: vendored/installed trees and VCS metadata are never
     * scanned. One well-formed file at the top proves the run is not
     * vacuous; a MALFORMED file inside the pruned directory proves the
     * prune actually skips content rather than merely not adding it to
     * some other list — a gate that pruned nothing here would still read
     * it as a violation and reject. The bash original put all four pruned
     * directories into one fixture; one case per directory name decides
     * the same thing and names the entry that regressed.
     *
     * @param string $prunedName A PRUNED_DIRECTORY_NAMES entry.
     */
    #[Test]
    #[DataProvider('prunedDirectoryProvider')]
    public function acceptsMalformedJsonUnderAPrunedDirectory(string $prunedName): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->write($dir . '/' . $prunedName . '/broken.json', "not json\n");

        $this->assertGateAccepts(self::gate(), $dir, sprintf('malformed JSON under %s is never scanned', $prunedName));
    }

    /**
     * Excluded: both EXCLUDED_JSON_FILES entries proven together, the same
     * one-fixture-many-arms shape the bash original's pruned-dirs case
     * used. tests/consumer/tsconfig.json is JSONC by design (`tsc` accepts
     * comments and trailing commas there); package-lock.json is npm's own
     * gitignored, locally-generated lockfile. Each is given content that
     * would otherwise fail (a comment for one, plain non-JSON for the
     * other), so the accept only holds if BOTH are actually skipped rather
     * than merely absent from some other list.
     */
    #[Test]
    public function acceptsWhenBothExcludedEntriesCarryContentThatWouldOtherwiseFail(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->write($dir . '/tests/consumer/tsconfig.json', "{\n    // a comment, not valid strict JSON\n    \"a\": 1,\n}\n");
        $this->write($dir . '/package-lock.json', "not json\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Root argument: a nonexistent directory reports rather than crashing,
     * and the path itself cannot forge a workflow command in that report
     * either. The argument is under the caller's own control (never a
     * discovered file name), but safeReportValue() wraps it anyway —
     * proven here rather than assumed, the same way every discovered-file
     * report site is proven rather than assumed.
     *
     * Passed as a RELATIVE path, unlike the bash original's
     * `$work/<forged>-does-not-exist-at-all`: the gate echoes the argument
     * as given, and safeReportValue() caps it at 64 bytes — the bash
     * harness's short mktemp root left room for the forged segment inside
     * that cap, but this class's longer fixture root
     * (sys_get_temp_dir()/gate-fixture-<32 hex>/) alone would push the
     * must-carry segment past it. Relative to this process's own working
     * directory, the name exists nowhere, which is all the case needs.
     */
    #[Test]
    public function reportIsInertWhenANonexistentRootIsNamedToCarryALegacyWorkflowCommand(): void
    {
        $this->assertGateReportIsInert(
            self::gate(),
            self::FORGED . '-does-not-exist-at-all',
            self::SCRUBBED,
        );
    }

    /**
     * safeReportValue() wiring, INVALID report site: the file name is
     * consumer-controlled, not this repository's own choice, once
     * discovery replaces the hand-kept list. Each report site is proven
     * separately — a probe only ever reaches the branch its own fixture
     * takes, leaving the others free to lose their scrub.
     */
    #[Test]
    public function reportIsInertWhenAMalformedFileIsNamedToCarryALegacyWorkflowCommand(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/' . self::FORGED, self::MALFORMED);

        $this->assertGateReportIsInert(self::gate(), $dir, self::SCRUBBED);
    }

    /**
     * safeReportValue() wiring, MISSING report site.
     */
    #[Test]
    public function reportIsInertWhenADanglingSymlinkIsNamedToCarryALegacyWorkflowCommand(): void
    {
        $dir = $this->fixture()->path();
        $this->link($dir . '/does-not-exist', $dir . '/' . self::FORGED);

        $this->assertGateReportIsInert(self::gate(), $dir, self::SCRUBBED);
    }

    /**
     * safeReportValue() wiring, UNREADABLE report site. Same root-uid skip
     * as rejectsAJsonFileWithNoReadPermission().
     */
    #[Test]
    public function reportIsInertWhenAnUnreadableFileIsNamedToCarryALegacyWorkflowCommand(): void
    {
        $this->skipIfRunningAsRoot();

        $dir  = $this->fixture()->path();
        $file = $dir . '/' . self::FORGED;
        $this->write($file, self::WELL_FORMED);
        chmod($file, 0o000);

        try {
            $this->assertGateReportIsInert(self::gate(), $dir, self::SCRUBBED);
        } finally {
            chmod($file, 0o644);
        }
    }

    /**
     * safeReportValue() wiring, OK report site — on the ACCEPT path, which
     * assertGateReportIsInert() (a reject-only decision) cannot cover; see
     * assertGateAcceptsWithInertReport().
     */
    #[Test]
    public function acceptsAndReportsInertlyAWellFormedFileNamedToCarryALegacyWorkflowCommand(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/' . self::FORGED, self::WELL_FORMED);

        $this->assertGateAcceptsWithInertReport($dir, self::SCRUBBED);
    }

    /**
     * Discovery actually recurses into an ORDINARY nested directory. Every
     * other fixture here puts its interesting file at the fixture root, or
     * nested only under a name the prune list already covers. A scanner
     * that only ever looked at the root's immediate entries would still
     * pass every other case — this is the one that requires descending
     * through a plain, unpruned directory.
     */
    #[Test]
    public function rejectsAMalformedFileNestedUnderAnOrdinaryDirectory(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/config/sub/deep.json', self::MALFORMED);

        $this->assertGateRejects(self::gate(), $dir, 'INVALID  config/sub/deep.json');
    }

    /**
     * A symlinked DIRECTORY is never descended into either. Not because of
     * anything the gate's own code decides — verified by mutation that no
     * code change there affects it — but because
     * RecursiveDirectoryIterator::hasChildren() itself reports false for a
     * symlinked entry (see discoverJsonFiles()'s own docblock). Pinned as
     * a regression guard: a well-meaning future edit that adds
     * FOLLOW_SYMLINKS thinking it is needed for something else would
     * silently start walking a malformed *.json inside the real target and
     * reporting it as this gate's own finding.
     */
    #[Test]
    public function acceptsWhenASymlinkedDirectoryIsNeverDescendedInto(): void
    {
        $work = $this->fixture()->path();
        $this->write($work . '/target-dir/leaked.json', "not json\n");

        $dir = $work . '/symlinked-dir';
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->link($work . '/target-dir', $dir . '/linked');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Symlink escape: a LIVE symlink must not be read through, even when
     * its target exists and is valid JSON. The dangling-symlink case above
     * already forced is_file() to fail; that alone does not prove the gate
     * refuses to FOLLOW a working symlink. Without the is_link() check, a
     * leaf `escape.json` pointing at real, well-formed JSON outside the
     * scan root would resolve, read, parse and report OK — turning every
     * report line into an oracle for what exists, is readable and happens
     * to be valid JSON at an arbitrary path the symlink names.
     */
    #[Test]
    public function rejectsALiveSymlinkToAWellFormedFileOutsideTheScanRoot(): void
    {
        $work    = $this->fixture()->path();
        $outside = $work . '/outside-target.json';
        $this->write($outside, self::WELL_FORMED);

        $dir = $work . '/symlink-escape';
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->link($outside, $dir . '/escape.json');

        $this->assertGateRejects(self::gate(), $dir, 'MISSING  escape.json');
    }

    /**
     * Root argument: an EXISTING file (not a directory) reports the same
     * "Not a directory" verdict as a path that does not exist at all.
     * realpath() only fails for a path that is entirely absent; it resolves
     * an existing non-directory just fine, which would otherwise fall
     * through to the generic "Could not scan" handler with a less specific
     * message.
     */
    #[Test]
    public function rejectsARootArgumentThatIsAnExistingFileNotADirectory(): void
    {
        $file = $this->fixture()->path() . '/a-plain-file';
        $this->write($file, "not a directory\n");

        $this->assertGateRejects(self::gate(), $file, 'Not a directory');
    }

    /**
     * An unreadable subdirectory aborts the WHOLE scan, not just that
     * branch, and the exception message this throws cannot forge a
     * workflow command either. RecursiveIteratorIterator is left to throw
     * rather than swallowing a per-directory failure (see
     * discoverJsonFiles()'s own docblock). The directory name carries the
     * same forged sequence as the other report-is-inert cases:
     * UnexpectedValueException::getMessage() embeds the full path it failed
     * to open, so an unscrubbed catch block would put a directory NAME —
     * not just a discovered file name — into the report.
     *
     * No must-carry argument on the inert assertion, deliberately: the
     * message also embeds the fixture's full temp path ahead of the
     * directory name, and safeReportValue()'s 64-byte cap can truncate the
     * forged segment away entirely before this assertion ever sees it —
     * which is a safe outcome, not a failure to detect. What must hold
     * regardless of where the cap lands is that no RAW `##[` survives,
     * which assertGateReportIsInert() checks unconditionally.
     *
     * Two separate assertions, not one: the inert check alone would also
     * pass a regression that silently SKIPPED the unreadable directory
     * instead of aborting on it — the scan would then find nothing at all
     * and exit 1 through the vacuity guard's own static, already-inert
     * message, which is a caught violation for the WRONG reason. "Could not
     * scan" is a literal prefix outside any safeReportValue() call, so it
     * survives the 64-byte cap regardless of where the (possibly
     * truncated) forged segment lands — unlike that segment, it is safe to
     * assert verbatim here.
     *
     * Same root-uid skip as the two file-based unreadable cases — root
     * bypasses DAC on a directory too, so the locked directory would still
     * be enumerable (and empty), and the run would exit 1 through the
     * VACUITY guard instead of through the scan-abort path this case
     * exists to exercise: a silent pass for the wrong reason, not a caught
     * violation either way.
     */
    #[Test]
    public function rejectsInertlyWhenAnUnreadableSubdirectoryAbortsTheWholeScan(): void
    {
        $this->skipIfRunningAsRoot();

        $dir    = $this->fixture()->path();
        $locked = $dir . '/' . self::FORGED . '-dir';
        $this->makeDirectory($locked);
        chmod($locked, 0o000);

        try {
            $this->assertGateRejects(self::gate(), $dir, 'Could not scan', 'an unreadable subdirectory aborts the whole scan');
            $this->assertGateReportIsInert(self::gate(), $dir, null, 'an unreadable subdirectory aborts the whole scan, inertly');
        } finally {
            // Restored before tearDown(): FixtureDirectory::cleanup() cannot
            // scandir() a mode-000 directory to remove it.
            chmod($locked, 0o700);
        }
    }

    /**
     * A directory nested at MAX_SCAN_DEPTH fails the whole scan, rather
     * than silently completing without it. A well-formed kept.json at the
     * top would defeat the vacuity guard on its own, and a malformed file
     * past the depth bound would never be reached to report — exactly the
     * "partial scan reads as a clean one" failure mode the gate's own
     * vacuity guard exists to rule out for an EMPTY scan, reached here
     * through depth instead of through a hand-kept list. Built to EXACTLY
     * MAX_SCAN_DEPTH nested directories, not one level past it: the throw
     * fires on `$depth >= MAX_SCAN_DEPTH`, so a chain one level deeper than
     * necessary would still trip a `>` mutant of that comparison and not
     * tell the two apart.
     */
    #[Test]
    public function rejectsADirectoryNestedExactlyMaxScanDepthLevelsDeep(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->makeDirectory($dir . str_repeat('/nested', self::MAX_SCAN_DEPTH));

        $this->assertGateRejects(self::gate(), $dir, 'Could not scan');
    }

    /**
     * The level immediately BELOW that bound is still accepted. The
     * companion to the case above: without it, a regression that tightened
     * the comparison (rejecting one level earlier than intended) would pass
     * every other case here, since none of them nest this deep at all.
     */
    #[Test]
    public function acceptsADirectoryNestedOneLevelShortOfMaxScanDepth(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->makeDirectory($dir . str_repeat('/nested', self::MAX_SCAN_DEPTH - 1));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The largest file the gate reads whole has a bound, checked at the
     * read rather than measured after it. A gate that read every
     * discovered file unbounded is exactly what a hand-kept list never
     * exposed — every file it named was one this repository's own authors
     * wrote, a few kilobytes at most. Discovery removes that guarantee: a
     * pull request can add a *.json file of any size under an unpruned
     * path.
     */
    #[Test]
    public function rejectsAJsonFilePastTheSizeTheGateReadsWhole(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/kept.json', self::WELL_FORMED);
        $this->write($dir . '/huge.json', str_repeat('9', self::MAX_JSON_LINT_BYTES + 1));

        $this->assertGateRejects(self::gate(), $dir, 'TOO LARGE  huge.json');
    }

    /**
     * The level immediately AT that bound is still accepted. The companion
     * to the case above, the same pairing MAX_SCAN_DEPTH gets: without it,
     * a regression that rejected one byte earlier than intended (comparing
     * `>=` instead of `>`) would pass every other case here, since none of
     * them build a file this exact size. Exactly MAX_JSON_LINT_BYTES bytes
     * of valid JSON: a 6-byte prefix, a 2-byte suffix, and padding filling
     * the rest.
     */
    #[Test]
    public function acceptsAJsonFileExactlyAtTheSizeTheGateReadsWhole(): void
    {
        $dir      = $this->fixture()->path();
        $contents = '{"a":"' . str_repeat('9', self::MAX_JSON_LINT_BYTES - 8) . '"}';
        self::assertSame(self::MAX_JSON_LINT_BYTES, strlen($contents), 'the fixture is not exactly at the cap');
        $this->write($dir . '/exact.json', $contents);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Vacuity guard, reached the OTHER way: files exist, but every one of
     * them is on the exclusion list. The "no JSON files at all" case above
     * reaches the same message through pre-filter emptiness (the walk
     * finds nothing). This one reaches it through post-filter emptiness —
     * discovery finds files, and EXCLUDED_JSON_FILES removes every one of
     * them — a route the pre-filter fixture cannot exercise.
     */
    #[Test]
    public function rejectsWhenEveryDiscoveredFileIsOnTheExclusionList(): void
    {
        $dir = $this->fixture()->path();
        $this->write($dir . '/tests/consumer/tsconfig.json', "{\n    // JSONC by design\n    \"a\": 1\n}\n");
        $this->write($dir . '/package-lock.json', "not json\n");

        $this->assertGateRejects(self::gate(), $dir, 'matched nothing');
    }

    /**
     * The "accepted, and the report stayed inert" decision, local to this
     * class the same way the bash original's assert_ok_report_is_inert was
     * local to tests/lint-json-cases.sh: assertGateReportIsInert() exists
     * for the REJECT verdict (exit 1) only, because every other gate's
     * report sites sit on that path. This gate ALSO echoes a
     * consumer-controlled file name on its ACCEPT path — every well-formed
     * file gets printed too — which is the common case, not the rare one:
     * most files a pull request adds parse just fine.
     *
     * The must-carry check closes the same gap assertGateReportIsInert()'s
     * own must-carry argument closes on the reject path: absence of `##[`
     * alone is also satisfied by a value that never reached the report at
     * all — inert BY OMISSION rather than by scrubbing. Without it, a
     * regression that silently dropped the OK line for a suspicious file
     * name (rather than scrubbing and printing it) would pass: exit 0, and
     * no `##[` anywhere because nothing about the file was printed at all.
     *
     * Every check that touches the report goes through a manual condition
     * + self::fail() with a scrubbed message (ScrubbedDiagnostics), never a
     * PHPUnit string constraint, for the reason GateTestCase's own class
     * docblock gives.
     *
     * @param string $fixtureDir                The directory to run the gate against.
     * @param string $expectedScrubbedSubstring The scrubbed value the report must carry.
     *
     * @return void
     *
     * @throws AssertionFailedError If the gate ran degraded, did not accept, forged a legacy command, or omitted the value.
     */
    private function assertGateAcceptsWithInertReport(string $fixtureDir, string $expectedScrubbedSubstring): void
    {
        $result = (new GateProcess())->run(self::gate(), $fixtureDir);

        self::assertFalse($result->isDegraded(), 'The gate ran degraded — it emitted a diagnostic.');

        if ($result->exitCode !== 0) {
            self::fail(self::diagnosticMessage(sprintf('Expected accept, got exit %d.', $result->exitCode), $result->output));
        }

        self::assertOutputDoesNotContain(
            $result,
            '##[',
            'A consumer-controlled file name forged the legacy workflow-command prefix.',
        );

        self::assertOutputContains(
            $result,
            $expectedScrubbedSubstring,
            'The scrubbed value never reached the report — inert by omission, not by scrubbing.',
        );
    }

    /**
     * Skips the calling test when running as root: uid 0 bypasses DAC, so
     * mode 000 stays readable — a false regression, not a real one. CI runs
     * non-root, so the branch stays exercised there. Same helper as
     * AbstractConsumerConfigTestCase's own.
     *
     * @return void
     */
    private function skipIfRunningAsRoot(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }
    }

    /**
     * Writes $contents to $path, creating any missing parent directory.
     *
     * @param string $path     Absolute path inside this test's fixture directory.
     * @param string $contents The exact bytes to write.
     *
     * @return void
     *
     * @throws RuntimeException If the parent directory or the file cannot be written.
     */
    private function write(string $path, string $contents): void
    {
        $this->makeDirectory(dirname($path));

        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException(sprintf('Could not write fixture file: %s', $path));
        }
    }

    /**
     * Creates $path and any missing parent, a no-op when it already exists.
     *
     * @param string $path Absolute path inside this test's fixture directory.
     *
     * @return void
     *
     * @throws RuntimeException If the directory cannot be created.
     */
    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0o700, true)) {
            throw new RuntimeException(sprintf('Could not create fixture directory: %s', $path));
        }
    }

    /**
     * Creates the symlink $link pointing at $target, which need not exist.
     *
     * @param string $target The path the symlink names.
     * @param string $link   The symlink to create.
     *
     * @return void
     *
     * @throws RuntimeException If the symlink cannot be created.
     */
    private function link(string $target, string $link): void
    {
        if (!symlink($target, $link)) {
            throw new RuntimeException(sprintf('Could not create fixture symlink: %s', $link));
        }
    }

    /**
     * @return list<string> The interpreter and gate script.
     */
    private static function gate(): array
    {
        return ['php', self::root() . '/tests/lint-json.php'];
    }
}
