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
use RuntimeException;

use function chmod;
use function copy;
use function count;
use function file_put_contents;
use function json_encode;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_repeat;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Fixture-driven cases for bin/consumer-checks/check-jscpd-install.php — the
 * jscpd install contract a `.jscpd.json` brings with it (GH-219): jscpd pinned
 * to one exact version in package.json's `devDependencies`, a committed
 * lockfile for `npm ci`, no npm or npx run from a Composer event, and no
 * jscpd run through npx or with a version in the command. PHP gate only;
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
    /**
     * The Composer event names this suite drives a hook case for — mirrors
     * the gate's own $composerEvents, which lists every event Composer's
     * scripts documentation names (doc/articles/scripts.md, "Event names").
     *
     * @var list<non-empty-string>
     */
    private const array PROVEN_EVENTS = [
        'command',
        'init',
        'post-archive-cmd',
        'post-autoload-dump',
        'post-create-project-cmd',
        'post-file-download',
        'post-install-cmd',
        'post-package-install',
        'post-package-uninstall',
        'post-package-update',
        'post-root-package-install',
        'post-status-cmd',
        'post-update-cmd',
        'pre-archive-cmd',
        'pre-autoload-dump',
        'pre-command-run',
        'pre-file-download',
        'pre-install-cmd',
        'pre-operations-exec',
        'pre-package-install',
        'pre-package-uninstall',
        'pre-package-update',
        'pre-pool-create',
        'pre-status-cmd',
        'pre-update-cmd',
    ];

    // -------------------------------------------------------------------
    // Fixture builders only this contract's cases use — the shared ones
    // (mkCase()/writeJscpdInstall()/...) live in AbstractConsumerConfigTestCase.
    // -------------------------------------------------------------------

    /**
     * mkCase() plus the shipped .jscpd.json, the install it requires and a
     * composer.json whose cpd script runs the pinned binary — the clean shape
     * each case below corrupts exactly one part of.
     *
     * @return string This test's fixture directory.
     */
    private function installFixture(): string
    {
        $dir = $this->mkCase();
        copy(self::root() . '/templates/jscpd.json', $dir . '/.jscpd.json');
        self::writeJscpdInstall($dir);
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips',
        ]);

        return $dir;
    }

    /**
     * Writes a composer.json carrying the given `scripts` block.
     *
     * @param string                             $dir     The directory to write composer.json into.
     * @param array<string, string|list<string>> $scripts The `scripts` block.
     *
     * @return void
     */
    private static function writeComposerScripts(string $dir, array $scripts): void
    {
        $manifest = [
            'name'    => 'fixture/fixture',
            'scripts' => $scripts,
        ];

        file_put_contents(
            $dir . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * Writes a package.json whose `devDependencies.jscpd` is the given value.
     *
     * @param string $dir     The directory to write package.json into.
     * @param mixed  $version The value of `devDependencies.jscpd`.
     *
     * @return void
     */
    private static function writeJscpdPin(string $dir, mixed $version): void
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
    // Gate-source extraction — this contract's lockstep table, read at
    // runtime from the check-*.php split that declares it.
    // -------------------------------------------------------------------

    /**
     * Extracts the gate's `$composerEvents = ['post-install-cmd', ...]`
     * table, cross-checked against the block's plain quoted-string count.
     * Its own reader rather than the shared extractQuotedList(), whose
     * `[A-Za-z]+` entries cannot hold the hyphen every event name carries.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the block cannot be found, the counts disagree, or nothing parsed.
     */
    private static function composerEventsFromGate(): array
    {
        $relativePath = 'bin/consumer-checks/check-jscpd-install.php';
        $source       = self::gateSource($relativePath);

        if (preg_match('/\$composerEvents = \[(.*?)\];/s', $source, $matches) !== 1) {
            throw new RuntimeException("could not find \$composerEvents in {$relativePath}");
        }

        preg_match_all("/'([a-z]+(?:-[a-z]+)*)'/", $matches[1], $named);
        preg_match_all("/'[^']*'/", $matches[1], $any);

        if (count($any[0]) !== count($named[1])) {
            throw new RuntimeException(
                'the $composerEvents block declares ' . count($any[0]) . ' entries but this test parsed '
                . count($named[1]) . ' — widen the extractor rather than leaving one unexercised',
            );
        }

        if ($named[1] === []) {
            throw new RuntimeException('no entries parsed out of $composerEvents — the extraction broke');
        }

        /** @var list<non-empty-string> $events */
        $events = $named[1];

        return $events;
    }

    /**
     * The gate's $composerEvents and this suite's PROVEN_EVENTS name the
     * same set, in both directions: an event the gate loses is caught here,
     * and so is one it gains without a case driving it.
     *
     * @return void
     *
     * @throws RuntimeException If the gate's list cannot be extracted.
     */
    #[Test]
    public function composerEventListMatchesTheEventsThisSuiteDrives(): void
    {
        $fromGate = self::composerEventsFromGate();
        $proven   = self::PROVEN_EVENTS;

        sort($fromGate);
        sort($proven);

        self::assertGreaterThan(0, count($fromGate));
        self::assertSame($proven, $fromGate, 'the gate\'s $composerEvents and PROVEN_EVENTS must name the same events');
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
     * A repository without composer.json still owes the package.json half.
     */
    #[Test]
    public function acceptsTheCleanInstallWithoutComposerJson(): void
    {
        $dir = $this->installFixture();
        unlink($dir . '/composer.json');

        $this->assertGateAccepts(self::phpGate(), $dir, 'no composer.json: only the package.json half applies');
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
     * jscpd under `dependencies` rather than `devDependencies`: a CI tool is
     * not a runtime dependency, and the report names where it was found.
     */
    #[Test]
    public function rejectsJscpdDeclaredUnderDependencies(): void
    {
        $dir = $this->installFixture();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"fixture\",\n    \"dependencies\": {\n        \"jscpd\": \"5.3.2\"\n    }\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'it is declared under `dependencies`', 'jscpd under dependencies');
    }

    /**
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
            'github:kucherenko/jscpd#v5.3.2',
            'npm:jscpd@5.3.2',
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
     * @return array<string, array{0: string}>
     */
    public static function exactVersionProvider(): array
    {
        return self::singleArgProviderRows([
            '5.3.2',
            '10.0.0',
            '5.4.0-rc.1',
            '5.4.0-beta.2+build.7',
        ]);
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
     * A non-string pin is reported, not treated as absent or coerced.
     */
    #[Test]
    public function rejectsANonStringVersion(): void
    {
        $dir = $this->installFixture();
        self::writeJscpdPin($dir, 5);

        $this->assertGateRejects(self::phpGate(), $dir, '`devDependencies.jscpd` must be one exact version', 'jscpd pinned as a number');
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
    // composer.json — Composer events
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function composerEventProvider(): array
    {
        return self::singleArgProviderRows(self::PROVEN_EVENTS);
    }

    /**
     * npm run from any Composer event: every `composer install` then
     * reaches the network, outside the step that installs Node tooling.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('composerEventProvider')]
    public function rejectsNpmFromAComposerEvent(string $event): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'node_modules/.bin/jscpd --config .jscpd.json',
            $event            => 'npm ci --no-audit --no-fund',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, "the Composer event `{$event}` runs npm or npx", "npm ci from {$event}");
    }

    /**
     * @return array<string, array{0: string|list<string>}>
     */
    public static function hookCommandProvider(): array
    {
        return [
            'npm install, string'  => ['npm install jscpd@^5.0.11'],
            'npm ci in a list'     => [['@php -r "echo 1;"', 'npm ci']],
            'npm behind sh -c'     => ["sh -c '[ -d node_modules ] || npm ci --ignore-scripts'"],
            'npm after ;'          => ['true;npm ci'],
            'npm after &&'         => ['true && npm ci'],
            'npm after ||'         => ['false||npm ci'],
            'npm in a subshell'    => ['(npm ci)'],
            'npm with --prefix'    => ['npm install --prefix .build jscpd@^5.0.11'],
            'npx'                  => ['npx --yes jscpd --version'],
            'npm alone'            => ['npm'],
            'npm in double quotes' => ['sh -c "npm ci"'],
        ];
    }

    /**
     * The spellings real consumers use to reach npm from a hook.
     *
     * @param string|list<string> $command The hook's value.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('hookCommandProvider')]
    public function rejectsEachSpellingOfNpmInAHook(string|array $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => 'node_modules/.bin/jscpd --config .jscpd.json',
            'post-install-cmd' => $command,
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', 'npm from post-install-cmd');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hookLookalikeProvider(): array
    {
        return self::singleArgProviderRows([
            '@php -r "echo 1;"',
            'pnpm-lock-check',
            'echo snpm',
            '.build/bin/npmish --check',
            'echo npm-free',
            '@composer dump-autoload',
        ]);
    }

    /**
     * A command merely containing the letters, or naming a different
     * program, is not npm.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('hookLookalikeProvider')]
    public function acceptsAHookThatOnlyLooksLikeNpm(string $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'    => 'node_modules/.bin/jscpd --config .jscpd.json',
            'post-autoload-dump' => $command,
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, "post-autoload-dump: {$command}");
    }

    /**
     * A hook that reaches npm through a referenced script, two levels deep,
     * is reported with the chain that leads there.
     */
    #[Test]
    public function rejectsNpmReachedThroughAReferencedScript(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'node_modules/.bin/jscpd --config .jscpd.json',
            'post-update-cmd' => ['@tools'],
            'tools'           => ['@php -r "echo 1;"', '@tools:node'],
            'tools:node'      => 'npm ci',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-update-cmd` runs npm or npx through `@tools` -> `@tools:node`', 'npm via @tools -> @tools:node');
    }

    /**
     * Scripts that reference each other in a cycle do not hang the gate,
     * and npm inside the cycle is still found.
     */
    #[Test]
    public function survivesAReferenceCycle(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => 'node_modules/.bin/jscpd --config .jscpd.json',
            'post-install-cmd' => '@a',
            'a'                => '@b',
            'b'                => ['@a', 'npm ci'],
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', 'cycle a -> b -> a with npm in b');
    }

    /**
     * npm in a script no Composer event runs is the contributor's own
     * choice — the contract is about what `composer install` does on its own.
     */
    #[Test]
    public function acceptsNpmInAScriptNoEventRuns(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'node_modules/.bin/jscpd --config .jscpd.json',
            'tools:install'   => 'npm ci',
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, 'npm ci in a script no event runs');
    }

    // -------------------------------------------------------------------
    // composer.json — how jscpd is run
    // -------------------------------------------------------------------

    /**
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
        ]);
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
}
