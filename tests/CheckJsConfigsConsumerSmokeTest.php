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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * The packaged-consumer smoke, split out of the former
 * tests/CheckJsConfigsTest.php (#75): real Biome, tsc and jscpd against the
 * shared configs as a consumer receives them — installed from the tarball
 * AbstractJsConfigsTestCase::packagedConsumer() packs from the git archive.
 * Covers the accept smoke (a consumer extending both shared configs), the
 * controls proving the shared rules actually bite (every one asserting the
 * DIAGNOSTIC, not the bare exit status), and templates/jscpd.json's format
 * names against jscpd itself.
 *
 * Extends AbstractJsConfigsTestCase for the shared packagedConsumer() and
 * mutateConsumerFile(); see that class's own docblock for the split, the
 * shared-cache semantics, and why `#[Group('js-packaging')]` runs on one CI
 * matrix leg only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsConsumerSmokeTest extends AbstractJsConfigsTestCase
{
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
     * The "reject, and every one of N patterns is present" triad — this
     * class's own equivalent of the bash original's harness_assert_tool_rejects(),
     * expressed with preg_match() rather than a literal ERE port. Every
     * pattern must match (AND, not OR); an alternation inside one pattern
     * already gets the OR case.
     *
     * Several callers drive this with a $result whose $result->output comes
     * from running Biome/tsc against the shared, PR-editable
     * packagedConsumer() config — re-derive the current caller list with
     * `grep -n '$thi[s]->assertRejectedForReason(' tests/CheckJsConfigsConsumerSmokeTest.php`
     * (anchored on the `$this->` call syntax, with the "s" bracket-split so
     * this citation's own copy of the command text does not also match,
     * alongside the method's own declaration and the prose mention above)
     * rather than trusting a name list frozen here. A poisoned
     * biome/base.json or tsconfig/base.json could otherwise forge a
     * `##[`/`::` workflow-command sequence through this method's own default
     * failure messages below, the same defect class GateTestCase's
     * assertGateReportIsInert()/assertReportCarries() and this file's own
     * scrubbedForDiagnostic()-based tests exist to prevent. Neither of this
     * method's internal assertions passes $result->output as the assertion
     * SUBJECT: the exit-code check compares plain integers, and the
     * per-pattern check computes preg_match() manually and calls self::fail()
     * with a message composed entirely by this method, so PHPUnit's own
     * Exporter::export() of the subject/actual/expected operand — which a
     * scrubbed custom $message cannot suppress — never sees the raw output
     * either. This closes the class fully for this method's own internal
     * assertions; a caller that supplies its own non-empty $message embedding
     * $result->output must still scrub it there itself, since this method
     * uses that message verbatim.
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
        self::assertNotSame(
            0,
            $result->exitCode,
            self::messageOrDefault($message, 'Accepted; the rule is not in force.', $result->output),
        );

        $flags = $caseInsensitive ? 'i' : '';

        foreach ($mustAllMatch as $pattern) {
            if (preg_match("#{$pattern}#{$flags}", $result->output) !== 1) {
                self::fail(self::messageOrDefault($message, 'Rejected, but not for the tested reason.', $result->output));
            }
        }
    }

    /**
     * The exit-0 accept-path check nearly every biomeCi()/runTsc() call in
     * this file drives — mirrors tests/CheckJsConfigsManifestTest.php's own
     * assertManifestAccepts() shape. $context both labels the failure and is
     * the only thing that varies between call sites, so this collapses the
     * hand-rolled `self::assertSame(0, $result->exitCode,
     * self::diagnosticMessage(<label>, $result->output))` triad repeated at
     * every accept-path assertion into one call.
     *
     * @param GateResult $result  The captured biomeCi()/runTsc() run to check.
     * @param string     $context Describes the tool/fixture under test, used as the failure label.
     *
     * @return void
     */
    private function assertAccepted(GateResult $result, string $context): void
    {
        self::assertSame(0, $result->exitCode, self::diagnosticMessage($context, $result->output));
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
    // A consumer extending both shared configs — the accept smoke.
    // -------------------------------------------------------------------

    /**
     * The shared config loads and a clean fixture passes, on both tools.
     *
     * $biome->output/$tsc->output are scrubbed before landing in either
     * failure message below: both run against this repository's own real
     * biome/base.json and tsconfig/base.json — PR-editable shipped configs,
     * not test-authored literals — and AGENTS.md's own documented incident
     * notes Biome's config deserializer echoing an unrecognized key back
     * verbatim in its error text, so a crafted key could reach this
     * assertion's own message the same way a subprocess-output assertion
     * would.
     */
    #[Test]
    public function sharedConfigAcceptsAConsumerExtendingBiomeAndTsconfig(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];

        $biome = $this->biomeCi($consumerDir);
        $this->assertAccepted($biome, 'biome ci — shared config rejected or the clean fixture reported findings.');

        $tsc = $this->runTsc($consumerDir);
        $this->assertAccepted($tsc, 'tsc — shared config rejected or the clean fixture failed to compile.');
    }

    /**
     * Proves the pattern applied at every biomeCi()/runTsc() accept-path
     * assertion — self::scrubbedForDiagnostic() wrapping $result->output
     * before it can land in a failure message — actually closes the trap,
     * using the exact real incident AGENTS.md documents
     * rather than a hand-crafted stand-in for it: Biome's config deserializer
     * echoes an unrecognized key back verbatim ("Found an unknown key ...").
     * Poisons the CONSUMER'S INSTALLED copy of biome/base.json (the file
     * biome.json's "extends" specifier actually resolves to at runtime, not
     * the archived source tree) with an unknown key carrying a forged
     * `::error::` sequence, confirms the resulting real `biome ci` failure
     * genuinely still carries that sequence raw in $result->output (else this
     * test would not be exercising the trap it claims to), then drives the
     * same scrubbed assertion shape sharedConfigAcceptsAConsumerExtendingBiomeAndTsconfig()
     * above uses and confirms the caught failure message does not.
     */
    #[Test]
    public function biomeCiFailureAgainstAPoisonedSharedConfigDoesNotForgeAWorkflowCommand(): void
    {
        $consumerDir = self::packagedConsumer()['consumerDir'];
        $poison      = '::error title=pwned::forged';

        $this->mutateConsumerFile(
            $consumerDir,
            'node_modules/@magicsunday/coding-standard/biome/base.json',
            <<<JSON
            {
                "{$poison}": true
            }
            JSON,
        );

        $result = $this->biomeCi($consumerDir);

        self::assertNotSame(
            0,
            $result->exitCode,
            'The poisoned unknown key did not make biome ci fail — this test is not exercising the trap it claims to.',
        );

        if (!str_contains($result->output, $poison)) {
            self::fail(self::diagnosticMessage("The poisoned config's own raw biome output no longer carries the poisoned sequence; this test is not exercising the trap it claims to.", $result->output));
        }

        $thrown = self::assertThrows(
            fn () => $this->assertAccepted($result, 'biome ci — shared config rejected or the clean fixture reported findings.'),
            AssertionFailedError::class,
            'The poisoned config no longer fails the scrubbed assertion this test drives.',
        );

        self::assertMessageDoesNotForgeWorkflowCommand(
            $thrown->getMessage(),
            $poison,
            'The scrubbed biome ci failure message forged a workflow command.',
        );
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
     *
     * $biome->output/$tsc->output are scrubbed for the same reason as
     * sharedConfigAcceptsAConsumerExtendingBiomeAndTsconfig()'s own docblock
     * above — see that method, not repeated here.
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
        $this->assertAccepted($biome, 'biome — the house .js import extension was rejected.');

        $tsc = $this->runTsc($consumerDir);
        $this->assertAccepted($tsc, 'tsc — the house .js import extension failed to compile.');
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

        if ($provenKeys !== $mappedKeys) {
            self::fail(self::diagnosticMessage("biome/base.json's extensionMappings keys no longer match the set this suite proves.", json_encode($mappedKeys, JSON_THROW_ON_ERROR)));
        }

        foreach (self::PROVEN_EXTENSION_TARGETS as $source => $want) {
            $mapped = $mappings[$source] ?? null;

            if ($want !== $mapped) {
                self::fail(self::diagnosticMessage("biome/base.json maps .{$source} to something other than .{$want}, the target this smoke proves.", (string) $mapped));
            }
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
        $this->assertAccepted($accept, "biome — an import spelling .{$targetExtension} from a .{$sourceExtension} source was rejected; the mapping row is wrong or missing.");

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
            self::diagnosticMessage("biome — an extensionless import from a .{$sourceExtension} source was accepted, so useImportExtensions is not in force for that extension.", $reject->output),
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

        $this->assertAccepted($result, 'biome — the asset fixture failed; either an asset import was told to add a .js extension, or it failed for an unrelated reason.');
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

        $this->assertAccepted($result, 'tsc no longer resolves the extensionless tsconfig "extends" specifier; the gate\'s suffixOptional=true assumption is wrong.');
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
            self::diagnosticMessage('biome resolved the extensionless specifier; the gate\'s requirement of the .json suffix for biome is wrong.', $result->output),
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

        if ($provenFormats !== $templateFormats) {
            self::fail(self::diagnosticMessage("templates/jscpd.json's format list no longer matches the set this suite proves — a format was added or dropped without a matching fixture.", json_encode($templateFormats, JSON_THROW_ON_ERROR)));
        }
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
            self::diagnosticMessage("jscpd control — no clone found in two identical .{$extension} files; the \"{$format}\" format name no longer analyses anything.", $result->output),
        );
    }
}
