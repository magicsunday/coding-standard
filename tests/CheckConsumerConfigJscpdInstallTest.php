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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function chmod;
use function copy;
use function file_put_contents;
use function implode;
use function json_encode;
use function microtime;
use function str_repeat;
use function str_split;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Fixture-driven cases for bin/consumer-checks/check-jscpd-install.php — the
 * jscpd install contract a `.jscpd.json` brings with it (GH-219): jscpd pinned
 * to one exact version in package.json's `devDependencies`, a lockfile for
 * `npm ci`, and no jscpd run through npx or with a version in the command,
 * with the scrubbing of what those reports echo, plus a composer.json that
 * cannot be read or parsed and a scripts block of odd shapes (a list, entries
 * that are not strings).
 * npm or npx run from a Composer event is
 * CheckConsumerConfigJscpdInstallHooksTest and the command text of the cpd
 * script is CheckConsumerConfigJscpdScriptTest. PHP gate only;
 * bin/check-js-config.mjs has no `.jscpd.json` counterpart. See
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigJscpdInstallTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // Fixture builders only this contract's cases use — the shared ones
    // (mkCase()/writeJscpdInstall()/...) live in AbstractConsumerConfigTestCase.
    // -------------------------------------------------------------------

    /**
     * Writes a package.json whose `devDependencies.jscpd` is the given value.
     *
     * @param string                                                  $dir     The directory to write package.json into.
     * @param string|int|float|bool|array<array-key, string|int>|null $version The value of `devDependencies.jscpd`.
     *
     * @return void
     */
    private static function writeJscpdPin(string $dir, string|int|float|bool|array|null $version): void
    {
        $manifest = [
            'name'            => 'fixture',
            'private'         => true,
            'devDependencies' => ['jscpd' => $version],
        ];

        file_put_contents(
            $dir . '/package.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    // -------------------------------------------------------------------
    // Keyed on .jscpd.json
    // -------------------------------------------------------------------

    /**
     * The clean contract is accepted.
     */
    #[Test]
    public function acceptsTheCleanInstall(): void
    {
        $this->assertGateAccepts(self::phpGate(), $this->installFixture(), 'exact pin, lockfile, pinned binary in the cpd script');
    }

    /**
     * Without a .jscpd.json the contract does not apply: a repository that
     * runs no jscpd is not asked to pin one, whatever its hooks do.
     */
    #[Test]
    public function ignoresARepositoryWithoutJscpdJson(): void
    {
        $dir = $this->mkCase();
        self::writeComposerScripts($dir, ['post-install-cmd' => 'npm install jscpd@^5.0.11']);

        $this->assertGateAccepts(self::phpGate(), $dir, 'no .jscpd.json, so the install contract does not apply');
    }

    /**
     * A repository without composer.json still owes the package.json pin and the lockfile.
     */
    #[Test]
    public function acceptsTheCleanInstallWithoutComposerJson(): void
    {
        $dir = $this->installFixture();
        unlink($dir . '/composer.json');

        $this->assertGateAccepts(self::phpGate(), $dir, 'no composer.json: only the pin and the lockfile apply');
    }

    // -------------------------------------------------------------------
    // package.json
    // -------------------------------------------------------------------

    /**
     * A .jscpd.json without a package.json pinning jscpd.
     */
    #[Test]
    public function rejectsMissingPackageJson(): void
    {
        $dir = $this->installFixture();
        unlink($dir . '/package.json');

        $this->assertGateRejects(self::phpGate(), $dir, 'package.json: is missing', '.jscpd.json without package.json');
    }

    /**
     * package.json declaring no jscpd at all.
     */
    #[Test]
    public function rejectsPackageJsonWithoutJscpd(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"fixture\",\n    \"devDependencies\": {}\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, '`devDependencies` must declare jscpd', 'package.json without jscpd');
    }

    /**
     * jscpd under another section rather than `devDependencies`: a CI tool is
     * not a runtime dependency, and the report names where it was found.
     */
    #[Test]
    #[DataProvider('otherDependencySectionProvider')]
    public function rejectsJscpdDeclaredUnderAnotherSection(string $section): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"fixture\",\n    \"{$section}\": {\n        \"jscpd\": \"5.3.2\"\n    }\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, "it is declared under `{$section}`", "jscpd under {$section}");
    }

    /**
     * The exact dev pin does not excuse a second declaration: the version
     * then lives in two places and the other one can name any release.
     */
    #[Test]
    #[DataProvider('otherDependencySectionProvider')]
    public function rejectsASecondDeclarationBesideTheDevPin(string $section): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"fixture\",\n    \"devDependencies\": {\n        \"jscpd\": \"5.3.2\"\n    },\n    \"{$section}\": {\n        \"jscpd\": \"^4.0.0\"\n    }\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, "also declared under `{$section}`", "second jscpd declaration under {$section}");
    }

    /**
     * Another package in a section jscpd does not belong in is nobody's
     * business: the report is about jscpd's own declarations, so the exact dev
     * pin beside an unrelated runtime dependency stays accepted.
     *
     * @param string $section The package.json section holding the unrelated package.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('otherDependencySectionProvider')]
    public function acceptsAnotherPackageInASectionBesideTheDevPin(string $section): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"devDependencies\": {\"jscpd\": \"5.3.2\"},\n    \"{$section}\": {\"left-pad\": \"1.3.0\"}\n}\n");

        $this->assertGateAccepts(self::phpGate(), $dir, "an unrelated package under {$section} beside the dev pin");
    }

    /**
     * Without a jscpd declaration the report says only that one is missing:
     * an unrelated package in another section is not where jscpd was found.
     *
     * @param string $section The package.json section holding the unrelated package.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('otherDependencySectionProvider')]
    public function doesNotClaimAnUnrelatedPackageIsWhereJscpdWasDeclared(string $section): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"devDependencies\": {},\n    \"{$section}\": {\"left-pad\": \"1.3.0\"}\n}\n");

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'pinned to one exact version, because `.jscpd.json` is present.',
            "no jscpd, only an unrelated package under {$section}",
        );
    }

    /**
     * Spellings that are no exact version: ranges, tags, aliases and sources,
     * a prefix or a trailing newline, leading zeros, empty identifiers, a
     * fourth numeric part and characters outside the identifier alphabet.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonExactVersionProvider(): array
    {
        return ['empty string' => ['']] + self::singleArgProviderRows([
            '^5.3.2',
            '~5.3.2',
            '>=5.3.0',
            '5.x',
            '5.3',
            '5',
            '*',
            'latest',
            '5.3.2 || 5.4.0',
            'v5.3.2',
            '=5.3.2',
            '05.3.2',
            "5.3.2\n",
            '5.4.0-01',
            '5.4.0-',
            '5.4.0+',
            '5.4.0-rc..1',
            'github:kucherenko/jscpd#v5.3.2',
            'npm:jscpd@5.3.2',
            '5.03.2',
            '5.3.02',
            '1.0.0-alpha.01',
            '1.0.0-alpha.',
            '1.0.0+a.',
            '1.0.0+.a',
            '1.0.0+a..b',
            '1.0.0-a_b',
            '1.0.0+a_b',
            '5.3.2.1',
            '1x1.0.0',
            '1.1x1.0',
            '1.1.1x',
            '1.0.0-1_',
            '1.0.0-a.1_',
            '1.0.0-a.0_a',
            '1.0.0+a+b',
            '1.0.0-a.a+',
            '1.0.0-a.a.',
            'a.0.0',
            '0.a.0',
            '0.0.a',
            '-.0.0',
            '_.0.0',
            '0.1.1-_',
            '0.1.1-a._',
            '0.0.:',
            '0.:.0',
            '.1.1',
            '1.1.',
            '+.1.1',
            '1.+.1',
            '1.1.+',
            '1..0.0',
            '1.00.0',
            '1.0.00',
            '00.0.0',
            '00.0',
            '0.00',
            '0..0',
            '0.0..0',
            '123',
            '1.0.0++a',
            '0.1.1-a.+',
            '0.1.1-00',
            '0.1.1-a.00',
            '1x1.1',
            '1.1x1',
            '1.0.0+a.b+c',
            '0.1.1-0.+a',
            '0.1.1-+',
            '0.1.1-a+',
            '0.1.1+a.a_',
            '0.1.1-a.a_',
        ]);
    }

    /**
     * Every spelling that is not one exact version is a range or a moving
     * reference: npm resolves it to whatever release is newest instead of the
     * version Dependabot proposed, so it is rejected. `=5.3.2` and `v5.3.2`
     * are rejected too, although npm reads both as exact: the bare form is
     * the one Dependabot writes, and one spelling is what keeps a pin
     * greppable across repositories.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('nonExactVersionProvider')]
    public function rejectsANonExactVersion(string $version): void
    {
        $dir = $this->installFixture();
        self::writeJscpdPin($dir, $version);

        $this->assertGateRejects(self::phpGate(), $dir, '`devDependencies.jscpd` must be one exact version', "jscpd pinned as \"{$version}\"");
    }

    /**
     * Spellings of one exact version: the plain core, a zero major, components
     * of several digits, pre-release and build metadata with several
     * identifiers, and every character each identifier position accepts.
     *
     * @return array<string, array{0: string}>
     */
    public static function exactVersionProvider(): array
    {
        $digits   = '0123456789';
        $letters  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $alphabet = $digits . $letters . '-';

        $versions = [
            '5.3.2',
            '10.0.0',
            '0.0.0',
            '0.1.0',
            '5.10.0',
            '5.3.12',
            '100.200.300-123.12alpha',
            '5.4.0-rc.1',
            '5.4.0-beta.2+build.7',
            '5.4.0-0',
            '5.4.0-1a',
            '5.4.0-alpha-1',
            '5.3.2+a-b',
            '1.0.0-a.b.c',
            '1.0.0-a-b.c-d',
            '1.0.0-1-1',
            '1.0.0-a.1-1',
            '1.0.0-a.-',
            '1.0.0-10',
            '1.0.0-100',
            '1.0.0-a.101',
            '1.0.0-12a',
            '1.0.0-a.12a',
            '1.0.0-rc.10',
            '1.0.0-RC.1',
            '1.0.0-a.bC',
            '1.0.0-rc.1+build.5.7',
            '1.0.0-rc.1+Build.A',
            '1.0.0+a.b.c',
            // Every character of each identifier position, in one row where
            // the position repeats and one row per character where it does not.
            '1.0.0-a' . $alphabet,
            '1.0.0-a.a' . $alphabet,
            '1.0.0-a.' . implode('.', str_split($letters . '-')),
            '1.0.0-a.' . implode('.', str_split($digits)),
            '1.0.0+' . $alphabet,
            '1.0.0+x.' . $alphabet,
        ];

        foreach (str_split('123456789') as $digit) {
            $versions[] = $digit . '.' . $digit . '.' . $digit;
            $versions[] = '1.0.0-' . $digit;
        }

        foreach (str_split($letters . '-') as $leadingCharacter) {
            $versions[] = '1.0.0-' . $leadingCharacter;
        }

        return self::singleArgProviderRows($versions);
    }

    /**
     * An exact version is accepted, a pre-release and build metadata included.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('exactVersionProvider')]
    public function acceptsAnExactVersion(string $version): void
    {
        $dir = $this->installFixture();
        self::writeJscpdPin($dir, $version);

        $this->assertGateAccepts(self::phpGate(), $dir, "jscpd pinned as \"{$version}\"");
    }

    /**
     * Values of `devDependencies.jscpd` that are not a string: a number, a
     * list, a float, true, false and null.
     *
     * @return array<string, array{0: int|float|bool|list<string>|null}>
     */
    public static function nonStringVersionProvider(): array
    {
        return [
            'integer' => [5],
            'list'    => [['5.3.2']],
            'float'   => [5.3],
            'true'    => [true],
            'false'   => [false],
            'null'    => [null],
        ];
    }

    /**
     * A non-string pin is reported as such, not treated as absent or coerced
     * into a version it might have been spelled as.
     *
     * @param int|float|bool|list<string>|null $version The value of `devDependencies.jscpd`.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('nonStringVersionProvider')]
    public function rejectsANonStringVersion(int|float|bool|array|null $version): void
    {
        $dir = $this->installFixture();
        self::writeJscpdPin($dir, $version);

        $this->assertGateRejects(self::phpGate(), $dir, 'is not a string', 'jscpd pinned as a non-string');
    }

    /**
     * package.json that does not parse.
     */
    #[Test]
    public function rejectsPackageJsonNotValidJson(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', '{ not json');

        $this->assertGateRejects(self::phpGate(), $dir, 'package.json: is not valid JSON, so the jscpd install contract cannot be checked', 'malformed package.json');
    }

    /**
     * npm reads a BOM-prefixed package.json, so the gate does too.
     */
    #[Test]
    public function acceptsPackageJsonWithABom(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "\xEF\xBB\xBF{\n    \"devDependencies\": {\n        \"jscpd\": \"5.3.2\"\n    }\n}\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'package.json with a UTF-8 BOM');
    }

    /**
     * An unreadable package.json is reported once, as itself.
     */
    #[Test]
    public function reportsUnreadablePackageJsonOnce(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->installFixture();
        chmod($dir . '/package.json', 0o000);

        try {
            $this->assertGateReportsOnce(self::phpGate(), $dir, 'package.json', 'an unreadable package.json is reported once, as itself');
        } finally {
            chmod($dir . '/package.json', 0o644);
        }
    }

    /**
     * An oversize package.json is reported once, as itself.
     */
    #[Test]
    public function reportsOnceWhenPackageJsonExceedsTheTextSizeCap(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'package.json', 'an oversized package.json is reported once, as itself');
    }

    /**
     * A pin carrying a forged workflow command is reported inertly.
     */
    #[Test]
    public function reportsAForgedVersionInertly(): void
    {
        $dir = $this->installFixture();
        self::writeJscpdPin($dir, "^5\n##[error]forged");

        $this->assertGateReportIsInert(self::phpGate(), $dir, '##?[error]forged', 'a forged pin is scrubbed before it is reported');
    }

    // -------------------------------------------------------------------
    // Lockfile
    // -------------------------------------------------------------------

    /**
     * No lockfile: `npm ci` refuses to run without one.
     */
    #[Test]
    public function rejectsMissingLockfile(): void
    {
        $dir = $this->installFixture();
        unlink($dir . '/package-lock.json');

        $this->assertGateRejects(self::phpGate(), $dir, 'package-lock.json: is missing', 'no lockfile');
    }

    /**
     * npm-shrinkwrap.json does not stand in for package-lock.json: `npm ci`
     * would install from it, but the shared cpd workflow
     * (magicsunday/.github, `.github/workflows/cpd.yml`) requires
     * package-lock.json by name and keys its npm cache on it.
     */
    #[Test]
    public function rejectsShrinkwrapInsteadOfPackageLock(): void
    {
        $dir = $this->installFixture();
        copy($dir . '/package-lock.json', $dir . '/npm-shrinkwrap.json');
        unlink($dir . '/package-lock.json');

        $this->assertGateRejects(self::phpGate(), $dir, 'package-lock.json: is missing', 'npm-shrinkwrap.json instead of package-lock.json');
    }

    // -------------------------------------------------------------------
    // composer.json — how jscpd is run
    // -------------------------------------------------------------------

    /**
     * Commands that run jscpd through npx or name a version: launcher spellings, paths, separators, line breaks and continuations, and versioned binaries.
     *
     * @return array<string, array{0: string}>
     */
    public static function unpinnedJscpdRunProvider(): array
    {
        return self::singleArgProviderRows([
            'npx jscpd --config .jscpd.json',
            'npx --yes jscpd@^5.0.11 --config .jscpd.json',
            'npx --prefix .build jscpd --config .jscpd.json',
            'npx jscpd@5.0.11 src tests --config .jscpd.json',
            'node_modules/.bin/jscpd@5.3.2 --config .jscpd.json',
            '/usr/bin/npx jscpd --config .jscpd.json',
            'npx.cmd jscpd --config .jscpd.json',
            'C:\\nodejs\\NPX.CMD jscpd --config .jscpd.json',
            'npx.exe jscpd --config .jscpd.json',
            "npx\tjscpd --config .jscpd.json",
            'echo hi; npx jscpd --config .jscpd.json',
            'echo hi && npx jscpd --config .jscpd.json',
            "echo hi\nnpx jscpd --config .jscpd.json",
            "npx x js\\\ncpd",
            'npx.bat jscpd --config .jscpd.json',
            'NPX.EXE jscpd --config .jscpd.json',
            'NPX.BAT jscpd --config .jscpd.json',
            "npx \\\n jscpd --config .jscpd.json",
        ]);
    }

    /**
     * Commands that only resemble an unpinned run and must stay accepted: lookalike words, paths that end in jscpd and npx runs of other tools.
     *
     * @return array<string, array{0: string}>
     */
    public static function pinnedJscpdRunProvider(): array
    {
        return self::singleArgProviderRows([
            self::JSCPD_COMMAND,
            'npx biome check',
            'npx some-tool --report jscpd-report',
            'echo jscpd-config@x',
            'echo xjscpd@1',
            'echo my-jscpd@1',
            'npx biome check && ' . self::JSCPD_COMMAND,
            'npx foo node_modules/.bin/jscpd',
            'npx foo ./jscpd',
            'npx a; ' . self::JSCPD_COMMAND,
            'npx a && ' . self::JSCPD_COMMAND,
            'npx a | ' . self::JSCPD_COMMAND,
            'npx-wrapper jscpd',
            'echo jscpd npx foo',
            'npx x.jscpd',
            'npx x-jscpd',
            'npx xjscpd',
            'npx jscpdx',
            'xnpx jscpd',
            'my-npx jscpd',
            'pnpx jscpd',
            "npx biome check\necho jscpd",
        ]);
    }

    /**
     * The binary package.json pins, and commands that only resemble an
     * unpinned run, stay accepted: a detector widened to any `npx` or any
     * `@` goes red here.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('pinnedJscpdRunProvider')]
    public function acceptsAScriptThatDoesNotRunJscpdAroundThePin(string $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => $command]);

        $this->assertGateAccepts(self::phpGate(), $dir, "cpd script: {$command}");
    }

    /**
     * jscpd run through npx, or with a version in the command, runs a
     * version the package.json pin does not control.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unpinnedJscpdRunProvider')]
    public function rejectsJscpdRunAroundThePin(string $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => $command]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the script `ci:test:php:cpd` runs jscpd through npx or names a version', "cpd script: {$command}");
    }

    /**
     * A script name carrying a forged workflow command is reported inertly.
     */
    #[Test]
    public function reportsAForgedScriptNameInertly(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ["cpd\n##[error]forged" => 'npx jscpd']);

        $this->assertGateReportIsInert(self::phpGate(), $dir, '##?[error]forged', 'a forged script name is scrubbed before it is reported');
    }

    /**
     * composer.json that does not parse.
     */
    #[Test]
    public function rejectsComposerJsonNotValidJson(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/composer.json', '{ not json');

        $this->assertGateRejects(self::phpGate(), $dir, 'composer.json: is not valid JSON, so the jscpd install contract cannot be checked', 'malformed composer.json');
    }

    /**
     * A composer.json without a `scripts` block has nothing to check.
     */
    #[Test]
    public function acceptsComposerJsonWithoutScripts(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/composer.json', "{\n    \"name\": \"fixture/fixture\"\n}\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'composer.json without scripts');
    }

    /**
     * An unreadable composer.json is reported once, as itself.
     */
    #[Test]
    public function reportsUnreadableComposerJsonOnce(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->installFixture();
        chmod($dir . '/composer.json', 0o000);

        try {
            $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'an unreadable composer.json is reported once, as itself');
        } finally {
            chmod($dir . '/composer.json', 0o644);
        }
    }

    /**
     * An oversize composer.json is reported once, as itself.
     */
    #[Test]
    public function reportsOnceWhenComposerJsonExceedsTheTextSizeCap(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/composer.json', str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'an oversized composer.json is reported once, as itself');
    }

    /**
     * Non-string script entries (a callback map, a number, null) are
     * skipped, not fatal: rejecting them is Composer's own job.
     */
    #[Test]
    public function toleratesNonStringScriptEntries(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/composer.json', "{\n    \"scripts\": {\n        \"post-install-cmd\": [5, {\"a\": 1}],\n        \"odd\": null\n    }\n}\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'non-string script entries');
    }

    /**
     * A package.json section that is not an object (a string, null) is
     * skipped, not fatal, while a `devDependencies` that is not an object
     * declares nothing.
     */
    #[Test]
    public function toleratesANonArraySectionBesideTheDevPin(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"devDependencies\": {\"jscpd\": \"5.3.2\"},\n    \"dependencies\": \"x\",\n    \"peerDependencies\": null\n}\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'non-object dependency sections');
    }

    /**
     * `devDependencies` that is not an object declares no jscpd.
     */
    #[Test]
    public function rejectsANonArrayDevDependencies(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"devDependencies\": \"x\"\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, '`devDependencies` must declare jscpd', 'devDependencies as a string');
    }

    /**
     * One script is reported once however many of its commands run jscpd
     * around the pin.
     */
    #[Test]
    public function reportsAScriptWithSeveralUnpinnedCommandsOnce(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => ['npx jscpd', 'npx jscpd --version']]);

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'two unpinned commands in one script');
    }

    /**
     * A command made of very many npx words is scanned in linear time: the
     * pattern for jscpd behind an npx must not restart at every npx.
     */
    #[Test]
    public function scansACommandOfManyNpxWordsInLinearTime(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => str_repeat('npx ', 60_000) . 'jscpd-']);

        $started = microtime(true);

        $this->assertGateAccepts(self::phpGate(), $dir, 'a command of many npx words');

        self::assertLessThan(10.0, microtime(true) - $started, 'The npx scan took quadratic time.');
    }

    /**
     * A `scripts` list instead of a map gives the scripts numeric keys, which
     * are reported by their index.
     *
     * @return void
     */
    #[Test]
    public function reportsAScriptOfAListByItsIndex(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/composer.json', "{\n    \"name\": \"fixture/fixture\",\n    \"scripts\": [\"npx jscpd --config .jscpd.json\"]\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'the script `0` runs jscpd through npx', 'a script reached by a numeric key');
    }

    /**
     * The command of a script that runs jscpd through npx, carrying a forged
     * workflow command, is reported inertly.
     *
     * @return void
     */
    #[Test]
    public function reportsAForgedNpxCommandInertly(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => "npx jscpd\n##[error]forged",
        ]);

        $this->assertGateReportIsInert(self::phpGate(), $dir, '##?[error]forged', 'a forged npx command is scrubbed before it is reported');
    }
}
