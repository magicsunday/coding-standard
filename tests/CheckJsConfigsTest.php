<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\FixtureDirectory;
use MagicSunday\CodingStandard\Test\Support\GateProcess;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function bin2hex;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function random_bytes;
use function rmdir;
use function rtrim;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

// scrubReportControlBytes() — the control-byte-strip + legacy-`##[`-break core
// bin/support/safe-report-value.php's own safeReportValue() applies to a shipped
// gate's own report line. safeSubprocessOutput() below shares that core rather than
// duplicating it, then layers its own `::`-scrubbing on top (see that method's own
// docblock for why the `::` step lives here rather than inside the shared core).
require_once __DIR__ . '/../bin/support/safe-report-value.php';

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
 * `#[Group('js-packaging')]` marks this class (and CheckJsConfigsManifestTest)
 * as PHP-version-invariant: the packaging pipeline this class drives (git
 * archive, npm pack/install, Biome/tsc/jscpd) exercises none of this
 * package's own PHP-version-dependent code, so .github/workflows/ci.yml's
 * `build` job runs the group on only ONE matrix leg (`php == '8.3'`, this
 * repository's own floor) rather than once per PHP version — see that
 * workflow's own PHPUnit step comment for the reasoning.
 *
 * Ported and NOT ported, and why, not repeated per test method below:
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
 *     to console output — the safeSubprocessOutput() docblock below and
 *     buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand()'s
 *     own docblock further down both point back to this observation rather
 *     than repeating it. See safeSubprocessOutput()'s own call sites in
 *     packagedConsumer() and buildToolsFromDevDependencies() below, which
 *     scrub subprocess error output for exactly that reason.
 *     bin/check-js-config.mjs's OWN report-inertness is a different, separate
 *     concern this bash file never actually drove through the real binary in
 *     the first place (grep confirms no such call site), so there is nothing
 *     of that shape to port here either.
 *   - harness_assert_tool_rejects's bash triad becomes assertRejectedForReason()
 *     below, a private helper local to this class (house convention: start
 *     local, promote to GateTestCase only on a second real need) rather than
 *     a literal ERE port — "reject, and every one of N patterns present" is
 *     expressed with preg_match() rather than bash's grep -E.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsTest extends GateTestCase
{
    /**
     * A byte-for-byte copy of build_tools_from_devdependencies()'s own
     * `node -e '...'` body from the now-PHPUnit-migrated check-js-configs.sh.
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
     * biome/base.json's own extensionMappings table, held here INDEPENDENTLY
     * so extensionMappingsTableMatchesProvenTargetsAndIsComplete() can prove
     * both directions of the bijection: a row the gate LOSES is caught by
     * comparing against this list, and a row it GAINS unproven is caught by
     * the same comparison the other way.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const array PROVEN_EXTENSION_TARGETS = ['ts' => 'js', 'tsx' => 'js', 'mts' => 'mjs', 'cts' => 'cjs'];

    /**
     * templates/jscpd.json's own "format" list, held here INDEPENDENTLY of
     * the template so a format ADDED there without a fixture cannot ship —
     * jscpdFormatListMatchesProvenExtensionsAndIsComplete() proves both
     * directions.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const array PROVEN_JSCPD_EXTENSIONS = ['php' => 'php', 'javascript' => 'js', 'typescript' => 'ts', 'jsx' => 'jsx', 'tsx' => 'tsx'];

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
     * cleanup. Guarded, not `@`-suppressed, the same way FixtureDirectory
     * guards its own filesystem calls: the shared, class-scoped
     * packagedConsumer() fixture is reused across every test method in this
     * class, so a silently failed restore here would corrupt state for every
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
     * Creates a real, class-scoped temporary directory with a
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
    private static function makeTempDir(string $label, ?string $baseDirectory = null): string
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
    private function runCommand(array $command, ?string $cwd = null, array $env = []): GateResult
    {
        return (new GateProcess())->runRaw($command, $cwd, $env, 300.0);
    }

    /**
     * The "reject, and every one of N patterns is present" triad — this
     * class's own equivalent of the bash original's harness_assert_tool_rejects(),
     * expressed with preg_match() rather than a literal ERE port. Every
     * pattern must match (AND, not OR); an alternation inside one pattern
     * already gets the OR case.
     *
     * @param GateResult   $result          The captured run to check.
     * @param list<string> $mustAllMatch    PCRE fragments (no delimiter) every one of which must match $result->output.
     * @param bool         $caseInsensitive Whether every pattern is matched case-insensitively.
     * @param string       $message         An optional assertion message, used verbatim when non-empty.
     *
     * @return void
     */
    private function assertRejectedForReason(GateResult $result, array $mustAllMatch, bool $caseInsensitive = false, string $message = ''): void
    {
        self::assertNotSame(0, $result->exitCode, $message !== '' ? $message : "Accepted; the rule is not in force.\n{$result->output}");

        $flags = $caseInsensitive ? 'i' : '';

        foreach ($mustAllMatch as $pattern) {
            self::assertMatchesRegularExpression(
                "#{$pattern}#{$flags}",
                $result->output,
                $message !== '' ? $message : "Rejected, but not for the tested reason.\n{$result->output}",
            );
        }
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
     * @return string The normalised $content, with exactly one trailing newline, or unchanged if empty.
     */
    private static function withTrailingNewline(string $content): string
    {
        return $content === '' ? $content : rtrim($content, "\n") . "\n";
    }

    /**
     * Reduces $value — real npm/node subprocess error output — to something
     * safe to embed in an uncaught RuntimeException message: shares
     * scrubReportControlBytes()'s C0/DEL control-byte strip and legacy `##[`
     * GitHub Actions workflow-command prefix break — the same core
     * bin/support/safe-report-value.php's own safeReportValue() applies to a
     * shipped gate's own report line — for the same reason (see this class's
     * own docblock above for the dated observation that an uncaught
     * exception's message reaches console output verbatim, the exact
     * channel a runner scans unanchored for that prefix). Deliberately
     * WITHOUT safeReportValue()'s own 64-byte cap: this message
     * is a developer-facing diagnostic for an ordinary packaging/smoke
     * failure, not a one-line machine-parsed report, and truncating a real
     * npm error to 64 bytes would cost far more debugging value than the
     * forgery this scrub actually closes. A devDependency name or value the
     * shipped BUILD_TOOLS_SCRIPT's own unsafeAsArgument() check does not
     * reject (no whitespace, not empty, no NUL, no leading dash) can still
     * carry `##[` through to a real npm error naming that argument.
     *
     * Additionally breaks every `::` occurrence that opens a line — a step
     * scrubReportControlBytes() itself deliberately does NOT take (its own body
     * comment records why: `::` is legitimate in a namespaced identifier such as
     * `Vendor\Package::method`, and changing that would mangle every OTHER
     * caller's report line on every run). This file's own call sites do not carry
     * that risk and cannot skip the `::` defence either: every one of them embeds
     * $value directly after a literal `\n` in the exception message, i.e. at true
     * column 0 of a new line — exactly the placement the modern `::cmd::` parser
     * needs (it TrimStart()s first, so leading whitespace does not protect a
     * line). scrubReportControlBytes() has already turned every control byte in
     * $value — a raw embedded newline included — into `?` by the time this method's
     * own `::` step runs, so $value's own FIRST character is the only line-start
     * position a `::` can ever occupy in the final message; str_replace()'s
     * left-to-right, non-overlapping scan always consumes a leading `::` pair
     * first, so the result can never begin with `::` regardless of what follows.
     * That is NOT the same as guaranteeing every `::` later in the string is gone
     * too: an odd-length run of colons leaves one unmatched
     * (`str_replace('::', ':?:', ':::')` produces `':?::'`, which still contains
     * `::`) — harmless here only because no such residual position is ever a true
     * line start, never because the scrub removed it.
     *
     * @param string $value The raw subprocess error output to embed.
     *
     * @return string The value scrubbed per scrubReportControlBytes(), with every `::` occurrence that opens a line broken — not literally every occurrence: a residual `::` can survive later in an odd-length colon run, but never at true line start, which is the only placement that matters.
     */
    private static function safeSubprocessOutput(string $value): string
    {
        return str_replace('::', ':?:', scrubReportControlBytes($value));
    }

    /**
     * Runs BUILD_TOOLS_SCRIPT against $root's own package.json — the SAME
     * validation runBuildToolsSeparated() drives against a synthetic fixture
     * further below — so packagedConsumer()'s real `npm install` argument
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
    private static function buildToolsFromDevDependencies(string $root): array
    {
        $process = new Process(['node', '-e', self::BUILD_TOOLS_SCRIPT], null, ['ROOT' => $root]);
        $process->setTimeout(60.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException("package.json's devDependencies are not safe to pass to npm as arguments.\n" . self::safeSubprocessOutput($process->getErrorOutput()));
        }

        $tools = array_values(array_filter(explode("\n", trim($process->getOutput())), static fn (string $tool): bool => $tool !== ''));

        if ($tools === []) {
            throw new RuntimeException('no devDependencies in package.json — nothing to pin the smoke to.');
        }

        return $tools;
    }

    /**
     * Builds the ONE throwaway npm project this suite's packaging-pipeline-
     * dependent tests share: `git write-tree` + `git archive` (the artefact a
     * `github:` install/`npm pack` actually ships, `.gitattributes`
     * export-ignore applied), `npm pack --ignore-scripts` on that archived
     * tree, `npm install --ignore-scripts` of the resulting tarball plus this
     * repository's own devDependencies (pinned exactly, the versions the
     * smoke actually proves, and validated by buildToolsFromDevDependencies()
     * before they ever reach npm's argv), then a canon biome.json/tsconfig.json extending
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

        $archiveDir                   = self::makeTempDir('archive');
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

        $consumerDir                  = self::makeTempDir('consumer');
        self::$temporaryDirectories[] = $consumerDir;

        $pack = new Process(['npm', 'pack', '--ignore-scripts', '--pack-destination', $consumerDir, '--loglevel=error'], $archiveDir);
        $pack->setTimeout(300.0);
        $pack->run();

        $tarball = trim($pack->getOutput());

        if (!$pack->isSuccessful() || ($tarball === '') || !file_exists("{$consumerDir}/{$tarball}")) {
            throw new RuntimeException("npm pack produced no tarball — cannot run the smoke.\n" . self::safeSubprocessOutput($pack->getErrorOutput()));
        }

        $init = new Process(['npm', 'init', '-y'], $consumerDir);
        $init->run();

        if (!$init->isSuccessful()) {
            throw new RuntimeException("npm init -y failed.\n" . self::safeSubprocessOutput($init->getErrorOutput()));
        }

        $tools = self::buildToolsFromDevDependencies($root);

        $install = new Process([
            'npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts',
            "{$consumerDir}/{$tarball}",
            ...$tools,
        ], $consumerDir);

        $install->setTimeout(300.0);
        $install->run();

        if (!$install->isSuccessful()) {
            throw new RuntimeException("npm install failed — cannot run the smoke.\n" . self::safeSubprocessOutput($install->getErrorOutput()));
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

    /**
     * buildToolsFromDevDependencies()'s own first throw branch, called
     * DIRECTLY rather than only through runBuildToolsSeparated()'s hand-rolled
     * node invocation above: that helper drives BUILD_TOOLS_SCRIPT on its
     * own, so it never actually calls buildToolsFromDevDependencies() itself,
     * and packagedConsumer() — the method's only real call site — always runs
     * it against this repository's own valid package.json, so this throw was
     * dead from a coverage standpoint until now.
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsOnAnUnsafeDevDependenciesEntry(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16 --no-ignore-scripts']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/are not safe to pass to npm as arguments/');

        self::buildToolsFromDevDependencies($dir);
    }

    /**
     * The rejection message above embeds BUILD_TOOLS_SCRIPT's own
     * JSON.stringify()-encoded copy of the offending entry verbatim — so a
     * devDependency name or value carrying the legacy `##[` GitHub Actions
     * workflow-command prefix (JSON.stringify() does not escape `#`, `[` or
     * `]`) would reach this class's own uncaught RuntimeException message,
     * which reaches console output the same verbatim way this class's own
     * docblock above dates (2026-09-05) — the exact channel a runner scans
     * unanchored for that prefix. safeSubprocessOutput() must break it
     * before it gets there.
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16 ##[error]forged']]);

        $thrown = null;

        try {
            self::buildToolsFromDevDependencies($dir);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'buildToolsFromDevDependencies() did not reject the unsafe entry.');
        self::assertStringContainsString(
            'forged',
            $thrown->getMessage(),
            "The scrub dropped the offending entry entirely instead of merely breaking the forged prefix.\n{$thrown->getMessage()}",
        );
        self::assertStringNotContainsString(
            '##[',
            $thrown->getMessage(),
            "The exception message still carries the legacy workflow-command prefix.\n{$thrown->getMessage()}",
        );
    }

    /**
     * buildToolsFromDevDependencies()'s own second throw branch — an empty
     * devDependencies object, which BUILD_TOOLS_SCRIPT itself accepts (it
     * simply prints nothing), reported by buildToolsFromDevDependencies()
     * itself as "nothing to pin the smoke to" rather than silently installing
     * no tools at all. See the docblock above for why a direct call is what
     * actually proves this, not runBuildToolsSeparated().
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsWhenThereAreNoDevDependencies(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no devDependencies in package\.json/');

        self::buildToolsFromDevDependencies($dir);
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
     * Shared body for npmPackWithIgnoreScriptsSuppressesPrepack() and
     * npmPackWithoutIgnoreScriptsRunsPrepack() below, which differ only in
     * the presence of `--ignore-scripts`, the marker filename, and the
     * assertion polarity each keeps as its own separately-named test — the
     * same shape installIgnoreScriptsProbe() further below already
     * deduplicates for the analogous postinstall pair.
     *
     * @param bool   $ignoreScripts Whether `npm pack` is given `--ignore-scripts`.
     * @param string $markerName    The marker filename `IGNORE_SCRIPTS_PROBE_MARKER` names.
     *
     * @return array{result: GateResult, marker: string} The captured `npm pack` run and the marker's absolute path.
     */
    private function packIgnoreScriptsProbe(bool $ignoreScripts, string $markerName): array
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $marker = "{$dir}/{$markerName}";

        $command = ['npm', 'pack'];

        if ($ignoreScripts) {
            $command[] = '--ignore-scripts';
        }

        $command[] = '--pack-destination';
        $command[] = $dir;
        $command[] = '--loglevel=error';

        $result = $this->runCommand($command, $dir, ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker]);

        return ['result' => $result, 'marker' => $marker];
    }

    /**
     * `npm pack --ignore-scripts` suppresses prepack.
     */
    #[Test]
    public function npmPackWithIgnoreScriptsSuppressesPrepack(): void
    {
        $probe = $this->packIgnoreScriptsProbe(true, 'prepack-suppressed');

        self::assertSame(0, $probe['result']->exitCode, "npm pack (suppressed) failed.\n{$probe['result']->output}");
        self::assertFileDoesNotExist($probe['marker'], 'npm pack --ignore-scripts did not suppress prepack.');
    }

    /**
     * The negative twin: without the flag, the SAME package's prepack fires —
     * proving the marker mechanism itself can detect a run, not just default
     * to "absent" regardless.
     */
    #[Test]
    public function npmPackWithoutIgnoreScriptsRunsPrepack(): void
    {
        $probe = $this->packIgnoreScriptsProbe(false, 'prepack-unsuppressed');

        self::assertSame(0, $probe['result']->exitCode, "npm pack (unsuppressed) failed.\n{$probe['result']->output}");
        self::assertFileExists($probe['marker'], 'npm pack without --ignore-scripts did not run prepack — the mutation control no longer discriminates.');
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
     * Shared body for npmInstallWithIgnoreScriptsSuppressesPostinstall() and
     * npmInstallWithoutIgnoreScriptsRunsPostinstall() below, which differ
     * only in the presence of `--ignore-scripts`, the marker filename, and
     * the assertion polarity each keeps as its own separately-named test.
     * Each call gets its own FRESH consumer directory — reinstalling the
     * identical tarball spec into the same node_modules can be treated by
     * npm as already satisfied and silently skipped, which would pass the
     * unsuppressed twin for the wrong reason (nothing ran, rather than the
     * flag being honoured).
     *
     * @param bool   $ignoreScripts Whether `npm install` is given `--ignore-scripts`.
     * @param string $markerName    The marker filename `IGNORE_SCRIPTS_PROBE_MARKER` names.
     *
     * @return array{result: GateResult, marker: string} The captured `npm install` run and the marker's absolute path.
     */
    private function installIgnoreScriptsProbe(bool $ignoreScripts, string $markerName): array
    {
        $dir = $this->fixture()->path();
        $this->writeIgnoreScriptsProbePackage($dir);
        $tarball = $this->packIgnoreScriptsProbeForInstall($dir);

        $consumerDir = "{$dir}/consumer";
        mkdir($consumerDir);
        $init = $this->runCommand(['npm', 'init', '-y'], $consumerDir);
        self::assertSame(0, $init->exitCode, "npm init -y failed.\n{$init->output}");

        $command = ['npm', 'install', '--no-audit', '--no-fund'];

        if ($ignoreScripts) {
            $command[] = '--ignore-scripts';
        }

        $command[] = '--prefix';
        $command[] = $consumerDir;
        $command[] = $tarball;

        $marker = "{$dir}/{$markerName}";
        $result = $this->runCommand($command, null, ['IGNORE_SCRIPTS_PROBE_MARKER' => $marker]);

        return ['result' => $result, 'marker' => $marker];
    }

    /**
     * `npm install --ignore-scripts` suppresses postinstall.
     */
    #[Test]
    public function npmInstallWithIgnoreScriptsSuppressesPostinstall(): void
    {
        $probe = $this->installIgnoreScriptsProbe(true, 'postinstall-suppressed');

        self::assertSame(0, $probe['result']->exitCode, "npm install (suppressed) failed.\n{$probe['result']->output}");
        self::assertFileDoesNotExist($probe['marker'], 'npm install --ignore-scripts did not suppress postinstall.');
    }

    /**
     * The negative twin — see installIgnoreScriptsProbe()'s own docblock for
     * why each call needs its own fresh consumer directory.
     */
    #[Test]
    public function npmInstallWithoutIgnoreScriptsRunsPostinstall(): void
    {
        $probe = $this->installIgnoreScriptsProbe(false, 'postinstall-unsuppressed');

        self::assertSame(0, $probe['result']->exitCode, "npm install (unsuppressed) failed.\n{$probe['result']->output}");
        self::assertFileExists($probe['marker'], 'npm install without --ignore-scripts did not run postinstall — the mutation control no longer discriminates.');
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

    // -------------------------------------------------------------------
    // Controls: the shared rules must actually bite. Every control asserts
    // the DIAGNOSTIC, never the bare exit status — a non-zero exit is worth
    // nothing on its own, since `biome ci` also exits non-zero on an
    // unloadable config or an `npx --no-install` resolution failure.
    // -------------------------------------------------------------------

    /**
     * `noDoubleEquals` is "error" in the shared linter block.
     */
    #[Test]
    public function rejectsALooseEqualityComparison(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/dirty.ts', <<<'TS'
export const loose = (a: string, b: string): boolean => {
    return a == b;
};
TS);

        $result = $this->biomeCi($consumerDir);

        $this->assertRejectedForReason($result, ['lint/suspicious/noDoubleEquals']);
    }

    /**
     * The formatter half of the standard, with its own fixture and its own
     * cause — sharing one fixture with the linter control above is what let
     * that control pass on the formatter's finding instead of its own.
     */
    #[Test]
    public function rejectsFormatterDrift(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/unformatted.ts', <<<'TS'
export const wide = (value: string): string => {
  return value;
};
TS);

        $result = $this->biomeCi($consumerDir);

        $this->assertRejectedForReason($result, ['src/unformatted\.ts', 'File content differs from formatting output']);
    }

    /**
     * `noDebugger` is in Biome's recommended set and deliberately not listed
     * in biome/base.json, so it only fires while the recommended preset is
     * actually on.
     */
    #[Test]
    public function rejectsADebuggerStatementViaTheRecommendedPreset(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/debugger.ts', <<<'TS'
export const trace = (): void => {
    debugger;
};
TS);

        $result = $this->biomeCi($consumerDir);

        $this->assertRejectedForReason($result, ['lint/suspicious/noDebugger']);
    }

    /**
     * The house rule the shared config exists to carry: a local ESM import
     * spells the extension `.js`, in TypeScript sources too — what TS ESM
     * emits and what tsc resolves. Both tools accept the same fixture.
     */
    #[Test]
    public function acceptsTheHouseJsImportExtensionInBiomeAndTsc(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/imported.ts', "export const value = 1;\n");
        $this->mutateConsumerFile($consumerDir, 'src/importer.ts', <<<'TS'
import { value } from "./imported.js";

export const doubled = (): number => value * 2;
TS);

        $biome = $this->biomeCi($consumerDir);
        self::assertSame(0, $biome->exitCode, "biome — the house .js import extension was rejected.\n{$biome->output}");

        $tsc = $this->runTsc($consumerDir);
        self::assertSame(0, $tsc->exitCode, "tsc — the house .js import extension failed to compile.\n{$tsc->output}");
    }

    /**
     * The control: an extensionless import must still be reported, so the
     * rule is relaxed in spelling only, not switched off.
     */
    #[Test]
    public function rejectsAnExtensionlessImport(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/imported.ts', "export const value = 1;\n");
        $this->mutateConsumerFile($consumerDir, 'src/importer.ts', <<<'TS'
import { value } from "./imported";

export const doubled = (): number => value * 2;
TS);

        $result = $this->biomeCi($consumerDir);

        $this->assertRejectedForReason($result, ['lint/correctness/useImportExtensions']);
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    private static function extensionMappingsFromArchive(): array
    {
        $archiveDir = self::packagedConsumer()['archiveDir'];
        /** @var array<string, mixed> $base */
        $base = (array) json_decode((string) file_get_contents("{$archiveDir}/biome/base.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $linter */
        $linter = (array) ($base['linter'] ?? []);
        /** @var array<string, mixed> $rules */
        $rules = (array) ($linter['rules'] ?? []);
        /** @var array<string, mixed> $correctness */
        $correctness = (array) ($rules['correctness'] ?? []);
        /** @var array<string, mixed> $useImportExtensions */
        $useImportExtensions = (array) ($correctness['useImportExtensions'] ?? []);
        /** @var array<string, mixed> $options */
        $options = (array) ($useImportExtensions['options'] ?? []);
        /** @var array<non-empty-string, non-empty-string> $mappings */
        $mappings = (array) ($options['extensionMappings'] ?? []);

        return $mappings;
    }

    /**
     * Both directions of the extensionMappings bijection: a row this suite
     * proves that biome/base.json no longer carries (dropped or retargeted)
     * fails here, and a row biome/base.json carries that this suite has no
     * fixture for fails here too — silence that reads as success otherwise.
     */
    #[Test]
    public function extensionMappingsTableMatchesProvenTargetsAndIsComplete(): void
    {
        $mappings = self::extensionMappingsFromArchive();

        self::assertNotEmpty($mappings, 'Could not read extensionMappings from biome/base.json — the mapping controls did not run.');

        $mappedKeys = array_keys($mappings);
        $provenKeys = array_keys(self::PROVEN_EXTENSION_TARGETS);
        sort($mappedKeys);
        sort($provenKeys);

        self::assertSame($provenKeys, $mappedKeys, 'biome/base.json\'s extensionMappings keys no longer match the set this suite proves.');

        foreach (self::PROVEN_EXTENSION_TARGETS as $source => $want) {
            self::assertSame(
                $want,
                $mappings[$source] ?? null,
                "biome/base.json maps .{$source} to something other than .{$want}, the target this smoke proves.",
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function extensionMappingRowProvider(): array
    {
        return [
            'tsx -> js'  => ['tsx', 'js'],
            'mts -> mjs' => ['mts', 'mjs'],
            'cts -> cjs' => ['cts', 'cjs'],
        ];
    }

    /**
     * Every extensionMappings row OTHER than ts (already proven by
     * acceptsTheHouseJsImportExtensionInBiomeAndTsc() /
     * rejectsAnExtensionlessImport() above, which additionally proves the
     * tsc direction) must be in force both ways: the mapped spelling is
     * accepted, and an extensionless import from the SAME source extension is
     * still reported by useImportExtensions specifically — a Biome that
     * merely does not analyse that extension at all would otherwise satisfy
     * the accepting half alone.
     *
     * @param string $sourceExtension The source file extension (without a leading dot).
     * @param string $targetExtension The import spelling the row maps that extension to.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('extensionMappingRowProvider')]
    public function extensionMappingsRowIsInForceBothWays(string $sourceExtension, string $targetExtension): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];

        $this->mutateConsumerFile($consumerDir, "src/mod.{$sourceExtension}", "export const value = 1;\n");
        $this->mutateConsumerFile(
            $consumerDir,
            "src/use.{$sourceExtension}",
            sprintf("import { value } from \"./mod.%s\";\n\nexport const doubled = (): number => value * 2;\n", $targetExtension),
        );

        $accept = $this->biomeCi($consumerDir);
        self::assertSame(
            0,
            $accept->exitCode,
            "biome — an import spelling .{$targetExtension} from a .{$sourceExtension} source was rejected; the mapping row is wrong or missing.\n{$accept->output}",
        );

        $this->mutateConsumerFile(
            $consumerDir,
            "src/bare.{$sourceExtension}",
            "import { value } from \"./mod\";\n\nexport const tripled = (): number => value * 3;\n",
        );

        $reject = $this->biomeCi($consumerDir);
        $this->assertRejectedForReason(
            $reject,
            ['lint/correctness/useImportExtensions'],
            false,
            "biome — an extensionless import from a .{$sourceExtension} source was accepted, so useImportExtensions is not in force for that extension.\n{$reject->output}",
        );
    }

    /**
     * The other half of the same option choice: `extensionMappings` must
     * leave a stylesheet or a JSON asset import ALONE. `forceJsExtensions`
     * would instead rewrite the suggestion for every extension, offering a
     * SAFE fix that points at a `.js` path which does not exist.
     */
    #[Test]
    public function leavesAStylesheetAndJsonAssetImportAlone(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/theme.css', "body {\n    color: red;\n}\n");
        $this->mutateConsumerFile($consumerDir, 'src/palette.json', "{ \"accent\": \"#b60205\" }\n");
        $this->mutateConsumerFile($consumerDir, 'src/assets.ts', <<<'TS'
import palette from "./palette.json";

import "./theme.css";

export const accent = (): unknown => palette;
TS);

        $result = $this->biomeCi($consumerDir);

        self::assertSame(
            0,
            $result->exitCode,
            "biome — the asset fixture failed; either an asset import was told to add a .js extension, or it failed for an unrelated reason.\n{$result->output}",
        );
    }

    /**
     * bin/check-consumer-config.php accepts an extensionless tsconfig
     * "extends" specifier — proven against the tool that actually grants it.
     */
    #[Test]
    public function tscResolvesAnExtensionlessTsconfigExtendsSpecifier(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'tsconfig.json', <<<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base",
    "compilerOptions": { "noEmit": true },
    "include": ["src"]
}
JSON);

        $result = $this->runTsc($consumerDir);

        self::assertSame(
            0,
            $result->exitCode,
            "tsc no longer resolves the extensionless tsconfig \"extends\" specifier; the gate's suffixOptional=true assumption is wrong.\n{$result->output}",
        );
    }

    /**
     * The asymmetric twin: Biome REQUIRES the ".json" suffix on its own
     * "extends" specifier, unlike tsc above.
     */
    #[Test]
    public function biomeRefusesAnExtensionlessBiomeExtendsSpecifier(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'biome.json', <<<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base"]
}
JSON);

        $result = $this->biomeCi($consumerDir);

        $this->assertRejectedForReason(
            $result,
            ['not found|could not resolve'],
            true,
            "biome resolved the extensionless specifier; the gate's requirement of the .json suffix for biome is wrong.\n{$result->output}",
        );
    }

    /**
     * The shared base deliberately carries no `vcs` block: with
     * `vcs.useIgnoreFile: true`, Biome aborts in any consumer with no
     * `.gitignore` beside its config — a configuration error, not a finding.
     * Both halves of that guarantee are checked: the fixture actually has no
     * `.gitignore` (or the accept runs elsewhere prove nothing about this),
     * and biome/base.json itself declares no `vcs` block.
     */
    #[Test]
    public function theSharedBaseCarriesNoVcsBlockAndIsProvenLoadableWithoutAGitignore(): void
    {
        $fixture     = self::packagedConsumer();
        $consumerDir = $fixture['consumerDir'];

        self::assertFileDoesNotExist(
            "{$consumerDir}/.gitignore",
            'The fixture grew a .gitignore — the no-vcs-block guarantee is no longer proven by the accept runs elsewhere.',
        );

        /** @var array<string, mixed> $base */
        $base = (array) json_decode((string) file_get_contents("{$fixture['archiveDir']}/biome/base.json"), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey(
            'vcs',
            $base,
            'biome/base.json declares a vcs block; a consumer with no .gitignore beside its config would abort with "couldn\'t find an ignore file".',
        );
    }

    /**
     * `noUncheckedIndexedAccess` comes only from the shared base; without it
     * this compiles cleanly, so a consumer silently dropping the `extends`
     * would go unnoticed.
     */
    #[Test]
    public function rejectsAnUncheckedIndexedAccess(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'src/unchecked.ts', <<<'TS'
export const first = (values: string[]): string => {
    const value: string = values[0];

    return value;
};
TS);

        $result = $this->runTsc($consumerDir);

        $this->assertRejectedForReason($result, ['unchecked\.ts', 'TS2322']);
    }

    // -------------------------------------------------------------------
    // templates/jscpd.json — the format names, against jscpd itself.
    // -------------------------------------------------------------------

    /**
     * jscpd's `format` takes FORMAT names and an unknown one is not an
     * error — it silently analyses nothing. jscpd reads strict JSON, not
     * JSON5: a config carrying a line comment or a trailing comma must be
     * rejected with a config-parse diagnostic, matching README.md and the
     * strict json_decode() read in bin/consumer-checks/check-jscpd-json.php.
     *
     * @return array<string, array{0: string}>
     */
    public static function jscpdJson5Provider(): array
    {
        return [
            'a line comment' => [
                <<<'JSON'
{
    // a line comment
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["console-full"],
    "path": ["src"]
}
JSON,
            ],
            'a trailing comma' => [
                <<<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["console-full"],
    "path": ["src"],
}
JSON,
            ],
        ];
    }

    /**
     * @param string $config The JSON5-flavoured jscpd config body.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('jscpdJson5Provider')]
    public function rejectsAJson5FeatureInAJscpdConfig(string $config): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $this->mutateConsumerFile($consumerDir, 'jscpd-json5.json', $config);

        $result = $this->runCommand(['npx', '--no-install', 'jscpd', '--config', 'jscpd-json5.json'], $consumerDir);

        $this->assertRejectedForReason($result, ['config file .* line']);
    }

    /**
     * Both directions of the jscpd format-name bijection: a format templates/
     * jscpd.json names that this suite has no fixture extension for, and a
     * proven format the template no longer names, are each a finding — a
     * consumer copying a narrowed template would otherwise run a clone gate
     * blind to that format, silently.
     */
    #[Test]
    public function jscpdFormatListMatchesProvenExtensionsAndIsComplete(): void
    {
        $archiveDir = self::packagedConsumer()['archiveDir'];
        /** @var array<string, mixed> $template */
        $template = (array) json_decode((string) file_get_contents("{$archiveDir}/templates/jscpd.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<string> $formats */
        $formats = (array) ($template['format'] ?? []);

        self::assertNotEmpty($formats, 'Could not read the format list from templates/jscpd.json — the jscpd controls did not run.');

        $templateFormats = $formats;
        $provenFormats   = array_keys(self::PROVEN_JSCPD_EXTENSIONS);
        sort($templateFormats);
        sort($provenFormats);

        self::assertSame(
            $provenFormats,
            $templateFormats,
            'templates/jscpd.json\'s format list no longer matches the set this suite proves — a format was added or dropped without a matching fixture.',
        );
    }

    /**
     * Two bodies IDENTICAL except for the exported name: jscpd matches token
     * sequences, so renaming parameters too — the shape a hand-written
     * "near-identical" pair naturally takes — would break the sequence and
     * the fixture would find nothing for a reason unrelated to the format
     * name under test.
     *
     * @param string $name      The exported function/name.
     * @param string $extension The file extension (without a leading dot) — only "php" gets PHP syntax, every other extension gets the same JS-compatible body.
     *
     * @return string
     */
    private function jscpdBody(string $name, string $extension): string
    {
        if ($extension === 'php') {
            return <<<PHP
<?php

function {$name}(array \$values): string {
    \$total = array_sum(\$values);
    \$average = count(\$values) === 0 ? 0 : \$total / count(\$values);
    \$highest = count(\$values) === 0 ? 0 : max(\$values);
    \$lowest = count(\$values) === 0 ? 0 : min(\$values);
    \$spread = \$highest - \$lowest;
    \$count = count(\$values);
    \$label = \$count === 1 ? 'value' : 'values';

    return sprintf('%d %s: total %d, average %d, spread %d', \$count, \$label, \$total, \$average, \$spread);
}

PHP;
        }

        return <<<JS
export const {$name} = (values) => {
    const total = values.reduce((carry, value) => carry + value, 0);
    const average = values.length === 0 ? 0 : total / values.length;
    const highest = values.length === 0 ? 0 : Math.max(...values);
    const lowest = values.length === 0 ? 0 : Math.min(...values);
    const spread = highest - lowest;
    const count = values.length;
    const label = count === 1 ? "value" : "values";

    return `\${count} \${label}: total \${total}, average \${average}, spread \${spread}`;
};

JS;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function jscpdFormatProvider(): array
    {
        return [
            'php'        => ['php', 'php'],
            'javascript' => ['javascript', 'js'],
            'typescript' => ['typescript', 'ts'],
            'jsx'        => ['jsx', 'jsx'],
            'tsx'        => ['tsx', 'tsx'],
        ];
    }

    /**
     * Every templates/jscpd.json format name still analyses something — two
     * near-identical files in that format must be reported as a clone. The
     * template runs VERBATIM (copied from the archived tree, not a stripped
     * copy), because the copy a consumer makes is what the gate checks.
     *
     * @param string $format    The jscpd format name.
     * @param string $extension The matching file extension (without a leading dot).
     *
     * @return void
     */
    #[Test]
    #[DataProvider('jscpdFormatProvider')]
    public function jscpdFormatNameStillAnalysesTwoIdenticalFiles(string $format, string $extension): void
    {
        $fixture     = self::packagedConsumer();
        $consumerDir = $fixture['consumerDir'];

        $this->mutateConsumerFile(
            $consumerDir,
            '.jscpd.json',
            (string) file_get_contents("{$fixture['archiveDir']}/templates/jscpd.json"),
        );
        $this->mutateConsumerFile($consumerDir, "jscpd-fixture/src/one.{$extension}", $this->jscpdBody('summarise', $extension));
        $this->mutateConsumerFile($consumerDir, "jscpd-fixture/src/two.{$extension}", $this->jscpdBody('describe', $extension));

        $result = $this->runCommand(
            ['npx', '--no-install', 'jscpd', '--config', '.jscpd.json', '--pattern', "**/*.{$extension}", 'jscpd-fixture/src'],
            $consumerDir,
        );

        $this->assertRejectedForReason(
            $result,
            ['clone|duplicat'],
            true,
            "jscpd control — no clone found in two identical .{$extension} files; the \"{$format}\" format name no longer analyses anything.\n{$result->output}",
        );
    }

    // -------------------------------------------------------------------
    // Hardening guards this suite's own harness relies on — tearDown()'s
    // restore-failure handling and makeTempDir()'s mkdir()-failure branch —
    // both untested since the round that introduced them (a broken guard
    // would have shipped silently).
    // -------------------------------------------------------------------

    /**
     * tearDown()'s own restore-failure handling: a mutation whose restore
     * fails must be REPORTED (naming the path) and must invalidate
     * self::$packagedConsumer, so the next test that calls packagedConsumer()
     * rebuilds a fresh, uncorrupted consumer instead of silently inheriting
     * the corruption. Calls tearDown() directly — it is `protected` and this
     * is a test method on the same class — then lets PHPUnit's own automatic
     * tearDown() call run once more afterwards as a no-op, since the manual
     * call already reset $this->consumerFileMutations.
     *
     * The mutation exercises the `$original === null` branch (a file
     * mutateConsumerFile() created rather than one it is restoring), then
     * replaces that file with a directory before tearDown() runs: unlink()
     * on a directory fails deterministically regardless of permissions,
     * mirroring FixtureDirectoryTest's own file-blocks-mkdir() pattern in the
     * opposite direction (a directory blocking unlink() here).
     */
    #[Test]
    public function tearDownReportsEveryFailedPathAndInvalidatesTheSharedConsumerCache(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $path        = $this->mutateConsumerFile($consumerDir, 'src/teardown-guard-probe.ts', "export const value = 1;\n");

        unlink($path);
        mkdir($path, 0o700);

        $thrown = null;

        try {
            $this->tearDown();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        self::assertNotNull($thrown, 'tearDown() did not report the failed restore.');
        self::assertStringContainsString($path, $thrown->getMessage());
        self::assertNull(
            self::$packagedConsumer,
            'A failed restore must invalidate the shared packagedConsumer cache so the next test rebuilds it.',
        );
    }

    /**
     * makeTempDir()'s own mkdir()-failure branch. The random suffix mkdir()
     * appends cannot be pre-occupied by name (unlike FixtureDirectoryTest's
     * own fixed-name probes), and overriding sys_get_temp_dir() itself via
     * `putenv("TMPDIR=...")` does not work here: as observed 2026-09-05
     * against the installed PHP (8.3-8.5), sys_get_temp_dir() memoizes its
     * result for the process lifetime — a putenv() call made after ANYTHING
     * else in the same process has already called sys_get_temp_dir(),
     * including PHPUnit's own harness, has no effect at all, even under
     * #[RunInSeparateProcess]. makeTempDir()'s own optional $baseDirectory
     * parameter exists for exactly this: a plain FILE (not a directory)
     * passed as the base forces mkdir()'s recursive creation to fail
     * deterministically, regardless of the random suffix.
     */
    #[Test]
    public function makeTempDirThrowsWhenMkdirCannotCreateTheDirectory(): void
    {
        $blocked = $this->fixture()->path() . '/not-a-directory';
        file_put_contents($blocked, 'blocking mkdir');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Could not create temporary directory: /');

        self::makeTempDir('probe', $blocked);
    }

    /**
     * The harder case buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand()
     * above cannot reach: a devDependency VALUE BUILD_TOOLS_SCRIPT's own
     * unsafeAsArgument() does not reject at all (a non-empty string, no
     * whitespace, no NUL, no leading dash) still reaches npm's own argv as
     * the real `npm install` call packagedConsumer() drives, and npm's own
     * local package-name validation — no registry/network access needed —
     * quotes the offending spec verbatim in its error text, carrying the
     * embedded `##[` straight through to this class's own RuntimeException
     * message unless safeSubprocessOutput() breaks it first. Drives a real
     * `npm install` directly against a throwaway project rather than through
     * packagedConsumer() itself, whose only devDependencies source is this
     * repository's own real package.json.
     */
    #[Test]
    public function npmInstallFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path();

        $init = new Process(['npm', 'init', '-y'], $dir);
        $init->run();

        self::assertTrue($init->isSuccessful(), "npm init -y control failed.\n" . self::safeSubprocessOutput($init->getErrorOutput()));

        // Not rejected by unsafeAsArgument() (a non-empty string, no
        // whitespace, no NUL, no leading dash) but not a URL-friendly npm
        // package name either.
        $poisonedTool = 'forges-a-workflow-command-##[error]forged@0.0.0-does-not-exist';

        $install = new Process(['npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts', $poisonedTool], $dir);
        $install->setTimeout(120.0);
        $install->run();

        // Both custom failure messages below embed the subprocess output
        // through safeSubprocessOutput() rather than raw: $poisonedTool
        // deliberately carries `##[error]forged`, and a PHPUnit assertion
        // FAILURE message reaches console output exactly as verbatim as an
        // uncaught exception's message (dated on this class's own docblock
        // above). Were either control assertion to ever fail for real, an
        // unscrubbed message here would forge the very annotation this test
        // exists to prove is prevented. The bare $install->getErrorOutput()
        // passed as assertStringContainsString()'s own haystack argument
        // below stays raw on purpose — scrubbing it would change the
        // property under test, not just its diagnostic.
        self::assertFalse(
            $install->isSuccessful(),
            "npm install of a deliberately invalid package name unexpectedly succeeded — this control fixture is not testing what it claims.\n"
                . self::safeSubprocessOutput($install->getOutput() . $install->getErrorOutput()),
        );
        self::assertStringContainsString(
            '##[',
            $install->getErrorOutput(),
            "The control fixture's own raw npm error no longer carries the poisoned sequence — this test is not exercising the trap it claims to.\n"
                . self::safeSubprocessOutput($install->getErrorOutput()),
        );

        $message = "npm install failed — cannot run the smoke.\n" . self::safeSubprocessOutput($install->getErrorOutput());

        self::assertStringNotContainsString(
            '##[',
            $message,
            "The scrubbed exception message still carries the legacy workflow-command prefix.\n{$message}",
        );
    }

    /**
     * The `npm pack` throw site's own regression twin: a deliberately
     * malformed package.json carrying the poisoned sequence makes npm's own
     * JSON-parse error quote a snippet of the raw file content verbatim —
     * measured directly against the installed npm (2026-09-05): "npm error
     * JSON.parse … while parsing near \"{ \"name\": \"x\", ##[error]forged
     * BROK...\"". No registry/network access needed. Drives a real
     * `npm pack` directly rather than through packagedConsumer(), whose own
     * package.json is always this repository's real, well-formed one.
     */
    #[Test]
    public function npmPackFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents("{$dir}/package.json", '{ "name": "x", ##[error]forged BROKEN JSON');

        $pack = new Process(['npm', 'pack', '--ignore-scripts', '--pack-destination', $dir, '--loglevel=error'], $dir);
        $pack->setTimeout(120.0);
        $pack->run();

        self::assertFalse(
            $pack->isSuccessful(),
            "npm pack over a deliberately malformed package.json unexpectedly succeeded — this control fixture is not testing what it claims.\n"
                . self::safeSubprocessOutput($pack->getOutput() . $pack->getErrorOutput()),
        );
        self::assertStringContainsString(
            '##[',
            $pack->getErrorOutput(),
            "The control fixture's own raw npm error no longer carries the poisoned sequence — this test is not exercising the trap it claims to.\n"
                . self::safeSubprocessOutput($pack->getErrorOutput()),
        );

        $message = "npm pack produced no tarball — cannot run the smoke.\n" . self::safeSubprocessOutput($pack->getErrorOutput());

        self::assertStringNotContainsString(
            '##[',
            $message,
            "The scrubbed exception message still carries the legacy workflow-command prefix.\n{$message}",
        );
    }

    /**
     * The `npm init -y` throw site's own regression twin: a project
     * directory whose own NAME carries the poisoned sequence makes npm's
     * own package-name validation echo it back verbatim — measured directly
     * against the installed npm (2026-09-05): `npm error Invalid name:
     * "poisoned-##[error]forged-dir"`. No registry/network access needed.
     * Drives a real `npm init -y` directly rather than through
     * packagedConsumer(), whose own consumer directory name never carries
     * consumer-controlled content.
     */
    #[Test]
    public function npmInitFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path() . '/poisoned-##[error]forged-dir';
        mkdir($dir, 0o755, true);

        $init = new Process(['npm', 'init', '-y'], $dir);
        $init->setTimeout(120.0);
        $init->run();

        self::assertFalse(
            $init->isSuccessful(),
            "npm init -y inside a deliberately poisoned directory name unexpectedly succeeded — this control fixture is not testing what it claims.\n"
                . self::safeSubprocessOutput($init->getOutput() . $init->getErrorOutput()),
        );
        self::assertStringContainsString(
            '##[',
            $init->getErrorOutput(),
            "The control fixture's own raw npm error no longer carries the poisoned sequence — this test is not exercising the trap it claims to.\n"
                . self::safeSubprocessOutput($init->getErrorOutput()),
        );

        $message = "npm init -y failed.\n" . self::safeSubprocessOutput($init->getErrorOutput());

        self::assertStringNotContainsString(
            '##[',
            $message,
            "The scrubbed exception message still carries the legacy workflow-command prefix.\n{$message}",
        );
    }

    /**
     * scrubReportControlBytes()'s own control-byte-stripping half, direct
     * and independent of any real subprocess invocation: every regression
     * test above only ever feeds a `##[`- or `::`-carrying value through the
     * scrub and checks that PREFIX is broken, so a broken or narrowed
     * `[\x00-\x1F\x7F]` character class (an off-by-one, a typo'd range)
     * could ship silently, unnoticed by any of them. Measured directly
     * against the installed PHP (2026-09-05):
     * `scrubReportControlBytes("a\x01b\x7fc")` produces `"a?b?c"` — \x01 (a
     * C0 control byte) and \x7f (DEL) each replaced by a literal `?`, the
     * ordinary ASCII bytes either side left untouched. Calls the shared
     * bin/support/safe-report-value.php function directly (required near
     * the top of this file), not this class's own safeSubprocessOutput()
     * wrapper, since the property under test belongs to the shared core.
     */
    #[Test]
    public function scrubReportControlBytesReplacesControlBytesWithAQuestionMark(): void
    {
        self::assertSame('a?b?c', scrubReportControlBytes("a\x01b\x7fc"));
    }
}
