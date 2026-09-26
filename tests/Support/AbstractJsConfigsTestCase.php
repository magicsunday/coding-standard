<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use MagicSunday\CodingStandard\Test\GateTestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_key_exists;
use function array_values;
use function bin2hex;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rtrim;
use function sprintf;
use function str_contains;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * Shared infrastructure for the suites migrated off tests/check-js-configs.sh
 * (#79) — everything EXCEPT manifest_check()'s own fixtures, which live in
 * the sibling tests/CheckJsConfigsManifestTest.php because that validator
 * needs no packaging pipeline. Split out of the former single
 * tests/CheckJsConfigsTest.php (#75, #52) along that file's own section
 * banners, into four `final` suites extending this class:
 *
 *   - tests/CheckJsConfigsConsumerSmokeTest.php — the packaged-consumer
 *     smoke: real Biome/tsc/jscpd against the shared configs as installed
 *     from the tarball (the accept smoke, the "rules must bite" controls,
 *     the templates/jscpd.json format names);
 *   - tests/CheckJsConfigsPackagingTest.php — the packaging controls: the
 *     `--ignore-scripts` enforcement, the installed npm bin entry, the
 *     `files` allow-list vs. the tarball, and the `.gitattributes`
 *     export-ignore completeness sweep with its archiveIndexInto()/
 *     pathsMissingFromArchive() fixtures;
 *   - tests/CheckJsConfigsToolPinsTest.php — the devDependencies tool pins,
 *     needing no packaging at all: build_tools_from_devdependencies()'s own
 *     accept/reject fixtures and the README tool-version-pin lockstep;
 *   - tests/CheckJsConfigsHarnessTest.php — the regression tests for this
 *     class's own harness: tearDown()'s restore-failure handling,
 *     makeTempDir()'s mkdir()-failure branch, the npm pack/init/install
 *     throw sites' scrubbing, and the shared scrub core itself.
 *
 * This class covers what those suites share: packing this package the way
 * npm ships it (`git archive` of the committed tree, so `.gitattributes`
 * export-ignore applies, then `npm pack --ignore-scripts`), installing the
 * tarball into ONE throwaway npm project, and the helpers every suite
 * drives it through (runCommand(), mutateConsumerFile(), makeTempDir(),
 * the requirePacked*()/requireSuccessful*() throw sites,
 * assertMessageDoesNotForgeWorkflowCommand()).
 *
 * packagedConsumer() builds that ONE throwaway project lazily, once per test
 * run: self::$packagedConsumer is declared here and nowhere else, and a
 * static property a subclass does not redeclare is shared storage across
 * every subclass, so whichever of the four suites runs first pays for the
 * one `npm install` and every later suite reuses it. Every test that reuses
 * it either reads it without mutation or mutates a file through
 * mutateConsumerFile(), which this class's own tearDown() restores (an
 * existing file) or removes (a file the test created) after every test — so
 * the fixture's baseline state (a passing biome.json/tsconfig.json extending
 * the installed package, plus one clean src file) is never actually
 * order-dependent, even though PHPUnit's own
 * `executionOrder="depends,defects"` (phpunit.xml.dist) does not guarantee
 * declaration order, within a suite or across them.
 *
 * Because the cache outlives any single suite, its directories are NOT
 * removed by a tearDownAfterClass() (which would run once per subclass and
 * force the next suite to reinstall); packagedConsumer() instead registers
 * removeTemporaryDirectories() once, via register_shutdown_function(), on the
 * first build — see that method's own docblock.
 *
 * `#[Group('js-packaging')]` on every subclass (and on
 * CheckJsConfigsManifestTest) marks it as PHP-version-invariant: the
 * packaging pipeline this class drives (git archive, npm pack/install,
 * Biome/tsc/jscpd) exercises none of this package's own PHP-version-dependent
 * code, so .github/workflows/ci.yml's `build` job runs the group on only ONE
 * matrix leg (`php == '8.3'`, this repository's own floor) rather than once
 * per PHP version — see that workflow's own PHPUnit step comment for the
 * reasoning.
 *
 * Ported and NOT ported, and why, not repeated per test method in the
 * subclasses:
 *   - probe_reporters, harness_probe_report_inertness and its own nested
 *     probe_work_nested_scratch_is_cleaned_up_after_hard_abort, and
 *     harness_assert_no_stray_increments's own bookkeeping check are
 *     bash-only plumbing protecting THIS FILE's hand-rolled `pass`/`fail`/
 *     `safe_report` echo helpers against a devDependency name or a `files`
 *     entry forging a workflow command in output a CI runner scans — not
 *     applicable to a normal PHPUnit assertion failure, which goes through
 *     PHPUnit's own trusted assertion API rather than an echoed report line.
 *     An UNCAUGHT exception message is a different matter and is not exempt
 *     from this concern: as observed 2026-09-05 against the installed
 *     PHPUnit (8.3-8.5), an uncaught exception's message is printed verbatim
 *     to console output —
 *     CheckJsConfigsToolPinsTest::buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand()'s
 *     own docblock points back to this observation rather than repeating
 *     it. See ScrubbedDiagnostics::scrubbedForDiagnostic()'s (inherited by
 *     this class) call sites in packagedConsumer() and
 *     buildToolsFromDevDependencies() below, which scrub subprocess error
 *     output for exactly that reason.
 *     bin/check-js-config.mjs's OWN report-inertness is a different, separate
 *     concern this bash file never actually drove through the real binary in
 *     the first place (grep confirms no such call site), so there is nothing
 *     of that shape to port here either.
 *   - harness_assert_tool_rejects's bash triad becomes
 *     CheckJsConfigsConsumerSmokeTest::assertRejectedForReason(), a private
 *     helper local to its one user (house convention: start local, promote
 *     to a shared base only on a second real need) rather than a literal ERE
 *     port — "reject, and every one of N patterns present" is expressed with
 *     preg_match() rather than bash's grep -E.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class AbstractJsConfigsTestCase extends GateTestCase
{
    /**
     * A byte-for-byte copy of build_tools_from_devdependencies()'s own
     * `node -e '...'` body from the now-PHPUnit-migrated check-js-configs.sh.
     */
    protected const string BUILD_TOOLS_SCRIPT = <<<'JS'
const d = require(process.env.ROOT + "/package.json").devDependencies;
const entries = Object.entries(d);
const unsafeAsArgument = (s) => typeof s !== "string" || s === "" || /\s/.test(s) || s.includes("\0") || s.startsWith("-");
const bad = entries.filter(([n, v]) => unsafeAsArgument(n) || unsafeAsArgument(v));
if (bad.length) {
    for (const [n, v] of bad) {
        console.error("devDependencies entry is not safe to pass to npm as an argument: " + JSON.stringify({ [n]: v }));
    }
    process.exit(1);
}
for (const [n, v] of entries) {
    console.log(n + "@" + v);
}
JS;

    /**
     * The lazily-built, run-scoped packaged consumer every subclass's
     * packaging-pipeline-dependent tests share, or null before the first one
     * that needs it runs. Declared here only, and read and written only
     * through `self::` inside this class, so it is ONE storage for every
     * subclass (`self::` binds to this declaring class, not the calling
     * subclass).
     *
     * @var array{consumerDir: string, archiveDir: string, archiveTree: string}|null
     */
    protected static ?array $packagedConsumer = null;

    /**
     * Every throwaway directory packagedConsumer() created, removed once by
     * removeTemporaryDirectories() at the end of the run.
     *
     * @var list<string>
     */
    private static array $temporaryDirectories = [];

    /**
     * Per-test record of every file mutateConsumerFile() touched in the
     * shared packagedConsumer(): the original content (a file that already
     * existed) or null (a file this test created), restored/removed by
     * tearDown() regardless of the test's own outcome.
     *
     * @var array<string, string|null>
     */
    private array $consumerFileMutations = [];

    /**
     * Whether removeTemporaryDirectories() is already registered as a
     * shutdown function — registered at most once per process, however many
     * times packagedConsumer() rebuilds after an invalidation.
     */
    private static bool $cleanupRegistered = false;

    /**
     * Restores or removes every file mutateConsumerFile() touched during
     * this test, then defers to GateTestCase's own per-test fixture()
     * cleanup. Guarded, not `@`-suppressed, the same way FixtureDirectory
     * guards its own filesystem calls: the shared, run-scoped
     * packagedConsumer() fixture is reused across every test method in every
     * subclass, so a silently failed restore here would corrupt state for every
     * REMAINING test in the run rather than just this one.
     *
     * Every entry is attempted — a failure on one path does not skip the
     * rest — and the report names every path that failed, not only the
     * first. A failure also invalidates the shared self::$packagedConsumer
     * cache, so the next test that calls packagedConsumer() rebuilds a
     * fresh, uncorrupted consumer from scratch instead of silently inheriting
     * the corruption and failing later for an unrelated reason. The restore
     * loop and the cache invalidation both run inside a finally block ahead
     * of the eventual throw, so $this->consumerFileMutations is always reset
     * and parent::tearDown() (this class's own fixture() cleanup) always
     * runs, even when a restore failed.
     *
     * parent::tearDown() is called through its OWN try/catch rather than
     * bare inside the finally block: a bare call whose own RuntimeException
     * (GateTestCase's fixture() cleanup failure) propagates from inside a
     * finally would otherwise replace this method's own pending "could not
     * restore" throw below without a trace, even though the restore failure
     * and its cache invalidation already ran correctly. Chaining both
     * messages when $failedPaths is also non-empty keeps the more specific
     * diagnostic visible instead of letting the parent's unrelated exception
     * silently win.
     *
     * @return void
     *
     * @throws RuntimeException If a mutated file could not be restored or removed, naming every such path, and/or the parent fixture cleanup itself failed.
     */
    protected function tearDown(): void
    {
        $failedPaths     = [];
        $parentException = null;

        try {
            foreach ($this->consumerFileMutations as $path => $original) {
                if ($original === null) {
                    if (FixtureDirectory::withoutWarnings(static fn (): bool => unlink($path)) !== true) {
                        $failedPaths[] = $path;
                    }

                    continue;
                }

                if (FixtureDirectory::withoutWarnings(static fn (): int|false => file_put_contents($path, $original)) !== strlen($original)) {
                    $failedPaths[] = $path;
                }
            }
        } finally {
            $this->consumerFileMutations = [];

            if ($failedPaths !== []) {
                self::$packagedConsumer = null;
            }

            try {
                parent::tearDown();
            } catch (RuntimeException $exception) {
                $parentException = $exception;
            }
        }

        if ($parentException instanceof RuntimeException) {
            if ($failedPaths === []) {
                throw $parentException;
            }

            throw new RuntimeException(
                sprintf('Could not restore or remove mutated consumer file(s): %s', implode(', ', $failedPaths)),
                0,
                $parentException,
            );
        }

        if ($failedPaths !== []) {
            throw new RuntimeException(sprintf('Could not restore or remove mutated consumer file(s): %s', implode(', ', $failedPaths)));
        }
    }

    /**
     * Removes every throwaway directory packagedConsumer() created. `rm -rf`
     * via a real subprocess rather than FixtureDirectory's own recursive walk:
     * that class is scoped to ONE random-named per-test root it created
     * itself, whereas this suite owns several, run-scoped, built outside
     * any single test's lifecycle.
     *
     * Runs ONCE, at the end of the PHP process, not from a
     * tearDownAfterClass(): the cache is shared by every subclass, and
     * tearDownAfterClass() fires once per subclass — clearing the cache there
     * would make every later suite repeat the whole pack-and-install, and
     * PHPUnit offers no "after the last class that still needs it" hook. A
     * shutdown function also runs when the process ends abnormally (a fatal
     * error, an exit() from inside a test), where no per-class hook runs at
     * all.
     *
     * @return void
     */
    private static function removeTemporaryDirectories(): void
    {
        foreach (self::$temporaryDirectories as $directory) {
            $process = new Process(['rm', '-rf', '--', $directory]);
            $process->run();
        }

        self::$temporaryDirectories = [];
        self::$packagedConsumer     = null;
    }

    /**
     * Registers removeTemporaryDirectories() as a shutdown function, the
     * first time packagedConsumer() creates a directory in this process.
     *
     * @return void
     */
    private static function registerTemporaryDirectoryCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }

        register_shutdown_function(static function (): void {
            self::removeTemporaryDirectories();
        });

        self::$cleanupRegistered = true;
    }

    /**
     * Creates a real, run-scoped temporary directory with a
     * collision-free name, the same way tests/Support/FixtureDirectory.php's
     * constructor does for its own per-test root: the path is generated
     * locally with `bin2hex(random_bytes(16))`, not reserved via
     * `uniqid()`'s weaker, time-seeded entropy, so mkdir() is the only
     * filesystem call that decides existence — no unlink()-then-recreate gap
     * for a co-resident process to win a symlink race in. `$label`
     * distinguishes the archive root from the consumer root in a directory
     * listing, nothing more.
     *
     * @param string      $label         A short, human-readable tag for this directory's purpose.
     * @param string|null $baseDirectory The directory to create the new directory under, or null
     *                                   for sys_get_temp_dir() — every real caller relies on that
     *                                   default; the override exists only so this method's own
     *                                   mkdir()-failure branch can be forced deterministically
     *                                   (a plain file at $baseDirectory), since the random suffix
     *                                   below cannot be pre-occupied by name.
     *
     * @return string The absolute path to the newly created directory.
     *
     * @throws RuntimeException If mkdir() cannot create the directory.
     */
    protected static function makeTempDir(string $label, ?string $baseDirectory = null): string
    {
        $path = sprintf('%s/coding-standard-js-%s-%s', $baseDirectory ?? sys_get_temp_dir(), $label, bin2hex(random_bytes(16)));

        if (!FixtureDirectory::withoutWarnings(static fn (): bool => mkdir($path, 0o700, true))) {
            throw new RuntimeException("Could not create temporary directory: {$path}");
        }

        return $path;
    }

    /**
     * Runs $command as a real subprocess, argv only, capturing stdout+stderr
     * combined in arrival order (matching the bash original's `2>&1`) — the
     * same contract GateProcess::runRaw() gives, generalised to the
     * arbitrary git/npm/tar/biome/tsc/jscpd invocations this suite drives
     * that GateProcess::run()'s own `<command...> <fixtureDir>` shape cannot
     * express (a fixture directory is not always $command's last positional
     * argument, or an argument at all). Delegates the spawn-and-capture body
     * itself to GateProcess::runRaw() rather than reimplementing it a third
     * time — see that method's own docblock for the "start local, promote on
     * second real need" precedent this class was the second caller of.
     *
     * @param list<string>          $command The interpreter/binary and its arguments.
     * @param string|null           $cwd     The working directory, or null for this process's own cwd.
     * @param array<string, string> $env     Extra environment variables, merged onto the inherited environment.
     *
     * @return GateResult
     */
    protected function runCommand(array $command, ?string $cwd = null, array $env = []): GateResult
    {
        return (new GateProcess())->runRaw($command, $cwd, $env, 300.0);
    }

    /**
     * Writes $content to $relativePath inside $dir, recording whatever was
     * there before (or null, for a brand-new file) so this test's own
     * tearDown() can undo it. Every call in a single test that targets the
     * SAME path only records the ORIGINAL content once, the same way the
     * bash original mutates one shared $work in place and relies on each
     * case restoring what it changed.
     *
     * @param string $dir          The consumer directory to write into (normally packagedConsumer()'s own consumerDir).
     * @param string $relativePath Path relative to $dir.
     * @param string $content      The content to write — see withTrailingNewline() for why this is normalised first.
     *
     * @return string The absolute path written.
     */
    protected function mutateConsumerFile(string $dir, string $relativePath, string $content): string
    {
        $path = "{$dir}/{$relativePath}";

        if (!array_key_exists($path, $this->consumerFileMutations)) {
            $this->consumerFileMutations[$path] = file_exists($path) ? (string) file_get_contents($path) : null;
        }

        $parent = dirname($path);

        if (!is_dir($parent)) {
            mkdir($parent, 0o755, true);
        }

        file_put_contents($path, self::withTrailingNewline($content));

        return $path;
    }

    /**
     * Normalises $content to end in exactly one trailing newline (a no-op on
     * an already-empty string). Every source fixture in this class is
     * authored as a PHP nowdoc, and PHP's own heredoc/nowdoc syntax strips
     * the single newline immediately before the closing identifier — unlike
     * the bash original's heredocs, which keep it — so a literal transcription
     * would silently hand Biome's formatter a file missing its trailing
     * newline and fail every accepting case on formatter drift instead of
     * the rule actually under test. Measured: every accept-path case in this
     * class failed on "File content differs from formatting output" before
     * this existed.
     *
     * @param string $content The content to normalise.
     *
     * @return string The normalised $content, with exactly one trailing newline, or unchanged if empty.
     */
    private static function withTrailingNewline(string $content): string
    {
        return $content === '' ? $content : rtrim($content, "\n") . "\n";
    }

    /**
     * The shared "must not carry $needle" shape
     * CheckJsConfigsToolPinsTest::buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand() and
     * CheckJsConfigsHarnessTest::assertRealThrowSiteCannotForgeAWorkflowCommand() each drove
     * separately before this existed: a manual str_contains() + self::fail(),
     * never assertStringContainsString()/assertStringNotContainsString() —
     * $haystack is exactly the value under test for a forged workflow
     * command, so it can legitimately still carry the poison on the very
     * regression each caller exists to catch, and PHPUnit's own
     * Constraint::fail()/failureDescription() mechanism unconditionally
     * re-embeds the FULL, RAW haystack into a failed assertion's own message
     * — as observed 2026-09-05 against this repository's own installed
     * PHPUnit (`.build/vendor/phpunit/phpunit`) — so a real failure of
     * either constraint would forge the very annotation each caller exists
     * to prove is prevented. self::fail() takes a literal string with no
     * such re-embedding, so $haystack is scrubbed through
     * ScrubbedDiagnostics::scrubbedForDiagnostic() (inherited by this class) before it is
     * handed to self::fail().
     *
     * @param string $haystack     The value to check, which may itself legitimately carry the poison.
     * @param string $needle       The forged-workflow-command substring $haystack must not carry.
     * @param string $failureLabel The failure message, used verbatim ahead of the scrubbed $haystack.
     *
     * @return void
     */
    protected static function assertMessageDoesNotForgeWorkflowCommand(string $haystack, string $needle, string $failureLabel): void
    {
        if (str_contains($haystack, $needle)) {
            self::fail(self::diagnosticMessage($failureLabel, $haystack));
        }
    }

    /**
     * The shared "reject unless $process succeeded, scrubbing the error
     * output first" shape buildToolsFromDevDependencies(), requireSuccessfulInit()
     * and requireSuccessfulInstall() below each drove separately before this
     * existed — requirePackedTarball() below keeps its own, differently-shaped
     * three-part condition (also checking the produced tarball name and its
     * existence on disk) and is deliberately NOT routed through this helper.
     *
     * @param Process $process The already-run subprocess to check.
     * @param string  $message The diagnostic prefix, used verbatim ahead of the scrubbed error output.
     *
     * @return void
     *
     * @throws RuntimeException If $process did not succeed.
     */
    private static function requireSuccessfulProcess(Process $process, string $message): void
    {
        if (!$process->isSuccessful()) {
            throw new RuntimeException(self::diagnosticMessage($message, $process->getErrorOutput()));
        }
    }

    /**
     * Runs BUILD_TOOLS_SCRIPT against $root's own package.json — the SAME
     * validation CheckJsConfigsToolPinsTest::runBuildToolsSeparated() drives
     * against a synthetic fixture — so packagedConsumer()'s real `npm install` argument
     * list is built from THIS repository's own devDependencies only after
     * they clear the identical unsafe-argument rejection, rather than being
     * trusted unconditionally. Mirrors the bash original's own
     * `mapfile -t tools < <(build_tools_from_devdependencies "$root" ...)`,
     * which ran this validation on every single invocation, not only in a
     * dedicated test.
     *
     * @param string $root The repository root to read package.json's devDependencies from.
     *
     * @return list<non-empty-string> Each devDependency as "name@version", safe to pass to npm as an argv element.
     *
     * @throws RuntimeException If a devDependencies entry is not safe to pass to npm as an argument, or if there are none.
     */
    protected static function buildToolsFromDevDependencies(string $root): array
    {
        $process = new Process(['node', '-e', self::BUILD_TOOLS_SCRIPT], null, ['ROOT' => $root]);
        $process->setTimeout(60.0);
        $process->run();

        self::requireSuccessfulProcess($process, "package.json's devDependencies are not safe to pass to npm as arguments.");

        $tools = array_values(array_filter(explode("\n", trim($process->getOutput())), static fn (string $tool): bool => $tool !== ''));

        if ($tools === []) {
            throw new RuntimeException('no devDependencies in package.json — nothing to pin the smoke to.');
        }

        return $tools;
    }

    /**
     * packagedConsumer()'s own `npm pack` throw site, extracted so a test can
     * drive it against a REAL, already-run, deliberately failing $pack rather
     * than hand-reconstructing the message packagedConsumer() would have
     * thrown for it — the discriminating half of
     * CheckJsConfigsHarnessTest::npmPackFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(),
     * this method's own second real caller besides packagedConsumer()
     * itself: calling it directly and catching the RuntimeException it
     * actually throws is what makes that test fail if this method's own
     * scrubbedForDiagnostic() wrap were ever reverted, which a hand-reconstructed
     * expected message could not do.
     *
     * @param Process $pack        The already-run `npm pack` subprocess.
     * @param string  $consumerDir The directory `npm pack` was told to write the tarball into.
     *
     * @return string The produced tarball's filename (relative to $consumerDir), once $pack is confirmed successful and non-empty.
     *
     * @throws RuntimeException If $pack failed, produced no tarball name, or the named tarball does not exist on disk.
     */
    protected static function requirePackedTarball(Process $pack, string $consumerDir): string
    {
        $tarball = trim($pack->getOutput());

        if (!$pack->isSuccessful() || ($tarball === '') || !file_exists("{$consumerDir}/{$tarball}")) {
            throw new RuntimeException(self::diagnosticMessage('npm pack produced no tarball — cannot run the smoke.', $pack->getErrorOutput()));
        }

        return $tarball;
    }

    /**
     * packagedConsumer()'s own `npm init -y` throw site, extracted the same
     * way requirePackedTarball() above is — the discriminating half of
     * CheckJsConfigsHarnessTest::npmInitFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(),
     * this method's own second real caller.
     *
     * @param Process $init The already-run `npm init -y` subprocess.
     *
     * @return void
     *
     * @throws RuntimeException If $init failed.
     */
    protected static function requireSuccessfulInit(Process $init): void
    {
        self::requireSuccessfulProcess($init, 'npm init -y failed.');
    }

    /**
     * packagedConsumer()'s own `npm install` throw site, extracted the same
     * way requirePackedTarball() above is — the discriminating half of
     * CheckJsConfigsHarnessTest::npmInstallFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(),
     * this method's own second real caller.
     *
     * @param Process $install The already-run `npm install` subprocess.
     *
     * @return void
     *
     * @throws RuntimeException If $install failed.
     */
    protected static function requireSuccessfulInstall(Process $install): void
    {
        self::requireSuccessfulProcess($install, 'npm install failed — cannot run the smoke.');
    }

    /**
     * Extracts what $repository's index would ship into $archiveDir:
     * `git write-tree` of the index, then `git archive` of that tree, which
     * applies the tree's own `.gitattributes` export-ignore entries — the
     * artefact a `github:` install and the Composer dist archive both
     * receive. Shared by packagedConsumer() and the export-ignore fixtures
     * (#100), so those fixtures drive the same mechanism the smoke relies on
     * rather than a copy of it.
     *
     * @param string $repository A git work tree whose index is what would ship.
     * @param string $archiveDir An existing, empty directory to extract into.
     *
     * @return string The archived tree's object name.
     *
     * @throws RuntimeException If writing, archiving or extracting the tree failed.
     */
    protected static function archiveIndexInto(string $repository, string $archiveDir): string
    {
        $writeTree = new Process(['git', '-C', $repository, 'write-tree']);
        $writeTree->run();

        if (!$writeTree->isSuccessful()) {
            throw new RuntimeException('git write-tree failed — cannot determine what this commit would ship.');
        }

        $archiveTree = trim($writeTree->getOutput());

        $archive = new Process(['git', '-C', $repository, 'archive', $archiveTree]);
        $archive->setTimeout(300.0);
        $archive->run();

        if (!$archive->isSuccessful()) {
            throw new RuntimeException("git archive {$archiveTree} failed — cannot pack the artefact a consumer receives.");
        }

        $extract = new Process(['tar', '-x', '-C', $archiveDir]);
        $extract->setInput($archive->getOutput());
        $extract->setTimeout(300.0);
        $extract->run();

        if (!$extract->isSuccessful()) {
            throw new RuntimeException("git archive {$archiveTree} could not be extracted.");
        }

        return $archiveTree;
    }

    /**
     * The entries of $paths that are not present under $archiveDir — the
     * verdict CheckJsConfigsPackagingTest::everyPackagingSurfacePathReachesAConsumerThroughTheGitArchive()
     * reports on, held apart so the export-ignore fixtures (#100) can prove it
     * against a deliberately broken archive too. Empty entries are skipped.
     *
     * @param string       $archiveDir The extracted archive.
     * @param list<string> $paths      Repository-relative paths that must ship.
     *
     * @return list<string> The paths missing from the archive, in input order.
     */
    protected static function pathsMissingFromArchive(string $archiveDir, array $paths): array
    {
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => ($path !== '') && !file_exists("{$archiveDir}/{$path}"),
        ));
    }

    /**
     * Builds the ONE throwaway npm project every subclass's packaging-pipeline-
     * dependent tests share: `git write-tree` + `git archive` (the artefact a
     * `github:` install/`npm pack` actually ships, `.gitattributes`
     * export-ignore applied), `npm pack --ignore-scripts` on that archived
     * tree, `npm install --ignore-scripts` of the resulting tarball plus this
     * repository's own devDependencies (pinned exactly, the versions the
     * smoke actually proves, and validated by buildToolsFromDevDependencies()
     * before they ever reach npm's argv), then a canon biome.json/tsconfig.json extending
     * the installed package and one clean, correctly-formatted source file —
     * a permanently ACCEPTING baseline every other test either reads as-is
     * or mutates and restores via mutateConsumerFile(). Its own `npm pack`/
     * `npm init -y`/`npm install` failure branches are extracted to
     * requirePackedTarball()/requireSuccessfulInit()/requireSuccessfulInstall()
     * above so the three forgery-regression tests in CheckJsConfigsHarnessTest
     * can drive the SAME throw sites directly instead of duplicating their
     * logic. The first build in a process also registers the end-of-run
     * cleanup (registerTemporaryDirectoryCleanup()).
     *
     * @return array{consumerDir: string, archiveDir: string, archiveTree: string}
     */
    protected static function packagedConsumer(): array
    {
        if (self::$packagedConsumer !== null) {
            return self::$packagedConsumer;
        }

        $root = self::root();

        self::registerTemporaryDirectoryCleanup();

        $archiveDir                   = self::makeTempDir('archive');
        self::$temporaryDirectories[] = $archiveDir;
        $archiveTree                  = self::archiveIndexInto($root, $archiveDir);

        $consumerDir                  = self::makeTempDir('consumer');
        self::$temporaryDirectories[] = $consumerDir;

        $pack = new Process(['npm', 'pack', '--ignore-scripts', '--pack-destination', $consumerDir, '--loglevel=error'], $archiveDir);
        $pack->setTimeout(300.0);
        $pack->run();

        $tarball = self::requirePackedTarball($pack, $consumerDir);

        $init = new Process(['npm', 'init', '-y'], $consumerDir);
        $init->run();

        self::requireSuccessfulInit($init);

        $tools = self::buildToolsFromDevDependencies($root);

        $install = new Process([
            'npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts',
            "{$consumerDir}/{$tarball}",
            ...$tools,
        ], $consumerDir);

        $install->setTimeout(300.0);
        $install->run();

        self::requireSuccessfulInstall($install);

        mkdir("{$consumerDir}/src", 0o755, true);
        file_put_contents(
            "{$consumerDir}/biome.json",
            (string) file_get_contents("{$root}/tests/consumer/biome.json"),
        );
        file_put_contents(
            "{$consumerDir}/tsconfig.json",
            (string) file_get_contents("{$root}/tests/consumer/tsconfig.json"),
        );
        file_put_contents(
            "{$consumerDir}/src/clean.ts",
            self::withTrailingNewline(<<<'TS'
export const greet = (name: string): string => `hi ${name}`;

export const isSame = (left: string, right: string): boolean => left === right;
TS),
        );

        return self::$packagedConsumer = [
            'consumerDir' => $consumerDir,
            'archiveDir'  => $archiveDir,
            'archiveTree' => $archiveTree,
        ];
    }
}
