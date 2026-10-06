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

use function file_get_contents;
use function json_encode;
use function preg_match_all;
use function substr;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Fixture-driven cases for the command text part of
 * bin/consumer-checks/check-jscpd-install.php (GH-223): a Composer script
 * that runs jscpd carries exactly the command line the shared cpd workflow
 * runs, so the command text of a recognised run is the one CI uses. The install part of that
 * check (the pin, the lockfile, npm and npx) is proven in
 * CheckConsumerConfigJscpdInstallTest. PHP gate only. See
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigJscpdScriptTest extends AbstractConsumerConfigTestCase
{
    /**
     * The README example of the cpd script is the command line this suite
     * and the gate hold as the documented one.
     *
     * @return void
     */
    #[Test]
    public function theReadmeExampleStatesTheDocumentedCommand(): void
    {
        $readme = file_get_contents(self::root() . '/README.md');

        if ($readme === false) {
            throw new RuntimeException('could not read README.md');
        }

        self::assertSame(
            1,
            preg_match_all('/"ci:test:php:cpd": "([^"]+)"/', $readme, $matches),
            'the README states the cpd script exactly once, in the jscpd install contract section',
        );
        self::assertSame(self::JSCPD_COMMAND, $matches[1][0]);
    }

    /**
     * Rows the gate must accept: the documented command in the positions the
     * scan recognises, and commands that do not run jscpd at all.
     *
     * @return array<string, array{0: string|list<string>}>
     */
    public static function acceptedScriptProvider(): array
    {
        return [
            'the documented command'                    => [self::JSCPD_COMMAND],
            'the flags in another order'                => ['node_modules/.bin/jscpd --fail-on-empty --no-tips --skip-comments --config .jscpd.json'],
            'the config pair first'                     => ['node_modules/.bin/jscpd --config .jscpd.json --fail-on-empty --no-tips --skip-comments'],
            'tabs and repeated spaces'                  => ["node_modules/.bin/jscpd\t--config  .jscpd.json  --skip-comments --no-tips   --fail-on-empty"],
            'after another command'                     => ['composer ci:test:php:lint && ' . self::JSCPD_COMMAND],
            'before another command'                    => [self::JSCPD_COMMAND . ' ; echo done'],
            'piped to another command'                  => [self::JSCPD_COMMAND . ' | tee cpd.log'],
            'one command of a script list'              => [['@php -r "echo 1;"', self::JSCPD_COMMAND]],
            'a tool that only mentions jscpd'           => ['echo jscpd'],
            'a path argument ending in jscpd'           => ['npx foo node_modules/.bin/jscpd'],
            'another tool run through npx'              => ['npx biome check'],
            'the program as an echo argument'           => ['echo node_modules/.bin/jscpd --version'],
            'a program name ending in jscpd'            => ['xjscpd --foo'],
            'a hyphenated program name'                 => ['my-jscpd --foo'],
            'a separator inside a quote'                => ['echo "hint; node_modules/.bin/jscpd --config .jscpd.json"'],
            'a separator inside single quotes'          => ["echo 'a && node_modules/.bin/jscpd --config .jscpd.json'"],
            'a line continuation between flags'         => ["node_modules/.bin/jscpd \\\n--config .jscpd.json --skip-comments --no-tips --fail-on-empty"],
            'a hash inside a word'                      => ['echo foo#bar; ' . self::JSCPD_COMMAND],
            'an escaped semicolon'                      => ['echo a\\; node_modules/.bin/jscpd src'],
            'a redirection on the program word'         => ['node_modules/.bin/jscpd>out src'],
            'a quoted semicolon before a comment'       => ['echo "a;b" # node_modules/.bin/jscpd src'],
            'an escaped quote inside double quotes'     => ['echo "say \"hi\"; node_modules/.bin/jscpd src"'],
            'a separator in a comment after a command'  => ['true # note ; node_modules/.bin/jscpd src'],
            'the command with a trailing comment'       => [self::JSCPD_COMMAND . ' # note'],
            'repeated spaces only'                      => ['node_modules/.bin/jscpd  --config  .jscpd.json  --skip-comments  --no-tips  --fail-on-empty'],
            'a program name that starts with jscpd'     => ['jscpd-foo --x'],
            'a program file that continues after jscpd' => ['node_modules/.bin/jscpd.sh --x'],
            'a continuation joining a word'             => ["node_modules/.bin/js\\\ncpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty"],
            'two documented commands chained'           => [self::JSCPD_COMMAND . ' && ' . self::JSCPD_COMMAND],
            'a tab before a trailing comment'           => [self::JSCPD_COMMAND . "\t# note"],
            'a redirection word before the program'     => ['>x node_modules/.bin/jscpd src'],
            'a command inside a comment'                => ["# node_modules/.bin/jscpd src\n" . self::JSCPD_COMMAND],
        ];
    }

    /**
     * A command that is the documented one, or does not run jscpd at all,
     * stays accepted: a check widened to any word that mentions jscpd goes
     * red here.
     *
     * @param string|list<string> $script The Composer script under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('acceptedScriptProvider')]
    public function acceptsTheDocumentedCommandAndCommandsThatDoNotRunJscpd(string|array $script): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => $script]);

        $this->assertGateAccepts(self::phpGate(), $dir, 'cpd script: ' . json_encode($script, JSON_THROW_ON_ERROR));
    }

    /**
     * A repository without any script that runs jscpd owes no command text,
     * and neither does one without a composer.json.
     *
     * @return void
     */
    #[Test]
    public function acceptsARepositoryWithoutAJscpdScript(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:lint' => 'phplint']);

        $this->assertGateAccepts(self::phpGate(), $dir, 'a composer.json whose scripts never run jscpd');

        unlink($dir . '/composer.json');

        $this->assertGateAccepts(self::phpGate(), $dir, 'no composer.json at all');
    }

    /**
     * Command text paired with the report fragment it must produce.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function driftingCommandProvider(): array
    {
        return [
            'no --fail-on-empty'                                     => ['node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips', '`--fail-on-empty` is missing'],
            'no --config'                                            => ['node_modules/.bin/jscpd --skip-comments --no-tips --fail-on-empty', '`--config .jscpd.json` is missing'],
            'no --skip-comments'                                     => ['node_modules/.bin/jscpd --config .jscpd.json --no-tips --fail-on-empty', '`--skip-comments` is missing'],
            'no --no-tips'                                           => ['node_modules/.bin/jscpd --config .jscpd.json --skip-comments --fail-on-empty', '`--no-tips` is missing'],
            'nothing but the program'                                => ['node_modules/.bin/jscpd', '`--config .jscpd.json` is missing'],
            'another config file'                                    => ['node_modules/.bin/jscpd --config other.json --skip-comments --no-tips --fail-on-empty', '`--config` must be followed by `.jscpd.json`'],
            'a config flag without a value'                          => ['node_modules/.bin/jscpd --skip-comments --no-tips --fail-on-empty --config', '`--config` must be followed by `.jscpd.json`'],
            'a config flag with another flag'                        => ['node_modules/.bin/jscpd --config --fail-on-empty --skip-comments --no-tips', '`--config` must be followed by `.jscpd.json`'],
            'the config as one --config= word'                       => ['node_modules/.bin/jscpd --config=.jscpd.json --skip-comments --no-tips --fail-on-empty', '`--config=.jscpd.json` is not part of the documented command'],
            'a scan path'                                            => [self::JSCPD_COMMAND . ' src', '`src` is not part of the documented command'],
            'a scan path before the flags'                           => ['node_modules/.bin/jscpd src tests --config .jscpd.json --skip-comments --no-tips --fail-on-empty', '`src` is not part of the documented command'],
            'another flag'                                           => [self::JSCPD_COMMAND . ' --reporters console', '`--reporters` is not part of the documented command'],
            'a threshold flag'                                       => [self::JSCPD_COMMAND . ' --threshold 5', '`--threshold` is not part of the documented command'],
            'the bare program'                                       => ['jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `jscpd`, not `node_modules/.bin/jscpd`'],
            'a relative dot path'                                    => ['./node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `./node_modules/.bin/jscpd`'],
            'an absolute path'                                       => ['/app/node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `/app/node_modules/.bin/jscpd`'],
            'the second command of a chain'                          => ['composer ci:test:php:lint && node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing'],
            'a drifting command before a pipe'                       => ['node_modules/.bin/jscpd --config .jscpd.json | tee cpd.log', '`--skip-comments` is missing'],
            'a drifting command after a ;'                           => ['true;node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing'],
            'the flags on the next line'                             => ["node_modules/.bin/jscpd --config .jscpd.json\n--skip-comments --no-tips --fail-on-empty", '`--skip-comments` is missing'],
            'a drifting command on a later line'                     => ["echo start\nnode_modules/.bin/jscpd --config .jscpd.json", '`--skip-comments` is missing'],
            'after a closed quote'                                   => ['echo "done" && node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing'],
            'a quote ending a segment before a separator'            => ['echo "a";echo "b";node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing'],
            'an apostrophe inside double quotes'                     => ['echo "it\'s"; node_modules/.bin/jscpd src', '`src` is not part of the documented command'],
            'a double quote inside single quotes'                    => ["true '\"'; node_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'an escaped apostrophe'                                  => ["echo it\\'s; node_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a continuation hiding a scan path'                      => ["node_modules/.bin/jscpd\\\n src", '`src` is not part of the documented command'],
            'a redirection after the command'                        => [self::JSCPD_COMMAND . ' 2>&1', '`2>` is not part of the documented command'],
            'tabs between the words'                                 => ["node_modules/.bin/jscpd\t--config\t.jscpd.json", '`--skip-comments` is missing'],
            'a newline after a trailing comment'                     => ["true # c\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a backslash inside single quotes'                       => ["echo 'a\\'; node_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'an escaped quote inside double quotes'                  => ['echo "a\"; b"; node_modules/.bin/jscpd src', '`src` is not part of the documented command'],
            'an escaped semicolon on the command'                    => [self::JSCPD_COMMAND . ' \;', '`\;` is not part of the documented command'],
            'a lone trailing backslash'                              => [self::JSCPD_COMMAND . ' \\', '`\\` is not part of the documented command'],
            'an unterminated quote at the end'                       => ['node_modules/.bin/jscpd src "', '`src` is not part of the documented command'],
            'a quote ending a comment'                               => ["true # say \"\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a comment on a later line'                              => ["echo a\n# c\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'the program in another letter case'                     => ['Node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `Node_modules/.bin/jscpd`'],
            'a config file that only starts like the documented one' => ['node_modules/.bin/jscpd --config .jscpd.json.bak --skip-comments --no-tips --fail-on-empty', '`--config` must be followed by `.jscpd.json`'],
            'a continuation before a hash'                           => [self::JSCPD_COMMAND . " \\\n#x\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a bare program after an npx and a semicolon'            => ['npx x ; jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `jscpd`'],
            'a bare program after an npx and &&'                     => ['npx x && jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `jscpd`'],
            'a bare program after an npx and a pipe'                 => ['npx x | jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `jscpd`'],
            'a group with the parenthesis on the program'            => ['(node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty)', 'the program is `(node_modules/.bin/jscpd`'],
            'a quoted config value'                                  => ['node_modules/.bin/jscpd --config ".jscpd.json" --skip-comments --no-tips --fail-on-empty', '`--config` must be followed by `.jscpd.json`'],
            'a second documented segment missing a flag'             => [self::JSCPD_COMMAND . ' && node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing'],
            'a repeated config with a wrong second value'            => ['node_modules/.bin/jscpd --config .jscpd.json --config other.json --skip-comments --no-tips --fail-on-empty', '`--config` must be followed by `.jscpd.json`'],
            'a continuation inside the program word'                 => ["node_modules/.bin/js\\\ncpd src", '`src` is not part of the documented command'],
            'a tab before a comment hiding a separator'              => ["true\t# say ; x\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a command substitution opener glued to the program'     => ['$(node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `$(node_modules/.bin/jscpd`'],
            'an assignment of a substitution glued to the program'   => ['x=$(node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `x=$(node_modules/.bin/jscpd`'],
            'a backtick glued to the program'                        => ['`node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is ``node_modules/.bin/jscpd`'],
            'a redirection glued before the program'                 => ['>node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty', 'the program is `>node_modules/.bin/jscpd`'],
            'a heredoc body starting with the program'               => ["cat <<EOF\nnode_modules/.bin/jscpd src\nEOF", '`src` is not part of the documented command'],
            'an apostrophe in a comment'                             => ["# it's\nnode_modules/.bin/jscpd src", '`src` is not part of the documented command'],
            'a repeated --fail-on-empty'                             => [self::JSCPD_COMMAND . ' --fail-on-empty', '`--fail-on-empty` is given twice'],
            'a repeated --skip-comments'                             => [self::JSCPD_COMMAND . ' --skip-comments', '`--skip-comments` is given twice'],
            'a repeated --no-tips'                                   => [self::JSCPD_COMMAND . ' --no-tips', '`--no-tips` is given twice'],
            'a repeated config pair'                                 => [self::JSCPD_COMMAND . ' --config .jscpd.json', '`--config .jscpd.json` is given twice'],
        ];
    }

    /**
     * Every way the command can differ from the documented one is reported,
     * with the part that differs.
     *
     * @param string $command  The Composer script under test.
     * @param string $expected The substring the report must carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('driftingCommandProvider')]
    public function rejectsACommandThatDiffersFromTheDocumentedOne(string $command, string $expected): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => $command]);

        $this->assertGateRejects(self::phpGate(), $dir, $expected, "cpd script: {$command}");
    }

    /**
     * The script name does not matter, a script that runs jscpd is checked
     * under whatever name it carries.
     *
     * @return void
     */
    #[Test]
    public function checksAScriptUnderAnyName(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => self::JSCPD_COMMAND,
            'ci:test:cpd'     => 'node_modules/.bin/jscpd --config .jscpd.json',
        ]);

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'the script `ci:test:cpd`',
            'a second script that runs jscpd with a drifting command',
        );
    }

    /**
     * One command of a script list that drifts is enough, the others being
     * correct.
     *
     * @return void
     */
    #[Test]
    public function rejectsADriftingCommandInsideAScriptList(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => [
                self::JSCPD_COMMAND,
                'node_modules/.bin/jscpd --config .jscpd.json',
            ],
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, '`--skip-comments` is missing', 'the second command of a list');
    }

    /**
     * A script with several drifting commands is one finding, not one per
     * command.
     *
     * @return void
     */
    #[Test]
    public function reportsAScriptWithSeveralDriftingCommandsOnce(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => [
                'node_modules/.bin/jscpd --config .jscpd.json',
                'node_modules/.bin/jscpd src',
            ],
        ]);

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'two drifting commands of one script');
    }

    /**
     * Drift fragments paired with the whole text the report carries for them,
     * the offending command following in backticks. A command past the report's
     * length cap is cut, so every row uses a short one except the last, which
     * builds the cut text from the command.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wholeDriftFragmentProvider(): array
    {
        $longCommand = 'node_modules/.bin/jscpd --config .jscpd.json --config .jscpd.json';

        return [
            'a wrong program'        => ['jscpd src', 'the program is `jscpd`, not `node_modules/.bin/jscpd` (`jscpd src`)'],
            'a config without value' => ['node_modules/.bin/jscpd --config', '`--config` must be followed by `.jscpd.json` (`node_modules/.bin/jscpd --config`)'],
            'a missing config'       => ['node_modules/.bin/jscpd', '`--config .jscpd.json` is missing (`node_modules/.bin/jscpd`)'],
            'a missing flag'         => ['node_modules/.bin/jscpd --config .jscpd.json', '`--skip-comments` is missing (`node_modules/.bin/jscpd --config .jscpd.json`)'],
            'a repeated flag'        => ['node_modules/.bin/jscpd --no-tips --no-tips', '`--no-tips` is given twice (`node_modules/.bin/jscpd --no-tips --no-tips`)'],
            'a repeated config pair' => [$longCommand, '`--config .jscpd.json` is given twice (`' . substr($longCommand, 0, 64) . '…`)'],
        ];
    }

    /**
     * Each drift fragment is reported whole, so text after the part a case
     * names, such as a stray full stop, turns a row red.
     *
     * @param string $script   The script command.
     * @param string $expected The whole fragment with the echoed command.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('wholeDriftFragmentProvider')]
    public function reportsEachDriftFragmentWhole(string $script, string $expected): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => $script]);

        $this->assertGateRejects(self::phpGate(), $dir, $expected, 'the drift fragment is reported whole');
    }

    /**
     * The report tells the author which command line the shared workflow runs.
     *
     * @return void
     */
    #[Test]
    public function reportsTheDocumentedCommandLineAsGuidance(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, ['ci:test:php:cpd' => 'node_modules/.bin/jscpd src']);

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'the script `ci:test:php:cpd` runs jscpd with a command line that differs from the documented one: `src` is not part of the documented command (`node_modules/.bin/jscpd src`). The shared cpd workflow runs `' . self::JSCPD_COMMAND . '`, so the script runs exactly that, with the scan paths in `.jscpd.json`.',
            'the report names the offending command and the documented command line',
        );
    }

    /**
     * Every script that drifts is reported, not only the first one.
     *
     * @return void
     */
    #[Test]
    public function reportsEveryDriftingScriptNotOnlyTheFirst(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'cpd:first'  => 'node_modules/.bin/jscpd src',
            'cpd:second' => 'node_modules/.bin/jscpd --config .jscpd.json',
        ]);

        $this->assertGateRejects(self::phpGate(), $dir, 'the script `cpd:first` runs jscpd', 'the first drifting script');
        $this->assertGateRejects(self::phpGate(), $dir, 'the script `cpd:second` runs jscpd', 'the second drifting script');
    }

    /**
     * The command is repository content, so a control character or a forged
     * workflow command in it reaches the report scrubbed.
     *
     * @return void
     */
    #[Test]
    public function scrubsAForgedArgumentBeforeItIsReported(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => self::JSCPD_COMMAND . " x##[error]forged\x1b[31m",
        ]);

        $this->assertGateReportIsInert(
            self::phpGate(),
            $dir,
            '##?[error]forged',
            'a forged argument is scrubbed before it is reported',
        );
    }

    /**
     * The script name is repository content too, and the drift report names it.
     *
     * @return void
     */
    #[Test]
    public function scrubsAForgedScriptNameBeforeItIsReported(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            "cpd\n##[error]forged" => 'node_modules/.bin/jscpd --config .jscpd.json',
        ]);

        $this->assertGateReportIsInert(
            self::phpGate(),
            $dir,
            '##?[error]forged',
            'a forged script name is scrubbed before the drift is reported',
        );
    }

    /**
     * The same for a forged program word, which the program-path report
     * carries on its own.
     *
     * @return void
     */
    #[Test]
    public function scrubsAForgedProgramWordBeforeItIsReported(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'x##[error]forged/node_modules/.bin/jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty',
        ]);

        $this->assertGateReportIsInert(
            self::phpGate(),
            $dir,
            '##?[error]forged',
            'a forged program word is scrubbed before it is reported',
        );
    }

    /**
     * A command that runs jscpd through npx, or with a version in it, is
     * the install part's finding and stays one finding, the command text is
     * not reported on top of it.
     *
     * @return void
     */
    #[Test]
    public function reportsJscpdThroughNpxOnceAsAnInstallFinding(): void
    {
        $dir = $this->installFixture();
        self::writeComposerScripts($dir, [
            'ci:test:php:cpd' => 'npx jscpd --config .jscpd.json --skip-comments --no-tips --fail-on-empty',
        ]);

        $this->assertGateReportsOnce(self::phpGate(), $dir, 'composer.json', 'jscpd through npx');
        $this->assertGateRejects(self::phpGate(), $dir, 'runs jscpd through npx or names a version', 'jscpd through npx');
    }
}
