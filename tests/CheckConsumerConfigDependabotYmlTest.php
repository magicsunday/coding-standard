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
use function mkdir;
use function str_repeat;

/**
 * Fixture-driven cases for bin/consumer-checks/check-dependabot-yml.php, the
 * optional .github/dependabot.yml contract. Every `updates` entry carries a
 * non-empty `commit-message.prefix`, as a direct key of the entry and as a direct
 * key of `commit-message`, in block style. PHP gate only, since bin/check-js-config.mjs has no
 * dependabot.yml counterpart. See AbstractConsumerConfigTestCase for the shared
 * scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigDependabotYmlTest extends AbstractConsumerConfigTestCase
{
    /**
     * Writes a .github/dependabot.yml into a fresh canonical case.
     *
     * @param string $contents The file contents.
     *
     * @return string The case directory.
     */
    private function caseWithDependabot(string $contents): string
    {
        $dir = $this->mkCase();
        mkdir($dir . '/.github');
        file_put_contents($dir . '/.github/dependabot.yml', $contents);

        return $dir;
    }

    /**
     * Wraps one entry body, as written after `updates:`, into a whole file.
     *
     * @param string $entry The entry, indented the way a block-style entry is.
     *
     * @return string The file contents.
     */
    private static function singleEntryFile(string $entry): string
    {
        return "updates:\n    -   package-ecosystem: npm\n" . $entry;
    }

    /**
     * The shipped template, as a consumer copies it.
     */
    #[Test]
    public function acceptsTheShippedTemplate(): void
    {
        $dir = $this->mkCase();
        mkdir($dir . '/.github');
        copy(self::root() . '/templates/dependabot.yml', $dir . '/.github/dependabot.yml');

        $this->assertGateAccepts(self::phpGate(), $dir, 'templates/dependabot.yml as shipped');
    }

    /**
     * No dependabot.yml at all. The file is optional.
     */
    #[Test]
    public function acceptsARepositoryWithoutDependabotYml(): void
    {
        $this->assertGateAccepts(
            self::phpGate(),
            $this->mkCase(),
            'a repository without .github/dependabot.yml'
        );
    }

    /**
     * One entry of two lacks the setting, and the report names that entry only.
     */
    #[Test]
    public function rejectsAnEntryWithoutCommitMessage(): void
    {
        $dir = $this->caseWithDependabot(
            "version: 2\nupdates:\n"
            . "    -   package-ecosystem: composer\n        directory: /\n        commit-message:\n            prefix: \"Update dependencies\"\n"
            . "    -   package-ecosystem: npm\n        directory: /\n"
        );

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'the `npm` entry has no `commit-message.prefix`',
            'an entry without commit-message'
        );

        $this->assertGateReportsOnce(
            self::phpGate(),
            $dir,
            '.github/dependabot.yml',
            'only the entry without the setting is reported'
        );
    }

    /**
     * A prefix set on the first entry does not carry over to the second.
     */
    #[Test]
    public function rejectsAPrefixOnlyOnAnEarlierEntry(): void
    {
        $dir = $this->caseWithDependabot(
            "updates:\n"
            . "    -   package-ecosystem: composer\n        commit-message:\n            prefix: A\n"
            . "    -   package-ecosystem: npm\n        groups:\n            npm:\n                patterns:\n                    - \"*\"\n"
        );

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'the `npm` entry',
            'a prefix only on an earlier entry'
        );
    }

    /**
     * An `updates` block with no entry gives the gate nothing to vouch for.
     */
    #[Test]
    public function rejectsAFileWithoutUpdatesEntries(): void
    {
        $dir = $this->caseWithDependabot("version: 2\n");

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'lists no `updates` entry',
            'a file without updates entries'
        );
    }

    /**
     * Entries that must be rejected, each written the way it would slip past a
     * looser matcher.
     *
     * @return array<string, array{0: string}>
     */
    public static function rejectedEntryProvider(): array
    {
        return [
            'commit-message without prefix'      => [self::singleEntryFile("        commit-message:\n            include: scope\n")],
            'bare empty prefix'                  => [self::singleEntryFile("        commit-message:\n            prefix:\n")],
            'double-quoted empty prefix'         => [self::singleEntryFile("        commit-message:\n            prefix: \"\"\n")],
            'single-quoted empty prefix'         => [self::singleEntryFile("        commit-message:\n            prefix: ''\n")],
            'whitespace-only quoted prefix'      => [self::singleEntryFile("        commit-message:\n            prefix: \"  \"\n")],
            'comment-only prefix'                => [self::singleEntryFile("        commit-message:\n            prefix: # nothing\n")],
            'literal block scalar prefix'        => [self::singleEntryFile("        commit-message:\n            prefix: |\n")],
            'folded block scalar prefix'         => [self::singleEntryFile("        commit-message:\n            prefix: >\n")],
            'alias prefix'                       => [self::singleEntryFile("        commit-message:\n            prefix: *shared\n")],
            'prefix under a different key'       => [self::singleEntryFile("        commit-message:\n            include: scope\n        labels:\n            prefix: x\n")],
            'prefix deeper than the first child' => [self::singleEntryFile("        commit-message:\n            include:\n                prefix: x\n")],
            'commit-message nested under groups' => [self::singleEntryFile("        groups:\n            deps:\n                commit-message:\n                    prefix: x\n")],
            'commit-message with comment only'   => [self::singleEntryFile("        commit-message: # note\n        directory: /\n")],
            'flow-style commit-message'          => [self::singleEntryFile("        commit-message: { prefix: x }\n")],
            'flow-style without prefix'          => [self::singleEntryFile("        commit-message: { include: scope }\n")],
            'commit-message with a plain value'  => [self::singleEntryFile("        commit-message: x\n")],
            'null prefix'                        => [self::singleEntryFile("        commit-message:\n            prefix: null\n")],
            'tilde prefix'                       => [self::singleEntryFile("        commit-message:\n            prefix: ~\n")],
            'null prefix in capitals'            => [self::singleEntryFile("        commit-message:\n            prefix: NULL # none\n")],
            'unterminated double quote'          => [self::singleEntryFile("        commit-message:\n            prefix: \"\n")],
            'unterminated single quote'          => [self::singleEntryFile("        commit-message:\n            prefix: 'x\n")],
            'anchor prefix'                      => [self::singleEntryFile("        commit-message:\n            prefix: &shared x\n")],
            'tagged prefix'                      => [self::singleEntryFile("        commit-message:\n            prefix: !!str x\n")],
            'flow sequence prefix'               => [self::singleEntryFile("        commit-message:\n            prefix: [x]\n")],
            'flow map prefix'                    => [self::singleEntryFile("        commit-message:\n            prefix: { a: b }\n")],
        ];
    }

    /**
     * Each rejected shape is reported for the entry it sits in.
     *
     * @param string $contents The whole dependabot.yml.
     */
    #[Test]
    #[DataProvider('rejectedEntryProvider')]
    public function rejectsAnEntryWithoutAUsablePrefix(string $contents): void
    {
        $this->assertGateRejects(
            self::phpGate(),
            $this->caseWithDependabot($contents),
            'the `npm` entry has no `commit-message.prefix`',
            'an entry whose prefix is missing, empty or not a direct key'
        );
    }

    /**
     * Whole files that must be accepted, each a legal shape of the same contract.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedFileProvider(): array
    {
        return [
            'column-0 items, CRLF, quoted and bare prefix, BOM' => [
                "\xEF\xBB\xBFversion: 2\r\nupdates:\r\n"
                . "- package-ecosystem: composer\r\n  commit-message:\r\n    prefix: \"Update dependencies\" # why\r\n"
                . "- package-ecosystem: npm\r\n  commit-message:\r\n    prefix: Update\r\n"
                . "- package-ecosystem: github-actions\r\n  commit-message:\r\n    prefix: 'Update'\r\n",
            ],
            'nested list inside a column-0 sequence' => [
                "updates:\n- package-ecosystem: npm\n  groups:\n    npm:\n      patterns:\n      - \"*\"\n  commit-message:\n    prefix: x\n",
            ],
            'quoted value that reads like null' => [
                self::singleEntryFile("        commit-message:\n            prefix: \"null\"\n"),
            ],
            'comment after commit-message' => [
                self::singleEntryFile("        commit-message: # note\n            prefix: x\n"),
            ],
            'blank and comment lines before prefix' => [
                self::singleEntryFile("        commit-message:\n\n            # why this prefix\n            prefix: x\n"),
            ],
            'prefix after another child key' => [
                self::singleEntryFile("        commit-message:\n            include: scope\n            prefix: x\n"),
            ],
            'commit-message on the dash line' => [
                "updates:\n    -   commit-message:\n            prefix: x\n        package-ecosystem: npm\n",
            ],
            'dash on a line of its own' => [
                "updates:\n    -\n        package-ecosystem: npm\n        commit-message:\n            prefix: x\n",
            ],
        ];
    }

    /**
     * Legal shapes of the contract are not reported.
     *
     * @param string $contents The whole dependabot.yml.
     */
    #[Test]
    #[DataProvider('acceptedFileProvider')]
    public function acceptsLegalShapes(string $contents): void
    {
        $this->assertGateAccepts(
            self::phpGate(),
            $this->caseWithDependabot($contents),
            'a legal shape of commit-message.prefix'
        );
    }

    /**
     * An entry without `package-ecosystem` is still reported, under a placeholder name.
     */
    #[Test]
    public function namesAnEntryWithoutEcosystemByPlaceholder(): void
    {
        $dir = $this->caseWithDependabot("updates:\n    -   directory: /\n");

        $this->assertGateRejects(
            self::phpGate(),
            $dir,
            'the `?` entry has no `commit-message.prefix`',
            'an entry without package-ecosystem'
        );
    }

    /**
     * An oversized file is reported once, as itself.
     */
    #[Test]
    public function reportsOnceWhenTheFileExceedsTheTextSizeCap(): void
    {
        $dir = $this->caseWithDependabot(str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(
            self::phpGate(),
            $dir,
            '.github/dependabot.yml',
            'an oversized dependabot.yml is reported once'
        );
    }

    /**
     * An unreadable file reports only that it cannot be read and fabricates no
     * content drift on top of it.
     */
    #[Test]
    public function rejectsAnUnreadableFileAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->caseWithDependabot("updates:\n    -   package-ecosystem: npm\n");
        chmod($dir . '/.github/dependabot.yml', 0o000);

        try {
            $this->assertGateRejects(
                self::phpGate(),
                $dir,
                '.github/dependabot.yml: exists but cannot be read',
                'an unreadable dependabot.yml reports only that it cannot be read'
            );

            $this->assertGateReportsOnce(
                self::phpGate(),
                $dir,
                '.github/dependabot.yml',
                'an unreadable dependabot.yml fabricates no content drift'
            );
        } finally {
            chmod($dir . '/.github/dependabot.yml', 0o644);
        }
    }

    /**
     * A control byte in the ecosystem name never reaches the report raw.
     */
    #[Test]
    public function reportIsInertForAForgedEcosystemName(): void
    {
        $dir = $this->caseWithDependabot(
            "updates:\n    -   package-ecosystem: \"##[error]forged\"\n        directory: /\n"
        );

        $this->assertGateReportIsInert(
            self::phpGate(),
            $dir,
            '##?[error]forged',
            'a forged ecosystem name'
        );
    }
}
