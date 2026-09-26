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
use PHPUnit\Framework\Attributes\Test;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_executable;
use function is_file;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function unlink;

/**
 * Proves the ROOT .php-cs-fixer.dist.php — this package's own self-lint
 * config (GH-83), run by `composer ci:test:php:cgl` — actually applies the
 * shared house style, and scopes its tests/consumer exclusion correctly.
 * Migrated off tests/check-php-cs-fixer-cases.sh (#71).
 *
 * Before that gate existed, this package's own first-party PHP under bin/,
 * tests/ and php-cs-fixer/ was style-checked only incidentally, by the
 * "Consumer smoke" step running against the INSTALLED tests/consumer fixture,
 * which never sees those directories. A broken require of
 * php-cs-fixer/base.php, or a Finder that resolved to an empty set, would
 * leave `composer ci:test:php:cgl` silently green.
 *
 * The CONTROL/POSITIVE probes live in this test's own throwaway fixture
 * directory: the Finder recurses through bin/, tests/ and php-cs-fixer/, so a
 * permanent violating fixture under any of them would make the real
 * `composer ci:test:php:cgl` fail on every run. php-cs-fixer accepts an
 * explicit path outside its Finder and still applies the loaded config's
 * rules to it (`fix -- <path>` overrides only the path set, not the rule set).
 *
 * The FINDER SCOPE case cannot work that way — an explicit path bypasses the
 * Finder entirely — so it writes uniquely named probes into the tracked tree
 * and removes them again, creating a probe directory only when it did not
 * exist yet (see mkdirOwned()/rmdirIfOwned(), and the PRESERVATION cases that
 * pin their ownership contract).
 *
 * Runs in the plain `composer ci:test:phpunit` step: it needs only this
 * repository's own root install, and a missing php-cs-fixer binary fails
 * rather than skips, since that step runs after `composer install`. The bash
 * original's bookkeeping self-test (harness_assert_no_stray_increments) is not
 * ported, the same way the earlier migrations reasoned: GateTestCase's own
 * meta-suite already proves its decisions generically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpCsFixerTest extends GateTestCase
{
    /**
     * The file header every probe carries. It must match
     * .php-cs-fixer.dist.php's own $header verbatim: header_comment is one of
     * the rules under test, so a mismatched header would make even the
     * CONTROL probe reportable, and the POSITIVE case would then prove
     * nothing beyond that same header drift.
     */
    private const string HEADER = <<<'PHP'
        <?php

        /**
         * This file is part of the package magicsunday/coding-standard.
         *
         * For the full copyright and license information, please read the
         * LICENSE file that was distributed with this source code.
         */

        declare(strict_types=1);

        PHP;

    /**
     * CONTROL: a probe already conforming to the shared style reports
     * nothing. If it did, a report on the POSITIVE probe could equally have
     * come from a header mismatch alone.
     */
    #[Test]
    public function aConformingProbeIsCleanAgainstTheRootConfig(): void
    {
        $result = $this->fixProbe(self::HEADER . "\nfunction cglControl(string \$s): string\n{\n    return trim(\$s);\n}\n");

        self::assertSame(0, $result->exitCode, self::diagnosticMessage('A conforming probe reports against .php-cs-fixer.dist.php.', $result->output));
    }

    /**
     * POSITIVE: a brace/spacing violation is reported, by the fixer's own
     * summary line — not merely as some non-zero exit.
     */
    #[Test]
    public function aMalformedProbeIsReportedThroughTheRootConfig(): void
    {
        $result = $this->fixProbe(self::HEADER . "\nfunction cglPositive( string \$s ){\nreturn trim(\$s);\n}\n");

        self::assertNotSame(0, $result->exitCode, self::diagnosticMessage(
            '.php-cs-fixer.dist.php does not report the malformed probe — its require of php-cs-fixer/base.php or its Finder has broken.',
            $result->output,
        ));
        self::assertOutputContains(
            $result,
            'Found 1 of 1 files that can be fixed',
            '.php-cs-fixer.dist.php reported something on the malformed probe, but not the expected summary.',
        );
    }

    /**
     * FINDER SCOPE: the real Finder, with no explicit path, includes
     * bin/consumer and a nested tests/Support/consumer, and excludes only the
     * top-level tests/consumer fixture. That scoping is the config's own most
     * novel logic: a slashless exclude('consumer') would leak across in()
     * roots, and a bare one on the tests/-scoped Finder would match any
     * nested `consumer` directory, not only the fixture.
     */
    #[Test]
    public function theRealFinderLintsNestedConsumerDirectoriesAndExcludesOnlyTheFixture(): void
    {
        $root   = self::root();
        $suffix = bin2hex(random_bytes(8));
        $source = self::HEADER . "\nfunction cglFinderScope{$suffix}( string \$s ){\nreturn trim(\$s);\n}\n";

        $binDir    = "{$root}/bin/consumer";
        $nestedDir = "{$root}/tests/Support/consumer";
        $probes    = [
            'bin'    => "{$binDir}/probe-cgl-selftest-{$suffix}.php",
            'nested' => "{$nestedDir}/probe-cgl-selftest-{$suffix}.php",
            'top'    => "{$root}/tests/consumer/probe-cgl-selftest-{$suffix}.php",
        ];

        $binOwned    = self::mkdirOwned($binDir);
        $nestedOwned = self::mkdirOwned($nestedDir);

        try {
            foreach ($probes as $probe) {
                file_put_contents($probe, $source);
            }

            $result = $this->runFixer([], $root);

            self::assertNotSame(0, $result->exitCode, self::diagnosticMessage(
                'The real Finder reported nothing at all for the three throwaway probes — Finder resolution has broken.',
                $result->output,
            ));
            self::assertOutputContains(
                $result,
                "bin/consumer/probe-cgl-selftest-{$suffix}.php",
                "The real Finder does not include bin/consumer/ — a slashless exclude('consumer') on the tests/-scoped Finder is leaking across in() roots again.",
            );
            self::assertOutputContains(
                $result,
                "tests/Support/consumer/probe-cgl-selftest-{$suffix}.php",
                'The real Finder does not include tests/Support/consumer/ — the tests/-scoped exclusion matches any nested "consumer" directory, not only the fixture.',
            );
            self::assertOutputDoesNotContain(
                $result,
                "tests/consumer/probe-cgl-selftest-{$suffix}.php",
                'The real Finder reports a file inside tests/consumer/ — the tests/-scoped exclusion no longer excludes its own target fixture.',
            );
        } finally {
            foreach ($probes as $probe) {
                if (is_file($probe)) {
                    unlink($probe);
                }
            }

            self::rmdirIfOwned($binDir, $binOwned);
            self::rmdirIfOwned($nestedDir, $nestedOwned);
        }
    }

    /**
     * PRESERVATION, Case A: a pre-existing, non-empty directory (the shape a
     * maintainer's own tracked directory has) survives cleanup. mkdir's own
     * EEXIST decides "not owned" here regardless of the guard, so this proves
     * content is never destroyed, not that the guard is intact — Case C does.
     */
    #[Test]
    public function aPreExistingNonEmptyDirectorySurvivesCleanup(): void
    {
        $dir = $this->fixture()->path() . '/with-content';
        mkdir($dir);
        file_put_contents("{$dir}/real-maintainer-file.php", "<?php\n");

        self::rmdirIfOwned($dir, self::mkdirOwned($dir));

        self::assertFileExists("{$dir}/real-maintainer-file.php", 'A pre-existing, non-empty directory did not survive cleanup.');
    }

    /**
     * PRESERVATION, Case B: a pre-existing but EMPTY directory — invisible
     * to git, real on disk, the shape an unconditional rmdir would delete —
     * survives cleanup.
     */
    #[Test]
    public function aPreExistingEmptyDirectorySurvivesCleanup(): void
    {
        $dir = $this->fixture()->path() . '/empty';
        mkdir($dir);

        self::rmdirIfOwned($dir, self::mkdirOwned($dir));

        self::assertDirectoryExists($dir, 'A pre-existing, empty directory did not survive cleanup.');
    }

    /**
     * PRESERVATION, Case C: a directory this run created itself is removed
     * again. Cases A and B never report ownership, so without this a cleanup
     * that silently never removes anything would leave them green while
     * leaking bin/consumer/ and tests/Support/consumer/ into the tracked tree.
     */
    #[Test]
    public function aDirectoryThisRunOwnsIsRemovedByCleanup(): void
    {
        $dir   = $this->fixture()->path() . '/owned';
        $owned = self::mkdirOwned($dir);

        self::assertTrue($owned, 'mkdirOwned() did not report ownership of a directory it just created.');

        self::rmdirIfOwned($dir, $owned);

        self::assertDirectoryDoesNotExist($dir, 'A directory this run owns was not removed by rmdirIfOwned().');
    }

    /**
     * PRESERVATION, Case D (#158): an owned directory that gained content
     * between creation and cleanup — the concurrent-write window — keeps its
     * content, and the refused rmdir raises nothing: an unabsorbed warning
     * inside the FINDER SCOPE case's `finally` would replace that case's own
     * verdict with an unrelated error.
     */
    #[Test]
    public function anOwnedDirectoryThatGainedContentSurvivesCleanupSilently(): void
    {
        $dir   = $this->fixture()->path() . '/owned-then-populated';
        $owned = self::mkdirOwned($dir);

        self::assertTrue($owned, 'mkdirOwned() did not report ownership of a directory it just created.');

        file_put_contents("{$dir}/written-after-creation.php", "<?php\n");

        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            self::rmdirIfOwned($dir, $owned);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised, 'rmdirIfOwned() raised an error on an owned directory that gained content.');
        self::assertFileExists("{$dir}/written-after-creation.php", 'An owned directory that gained content lost it during cleanup.');
    }

    /**
     * Creates $dir and reports whether THIS call created it — decided by
     * mkdir's own result at creation time, not by an existence check taken
     * earlier and acted on later: a check-then-act split races a second
     * concurrent run, and removing without any ownership check deletes a
     * maintainer's genuinely pre-existing but EMPTY directory. Both failure
     * modes were live-reproduced against earlier revisions of the bash
     * original (harness_mkdir_owned).
     *
     * @param string $dir The directory to create.
     *
     * @return bool True when this call created $dir.
     */
    private static function mkdirOwned(string $dir): bool
    {
        return self::quietly(static fn (): bool => mkdir($dir));
    }

    /**
     * Removes $dir only when $owned, and only if it is still empty: a
     * directory that gained content since creation is left alone, and the
     * refused rmdir is absorbed rather than raised (Case D).
     *
     * @param string $dir   The directory to remove.
     * @param bool   $owned Whether mkdirOwned() created it.
     */
    private static function rmdirIfOwned(string $dir, bool $owned): void
    {
        if ($owned && is_dir($dir)) {
            self::quietly(static fn (): bool => rmdir($dir));
        }
    }

    /**
     * Runs $operation with PHP's own warnings suppressed for its duration —
     * mkdir() on an existing path and rmdir() on a non-empty one both raise
     * E_WARNING alongside their `false` result, and the result is all either
     * caller needs.
     *
     * @param callable(): bool $operation The filesystem call to run.
     *
     * @return bool The call's own result.
     */
    private static function quietly(callable $operation): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Writes $source as a probe into this test's own fixture directory and
     * dry-runs this repository's own php-cs-fixer on that path alone.
     *
     * @param string $source The probe's PHP source.
     *
     * @return GateResult The run's combined output and exit code.
     */
    private function fixProbe(string $source): GateResult
    {
        $probe = $this->fixture()->path() . '/probe.php';
        file_put_contents($probe, $source);

        return $this->runFixer(['--', $probe], null);
    }

    /**
     * Dry-runs this repository's own php-cs-fixer against the root
     * .php-cs-fixer.dist.php, with $arguments appended.
     *
     * @param list<string> $arguments Extra arguments, e.g. an explicit path.
     * @param string|null  $cwd       The working directory, or null for the current one.
     *
     * @return GateResult The run's combined output and exit code.
     */
    private function runFixer(array $arguments, ?string $cwd): GateResult
    {
        $fixer = self::root() . '/.build/bin/php-cs-fixer';

        if (!is_executable($fixer)) {
            self::fail("The root php-cs-fixer binary is missing or not executable ({$fixer}) — run `composer install` first.");
        }

        return (new GateProcess())->runRaw(
            [$fixer, 'fix', '--config', self::root() . '/.php-cs-fixer.dist.php', '--dry-run', '--diff', ...$arguments],
            $cwd,
            [],
            300.0,
        );
    }
}
