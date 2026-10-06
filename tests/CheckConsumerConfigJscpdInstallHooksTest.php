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

use function count;
use function preg_match;
use function preg_match_all;
use function sort;

/**
 * Fixture-driven cases for the Composer half of the jscpd install contract
 * (bin/consumer-checks/check-jscpd-install.php): npm or npx run from a
 * Composer event, directly or through a chain of script references, the
 * Composer events the gate hooks, and the scrubbing of the chain target and
 * the event command those reports echo. The pin, the lockfile and jscpd run through npx are
 * CheckConsumerConfigJscpdInstallTest, the command text of the cpd script is
 * CheckConsumerConfigJscpdScriptTest. PHP gate only; bin/check-js-config.mjs
 * has no `.jscpd.json` counterpart. See AbstractConsumerConfigTestCase for
 * the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigJscpdInstallHooksTest extends AbstractConsumerConfigTestCase
{
    /**
     * The Composer event names this suite drives a hook case for — mirrors
     * the gate's own $composerEvents, the list of Composer events a script
     * can hook.
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
    // composer.json — Composer events
    // -------------------------------------------------------------------

    /**
     * Every Composer event the gate hooks, one row each.
     *
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
     * @param string $event The Composer event the hook hangs on.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('composerEventProvider')]
    public function rejectsNpmFromAComposerEvent(string $event): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => self::JSCPD_COMMAND,
            $event            => 'npm ci --no-audit --no-fund',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, "the Composer event `{$event}` runs npm or npx", "npm ci from {$event}");
    }

    /**
     * Spellings of npm or npx in a hook: separators, quoting, paths, launchers and redirections.
     *
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
            'npm behind @php'      => ['@php -r "exit(0);" && npm ci'],
            'npm behind @composer' => ['@composer dump-autoload && npm ci'],
            'npm by absolute path' => ['/usr/bin/npm ci'],
            'npx by absolute path' => ['/usr/local/bin/npx --yes jscpd --version'],
            'npm after a variable' => ['CI=1 /usr/bin/npm ci'],
            'npm in single quotes' => ["sh -c 'npm ci'"],
            'npm after & alone'    => ['true&npm ci'],
            'npm in backticks'     => ['`npm ci`'],
            'npm after a lone &'   => ['true & npm ci'],
            'npm closed by )'      => ['(npm)'],
            'npm closed by ;'      => ['npm;true'],
            'npm closed by "'      => ['echo "npm"'],
            'npm closed by a tick' => ['echo `npm`'],
            'npm with a redirect'  => ['npm>/dev/null'],
            'npm via IFS'          => ['npm${IFS}ci'],
            'npm.cmd launcher'     => ['npm.cmd ci'],
            'npx.exe launcher'     => ['npx.exe --yes jscpd --version'],
            'npm launcher, upper'  => ['NPM.CMD ci'],
            'npm by windows path'  => ['C:\\nodejs\\npm.cmd ci'],
            'npm.bat launcher'     => ['npm.bat ci'],
            'npm after a newline'  => ["true\nnpm ci"],
            'npm after a tab'      => ["true\tnpm ci"],
            'npm then &&'          => ['npm&&true'],
            'npm then a pipe'      => ['npm|cat'],
            'npm then a paren'     => ['npm(true)'],
            'npm then a quote'     => ["npm'ci'"],
            'npm then a backslash' => ['npm\\ci'],
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
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => $command,
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', 'npm from post-install-cmd');
    }

    /**
     * Words that only contain npm or a launcher name and are another program.
     *
     * @return array<string, array{0: string}>
     */
    public static function hookLookalikeProvider(): array
    {
        return self::singleArgProviderRows([
            '@php -r "echo 1;"',
            'pnpm-lock-check',
            'echo snpm',
            '.build/bin/npmish --check',
            'npm.cmdx --version',
            'npm.json',
            'npm@latest-check',
            'echo docs/npm/readme.md',
            'echo npm-free',
            '@composer dump-autoload',
            'npm.batx --version',
            'xnpm.bat ci',
        ]);
    }

    /**
     * A command merely containing the letters, or naming a different
     * program, is not npm.
     *
     * @param string $command The hook command.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('hookLookalikeProvider')]
    public function acceptsAHookThatOnlyLooksLikeNpm(string $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'    => self::JSCPD_COMMAND,
            'post-autoload-dump' => $command,
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, "post-autoload-dump: {$command}");
    }

    /**
     * A hook that reaches npm through a referenced script, two levels deep,
     * is reported with the chain that leads there.
     *
     * @return void
     */
    #[Test]
    public function rejectsNpmReachedThroughAReferencedScript(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => self::JSCPD_COMMAND,
            'post-update-cmd' => ['@tools'],
            'tools'           => ['@php -r "echo 1;"', '@tools:node'],
            'tools:node'      => 'npm ci',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-update-cmd` runs npm or npx through `@tools` -> `@tools:node`', 'npm via @tools -> @tools:node');
    }

    /**
     * Scripts that reference each other in a cycle do not hang the gate,
     * and npm inside the cycle is still found.
     *
     * @return void
     */
    #[Test]
    public function survivesAReferenceCycle(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@a',
            'a'                => '@b',
            'b'                => ['@a', 'npm ci'],
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', 'cycle a -> b -> a with npm in b');
    }

    /**
     * npm in a script no Composer event runs is the contributor's own
     * choice — the contract is about what `composer install` does on its own.
     *
     * @return void
     */
    #[Test]
    public function acceptsNpmInAScriptNoEventRuns(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => self::JSCPD_COMMAND,
            'tools:install'   => 'npm ci',
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, 'npm ci in a script no event runs');
    }

    /**
     * Arguments appended to a script reference reach a shell, so npm passed
     * that way is found although the referenced script itself is clean.
     *
     * @return void
     */
    #[Test]
    public function rejectsNpmPassedAsArgumentsToAReference(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@runner npm ci',
            'runner'           => 'env',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', 'npm as arguments of @runner');
    }

    /**
     * Spellings of `@composer` that re-enter a script by name.
     *
     * @return array<string, array{0: string}>
     */
    public static function composerRunScriptProvider(): array
    {
        return self::singleArgProviderRows([
            '@composer run-script fetch-tools',
            '@composer run fetch-tools',
            '@composer run-script --no-interaction fetch-tools',
            '@composer fetch-tools',
            '@composer --no-interaction run-script fetch-tools',
            '@composer run-script --timeout 0 fetch-tools',
            '@composer run-script fetch-tools --no-interaction',
            '@composer run-script fetch-tools -- --verbose',
        ]);
    }

    /**
     * `@composer run-script <name>` re-enters a script like a reference, so
     * npm inside the named script is reached from the event.
     *
     * @param string $command The `@composer` spelling.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('composerRunScriptProvider')]
    public function rejectsNpmReEnteredThroughComposerRunScript(string $command): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => $command,
            'fetch-tools'      => 'npm ci',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx', "re-entry: {$command}");
    }

    /**
     * The longest chain of script references the gate follows ends in npm,
     * and the npm is reported with the chain that leads there.
     *
     * @return void
     */
    #[Test]
    public function followsAChainUpToTheDepthLimit(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, self::referenceChain(63));

        $this->assertGateRejects(self::phpGate(), $dir, 'the Composer event `post-install-cmd` runs npm or npx through', 'npm at the end of a chain within the limit');
    }

    /**
     * One reference more than the limit is not followed and is reported as
     * such, so a chain beyond the limit cannot hide npm.
     *
     * @return void
     */
    #[Test]
    public function reportsAChainDeeperThanTheLimit(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, self::referenceChain(64));

        $this->assertGateRejects(self::phpGate(), $dir, 'a chain of script references is deeper than', 'a chain beyond the limit');
    }

    /**
     * A chain far beyond the limit is cut off at the limit and reported once,
     * however long a manifest within the size cap makes it.
     *
     * @return void
     */
    #[Test]
    public function reportsAVeryLongChainWithoutWalkingItAll(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, self::referenceChain(20_000));

        $this->assertGateRejects(self::phpGate(), $dir, 'a chain of script references is deeper than', 'a chain of 20000 references is cut off at the limit');
        $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'a chain of 20000 references');
    }

    /**
     * A Composer event hooking a chain of the given number of references that
     * ends in npm.
     *
     * @param int $length The number of scripts in the chain.
     *
     * @return array<string, string>
     */
    private static function referenceChain(int $length): array
    {
        $scripts = [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@s0',
        ];

        for ($step = 0; $step < $length; ++$step) {
            $scripts['s' . $step] = '@s' . ($step + 1);
        }

        $scripts['s' . $length] = 'npm ci';

        return $scripts;
    }

    /**
     * The names of Composer's own `@` commands that are not script references.
     *
     * @return array<string, array{0: string}>
     */
    public static function composerCommandProvider(): array
    {
        return self::singleArgProviderRows(['php', 'putenv']);
    }

    /**
     * `@php` and `@putenv` are Composer's own commands. A script that happens
     * to carry one of those names is not what the word refers to, so npm in it
     * is not reached from the hook.
     *
     * @param string $name The Composer command and the name of the script beside it.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('composerCommandProvider')]
    public function doesNotFollowAComposerCommandIntoAScriptOfTheSameName(string $name): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => "@{$name} -v",
            $name              => 'npm ci',
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, "@{$name} beside a script named {$name}");
    }

    /**
     * A reference followed by arguments on several lines is still a
     * reference: the arguments are not cut at the first line break.
     *
     * @return void
     */
    #[Test]
    public function followsAReferenceWhoseArgumentsSpanSeveralLines(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => "@tools true\nmore",
            'tools'            => 'npm ci',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'runs npm or npx through `@tools`', 'a multi-line reference to a script that runs npm');
    }

    /**
     * The name of a referenced script is not a command: a script called
     * `tools/npm` that runs nothing harmful is not npm, although a slash
     * counts as the start of a program word in a command.
     *
     * @return void
     */
    #[Test]
    public function doesNotTreatAScriptNameContainingNpmAsNpm(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@tools/npm',
            'tools/npm'        => 'true',
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, 'a script named tools/npm referenced from a hook');
    }

    /**
     * A reference cycle with no npm in it is accepted, not reported as a
     * defect of its own.
     *
     * @return void
     */
    #[Test]
    public function acceptsAReferenceCycleWithoutNpm(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@a',
            'a'                => '@b',
            'b'                => '@a',
        ]);

        $this->assertGateAccepts(self::phpGate(), $dir, 'cycle a -> b -> a without npm');
    }

    /**
     * The reported chain is the one that leads to npm: a sibling branch that
     * was walked first and found nothing is not part of it.
     *
     * @return void
     */
    #[Test]
    public function reportsOnlyTheChainThatLeadsToNpm(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => ['@clean', '@dirty'],
            'clean'            => 'true',
            'dirty'            => 'npm ci',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'runs npm or npx through `@dirty` (`npm ci`)', 'npm behind the second of two siblings');
    }

    /**
     * A script name in the reported chain carrying a forged workflow command
     * is reported inertly.
     *
     * @return void
     */
    #[Test]
    public function reportsAForgedChainTargetInertly(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => '@##[error]forged',
            '##[error]forged'  => 'npm ci',
        ]);

        $this->assertGateReportIsInert(self::phpGate(), $dir, '##?[error]forged', 'a forged chain target is scrubbed before it is reported');
    }

    /**
     * The command of an event that runs npm, carrying a forged workflow
     * command, is reported inertly.
     *
     * @return void
     */
    #[Test]
    public function reportsAForgedEventCommandInertly(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd'  => self::JSCPD_COMMAND,
            'post-install-cmd' => "npm ci\n##[error]forged",
        ]);

        $this->assertGateReportIsInert(self::phpGate(), $dir, '##?[error]forged', 'a forged event command is scrubbed before it is reported');
    }
}
