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
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function array_map;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function implode;
use function is_dir;
use function is_file;
use function ksort;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function sort;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;

/**
 * Fixture-driven cases for bin/sync-webtrees-module.php (GH-344).
 *
 * Each case builds a scratch module directory that holds nothing but Make/module.mk, runs the script
 * against it and compares the result with templates/webtrees-module/. The script is a maintenance tool
 * and not a gate, so the cases read its exit code and its output directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class SyncWebtreesModuleTest extends GateTestCase
{
    /**
     * A complete parameter set. The namespace segment differs from the package name on purpose, as it does
     * in one real module, so a script that derives one from the other fails the identity assertions.
     *
     * @var array<string, string>
     */
    private const array PARAMETERS = [
        'MODULE_NAME'         => 'webtrees-statistics',
        'MODULE_NS'           => 'Statistic',
        'SCOPE_NS'            => 'StatisticsVendor',
        'WEBTREES_CONSTRAINT' => '~2.9.0',
        'CHART_LIB_MARKER'    => 'webtrees-chart-lib-marker.es.js',
        'SHARED_VIEWS'        => 'one-view other-view',
    ];

    /**
     * Every file of the template is copied to the same path of the module, the parameter file is left alone
     * and the example of it is not copied.
     */
    #[Test]
    public function copiesTheSharedFilesIntoAModule(): void
    {
        $module = $this->module(self::PARAMETERS);
        $result = $this->sync($module);

        self::assertSame(0, $result->exitCode, $result->output);

        $files = $this->templateFiles();

        self::assertNotSame([], $files);

        foreach ($files as $relative) {
            self::assertFileExists($module . '/' . $relative);
            self::assertStringContainsString('created ' . $relative . "\n", $result->output);
        }

        self::assertFileDoesNotExist($module . '/module.mk.example');
        self::assertSame($this->parameterFile(self::PARAMETERS), file_get_contents($module . '/Make/module.mk'));
    }

    /**
     * A file that is not PHP is copied byte for byte.
     */
    #[Test]
    public function copiesTheFilesThatAreNotPhpUnchanged(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        $compared = 0;

        foreach ($this->templateFiles() as $relative) {
            if (str_ends_with($relative, '.php')) {
                continue;
            }

            self::assertSame($this->template($relative), file_get_contents($module . '/' . $relative), $relative);

            ++$compared;
        }

        self::assertGreaterThan(0, $compared);
    }

    /**
     * The identity lines of a PHP file carry the values of the module, and nothing else of the file changes.
     */
    #[Test]
    public function rendersTheIdentityLinesOfThePhpFiles(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        $compared = 0;

        foreach ($this->templateFiles() as $relative) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $contents = (string) file_get_contents($module . '/' . $relative);

            self::assertStringContainsString(' * This file is part of the package magicsunday/webtrees-statistics.', $contents, $relative);
            self::assertStringContainsString(' * @link    https://github.com/magicsunday/webtrees-statistics/', $contents, $relative);
            self::assertStringContainsString("\nnamespace MagicSunday\\Webtrees\\Statistic\\Test\\Release;\n", $contents, $relative);
            self::assertStringNotContainsString('<name>', $contents, $relative);
            self::assertStringNotContainsString('ModuleName', $contents, $relative);

            // Undoing the three identity replacements gives the template back, so nothing else was touched.
            self::assertSame(
                $this->template($relative),
                str_replace(
                    ['webtrees-statistics', 'Webtrees\\Statistic\\'],
                    ['webtrees-<name>', 'Webtrees\\ModuleName\\'],
                    $contents
                ),
                $relative
            );

            ++$compared;
        }

        self::assertGreaterThan(0, $compared);
    }

    /**
     * A second run finds the module in step. It reports no file and does not rewrite any, which the unchanged
     * inode of a file shows.
     */
    #[Test]
    public function aSecondRunChangesNothing(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        $before = $this->snapshot($module);
        $inode  = fileinode($module . '/Make/release.mk');

        $second = $this->sync($module);

        self::assertSame(0, $second->exitCode, $second->output);
        self::assertStringNotContainsString('created ', $second->output);
        self::assertStringNotContainsString('updated ', $second->output);
        self::assertStringContainsString('nothing changed', $second->output);
        self::assertSame($before, $this->snapshot($module));
        self::assertSame($inode, fileinode($module . '/Make/release.mk'));
    }

    /**
     * A file that differs from the template is replaced and named, and the files that are in step are not.
     */
    #[Test]
    public function replacesAFileThatDiffersAndReportsOnlyThatFile(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        file_put_contents($module . '/Make/lint.mk', "# changed by hand\n");

        $result = $this->sync($module);

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertSame("updated Make/lint.mk\n", $result->output);
        self::assertSame($this->template('Make/lint.mk'), file_get_contents($module . '/Make/lint.mk'));
    }

    /**
     * A file of the module that is not a shared template file is never rewritten or deleted, whether it sits next to
     * the shared files, in a directory of the template, or elsewhere, and the parameter file is among them.
     */
    #[Test]
    public function leavesTheFilesOfTheModuleThatAreNotSharedFilesAlone(): void
    {
        $module  = $this->module(self::PARAMETERS);
        $foreign = [
            'Make/extra.mk'             => "EXTRA := 1\n",
            'src/Module.php'            => "<?php\n// module source\n",
            'tests/Release/Foreign.php' => "<?php\n// a test of the module\n",
            'README.md'                 => "# the module\n",
        ];

        foreach ($foreign as $relative => $contents) {
            if (!is_dir(dirname($module . '/' . $relative))) {
                mkdir(dirname($module . '/' . $relative), 0o700, true);
            }

            file_put_contents($module . '/' . $relative, $contents);
        }

        $foreign['Make/module.mk'] = $this->parameterFile(self::PARAMETERS);
        $inodes                    = [];

        foreach ($foreign as $relative => $contents) {
            $inodes[$relative] = fileinode($module . '/' . $relative);
        }

        foreach ([[], ['--check'], []] as $flags) {
            $this->sync($module, $flags);

            foreach ($foreign as $relative => $contents) {
                self::assertFileExists($module . '/' . $relative);
                self::assertSame($contents, file_get_contents($module . '/' . $relative), $relative);
                self::assertSame($inodes[$relative], fileinode($module . '/' . $relative), $relative);
            }
        }
    }

    /**
     * With --check a shared file that was edited by hand is named, nothing is written and the exit code is 1.
     */
    #[Test]
    public function checkNamesAHandEditedFileAndLeavesItAlone(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        file_put_contents($module . '/Make/lint.mk', "# changed by hand\n");

        $result = $this->sync($module, ['--check']);

        self::assertSame(1, $result->exitCode, $result->output);
        self::assertSame("would be updated Make/lint.mk\n", $result->output);
        self::assertSame("# changed by hand\n", file_get_contents($module . '/Make/lint.mk'));
    }

    /**
     * A shared file that exists but cannot be read is refused with exit 2 in both modes. Neither mode may take
     * it for a file that differs, because the normal run would then replace a file whose content it never saw.
     */
    #[Test]
    #[DataProvider('syncModes')]
    public function refusesATargetThatCannotBeRead(string $flag): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('A superuser reads a file whatever its mode is.');
        }

        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        file_put_contents($module . '/Make/lint.mk', "# changed by hand\n");
        chmod($module . '/Make/lint.mk', 0o000);

        $this->assertGateUsageError([...self::command(), ...($flag === '' ? [] : [$flag])], $module, 'cannot read');
        chmod($module . '/Make/lint.mk', 0o600);
        self::assertSame("# changed by hand\n", file_get_contents($module . '/Make/lint.mk'));
    }

    /**
     * A module without a parameter file is refused, and the refusal comes before any file is written.
     */
    #[Test]
    public function refusesAModuleWithoutTheParameterFile(): void
    {
        $module = $this->fixture()->path() . '/bare';

        mkdir($module . '/Make', 0o700, true);
        file_put_contents($module . '/Makefile', "# the module\n");

        $this->assertGateUsageError(self::command(), $module, 'has no Make/module.mk');
        self::assertSame(['Make', 'Makefile'], $this->entries($module));
        self::assertSame("# the module\n", file_get_contents($module . '/Makefile'));
    }

    /**
     * A directory that does not exist is refused.
     */
    #[Test]
    public function refusesAMissingModuleDirectory(): void
    {
        $this->assertGateUsageError(self::command(), $this->fixture()->path() . '/nowhere', 'does not exist');
    }

    /**
     * A parameter that the file does not define is refused by name, and nothing is written.
     */
    #[Test]
    #[DataProvider('requiredParameters')]
    public function refusesAMissingParameter(string $name): void
    {
        $parameters = self::PARAMETERS;

        unset($parameters[$name]);

        $module = $this->module($parameters);

        $this->assertGateUsageError(self::command(), $module, 'does not define ' . $name);
        self::assertSame(['Make'], $this->entries($module));
        self::assertSame(['module.mk'], $this->entries($module . '/Make'));
    }

    /**
     * A parameter without a value is refused by name, and nothing is written.
     */
    #[Test]
    #[DataProvider('emptyableParameters')]
    public function refusesAnEmptyParameter(string $name): void
    {
        $module = $this->module([$name => ''] + self::PARAMETERS);

        $this->assertGateUsageError(self::command(), $module, 'leaves ' . $name . ' empty');
        self::assertSame(['Make'], $this->entries($module));
    }

    /**
     * An identity parameter of the wrong shape is refused, because it ends up in PHP files.
     */
    #[Test]
    #[DataProvider('malformedParameters')]
    public function refusesAParameterOfTheWrongShape(string $name, string $value): void
    {
        $module = $this->module([$name => $value] + self::PARAMETERS);

        $this->assertGateUsageError(self::command(), $module, 'value for ' . $name . ' that does not have the expected shape');
        self::assertSame(['Make'], $this->entries($module));
    }

    /**
     * A module that has no shared views defines the list empty, and the script accepts it.
     */
    #[Test]
    public function acceptsAnEmptyListOfSharedViews(): void
    {
        $module = $this->module(['SHARED_VIEWS' => ''] + self::PARAMETERS);

        $result = $this->sync($module);

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertFileExists($module . '/Make/release.mk');
    }

    /**
     * The last assignment of a parameter wins, as it does in make, and a commented line is no assignment.
     */
    #[Test]
    public function readsTheLastAssignmentAndIgnoresComments(): void
    {
        $module = $this->fixture()->path() . '/module';

        mkdir($module . '/Make', 0o700, true);
        file_put_contents(
            $module . '/Make/module.mk',
            "# MODULE_NAME := webtrees-commented\nMODULE_NAME := webtrees-first\n"
            . $this->parameterFile(self::PARAMETERS)
        );

        self::assertSame(0, $this->sync($module)->exitCode);
        self::assertStringContainsString(
            ' * This file is part of the package magicsunday/webtrees-statistics.',
            (string) file_get_contents($module . '/tests/Release/DistSmokeTest.php')
        );
    }

    /**
     * With --check nothing is written, every missing or differing file is listed and the exit code is 1.
     */
    #[Test]
    public function checkListsTheDifferencesAndWritesNothing(): void
    {
        $module = $this->module(self::PARAMETERS);
        $result = $this->sync($module, ['--check']);

        self::assertSame(1, $result->exitCode, $result->output);
        self::assertStringContainsString("would be created Make/release.mk\n", $result->output);
        self::assertSame(['Make'], $this->entries($module));
        self::assertSame(['module.mk'], $this->entries($module . '/Make'));
    }

    /**
     * With --check a module that is in step exits 0.
     */
    #[Test]
    public function checkAcceptsAModuleThatIsInStep(): void
    {
        $module = $this->module(self::PARAMETERS);

        self::assertSame(0, $this->sync($module)->exitCode);

        $result = $this->sync($module, ['--check']);

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertStringContainsString('nothing changed', $result->output);
    }

    /**
     * The shared files hold no value of a module. A literal of one of the modules, its namespace prefix, its
     * webtrees constraint or its chart library marker in the template would end up in every module.
     */
    #[Test]
    #[DataProvider('moduleLiterals')]
    public function theSharedTemplateFilesHoldNoModuleLiteral(string $pattern): void
    {
        $offenders = [];

        self::assertNotSame([], $this->templateFiles());

        foreach ($this->templateFiles() as $relative) {
            if (preg_match($pattern, $this->template($relative)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'The pattern ' . $pattern . ' matches shared template files.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function syncModes(): array
    {
        return [
            'normal run' => [''],
            'check run'  => ['--check'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredParameters(): array
    {
        $cases = [];

        foreach (array_keys(self::PARAMETERS) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyableParameters(): array
    {
        $cases = [];

        foreach (array_keys(self::PARAMETERS) as $name) {
            if ($name !== 'SHARED_VIEWS') {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /**
     * One case per identity parameter and per way its shape can be wrong. WEBTREES_CONSTRAINT has no case, because
     * its value is trimmed and the only shape the script requires of it is that it is not empty.
     *
     * @return array<string, array{string, string}>
     */
    public static function malformedParameters(): array
    {
        return [
            'name without prefix'          => ['MODULE_NAME', 'statistics'],
            'name with a slash'            => ['MODULE_NAME', 'webtrees-a/b'],
            'name with upper case'         => ['MODULE_NAME', 'webtrees-Statistics'],
            'namespace with a backslash'   => ['MODULE_NS', 'Foo\\Bar'],
            'namespace with a digit start' => ['MODULE_NS', '1Statistic'],
            'scope with a space'           => ['SCOPE_NS', 'Foo Vendor'],
            'marker with a slash'          => ['CHART_LIB_MARKER', '../marker.js'],
            'view with upper case'         => ['SHARED_VIEWS', 'One-View'],
        ];
    }

    /**
     * Literals that identify one of the modules, as patterns over the contents of a template file. The cases span
     * the kinds of value a module holds, which are its name, its namespace, its constraint and its chart library
     * marker.
     *
     * @return array<string, array{string}>
     */
    public static function moduleLiterals(): array
    {
        return [
            'fan chart'                  => ['/fan-?chart/i'],
            'pedigree chart'             => ['/pedigree-?chart/i'],
            'descendants chart'          => ['/descendants-?chart/i'],
            'statistics package'         => ['/webtrees-statistic/i'],
            'statistics namespace'       => ['/Statistics?Vendor|Webtrees\\\\Statistic/'],
            'webtrees constraint'        => ['/~2\.[0-9]+\.[0-9]+/'],
            'chart library marker'       => ['/webtrees-chart-lib-(infra|widgets)\.es\.js/'],
            'scope prefix of the charts' => ['/[A-Za-z]+ChartVendor/'],
        ];
    }

    /**
     * @return list<string> The command that runs the script, without the flags and the module directory.
     */
    private static function command(): array
    {
        return ['php', self::root() . '/bin/sync-webtrees-module.php'];
    }

    /**
     * Runs the script against a module directory.
     *
     * @param string       $module The module directory
     * @param list<string> $flags  Flags that go before the directory
     */
    private function sync(string $module, array $flags = []): GateResult
    {
        $result = (new GateProcess())->run([...self::command(), ...$flags], $module);

        self::assertFalse($result->isDegraded(), 'The script ran degraded, it emitted a diagnostic.');

        return $result;
    }

    /**
     * Creates a module directory that holds a parameter file only.
     *
     * @param array<string, string> $parameters The parameters to write
     *
     * @return string The module directory
     */
    private function module(array $parameters): string
    {
        $module = $this->fixture()->path() . '/module';

        mkdir($module . '/Make', 0o700, true);
        file_put_contents($module . '/Make/module.mk', $this->parameterFile($parameters));

        return $module;
    }

    /**
     * @param array<string, string> $parameters The parameters to write
     */
    private function parameterFile(array $parameters): string
    {
        $lines = array_map(
            static fn (string $name, string $value): string => $name . ' := ' . $value . "\n",
            array_keys($parameters),
            $parameters
        );

        return implode('', $lines);
    }

    /**
     * @return list<string> The paths of the template files that a module receives, relative to the template directory.
     */
    private function templateFiles(): array
    {
        $directory = self::root() . '/templates/webtrees-module';
        $files     = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);

            if ($relative !== 'module.mk.example') {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param string $relative The path relative to the template directory
     */
    private function template(string $relative): string
    {
        $path = self::root() . '/templates/webtrees-module/' . $relative;

        self::assertTrue(is_file($path), $path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string> The contents of every file below the directory, by relative path.
     */
    private function snapshot(string $directory): array
    {
        $contents = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $contents[substr($file->getPathname(), strlen($directory) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($contents);

        return $contents;
    }

    /**
     * @return list<string> The entries of a directory in sorted order, without the dot entries.
     */
    private function entries(string $directory): array
    {
        self::assertTrue(is_dir($directory), $directory);

        $entries = [];

        foreach (new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entries[] = $entry->getFilename();
            }
        }

        sort($entries);

        return $entries;
    }
}
