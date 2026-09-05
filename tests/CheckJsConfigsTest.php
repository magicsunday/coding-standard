<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Fixture-driven cases for tests/check-js-configs.sh, migrated off that bash
 * harness (#79) the same way #78 migrated tests/check-consumer-config-cases.sh —
 * everything EXCEPT manifest_check()'s own fixtures, which live in the sibling
 * CheckJsConfigsManifestTest because that validator needs no packaging pipeline.
 *
 * This class covers the packaging-pipeline-dependent half: packing this
 * package the way npm ships it (`git archive` of the committed tree, so
 * `.gitattributes` export-ignore applies, then `npm pack --ignore-scripts`),
 * installing the tarball into ONE throwaway npm project and driving real
 * Biome/tsc/jscpd invocations against it — plus the packaging-adjacent
 * controls that only make sense against that same archived/packed artefact
 * (the `files` allow-list vs. the tarball, the `.gitattributes` export-ignore
 * completeness sweep, the `--ignore-scripts` enforcement, the installed npm
 * bin-entry smoke) and the two remaining self-contained concerns
 * (build_tools_from_devdependencies()'s own accept/reject fixtures, and the
 * README tool-version-pin lockstep, which needs no packaging at all).
 *
 * packagedConsumer() builds that ONE throwaway project lazily, once per test
 * run (a private static cache, per this project's own convention of starting
 * local rather than promoting a new GateTestCase mechanism on a single use),
 * and every test that reuses it either reads it without mutation or mutates
 * a file through mutateConsumerFile(), which this class's own tearDown()
 * restores (an existing file) or removes (a file the test created) after
 * every test — so the fixture's baseline state (a passing biome.json/
 * tsconfig.json extending the installed package, plus one clean src file) is
 * never actually order-dependent, even though PHPUnit's own
 * `executionOrder="depends,defects"` (phpunit.xml.dist) does not guarantee
 * declaration order.
 *
 * NOT ported: harness_probe_report_inertness (bash ~lines 85-177) and its own
 * nested self-tests are bash-only plumbing protecting THIS FILE's hand-rolled
 * `pass`/`fail` echo helpers against forging a workflow command — not
 * applicable once reporting goes through PHPUnit's own trusted assertion API.
 * bin/check-js-config.mjs's OWN report-inertness is a different, separate
 * concern this bash file never actually drove through the real binary in the
 * first place (grep confirms no such call site), so there is nothing of that
 * shape to port here either.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckJsConfigsTest extends GateTestCase
{
    /**
     * A byte-for-byte copy of build_tools_from_devdependencies()'s own
     * `node -e '...'` body (tests/check-js-configs.sh, ~lines 277-289).
     */
    private const string BUILD_TOOLS_SCRIPT = <<<'JS'
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
     * The directory-shaped export-ignore leaves this smoke proves, in
     * addition to the leaf FILES package.json's own "files" array already
     * names — Composer has no "files"-equivalent allow-list to derive these
     * from, so this is a hand-kept list, same as the bash original's own
     * $leaf_dirs.
     *
     * @var list<non-empty-string>
     */
    private const array EXPORT_IGNORE_LEAF_DIRECTORIES = ['biome', 'tsconfig', 'templates', 'phpstan', 'rector', 'php-cs-fixer', 'deptrac'];

    /**
     * The lazily-built, class-scoped packaged consumer this suite's
     * packaging-pipeline-dependent tests share, or null before the first one
     * that needs it runs.
     *
     * @var array{consumerDir: string, archiveDir: string, archiveTree: string}|null
     */
    private static ?array $packagedConsumer = null;

    /**
     * Every throwaway directory packagedConsumer() created, removed once by
     * tearDownAfterClass().
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
     * Restores or removes every file mutateConsumerFile() touched during
     * this test, then defers to GateTestCase's own per-test fixture()
     * cleanup.
     *
     * @return void
     *
     * @throws RuntimeException If the fixture directory or a file inside it cannot be removed.
     */
    protected function tearDown(): void
    {
        foreach ($this->consumerFileMutations as $path => $original) {
            if ($original === null) {
                @unlink($path);

                continue;
            }

            file_put_contents($path, $original);
        }

        $this->consumerFileMutations = [];

        parent::tearDown();
    }

    /**
     * Removes every throwaway directory packagedConsumer() created. `rm -rf`
     * via a real subprocess rather than FixtureDirectory's own recursive walk:
     * that class is scoped to ONE random-named per-test root it created
     * itself, whereas this suite owns several, class-scoped, built outside
     * any single test's lifecycle.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryDirectories as $directory) {
            $process = new Process(['rm', '-rf', '--', $directory]);
            $process->run();
        }

        self::$temporaryDirectories = [];
        self::$packagedConsumer     = null;

        parent::tearDownAfterClass();
    }

    /**
     * @return string Absolute path to the repository root.
     */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Runs $command as a real subprocess, argv only, capturing stdout+stderr
     * combined in arrival order (matching the bash original's `2>&1`) — the
     * same contract GateProcess gives the two real gates, generalised to the
     * arbitrary git/npm/tar/biome/tsc/jscpd invocations this suite drives
     * that GateProcess's own `<command...> <fixtureDir>` shape cannot express
     * (a fixture directory is not always $command's last positional
     * argument, or an argument at all).
     *
     * @param list<string>          $command The interpreter/binary and its arguments.
     * @param string|null           $cwd     The working directory, or null for this process's own cwd.
     * @param array<string, string> $env     Extra environment variables, merged onto the inherited environment.
     *
     * @return GateResult
     */
    private function runCommand(array $command, ?string $cwd = null, array $env = []): GateResult
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env);
        $process->setTimeout(300.0);
        $output = '';

        $process->run(static function (string $type, string $buffer) use (&$output): void {
            $output .= $buffer;
        });

        return new GateResult($output, $process->getExitCode() ?? -1);
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
    private function mutateConsumerFile(string $dir, string $relativePath, string $content): string
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
     * @return string $content with exactly one trailing newline, or unchanged if empty.
     */
    private static function withTrailingNewline(string $content): string
    {
        return $content === '' ? $content : rtrim($content, "\n") . "\n";
    }

    /**
     * Builds the ONE throwaway npm project this suite's packaging-pipeline-
     * dependent tests share: `git write-tree` + `git archive` (the artefact a
     * `github:` install/`npm pack` actually ships, `.gitattributes`
     * export-ignore applied), `npm pack --ignore-scripts` on that archived
     * tree, `npm install --ignore-scripts` of the resulting tarball plus this
     * repository's own devDependencies (pinned exactly, the versions the
     * smoke actually proves), then a canon biome.json/tsconfig.json extending
     * the installed package and one clean, correctly-formatted source file —
     * a permanently ACCEPTING baseline every other test either reads as-is
     * or mutates and restores via mutateConsumerFile().
     *
     * @return array{consumerDir: string, archiveDir: string, archiveTree: string}
     */
    private static function packagedConsumer(): array
    {
        if (self::$packagedConsumer !== null) {
            return self::$packagedConsumer;
        }

        $root = self::root();

        $writeTree = new Process(['git', '-C', $root, 'write-tree']);
        $writeTree->run();

        if (!$writeTree->isSuccessful()) {
            throw new RuntimeException('git write-tree failed — cannot determine what this commit would ship.');
        }

        $archiveTree = trim($writeTree->getOutput());

        $archiveDir = sprintf('%s/coding-standard-js-archive-%s', sys_get_temp_dir(), uniqid('', true));
        mkdir($archiveDir, 0o755, true);
        self::$temporaryDirectories[] = $archiveDir;

        $archive = new Process(['git', '-C', $root, 'archive', $archiveTree]);
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

        $consumerDir = sprintf('%s/coding-standard-js-consumer-%s', sys_get_temp_dir(), uniqid('', true));
        mkdir($consumerDir, 0o755, true);
        self::$temporaryDirectories[] = $consumerDir;

        $pack = new Process(['npm', 'pack', '--ignore-scripts', '--pack-destination', $consumerDir, '--loglevel=error'], $archiveDir);
        $pack->setTimeout(300.0);
        $pack->run();

        $tarball = trim($pack->getOutput());

        if (!$pack->isSuccessful() || $tarball === '' || !file_exists("{$consumerDir}/{$tarball}")) {
            throw new RuntimeException("npm pack produced no tarball — cannot run the smoke.\n{$pack->getErrorOutput()}");
        }

        $init = new Process(['npm', 'init', '-y'], $consumerDir);
        $init->run();

        if (!$init->isSuccessful()) {
            throw new RuntimeException("npm init -y failed.\n{$init->getErrorOutput()}");
        }

        /** @var array<string, mixed> $rootPackageJson */
        $rootPackageJson = (array) json_decode((string) file_get_contents("{$root}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $rootDevDependencies */
        $rootDevDependencies = (array) ($rootPackageJson['devDependencies'] ?? []);

        $tools = [];

        foreach ($rootDevDependencies as $name => $version) {
            $tools[] = "{$name}@{$version}";
        }

        if ($tools === []) {
            throw new RuntimeException('no devDependencies in package.json — nothing to pin the smoke to.');
        }

        $install = new Process([
            'npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts',
            "{$consumerDir}/{$tarball}",
            ...$tools,
        ], $consumerDir);
        $install->setTimeout(300.0);
        $install->run();

        if (!$install->isSuccessful()) {
            throw new RuntimeException("npm install failed — cannot run the smoke.\n{$install->getErrorOutput()}");
        }

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

    /**
     * Runs `biome ci` against $consumerDir the same way the bash original's
     * biome_ci() does.
     *
     * @param string $consumerDir The directory to run biome against.
     *
     * @return GateResult
     */
    private function biomeCi(string $consumerDir): GateResult
    {
        return $this->runCommand(['npx', '--no-install', 'biome', 'ci', '--error-on-warnings', '--colors=off', '.'], $consumerDir);
    }

    /**
     * Runs `tsc -p tsconfig.json` against $consumerDir the same way the bash
     * original's run_tsc() does.
     *
     * @param string $consumerDir The directory to run tsc against.
     *
     * @return GateResult
     */
    private function runTsc(string $consumerDir): GateResult
    {
        return $this->runCommand(['npx', '--no-install', 'tsc', '-p', 'tsconfig.json'], $consumerDir);
    }

    // -------------------------------------------------------------------
    // build_tools_from_devdependencies() — the devDependencies-to-npm-
    // argument builder feeding the real `npm install` call below.
    // -------------------------------------------------------------------

    /**
     * Runs build_tools_from_devdependencies() against $dir, capturing stdout
     * and stderr SEPARATELY — unlike every other gate this suite drives, the
     * property under test here is which STREAM carries what: the real caller
     * (`mapfile -t tools < <(...)`) reads stdout only and never inspects the
     * exit code, so a "reject" that leaked anything to stdout would still
     * poison the real npm install call regardless of how correct its
     * diagnostic looks.
     *
     * @param string $dir The directory to read package.json from.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runBuildToolsSeparated(string $dir): array
    {
        $process = new Process(['node', '-e', self::BUILD_TOOLS_SCRIPT], null, ['ROOT' => $dir]);
        $process->setTimeout(60.0);
        $process->run();

        return [
            'stdout'   => $process->getOutput(),
            'stderr'   => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? -1,
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unsafeDevDependencyProvider(): array
    {
        return [
            'a devDependency value carrying embedded whitespace' => [['typescript' => '5.0.16 --no-ignore-scripts']],
            'a devDependency name carrying a newline'            => [["typescript\n--no-ignore-scripts\njscpd" => '1.0.0']],
            'a devDependency name starting with a dash'          => [['--no-ignore-scripts' => '1.0.0']],
            'a devDependency name carrying a NUL byte'           => [["typescript\0evil" => '1.0.0']],
            'a devDependency value that is not a string'         => [['typescript' => 5]],
            'a devDependency value that is empty'                => [['typescript' => '']],
        ];
    }

    /**
     * Every devDependencies shape that could inject a second argument onto
     * the real `npm install` command line must be rejected on exit code AND
     * leave stdout empty AND report through the function's own diagnostic
     * (not a crash) — driven against the REAL function, not a hand-reasoned
     * example.
     *
     * @param array<string, mixed> $devDependencies The devDependencies fragment to test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeDevDependencyProvider')]
    public function rejectsAnUnsafeDevDependencyEntry(array $devDependencies): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => $devDependencies]);

        $result = $this->runBuildToolsSeparated($dir);

        self::assertNotSame(0, $result['exitCode'], "Accepted an unsafe devDependencies entry: {$result['stdout']}");
        self::assertSame('', $result['stdout'], "Rejected on exit code, but still emitted to stdout: {$result['stdout']}");
        self::assertStringContainsString(
            'is not safe to pass to npm as an argument',
            $result['stderr'],
            "Rejected with empty stdout, but not via its own diagnostic (crashed instead?): {$result['stderr']}",
        );
    }

    /**
     * The negative twin, proving the six controls above fail for the stated
     * reason and not because every input is rejected.
     */
    #[Test]
    public function acceptsAnOrdinaryDevDependencyPin(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16']]);

        $result = $this->runBuildToolsSeparated($dir);

        self::assertSame(0, $result['exitCode'], "Rejected an ordinary pin: {$result['stderr']}");
        self::assertSame('typescript@5.0.16', trim($result['stdout']));
    }

    // -------------------------------------------------------------------
    // --ignore-scripts enforcement — "a gate that cannot be shown to fail
    // proves nothing", applied to the flag protecting the pack/install
    // calls below from a package.json-declared lifecycle script.
    // -------------------------------------------------------------------

    /**
     * Writes a package.json declaring prepack/postinstall scripts that each
     * write a marker file named by IGNORE_SCRIPTS_PROBE_MARKER, plus the
     * script itself — the fixture every ignore-scripts case below drives.
     *
     * @param string $dir The directory to write into.
     *
     * @return void
     */
    private function writeIgnoreScriptsProbePackage(string $dir): void
    {
        file_put_contents(
            "{$dir}/package.json",
            json_encode(
                [
                    'name'    => 'ignore-scripts-probe',
                    'version' => '1.0.0',
                    'scripts' => ['prepack' => 'node write-marker.js', 'postinstall' => 'node write-marker.js'],
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );
        file_put_contents(
            "{$dir}/write-marker.js",
            "require('fs').writeFileSync(process.env.IGNORE_SCRIPTS_PROBE_MARKER, '1');\n",
        );
    }

    /**
     * `npm pack --ignore-scripts` suppresses prepack.
     */
    #[Test]
    public function npmPackWithIgnoreScriptsSuppressesPrepack(): void
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $marker = "{$dir}/prepack-suppressed";

        $result = $this->runCommand(
            ['npm', 'pack', '--ignore-scripts', '--pack-destination', $dir, '--loglevel=error'],
            $dir,
            ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker],
        );

        self::assertSame(0, $result->exitCode, "npm pack (suppressed) failed.\n{$result->output}");
        self::assertFileDoesNotExist($marker, 'npm pack --ignore-scripts did not suppress prepack.');
    }

    /**
     * The negative twin: without the flag, the SAME package's prepack fires —
     * proving the marker mechanism itself can detect a run, not just default
     * to "absent" regardless.
     */
    #[Test]
    public function npmPackWithoutIgnoreScriptsRunsPrepack(): void
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $marker = "{$dir}/prepack-unsuppressed";

        $result = $this->runCommand(
            ['npm', 'pack', '--pack-destination', $dir, '--loglevel=error'],
            $dir,
            ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker],
        );

        self::assertSame(0, $result->exitCode, "npm pack (unsuppressed) failed.\n{$result->output}");
        self::assertFileExists($marker, 'npm pack without --ignore-scripts did not run prepack — the mutation control no longer discriminates.');
    }

    /**
     * Packs the ignore-scripts probe package (with --ignore-scripts, so the
     * tarball is unaffected by whether prepack ran) and returns the tarball's
     * absolute path — shared by both install-side cases below.
     *
     * @param string $dir The directory the probe package was written into.
     *
     * @return string The absolute path to the produced tarball.
     */
    private function packIgnoreScriptsProbeForInstall(string $dir): string
    {
        $pack = $this->runCommand(['npm', 'pack', '--ignore-scripts', '--pack-destination', $dir, '--loglevel=error'], $dir);
        self::assertSame(0, $pack->exitCode, "npm pack failed.\n{$pack->output}");

        return "{$dir}/" . trim($pack->output);
    }

    /**
     * `npm install --ignore-scripts` suppresses postinstall.
     */
    #[Test]
    public function npmInstallWithIgnoreScriptsSuppressesPostinstall(): void
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $tarball = $this->packIgnoreScriptsProbeForInstall($dir);

        $consumerDir = "{$dir}/consumer";
        mkdir($consumerDir);
        $init = $this->runCommand(['npm', 'init', '-y'], $consumerDir);
        self::assertSame(0, $init->exitCode, "npm init -y failed.\n{$init->output}");

        $marker = "{$dir}/postinstall-suppressed";
        $result = $this->runCommand(
            ['npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts', '--prefix', $consumerDir, $tarball],
            null,
            ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker],
        );

        self::assertSame(0, $result->exitCode, "npm install (suppressed) failed.\n{$result->output}");
        self::assertFileDoesNotExist($marker, 'npm install --ignore-scripts did not suppress postinstall.');
    }

    /**
     * The negative twin, in a FRESH consumer directory (reinstalling the
     * identical tarball spec into the same node_modules can be treated by
     * npm as already satisfied and silently skipped, which would pass this
     * twin for the wrong reason — nothing ran, rather than the flag being
     * honoured).
     */
    #[Test]
    public function npmInstallWithoutIgnoreScriptsRunsPostinstall(): void
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $tarball = $this->packIgnoreScriptsProbeForInstall($dir);

        $consumerDir = "{$dir}/consumer";
        mkdir($consumerDir);
        $init = $this->runCommand(['npm', 'init', '-y'], $consumerDir);
        self::assertSame(0, $init->exitCode, "npm init -y failed.\n{$init->output}");

        $marker = "{$dir}/postinstall-unsuppressed";
        $result = $this->runCommand(
            ['npm', 'install', '--no-audit', '--no-fund', '--prefix', $consumerDir, $tarball],
            null,
            ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker],
        );

        self::assertSame(0, $result->exitCode, "npm install (unsuppressed) failed.\n{$result->output}");
        self::assertFileExists($marker, 'npm install without --ignore-scripts did not run postinstall — the mutation control no longer discriminates.');
    }

    // -------------------------------------------------------------------
    // The installed npm bin entry (package.json's "bin" mapping).
    // -------------------------------------------------------------------

    /**
     * The npm-installed `check-js-config` bin entry resolves and runs.
     * Proves ONLY package.json's own "bin" mapping — everything else about
     * the gate's own logic is proven directly against the working-tree
     * source elsewhere (CheckConsumerConfigTest's node-gate cases).
     */
    #[Test]
    public function theInstalledNpmBinEntryRunsAndAccepts(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];

        $result = $this->runCommand(['npx', '--no-install', 'check-js-config', '.'], $consumerDir);

        self::assertSame(
            0,
            $result->exitCode,
            "The installed npm bin entry (check-js-config) did not run — package.json's \"bin\" mapping may be broken.\n{$result->output}",
        );
    }

    /**
     * The negative twin: without it, a swapped pass/fail or a stray `|| true`
     * on the invocation above would go unnoticed. Drives the SAME bin entry
     * against a malformed biome.json — exit 1 specifically (2 is the usage-
     * error path, and an uncaught crash also happens to exit 1 on Node, same
     * as this gate's own reject convention), with the exact diagnostic.
     */
    #[Test]
    public function theInstalledNpmBinEntryRejectsAMalformedBiomeJson(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'biome.json', "{\n");

        $result = $this->runCommand(['npx', '--no-install', 'check-js-config', '.'], $consumerDir);

        self::assertSame(
            1,
            $result->exitCode,
            "The installed npm bin entry (check-js-config) exited {$result->exitCode}, not the 1 a reported drift needs.\n{$result->output}",
        );
        self::assertStringContainsString('biome.json: not valid JSON(C).', $result->output);
    }

    // -------------------------------------------------------------------
    // The "files" allow-list vs. the actual tarball contents.
    // -------------------------------------------------------------------

    /**
     * @param string $tarball Absolute path to the tarball to list.
     *
     * @return list<string> The tarball's entries, with the leading "package/" prefix stripped.
     */
    private function tarballEntries(string $tarball): array
    {
        $result = $this->runCommand(['tar', '-tzf', $tarball]);
        self::assertSame(0, $result->exitCode, "Could not list the tarball contents.\n{$result->output}");

        $entries = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            if ($line === '') {
                continue;
            }

            $entries[] = (string) preg_replace('#^package/#', '', $line);
        }

        return $entries;
    }

    /**
     * Every package.json "files" entry is actually present in the tarball —
     * read from the tarball npm produced, not a re-implementation of npm's
     * own glob/default-ignore semantics.
     */
    #[Test]
    public function everyFilesAllowListEntryIsPresentInTheTarball(): void
    {
        $fixture    = self::packagedConsumer();
        $archiveDir = $fixture['archiveDir'];

        $packed = $this->tarballEntries($this->rebuildTarballForListing($archiveDir));

        /** @var array<string, mixed> $packageJson */
        $packageJson = (array) json_decode((string) file_get_contents("{$archiveDir}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<string> $declared */
        $declared = (array) ($packageJson['files'] ?? []);

        self::assertNotEmpty($declared, 'Could not read the files allow-list from package.json.');

        foreach ($declared as $entry) {
            $entry = rtrim($entry, '/');

            $present = in_array($entry, $packed, true);

            if (!$present) {
                foreach ($packed as $packedEntry) {
                    if (str_starts_with($packedEntry, "{$entry}/")) {
                        $present = true;

                        break;
                    }
                }
            }

            self::assertTrue($present, "Declared in package.json \"files\" but absent from the tarball: {$entry}");
        }
    }

    /**
     * A fresh tarball, packed straight from packagedConsumer()'s own
     * archiveDir into a throwaway directory this method owns — kept separate
     * from the tarball packagedConsumer() itself already produced (and
     * already installed into node_modules) so listing its contents here
     * never depends on that first tarball still existing on disk.
     *
     * @param string $archiveDir The archived tree to pack.
     *
     * @return string Absolute path to the freshly packed tarball.
     */
    private function rebuildTarballForListing(string $archiveDir): string
    {
        $dir     = $this->fixture()->path();
        $pack    = $this->runCommand(['npm', 'pack', '--ignore-scripts', '--pack-destination', $dir, '--loglevel=error'], $archiveDir);
        $tarball = trim($pack->output);

        self::assertSame(0, $pack->exitCode, "npm pack produced no tarball.\n{$pack->output}");
        self::assertNotSame('', $tarball, "npm pack produced no tarball.\n{$pack->output}");

        return "{$dir}/{$tarball}";
    }

    // -------------------------------------------------------------------
    // Section K: .gitattributes export-ignore completeness for the npm/JS
    // packaging surface — distinct from tests/check-gitattributes-lockstep.php,
    // which only mirrors templates/gitattributes against .gitattributes
    // itself.
    // -------------------------------------------------------------------

    /**
     * Every path this package's own npm/JS packaging surface must reach a
     * consumer through actually reaches the archived (export-ignore-applied)
     * tree: the "files"-declared leaf files, composer.json's own "bin"
     * entries (Composer has no "files"-equivalent allow-list, so this is the
     * closest ready enumeration source), and every leaf file under the
     * hand-kept EXPORT_IGNORE_LEAF_DIRECTORIES — directories a Composer
     * consumer's `includes:`/`paths` references and npm never ships.
     */
    #[Test]
    public function everyPackagingSurfacePathReachesAConsumerThroughTheGitArchive(): void
    {
        $fixture     = self::packagedConsumer();
        $root        = self::root();
        $archiveDir  = $fixture['archiveDir'];
        $archiveTree = $fixture['archiveTree'];

        $leaves = [];

        foreach (self::EXPORT_IGNORE_LEAF_DIRECTORIES as $leafDir) {
            $result = $this->runCommand(['git', '-C', $root, 'ls-tree', '-r', '--name-only', $archiveTree, '--', $leafDir]);
            self::assertSame(0, $result->exitCode, "git ls-tree on {$leafDir}/ failed — the export-ignore control did not run for it.");

            $dirLeaves = array_values(array_filter(explode("\n", $result->output), static fn (string $line): bool => $line !== ''));
            self::assertNotEmpty($dirLeaves, "{$leafDir}/ is empty or no longer exists in the archived tree — the export-ignore checks did not run for it.");

            $leaves = [...$leaves, ...$dirLeaves];
        }

        /** @var array<string, mixed> $composerJson */
        $composerJson = (array) json_decode((string) file_get_contents("{$archiveDir}/composer.json"), true, 512, JSON_THROW_ON_ERROR);
        $composerBin  = $composerJson['bin'] ?? [];
        $composerBin  = is_array($composerBin) ? $composerBin : [$composerBin];

        self::assertNotEmpty($composerBin, "Could not read composer.json's \"bin\" entries — the Composer bin export-ignore check did not run.");

        /** @var array<string, mixed> $packageJson */
        $packageJson = (array) json_decode((string) file_get_contents("{$archiveDir}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<string> $declared */
        $declared = (array) ($packageJson['files'] ?? []);

        self::assertNotEmpty($declared, 'Could not read the files allow-list from package.json — the declared-entry check did not run.');

        $declaredLeafFiles = array_values(array_diff($declared, self::EXPORT_IGNORE_LEAF_DIRECTORIES));

        $exportedPaths = array_unique([
            ...$declaredLeafFiles,
            'package.json',
            'composer.json',
            'bin/support/safe-report-value.php',
            'bin/support/read-quietly.php',
            ...$composerBin,
            ...$leaves,
        ]);

        foreach ($exportedPaths as $exported) {
            if ($exported === '') {
                continue;
            }

            self::assertFileExists(
                "{$archiveDir}/{$exported}",
                "{$exported} is missing from the archived tree, so a github: install and the Composer dist archive both lose it.",
            );
        }
    }

    // -------------------------------------------------------------------
    // README tool-version-pin lockstep — needs no packaging at all.
    // -------------------------------------------------------------------

    /**
     * The fourth hand-kept copy of the tool versions (the $schema URL and the
     * peerDependencies ranges are each tied to the devDependencies pin
     * elsewhere; this is the tie for the README prose, which Dependabot
     * bumps never touch).
     */
    #[Test]
    public function readmeToolVersionsMatchTheDevDependenciesPins(): void
    {
        $root   = self::root();
        $readme = (string) file_get_contents("{$root}/README.md");
        /** @var array<string, mixed> $packageJson */
        $packageJson = (array) json_decode((string) file_get_contents("{$root}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $devDependencies */
        $devDependencies = (array) ($packageJson['devDependencies'] ?? []);

        $tools           = ['@biomejs/biome', 'typescript', 'jscpd'];
        $documentedCount = 0;

        foreach ($tools as $tool) {
            $pattern = '#`' . preg_quote($tool, '#') . ' ([0-9][^`]*)`#';

            if (preg_match($pattern, $readme, $matches) !== 1) {
                continue;
            }

            ++$documentedCount;
            $actual = $devDependencies[$tool] ?? null;

            self::assertSame(
                $matches[1],
                $actual,
                sprintf('README documents %s %s but package.json pins %s', $tool, $matches[1], $actual ?? 'nothing'),
            );
        }

        self::assertSame(
            3,
            $documentedCount,
            'README no longer documents all three tool versions in the shape this control reads — reword the control, not only the prose.',
        );
    }

    // -------------------------------------------------------------------
    // A consumer extending both shared configs — the accept smoke.
    // -------------------------------------------------------------------

    /**
     * The shared config loads and a clean fixture passes, on both tools.
     */
    #[Test]
    public function sharedConfigAcceptsAConsumerExtendingBiomeAndTsconfig(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];

        $biome = $this->biomeCi($consumerDir);
        self::assertSame(0, $biome->exitCode, "biome ci — shared config rejected or the clean fixture reported findings.\n{$biome->output}");

        $tsc = $this->runTsc($consumerDir);
        self::assertSame(0, $tsc->exitCode, "tsc — shared config rejected or the clean fixture failed to compile.\n{$tsc->output}");
    }
}
