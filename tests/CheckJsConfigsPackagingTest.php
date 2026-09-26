<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractJsConfigsTestCase;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_diff;
use function array_filter;
use function array_unique;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * The packaging controls, split out of the former tests/CheckJsConfigsTest.php
 * (#75): the `--ignore-scripts` enforcement on both `npm pack` and
 * `npm install`, the installed npm bin entry (package.json's "bin" mapping),
 * the `files` allow-list vs. the tarball npm actually produces, and the
 * `.gitattributes` export-ignore completeness sweep over the git archive,
 * with the archiveIndexInto()/pathsMissingFromArchive() fixtures proving that
 * sweep's own failure path (#100).
 *
 * Extends AbstractJsConfigsTestCase for the shared packagedConsumer() and its
 * helpers; see that class's own docblock for the split, the shared-cache
 * semantics, and why `#[Group('js-packaging')]` runs on one CI matrix leg
 * only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsPackagingTest extends AbstractJsConfigsTestCase
{
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

        self::assertSame(0, $probe['result']->exitCode, self::diagnosticMessage('npm pack (suppressed) failed.', $probe['result']->output));
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

        self::assertSame(0, $probe['result']->exitCode, self::diagnosticMessage('npm pack (unsuppressed) failed.', $probe['result']->output));
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
        self::assertSame(0, $pack->exitCode, self::diagnosticMessage('npm pack failed.', $pack->output));

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
        self::assertSame(0, $init->exitCode, self::diagnosticMessage('npm init -y failed.', $init->output));

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

        self::assertSame(0, $probe['result']->exitCode, self::diagnosticMessage('npm install (suppressed) failed.', $probe['result']->output));
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

        self::assertSame(0, $probe['result']->exitCode, self::diagnosticMessage('npm install (unsuppressed) failed.', $probe['result']->output));
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
            self::diagnosticMessage('The installed npm bin entry (check-js-config) did not run — package.json\'s "bin" mapping may be broken.', $result->output),
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
            self::diagnosticMessage("The installed npm bin entry (check-js-config) exited {$result->exitCode}, not the 1 a reported drift needs.", $result->output),
        );

        if (!str_contains($result->output, 'biome.json: not valid JSON(C).')) {
            self::fail(self::diagnosticMessage('The installed npm bin entry (check-js-config) did not report the expected malformed-JSON diagnostic.', $result->output));
        }
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
        self::assertSame(0, $result->exitCode, self::diagnosticMessage('Could not list the tarball contents.', $result->output));

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
            $this->assertFilesAllowListEntryIsPresentInTarball(rtrim($entry, '/'), $packed);
        }
    }

    /**
     * The per-entry presence check hoisted out of
     * everyFilesAllowListEntryIsPresentInTheTarball() above so
     * filesAllowListPresenceCheckFailsWithoutForgingAWorkflowCommand() below
     * can drive this exact assertTrue() throw site directly, with a
     * deliberately poisoned $entry and a $packed list that excludes it. $entry
     * is read straight from THIS repository's own package.json "files" array
     * — a field any PR can edit — and reaches this method's own failure
     * message on a genuine absence (an entry declared but not shipped,
     * whether a real packaging mistake or a deliberately forged one), so it
     * must be scrubbed through ScrubbedDiagnostics::scrubbedForDiagnostic() (inherited
     * by this class) before landing there, the same way every other
     * PR-editable-content diagnostic in this file already is.
     *
     * @param string       $entry  A single package.json "files" entry, already rtrim()'d of a trailing "/".
     * @param list<string> $packed The tarball's own entries, as tarballEntries() returns them.
     *
     * @return void
     */
    private function assertFilesAllowListEntryIsPresentInTarball(string $entry, array $packed): void
    {
        $present = in_array($entry, $packed, true);

        if (!$present) {
            foreach ($packed as $packedEntry) {
                if (str_starts_with($packedEntry, "{$entry}/")) {
                    $present = true;

                    break;
                }
            }
        }

        self::assertTrue(
            $present,
            'Declared in package.json "files" but absent from the tarball: ' . self::scrubbedForDiagnostic($entry),
        );
    }

    /**
     * assertFilesAllowListEntryIsPresentInTarball()'s own assertTrue() above
     * wraps $entry in scrubbedForDiagnostic() before embedding it — and
     * $entry is PR-editable content (a package.json "files" entry), not a
     * test-authored literal, so an unscrubbed files entry crafted to carry a
     * forged `::`/`##[` workflow command would otherwise reach this
     * assertion's own failure message verbatim on a genuine absence; this
     * regression test proves the wrap holds. Drives the extracted check
     * directly with a $packed list that deliberately excludes the poisoned
     * entry, rather than rebuilding a real tarball for it, the same way
     * CheckJsConfigsToolPinsTest::buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand()
     * drives its own throw site directly instead of the full packaging
     * pipeline.
     */
    #[Test]
    public function filesAllowListPresenceCheckFailsWithoutForgingAWorkflowCommand(): void
    {
        $poisoned = "forged\n::error title=pwned::forged";

        $thrown = self::assertThrows(
            fn () => $this->assertFilesAllowListEntryIsPresentInTarball($poisoned, ['some/other/path']),
            AssertionFailedError::class,
            'The presence check did not reject an entry absent from the tarball.',
        );

        self::assertMessageDoesNotForgeWorkflowCommand(
            $thrown->getMessage(),
            "\n::error title=pwned::forged",
            'The absent-entry diagnostic forged a workflow command.',
        );
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

        self::assertSame(0, $pack->exitCode, self::diagnosticMessage('npm pack produced no tarball.', $pack->output));
        self::assertNotSame('', $tarball, self::diagnosticMessage('npm pack produced no tarball.', $pack->output));

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

        $missing = self::pathsMissingFromArchive($archiveDir, array_values($exportedPaths));

        if ($missing !== []) {
            self::fail(
                'Missing from the archived tree, so a github: install and the Composer dist archive both lose it: '
                    . self::scrubbedForDiagnostic(implode(', ', $missing)),
            );
        }
    }

    /**
     * Builds a disposable git repository shipping bin/support/helper.php, with
     * $gitattributes as its `.gitattributes`, and returns what
     * self::pathsMissingFromArchive() reports for that file after
     * self::archiveIndexInto() — the same two steps the smoke and the check
     * above use against this repository.
     *
     * @param string $gitattributes The fixture repository's `.gitattributes` content.
     *
     * @return list<string> The shipped path, when the archive lost it; empty otherwise.
     */
    private function missingAfterArchivingFixtureWith(string $gitattributes): array
    {
        $repository = $this->fixture()->path() . '/repository';
        $archiveDir = $this->fixture()->path() . '/archive';

        mkdir("{$repository}/bin/support", 0o755, true);
        mkdir($archiveDir);
        file_put_contents("{$repository}/bin/support/helper.php", "<?php\n");
        file_put_contents("{$repository}/.gitattributes", $gitattributes);

        foreach ([['init', '--quiet'], ['add', '--all']] as $gitArguments) {
            $result = $this->runCommand(['git', '-C', $repository, ...$gitArguments]);
            self::assertSame(0, $result->exitCode, 'Could not build the export-ignore fixture repository.');
        }

        self::archiveIndexInto($repository, $archiveDir);

        return self::pathsMissingFromArchive($archiveDir, ['bin/support/helper.php']);
    }

    /**
     * The failure path the check above has never taken against this
     * repository's own, clean `.gitattributes` (#100): export-ignoring an
     * ANCESTOR directory of a shipped file — the `/bin/support export-ignore`
     * incident — drops the file from the archive, and the check names it.
     * Removing the archive step (packing the raw tree instead) or breaking
     * the missing-path check would turn this red; the control below proves
     * the fixture itself ships the file when nothing ignores it.
     */
    #[Test]
    public function anExportIgnoredAncestorDirectoryIsReportedAsMissingFromTheArchive(): void
    {
        self::assertSame(
            ['bin/support/helper.php'],
            $this->missingAfterArchivingFixtureWith("/bin/support export-ignore\n"),
            'Export-ignoring bin/support/ did not drop bin/support/helper.php from the archive, or the check missed it.',
        );
    }

    /**
     * The control for the case above: the same fixture repository with an
     * export-ignore entry that names an unrelated path ships the file, so the
     * case above fails for the ignored ancestor and nothing else.
     */
    #[Test]
    public function aFileNoExportIgnoreEntryCoversReachesTheArchive(): void
    {
        self::assertSame(
            [],
            $this->missingAfterArchivingFixtureWith("/tests export-ignore\n"),
            'The fixture repository did not ship bin/support/helper.php although nothing export-ignores it.',
        );
    }
}
