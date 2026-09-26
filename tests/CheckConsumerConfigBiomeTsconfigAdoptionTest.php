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
use function file_get_contents;
use function file_put_contents;

/**
 * Fixture-driven cases for the adoption gate in
 * bin/consumer-checks/check-biome-tsconfig.php ($npmDependencyDeclared) and
 * its bin/check-js-config.mjs twin: the extends contract applies only once a
 * consumer declares the npm dependency (in any of the four dependency
 * sections), a repository with no JS/TS config is never probed at all, and
 * the checks that cannot wait for adoption — an unreadable config or
 * package.json, one past the size cap, the `"//"` key, an unparseable
 * package.json — fire regardless. Every case runs BOTH gates against the
 * same fixture through the assertBoth*() helpers; see
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigBiomeTsconfigAdoptionTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // Fixture builders only this contract's cases use — the shared ones
    // (mkCase()/mkJsCase()/...) live in AbstractConsumerConfigTestCase.
    // -------------------------------------------------------------------

    /**
     * Writes a package.json declaring the npm devDependency on this package
     * without a `name` key — a second, distinct fixture from
     * writeAdoptingPackageJson(), which also sets `name`.
     *
     * @param string $dir The directory to write package.json into.
     *
     * @return void
     */
    private static function writePackageJsonWithoutNameKey(string $dir): void
    {
        file_put_contents(
            $dir . '/package.json',
            "{\n    \"devDependencies\": { \"@magicsunday/coding-standard\": \"github:magicsunday/coding-standard#1.7.0\" }\n}\n",
        );
    }

    /**
     * Writes a biome.json truncated mid-object — valid start, no closing
     * braces — shared by the adoption-gated and adoption-exempt malformed
     * biome.json cases below.
     *
     * @param string $dir The directory to write biome.json into.
     *
     * @return void
     */
    private static function writeMalformedBiomeJson(string $dir): void
    {
        file_put_contents(
            $dir . '/biome.json',
            "{\n    \"linter\": { \"enabled\": true\n",
        );
    }

    /**
     * Writes a package.json truncated mid-object — valid start, no closing
     * braces — used by the "unparseable package.json" cases.
     *
     * @param string $dir The directory to write package.json into.
     *
     * @return void
     */
    private static function writeTruncatedPackageJson(string $dir): void
    {
        file_put_contents(
            $dir . '/package.json',
            "{\n    \"devDependencies\": {\n",
        );
    }

    /**
     * mkCase() plus a package.json that declares no dependency on this
     * package at all — see "the adoption gate" section further down for why
     * that state must not be reported as drift.
     *
     * @return string This test's fixture directory.
     */
    private function mkUnadoptedCase(): string
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', <<<'JSON'
            {
                "name": "fixture",
                "devDependencies": { "typescript": "^7.0.2" }
            }

            JSON);

        return $dir;
    }

    // -------------------------------------------------------------------
    // The adoption gate — a consumer may ship a standalone biome.json while
    // still pulling this package over Composer rather than npm, so the
    // extends contract keys on the npm dependency being declared rather
    // than on the config file's mere presence.
    // -------------------------------------------------------------------

    /**
     * Standalone biome.json in a repo that has not adopted the npm package.
     */
    #[Test]
    public function acceptsStandaloneBiomeInRepoWithoutAdoption(): void
    {
        $dir = $this->mkUnadoptedCase();
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothAccept($dir, 'standalone biome.json in a repo that has not adopted the npm package');
    }

    /**
     * Standalone tsconfig.json in a repo that has not adopted the npm package.
     */
    #[Test]
    public function acceptsStandaloneTsconfigInRepoWithoutAdoption(): void
    {
        $dir = $this->mkUnadoptedCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"compilerOptions\": { \"strict\": false }\n}\n");

        $this->assertBothAccept($dir, 'standalone tsconfig.json in a repo that has not adopted the npm package');
    }

    /**
     * Standalone biome.json with no package.json at all.
     */
    #[Test]
    public function acceptsStandaloneBiomeWithNoPackageJsonAtAll(): void
    {
        $dir = $this->mkCase();
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothAccept($dir, 'standalone biome.json with no package.json at all');
    }

    /**
     * A parse failure, unlike the `"//"` key, IS gated on adoption — this
     * reader is not Biome's own, so it can reject a file the real tool
     * accepts, and reporting that to a repository which never claimed the
     * link is the failure the adoption gate exists to prevent.
     *
     * @return void
     */
    #[Test]
    public function acceptsMalformedBiomeInRepoWithoutAdoption(): void
    {
        $dir = $this->mkUnadoptedCase();
        self::writeMalformedBiomeJson($dir);

        $this->assertBothAccept($dir, 'malformed biome.json in a repo that has not adopted the npm package');
    }

    /**
     * Malformed biome.json once the npm package is declared — the adopted
     * counterpart of acceptsMalformedBiomeInRepoWithoutAdoption() above.
     */
    #[Test]
    public function rejectsMalformedBiomeOnceNpmPackageIsDeclared(): void
    {
        $dir = $this->mkJsCase();
        self::writeMalformedBiomeJson($dir);

        $this->assertBothReject($dir, 'biome.json: not valid JSON(C)', 'malformed biome.json once the npm package is declared');
    }

    /**
     * Biome.json saved with a UTF-8 BOM.
     */
    #[Test]
    public function acceptsBiomeSavedWithUtf8Bom(): void
    {
        // Both tools read a BOM-prefixed config and honour it; json_decode
        // does not. A reader stricter than the tools reports a defect in a
        // file that loads fine.
        $dir     = $this->mkJsCase();
        $content = (string) file_get_contents(self::canon() . '/biome.json');
        file_put_contents($dir . '/biome.json', "\xEF\xBB\xBF" . $content);

        $this->assertBothAccept($dir, 'biome.json saved with a UTF-8 BOM');
    }

    /**
     * Tsconfig.json saved with a UTF-8 BOM.
     */
    #[Test]
    public function acceptsTsconfigSavedWithUtf8Bom(): void
    {
        $dir     = $this->mkJsCase();
        $content = (string) file_get_contents(self::canon() . '/tsconfig.json');
        file_put_contents($dir . '/tsconfig.json', "\xEF\xBB\xBF" . $content);

        $this->assertBothAccept($dir, 'tsconfig.json saved with a UTF-8 BOM');
    }

    /**
     * The reject twin: a SECOND BOM, left over once the strip already
     * consumed the first. json_decode() sees the leftover BOM as
     * unparseable syntax and rejects it, while TextDecoder's default
     * would strip the second BOM too, leaving JSON.parse() nothing left to
     * reject — node-only, since PHP's own strip only ever runs once and
     * already rejects this.
     *
     * @return void
     */
    #[Test]
    public function rejectsTsconfigWithSecondLeftoverBom(): void
    {
        $dir     = $this->mkJsCase();
        $content = (string) file_get_contents(self::canon() . '/tsconfig.json');
        file_put_contents($dir . '/tsconfig.json', "\xEF\xBB\xBF\xEF\xBB\xBF" . $content);

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with a second, leftover BOM once the first is stripped');
    }

    /**
     * A package.json with a second, leftover BOM once the first is stripped is reported.
     */
    #[Test]
    public function rejectsPackageJsonWithSecondLeftoverBom(): void
    {
        // package.json shares its decode pipeline with biome.json/tsconfig.json,
        // so the same leftover-BOM parity gap applies to the npm probe's own read.
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "\xEF\xBB\xBF\xEF\xBB\xBF{\n    \"name\": \"consumer\",\n    \"devDependencies\": { \"@magicsunday/coding-standard\": \"^3.0.0\" }\n}\n");
        self::writeTsconfigExtendingWithoutJsonSuffix($dir);

        $this->assertBothReject($dir, 'package.json: is not valid JSON', 'a package.json with a second, leftover BOM once the first is stripped is reported');
    }

    /**
     * The probe that decides whether any of this runs must not fail open:
     * an unparseable manifest would otherwise switch the entire JS/TS
     * contract off while the gate still printed OK.
     */
    #[Test]
    public function rejectsUnparseablePackageJsonNotTreatedAsNonAdoption(): void
    {
        $dir = $this->mkCase();
        self::writeTruncatedPackageJson($dir);
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothReject($dir, 'package.json: is not valid JSON', 'an unparseable package.json is reported, not treated as non-adoption');
    }

    /**
     * A BOM-prefixed package.json is still read for the dependency.
     */
    #[Test]
    public function rejectsBomPrefixedPackageJsonStillReadForDependency(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "\xEF\xBB\xBF{\n    \"devDependencies\": { \"@magicsunday/coding-standard\": \"github:magicsunday/coding-standard#1.7.0\" }\n}\n");
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothReject($dir, 'must `extends`', 'a BOM-prefixed package.json is still read for the dependency');
    }

    /**
     * The oversize verdict is UNCONDITIONAL — a file this gate cannot read
     * in full is a defect whoever wrote it, so it is not gated on
     * adoption the way a parse failure is.
     *
     * @return void
     */
    #[Test]
    public function rejectsOversizedBiomeInRepoThatNeverAdopted(): void
    {
        $dir = $this->mkUnadoptedCase();
        file_put_contents($dir . '/biome.json', self::oversizedJsonBody());

        $this->assertBothReject($dir, 'larger than the ' . self::MAX_JSONC_BYTES . ' bytes this gate checks', 'an oversized biome.json is reported in a repository that never adopted the package');
    }

    /**
     * "//" key is reported even without adoption.
     */
    #[Test]
    public function rejectsNoteKeyEvenWithoutAdoption(): void
    {
        $dir = $this->mkUnadoptedCase();
        file_put_contents($dir . '/biome.json', "{\n    \"//\": \"shared config for this repo\",\n    \"linter\": { \"enabled\": true }\n}\n");

        $this->assertBothReject($dir, '`"//"` key', '"//" key is reported even without adoption');
    }

    /**
     * Biome.json without extends once the npm package is declared.
     */
    #[Test]
    public function rejectsBiomeWithoutExtendsOnceNpmPackageIsDeclared(): void
    {
        $dir = $this->mkJsCase();
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothReject($dir, 'must `extends`', 'biome.json without extends once the npm package is declared');
    }

    // -------------------------------------------------------------------
    // The remaining branches
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function dependencySectionProvider(): array
    {
        return self::singleArgProviderRows(['dependencies', 'optionalDependencies', 'peerDependencies']);
    }

    /**
     * The adoption probe reads four dependency sections; only
     * devDependencies (used throughout the rest of this suite) was
     * exercised elsewhere. peerDependencies included: as observed on
     * 2026-08-31 (npm is not a dependency of this repository, so there is
     * no local copy to re-check this against), npm >=7 auto-installs an
     * unmet peer with no other declaration needed, so a consumer (or an
     * adversarial PR) moving the entry there alone still has the package
     * on disk.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('dependencySectionProvider')]
    public function countsDependencyDeclaredUnderAlternateSectionAsAdoption(string $section): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "{\n    \"{$section}\": { \"@magicsunday/coding-standard\": \"github:magicsunday/coding-standard#1.7.0\" }\n}\n");
        self::writeMinimalBiomeJson($dir, true);

        $this->assertBothReject($dir, 'must `extends`', "the npm dependency declared under {$section} counts as adoption");
    }

    /**
     * PHP checks `isset($json[$section]['@magicsunday/coding-standard'])`,
     * which is false when the key is present but its value is null — a
     * lookup keyed purely on Object.hasOwn() would instead read this as
     * adopted.
     *
     * @return void
     */
    #[Test]
    public function acceptsExplicitNullDependencyValueDoesNotCountAsAdoption(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "{\n    \"devDependencies\": { \"@magicsunday/coding-standard\": null }\n}\n");
        self::writeMinimalBiomeJson($dir, false);

        $this->assertBothAccept($dir, 'an explicit null dependency value does not count as adoption');
    }

    /**
     * A repository with no JS config at all is never probed, so a broken
     * package.json there is not this gate's business. Deliberately outside
     * the root-skip guard below: it needs no permissions trick, and it is
     * the only case covering this arm.
     */
    #[Test]
    public function acceptsPhpOnlyRepoNotProbedForJsTsContractAtAll(): void
    {
        $dir = $this->mkCase();
        self::writeTruncatedPackageJson($dir);

        $this->assertBothAccept($dir, 'a PHP-only repo is not probed for the JS/TS contract at all');
    }

    /**
     * A TypeScript-only consumer is still held to the tsconfig contract.
     */
    #[Test]
    public function rejectsTypescriptOnlyConsumerStillHeldToTsconfigContract(): void
    {
        $dir = $this->mkCase();
        self::writePackageJsonWithoutNameKey($dir);
        self::writeTsconfigWithStrictFalse($dir);

        $this->assertBothReject($dir, '`compilerOptions.strict`', 'a TypeScript-only consumer is still held to the tsconfig contract');
    }

    /**
     * An unreadable biome.json reports as unreadable, not as malformed.
     */
    #[Test]
    public function rejectsUnreadableBiomeReportsAsUnreadableNotAsMalformed(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkUnadoptedCase();
        copy(self::canon() . '/biome.json', $dir . '/biome.json');
        chmod($dir . '/biome.json', 0o000);

        try {
            $this->assertBothReject($dir, 'biome.json: exists but cannot be read', 'an unreadable biome.json reports as unreadable, not as malformed');
        } finally {
            chmod($dir . '/biome.json', 0o644);
        }
    }

    /**
     * An unreadable tsconfig.json reports as unreadable, not as malformed.
     */
    #[Test]
    public function rejectsUnreadableTsconfigReportsAsUnreadableNotAsMalformed(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkJsCase();
        chmod($dir . '/tsconfig.json', 0o000);

        try {
            $this->assertBothReject($dir, 'tsconfig.json: exists but cannot be read', 'an unreadable tsconfig.json reports as unreadable, not as malformed');
        } finally {
            chmod($dir . '/tsconfig.json', 0o644);
        }
    }

    /**
     * The same file in a NON-adopting repository. An unopenable file is a
     * defect on its own terms — no reader tolerance is in play — so it
     * must not wait for adoption either.
     *
     * @return void
     */
    #[Test]
    public function rejectsUnreadableTsconfigReportedEvenWithoutAdoption(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkUnadoptedCase();
        copy(self::canon() . '/tsconfig.json', $dir . '/tsconfig.json');
        chmod($dir . '/tsconfig.json', 0o000);

        try {
            $this->assertBothReject($dir, 'tsconfig.json: exists but cannot be read', 'an unreadable tsconfig.json is reported even without adoption');
        } finally {
            chmod($dir . '/tsconfig.json', 0o644);
        }
    }

    /**
     * An unreadable package.json does not switch the JS/TS contract off.
     */
    #[Test]
    public function rejectsUnreadablePackageJsonDoesNotSwitchOffJsTsContract(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkCase();
        self::writePackageJsonWithoutNameKey($dir);
        self::writeMinimalBiomeJson($dir, true);
        chmod($dir . '/package.json', 0o000);

        try {
            $this->assertBothReject($dir, 'package.json: exists but cannot be read', 'an unreadable package.json does not switch the JS/TS contract off');
        } finally {
            chmod($dir . '/package.json', 0o644);
        }
    }

    /**
     * PHP-only repo without biome.json or tsconfig.json.
     */
    #[Test]
    public function acceptsPhpOnlyRepoWithoutBiomeOrTsconfig(): void
    {
        $this->assertBothAccept($this->mkCase(), 'PHP-only repo without biome.json or tsconfig.json');
    }
}
