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

use function array_diff;
use function count;
use function file_put_contents;
use function preg_match;
use function preg_match_all;
use function substr_count;
use function unlink;

/**
 * Fixture-driven cases for bin/consumer-checks/check-biome-tsconfig.php and
 * bin/check-js-config.mjs — the biome.json/biome.jsonc/tsconfig.json
 * extends-stub contract itself: which `extends` specifier counts as the
 * shared config, and what the effective config may not switch off (linter,
 * formatter, assist, includes, rule presets, overrides and the per-language
 * walk, proven in both directions against the gate's own language list).
 * Every case runs BOTH gates against the same fixture through the
 * assertBoth*() helpers. The rest of check-biome-tsconfig.php is split along
 * its own seams into the CheckConsumerConfigBiomeTsconfig*Test siblings —
 * see AbstractConsumerConfigTestCase for the map.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigBiomeTsconfigTest extends AbstractConsumerConfigTestCase
{
    /**
     * The languages this suite knows how to drive through biome's per-
     * language linter.enabled walk. A row the gate gains that is not here
     * fails languageWalkBijectionHoldsBothDirections() rather than shipping
     * unexercised.
     *
     * @var list<non-empty-string>
     */
    private const array PROVEN_LANGUAGES = ['javascript', 'json', 'css', 'graphql', 'grit', 'html'];

    // -------------------------------------------------------------------
    // Gate-source extraction — this contract's lockstep table, read at
    // runtime from the check-*.php split that declares it through the
    // shared gateSource()/extractQuotedList() primitives (see
    // AbstractConsumerConfigTestCase's class docblock).
    // -------------------------------------------------------------------

    /**
     * Extracts the language list from the gate's
     * `foreach (['javascript', 'json', ...] as $language)` line — the ONE
     * line carrying the array literal, not the block around it, so a range
     * that also swallowed the loop body would misread `linter`/`formatter`
     * keys as language names.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the line cannot be found, the comma count disagrees, or nothing parsed.
     */
    private static function languagesFromGate(): array
    {
        $relativePath = 'bin/consumer-checks/check-biome-tsconfig.php';
        $source       = self::gateSource($relativePath);

        if (preg_match("/foreach \(\['javascript'[^\]]*\]/", $source, $matches) !== 1) {
            throw new RuntimeException("could not find the per-language foreach literal in {$relativePath}");
        }

        $literal = $matches[0];
        $commas  = substr_count($literal, ',');

        preg_match_all("/['\"]([a-z0-9_-]+)['\"]/", $literal, $names);

        if ((count($names[1]) - 1) !== $commas) {
            throw new RuntimeException(
                'the language list holds ' . ($commas + 1) . ' entries but this test parsed ' . count($names[1])
                . ' — widen the extractor rather than leaving a row unexercised',
            );
        }

        if ($names[1] === []) {
            throw new RuntimeException('read no language names from the gate — the language lockstep did not run');
        }

        $languages = $names[1];

        /** @var list<non-empty-string> $languages */
        return $languages;
    }

    // -------------------------------------------------------------------
    // biome.json / tsconfig.json: the JS/TS extends contract
    // -------------------------------------------------------------------

    /**
     * Canonical biome.json + tsconfig.json (with a JSONC comment).
     */
    #[Test]
    public function acceptsCanonicalBiomeAndTsconfigWithJsoncComment(): void
    {
        $this->assertBothAccept($this->mkJsCase(), 'canonical biome.json + tsconfig.json (with a JSONC comment)');
    }

    /**
     * The bug this package shipped: a "//" note key is valid JSON but makes
     * Biome refuse the entire config.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeNoteKey(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"//\": \"shared config for this repo\",\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, '`"//"` key', 'biome.json with a "//" note key');
    }

    /**
     * Biome.json with a nested "//" key.
     */
    #[Test]
    public function rejectsBiomeNoteKeyNested(): void
    {
        // The same key nested one level down is just as fatal — Biome
        // rejects unknown keys at any depth, so a top-level-only check
        // would pass this vacuously.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": {\n        \"//\": \"our overrides\",\n        \"enabled\": true\n    }\n}\n");

        $this->assertBothReject($dir, '`"//"` key', 'biome.json with a nested "//" key');
    }

    /**
     * biome.json whose local extends target carries a "//" key.
     */
    #[Test]
    public function rejectsBiomeNoteKeyInLocalExtendsTarget(): void
    {
        // The same key inside a LOCAL `extends` target, not the document
        // itself. As re-verified on 2026-08-31 against the Biome version
        // package.json currently pins (`jq -r '.devDependencies["@biomejs/biome"]'
        // package.json`): the note key makes `./biome.loose.json` unloadable
        // regardless of what the document that extends it looks like.
        $dir = $this->mkJsCase();
        self::writeBiomeWithLocalLooseExtendsTarget($dir);
        file_put_contents($dir . '/biome.loose.json', "{ \"//\": \"note\", \"linter\": { \"enabled\": true } }\n");

        $this->assertBothReject($dir, 'a local `extends` target contains a `"//"` key', 'biome.json whose local extends target carries a "//" key');
    }

    /**
     * A local `extends` target past MAX_JSONC_BYTES. Unlike an unreadable
     * or unparseable local target (left to Biome's own error, by design),
     * an oversized one is a file Biome loads and applies without
     * complaint: the byte cap is this gate's OWN defensive bound against
     * the quadratic comment-strip regex, not a real limit either tool
     * enforces — an unterminated string literal is enough to cross the
     * byte cap without needing valid JSON, since the cap is checked before
     * parsing.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeLocalExtendsTargetPastTheSizeCap(): void
    {
        $dir = $this->mkJsCase();
        self::writeBiomeWithLocalLooseExtendsTarget($dir);
        file_put_contents($dir . '/biome.loose.json', self::oversizedJsonBody());

        $this->assertBothReject($dir, 'a local `extends` target (./biome.loose.json) is larger than the ' . self::MAX_JSONC_BYTES . ' bytes', 'biome.json whose local extends target is past the size cap');
    }

    /**
     * The oversized-local-target report interpolates the `extends`
     * candidate string verbatim, and the input is pull-request content in
     * the consumer's CI — a candidate string doubling as a real filename
     * the PR author controls can forge a GitHub Actions workflow command
     * mid-line.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenOversizedLocalExtendsTargetCarriesForgedAnnotation(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./##[error]forged\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        file_put_contents($dir . '/##[error]forged', self::oversizedJsonBody());

        $this->assertBothReportIsInert($dir, 'a local `extends` target (./##?[error]forged) is larger than the ' . self::MAX_JSONC_BYTES . ' bytes', 'biome.json whose oversized local extends target carries a forged CI annotation');
    }

    /**
     * Biome.json extending a look-alike package.
     */
    #[Test]
    public function rejectsBiomeExtendingALookalikePackage(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"notmagicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json extending a look-alike package');
    }

    /**
     * Biome.json extending via an explicit node_modules path.
     */
    #[Test]
    public function acceptsBiomeExtendingViaExplicitNodeModulesPath(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./node_modules/@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothAccept($dir, 'biome.json extending via an explicit node_modules path');
    }

    /**
     * Biome.json extending via a pnpm node_modules path.
     */
    #[Test]
    public function acceptsBiomeExtendingViaPnpmNodeModulesPath(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./node_modules/.pnpm/@magicsunday+coding-standard@1.7.0/node_modules/@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothAccept($dir, 'biome.json extending via a pnpm node_modules path');
    }

    /**
     * Biome.json extending a local look-alike copy outside node_modules.
     */
    #[Test]
    public function rejectsBiomeLocalLookalikeOutsideNodeModules(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./fixtures/@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json extending a local look-alike copy outside node_modules');
    }

    /**
     * Biome.json extending through a node_modules under an unrelated path.
     */
    #[Test]
    public function rejectsBiomeNestedLookalikeThroughUnrelatedNodeModules(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./fixtures/node_modules/@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json extending through a node_modules under an unrelated path');
    }

    /**
     * Biome.json extending another repository's node_modules.
     */
    #[Test]
    public function rejectsBiomeExtendingAnotherRepositorysNodeModules(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"../../other-repo/node_modules/@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', "biome.json extending another repository's node_modules");
    }

    /**
     * Biome.json extending without the .json suffix.
     */
    #[Test]
    public function rejectsBiomeExtendingWithoutJsonSuffix(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json extending without the .json suffix');
    }

    /**
     * Biome.json extending the unscoped package name.
     */
    #[Test]
    public function rejectsBiomeExtendingUnscopedPackageName(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json extending the unscoped package name');
    }

    /**
     * Tsconfig.json extending the unscoped package name.
     */
    #[Test]
    public function rejectsTsconfigExtendingUnscopedPackageName(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"magicsunday/coding-standard/tsconfig/base.json\"\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'tsconfig.json extending the unscoped package name');
    }

    /**
     * Tsconfig.json whose specifier carries leading whitespace.
     */
    #[Test]
    public function rejectsTsconfigSpecifierWithLeadingWhitespace(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \" @magicsunday/coding-standard/tsconfig/base.json\"\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'tsconfig.json whose specifier carries leading whitespace');
    }

    /**
     * Biome.json whose specifier carries trailing whitespace.
     */
    #[Test]
    public function rejectsBiomeSpecifierWithTrailingWhitespace(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json \"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json whose specifier carries trailing whitespace');
    }

    /**
     * Biome.json whose specifier ends in a newline.
     */
    #[Test]
    public function rejectsBiomeSpecifierEndingInNewline(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\\n\"]\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json whose specifier ends in a newline');
    }

    /**
     * Tsconfig.json whose specifier ends in a newline.
     */
    #[Test]
    public function rejectsTsconfigSpecifierEndingInNewline(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\\n\"\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'tsconfig.json whose specifier ends in a newline');
    }

    /**
     * Biome accepts only `"//"` or an array for `extends` and answers a bare
     * string with `The 'extends' field must be either '//' or an array of
     * paths` — re-verified 2026-08-31 against the version package.json
     * currently pins. tsc, by contrast, takes a bare string, which is why
     * the two are asserted in opposite directions elsewhere in this file.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeExtendsAsBareStringInsteadOfList(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": \"@magicsunday/coding-standard/biome/base.json\"\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json whose extends is a bare string instead of a list');
    }

    /**
     * Biome.json whose extends is not a specifier at all.
     */
    #[Test]
    public function rejectsBiomeExtendsNotAStringAtAll(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": 5\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'biome.json whose extends is not a specifier at all');
    }

    /**
     * Biome.json with the linter disabled.
     */
    #[Test]
    public function rejectsBiomeLinterDisabled(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"enabled\": false }\n}\n");

        $this->assertBothReject($dir, '`linter.enabled` must not be false', 'biome.json with the linter disabled');
    }

    /**
     * Biome.json with the recommended set disabled.
     */
    #[Test]
    public function rejectsBiomeRecommendedSetDisabled(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"recommended\": false } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.recommended`', 'biome.json with the recommended set disabled');
    }

    /**
     * Biome.json with the formatter disabled.
     */
    #[Test]
    public function rejectsBiomeFormatterDisabled(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"formatter\": { \"enabled\": false }\n}\n");

        $this->assertBothReject($dir, '`formatter.enabled` must not be false', 'biome.json with the formatter disabled');
    }

    /**
     * Verified against the pinned schema that `assist.enabled` exists at
     * the root, in an `overrides` entry and in each per-language block, so
     * it belongs in the same walk as the other two toggles rather than a
     * check of its own. Re-derive:
     *
     *     jq -r '.properties | keys[]' node_modules/@biomejs/biome/configuration_schema.json
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeAssistDisabled(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"assist\": { \"enabled\": false }\n}\n");

        $this->assertBothReject($dir, '`assist.enabled` must not be false', 'biome.json with assist disabled');
    }

    /**
     * Biome.json disabling assist inside an override's language block.
     */
    #[Test]
    public function rejectsBiomeAssistDisabledInOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"src/**\"], \"javascript\": { \"assist\": { \"enabled\": false } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].javascript.assist.enabled', "biome.json disabling assist inside an override's language block");
    }

    /**
     * The disable route that leaves every `enabled` flag true: narrowed to
     * nothing, Biome checks zero files and exits 0, so every other control
     * passes on a config that enforces nothing. Only the shape that can
     * ONLY mean "check nothing" is reported — the canon narrows too, and
     * narrowing is legitimate.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeIncludesNarrowedToNoPositivePattern(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"files\": { \"includes\": [\"!**/vendor/**\", \"!**/node_modules/**\"] }\n}\n");

        $this->assertBothReject($dir, 'carries no positive pattern', 'biome.json narrowed to no positive include');
    }

    /**
     * Biome.json narrowed to a real path set.
     */
    #[Test]
    public function acceptsBiomeIncludesNarrowedToARealPathSet(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"files\": { \"includes\": [\"src/**\", \"!**/vendor/**\"] }\n}\n");

        $this->assertBothAccept($dir, 'biome.json narrowed to a real path set');
    }

    /**
     * `preset: "none"` is the modern spelling of `recommended: false` and
     * silences exactly the same rules. As re-verified on 2026-08-31 against
     * the Biome version package.json currently pins
     * (`jq -r '.devDependencies["@biomejs/biome"]' package.json`), the
     * boolean form still works but is flagged deprecated in the schema.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeRulePresetSetToNone(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"preset\": \"none\" } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.preset`', 'biome.json with the rule preset set to none');
    }

    /**
     * Biome.json keeping the recommended rule preset.
     */
    #[Test]
    public function acceptsBiomeKeepingRecommendedRulePreset(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"preset\": \"recommended\" } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json keeping the recommended rule preset');
    }

    /**
     * Biome carries `recommended`/`preset` on every rule GROUP as well, so
     * switching one group off drops that group's floor while the top-level
     * keys stay untouched. Re-verified 2026-08-31 against the version
     * package.json currently pins: with this, `biome ci` passes a file
     * containing `debugger;` (normally flagged by `suspicious/noDebugger`).
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeGroupPresetSetToNone(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"preset\": \"none\" } } }\n}\n");

        $this->assertBothReject($dir, 'linter.rules.suspicious.preset', "biome.json switching one rule group's preset to none");
    }

    /**
     * Biome.json switching one rule group's recommended off.
     */
    #[Test]
    public function rejectsBiomeGroupRecommendedOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"correctness\": { \"recommended\": false } } }\n}\n");

        $this->assertBothReject($dir, 'linter.rules.correctness.recommended', "biome.json switching one rule group's recommended off");
    }

    /**
     * Biome.json disabling the linter through an overrides entry.
     */
    #[Test]
    public function rejectsBiomeOverrideLinterOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"**\"], \"linter\": { \"enabled\": false } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].linter.enabled', 'biome.json disabling the linter through an overrides entry');
    }

    /**
     * Biome.json dropping the rule floor through an overrides entry.
     */
    #[Test]
    public function rejectsBiomeOverridePresetNone(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"src/**\"], \"linter\": { \"rules\": { \"preset\": \"none\" } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].linter.rules.preset', 'biome.json dropping the rule floor through an overrides entry');
    }

    /**
     * Biome carries linter/formatter a THIRD time, per language — and
     * there it silences the shared standard for every file of that
     * language while the top-level keys still read as enabled. Re-verified
     * 2026-08-31 against the version package.json currently pins: with
     * this config a 2-space indent passes.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeLanguageFormatterOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"javascript\": { \"formatter\": { \"enabled\": false } }\n}\n");

        $this->assertBothReject($dir, 'javascript.formatter.enabled', 'biome.json disabling the formatter for a whole language');
    }

    /**
     * The cross product: a per-language block INSIDE an overrides entry —
     * the idiomatic place to write one, since an override is how a
     * language setting gets scoped to a path set.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeOverrideLanguageLinterOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"**\"], \"javascript\": { \"linter\": { \"enabled\": false } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].javascript.linter.enabled', "biome.json disabling a language's linter inside an overrides entry");
    }

    /**
     * Biome.json disabling a non-JS language's formatter in the SECOND overrides entry.
     */
    #[Test]
    public function rejectsBiomeOverrideLanguageSecondEntry(): void
    {
        // A non-zero index and a non-JS language, so neither the index nor
        // the language list is satisfied by the first entry alone.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"tests/**\"], \"javascript\": { \"formatter\": { \"quoteStyle\": \"single\" } } },\n        { \"includes\": [\"**\"], \"json\": { \"formatter\": { \"enabled\": false } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[1].json.formatter.enabled', "biome.json disabling a non-JS language's formatter in the SECOND overrides entry");
    }

    // -------------------------------------------------------------------
    // Per-language linter.enabled walk — derived from the gate's own
    // per-language foreach literal (languagesFromGate()).
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function languageProvider(): array
    {
        return self::singleArgProviderRows(self::languagesFromGate());
    }

    /**
     * Re-verified 2026-08-31 against the version package.json currently
     * pins: with `javascript.linter.enabled: false` a `==` comparison
     * passes while the top-level `linter.enabled` still reads true — which
     * is why a check that only walked the document would report this
     * config clean.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('languageProvider')]
    public function rejectsBiomeLanguageLinterOff(string $language): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"{$language}\": { \"linter\": { \"enabled\": false } }\n}\n");

        $this->assertBothReject($dir, "{$language}.linter.enabled", "biome.json disabling the linter for {$language}");
    }

    /**
     * Both directions of the bijection between PROVEN_LANGUAGES and the
     * gate's own per-language walk — a language the gate stops walking, and
     * one it starts walking that this suite never drove a case for.
     */
    #[Test]
    public function languageWalkBijectionHoldsBothDirections(): void
    {
        $gateLanguages = self::languagesFromGate();

        self::assertSame([], array_diff(self::PROVEN_LANGUAGES, $gateLanguages), 'the gate no longer walks a language this suite proves — the row was dropped rather than renamed');
        self::assertSame([], array_diff($gateLanguages, self::PROVEN_LANGUAGES), 'the gate now walks a language this suite does not name — add it rather than leaving the row unexercised');
    }

    /**
     * Biome.json setting a per-language style option inside an overrides entry.
     */
    #[Test]
    public function acceptsBiomeOverrideLanguageLegitimateStyleOption(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"tests/**\"], \"javascript\": { \"formatter\": { \"quoteStyle\": \"single\" } } }\n    ]\n}\n");

        $this->assertBothAccept($dir, 'biome.json setting a per-language style option inside an overrides entry');
    }

    /**
     * Biome.json setting a per-language style option.
     */
    #[Test]
    public function acceptsBiomeLanguageLegitimateStyleOption(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"javascript\": { \"formatter\": { \"quoteStyle\": \"single\" } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json setting a per-language style option');
    }

    /**
     * Biome.json narrowing a single rule for one path through overrides.
     */
    #[Test]
    public function acceptsBiomeOverrideLegitimateSingleRuleNarrowing(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        {\n            \"includes\": [\"tests/**\"],\n            \"linter\": { \"rules\": { \"suspicious\": { \"noExplicitAny\": \"off\" } } }\n        }\n    ]\n}\n");

        $this->assertBothAccept($dir, 'biome.json narrowing a single rule for one path through overrides');
    }

    /**
     * biome.json that is not valid JSON(C).
     */
    #[Test]
    public function rejectsBiomeMalformedJson(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"\n");

        $this->assertBothReject($dir, 'biome.json: not valid JSON(C)', 'biome.json that is not valid JSON(C)');
    }

    /**
     * biome.jsonc is Biome's own alternative filename; the gate must find
     * it there too — asserted as a REJECT, because that is the only shape
     * that proves discovery.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeJsoncDiscoveredParsedWithCommentsAndNamedInReport(): void
    {
        $dir = $this->mkJsCase();
        unlink($dir . '/biome.json');
        file_put_contents($dir . '/biome.jsonc', "{\n    // A jsonc file exists precisely so a consumer can comment it.\n    \"//\": \"and this note key makes it unloadable\",\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothReject($dir, 'biome.jsonc: ', 'biome.jsonc is discovered, parsed with comments, and named in the report');
    }

    /**
     * A clean biome.jsonc is accepted.
     */
    #[Test]
    public function acceptsCleanBiomeJsonc(): void
    {
        $dir = $this->mkJsCase();
        unlink($dir . '/biome.json');
        file_put_contents($dir . '/biome.jsonc', "{\n    // A jsonc file exists precisely so a consumer can comment it.\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"]\n}\n");

        $this->assertBothAccept($dir, 'a clean biome.jsonc is accepted');
    }

    /**
     * Biome.jsonc without the shared extends.
     */
    #[Test]
    public function rejectsBiomeJsoncWithoutSharedExtends(): void
    {
        $dir = $this->mkJsCase();
        unlink($dir . '/biome.json');
        file_put_contents($dir . '/biome.jsonc', "{\n    // no shared link\n    \"linter\": { \"enabled\": true }\n}\n");

        $this->assertBothReject($dir, 'biome.jsonc: must `extends`', 'biome.jsonc without the shared extends');
    }

    /**
     * Biome.jsonc with the linter disabled.
     */
    #[Test]
    public function rejectsBiomeJsoncLinterDisabled(): void
    {
        $dir = $this->mkJsCase();
        unlink($dir . '/biome.json');
        file_put_contents($dir . '/biome.jsonc', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    // switched off\n    \"linter\": { \"enabled\": false }\n}\n");

        $this->assertBothReject($dir, 'biome.jsonc: `linter.enabled`', 'biome.jsonc with the linter disabled');
    }

    /**
     * Tsconfig.json without the shared extends.
     */
    #[Test]
    public function rejectsTsconfigWithoutSharedExtends(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"compilerOptions\": { \"strict\": true }\n}\n");

        $this->assertBothReject($dir, 'must `extends`', 'tsconfig.json without the shared extends');
    }

    /**
     * Tsconfig.json overriding strict to false.
     */
    #[Test]
    public function rejectsTsconfigOverridingStrictToFalse(): void
    {
        $dir = $this->mkJsCase();
        self::writeTsconfigWithStrictFalse($dir);

        $this->assertBothReject($dir, '`compilerOptions.strict`', 'tsconfig.json overriding strict to false');
    }

    /**
     * The subtler override: `strict` stays on, but the flag the shared
     * base adds ON TOP of strict is switched off — this is the realistic
     * drift, and a check that only looked at `strict` would miss it.
     *
     * @return void
     */
    #[Test]
    public function rejectsTsconfigDisablingNoUncheckedIndexedAccess(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": true, \"noUncheckedIndexedAccess\": false }\n}\n");

        $this->assertBothReject($dir, 'noUncheckedIndexedAccess', 'tsconfig.json disabling noUncheckedIndexedAccess');
    }

    /**
     * Tsconfig.json with the shared base in an extends array.
     */
    #[Test]
    public function acceptsTsconfigWithSharedBaseInExtendsArray(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"./tsconfig.paths.json\", \"@magicsunday/coding-standard/tsconfig/base.json\"],\n    \"compilerOptions\": { \"noEmit\": true }\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with the shared base in an extends array');
    }

    /**
     * Tsconfig.json turning skipLibCheck off (stricter, not drift).
     */
    #[Test]
    public function acceptsTsconfigTurningSkipLibCheckOff(): void
    {
        // Ergonomics flags are deliberately NOT pinned: turning skipLibCheck
        // off is stricter, not looser, and must not be reported as drift.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"skipLibCheck\": false }\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json turning skipLibCheck off (stricter, not drift)');
    }

    /**
     * Tsconfig.json with trailing commas.
     */
    #[Test]
    public function acceptsTsconfigWithTrailingCommas(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": {\n        \"noEmit\": true,\n    },\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with trailing commas');
    }

    /**
     * Tsconfig.json with a // inside a string value.
     */
    #[Test]
    public function acceptsTsconfigWithCommentMarkerInsideStringValue(): void
    {
        // A "//" sequence INSIDE a string is not a comment — stripping it
        // would corrupt the document and turn a valid consumer config into
        // a false rejection.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": {\n        \"paths\": { \"@app/*\": [\"https://example.com/not-a-comment/*\"] }\n    }\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with a // inside a string value');
    }

    /**
     * Tsconfig.json extending without the .json suffix.
     */
    #[Test]
    public function acceptsTsconfigExtendingWithoutJsonSuffix(): void
    {
        // tsc appends `.json` itself, so this resolves to the very same
        // file — re-verified 2026-08-31 against the version package.json
        // currently pins (`jq -r '.devDependencies.typescript' package.json`).
        $dir = $this->mkJsCase();
        self::writeTsconfigExtendingWithoutJsonSuffix($dir);

        $this->assertBothAccept($dir, 'tsconfig.json extending without the .json suffix');
    }

    // -------------------------------------------------------------------
    // The remaining branches
    // -------------------------------------------------------------------

    /**
     * A violation in the SECOND overrides entry is reported with its index.
     */
    #[Test]
    public function rejectsBiomeOverridesSecondEntryReportedWithItsIndex(): void
    {
        // Every overrides case so far put the violation at index 0, so a
        // walk that only inspected the first entry would pass them all.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"tests/**\"], \"linter\": { \"rules\": { \"suspicious\": { \"noExplicitAny\": \"off\" } } } },\n        { \"includes\": [\"**\"], \"linter\": { \"enabled\": false } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[1].linter.enabled', 'a violation in the SECOND overrides entry is reported with its index');
    }

    /**
     * A non-object overrides entry does not hide the next one.
     */
    #[Test]
    public function rejectsBiomeOverrideEntryNotAnObjectDoesNotHideTheNext(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\"not-an-object\", { \"includes\": [\"**\"], \"linter\": { \"enabled\": false } }]\n}\n");

        $this->assertBothReject($dir, 'overrides[1].linter.enabled', 'a non-object overrides entry does not hide the next one');
    }

    /**
     * A mis-typed per-language block must not stop the walk reporting what
     * else it finds. What this pins is that the walk survives the shape at
     * all and still names the real drift — not that a type guard is what
     * causes it, since a `?? null` read on the string subscript already
     * absorbs that.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomePerLanguageBlockAsStringNotObject(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"javascript\": \"off\",\n    \"linter\": { \"enabled\": false }\n}\n");

        $this->assertBothReject($dir, '`linter.enabled` must not be false', 'biome.json whose per-language block is a string, not an object');
    }

    /**
     * Biome.json is read in preference to a biome.jsonc beside it.
     */
    #[Test]
    public function acceptsBiomeJsonReadInPreferenceToBiomeJsoncBeside(): void
    {
        // Every other .jsonc case removes .json first, so the discovery
        // ORDER was never driven: the gate reads biome.json and stops.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.jsonc', "{\n    // this file must not be the one the gate reads\n    \"linter\": { \"enabled\": false }\n}\n");

        $this->assertBothAccept($dir, 'biome.json is read in preference to a biome.jsonc beside it');
    }

    /**
     * A scalar linter.rules does not hide the enabled check.
     */
    #[Test]
    public function rejectsBiomeScalarLinterRulesDoesNotHideEnabledCheck(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"enabled\": false, \"rules\": \"off\" }\n}\n");

        $this->assertBothReject($dir, 'linter.enabled', 'a scalar linter.rules does not hide the enabled check');
    }

    /**
     * A scalar rule group does not hide the next group.
     */
    #[Test]
    public function rejectsBiomeScalarRuleGroupDoesNotHideNextGroup(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": \"info\", \"correctness\": { \"preset\": \"none\" } } }\n}\n");

        $this->assertBothReject($dir, 'linter.rules.correctness.preset', 'a scalar rule group does not hide the next group');
    }
}
