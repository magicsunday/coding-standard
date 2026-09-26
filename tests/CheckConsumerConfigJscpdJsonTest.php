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
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function substr_count;

use const PREG_SET_ORDER;

/**
 * Fixture-driven cases for bin/consumer-checks/check-jscpd-json.php — the
 * optional .jscpd.json contract: the zero-tolerance thresholds and the
 * extension-spelling deny list, proven in both directions against the
 * gate's own $extensionSpellings. PHP gate only; bin/check-js-config.mjs has
 * no .jscpd.json counterpart. See AbstractConsumerConfigTestCase for the
 * shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigJscpdJsonTest extends AbstractConsumerConfigTestCase
{
    /**
     * The jscpd extension-spelling deny list this suite proves, as
     * `spelling:canonical` pairs — mirrors the gate's own $extensionSpellings.
     *
     * @var list<non-empty-string>
     */
    private const array PROVEN_SPELLINGS = [
        'js:javascript',
        'mjs:javascript',
        'cjs:javascript',
        'ts:typescript',
        'mts:typescript',
        'cts:typescript',
    ];

    // -------------------------------------------------------------------
    // Fixture builders only this contract's cases use — the shared ones
    // (mkCase()/mkJsCase()/...) live in AbstractConsumerConfigTestCase.
    // -------------------------------------------------------------------

    /**
     * mkCase() plus a clean .jscpd.json, so each jscpd case below can
     * corrupt exactly one threshold and be rejected for that reason alone.
     *
     * @return string This test's fixture directory.
     */
    private function jscpdFixture(): string
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.jscpd.json', <<<'JSON'
            {
                "threshold": 0,
                "minTokens": 100,
                "minLines": 5,
                "exitCode": 1,
                "reporters": ["console-full"]
            }

            JSON);

        return $dir;
    }

    // -------------------------------------------------------------------
    // Gate-source extraction — this contract's lockstep table, read at
    // runtime from the check-*.php split that declares it through the
    // shared gateSource()/extractQuotedList() primitives (see
    // AbstractConsumerConfigTestCase's class docblock).
    // -------------------------------------------------------------------

    /**
     * Extracts the gate's `$extensionSpellings = ['js' => 'javascript', ...]`
     * table, cross-checked against the block's `=>` occurrence count — two
     * entries written on one physical line read as one under a line-based
     * count, which is the silent direction this guards against.
     *
     * @return list<non-empty-string> `spelling:canonical` pairs.
     *
     * @throws RuntimeException If the block cannot be found, the counts disagree, or nothing parsed.
     */
    private static function extensionSpellingsFromGate(): array
    {
        $relativePath = 'bin/consumer-checks/check-jscpd-json.php';
        $source       = self::gateSource($relativePath);

        if (preg_match('/\$extensionSpellings = \[(.*?)\];/s', $source, $matches) !== 1) {
            throw new RuntimeException("could not find \$extensionSpellings in {$relativePath}");
        }

        $block  = $matches[1];
        $arrows = substr_count($block, '=>');

        preg_match_all("/'([a-z0-9_-]+)' *=> *'([a-z0-9_-]+)'/", $block, $pairs, PREG_SET_ORDER);

        if (count($pairs) !== $arrows) {
            throw new RuntimeException(
                'the $extensionSpellings block carries ' . $arrows . ' entries but this test parsed '
                . count($pairs) . ' — widen the extractor rather than leaving a row unexercised',
            );
        }

        if ($pairs === []) {
            throw new RuntimeException('no entries parsed out of $extensionSpellings — the extraction broke');
        }

        $result = [];

        foreach ($pairs as $pair) {
            $result[] = "{$pair[1]}:{$pair[2]}";
        }

        return $result;
    }

    // -------------------------------------------------------------------
    // .jscpd.json
    // -------------------------------------------------------------------

    /**
     * .jscpd.json on the removed v4 reporter name.
     */
    #[Test]
    public function rejectsJscpdRemovedV4ReporterName(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.jscpd.json', "{\n    \"threshold\": 0,\n    \"minTokens\": 100,\n    \"minLines\": 5,\n    \"exitCode\": 1,\n    \"reporters\": [\"consoleFull\"]\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'console-full', '.jscpd.json on the removed v4 reporter name');
    }

    /**
     * .jscpd.json with minLines raised to disable detection.
     */
    #[Test]
    public function rejectsJscpdMinLinesRaisedToDisableDetection(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.jscpd.json', "{\n    \"threshold\": 0,\n    \"minTokens\": 100,\n    \"minLines\": 9999,\n    \"exitCode\": 1,\n    \"reporters\": [\"console-full\"]\n}\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'minLines', '.jscpd.json with minLines raised to disable detection');
    }

    /**
     * Three independently-checked jscpd thresholds, one mutation each:
     * threshold raised, exitCode flipped, minTokens raised to disable
     * detection.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function jscpdThresholdMutationProvider(): array
    {
        return [
            'threshold raised above zero'           => ['"threshold": 0', '"threshold": 5', 'threshold'],
            'exitCode not 1'                        => ['"exitCode": 1', '"exitCode": 0', 'exitCode'],
            'minTokens raised to disable detection' => ['"minTokens": 100', '"minTokens": 9999', 'minTokens'],
        ];
    }

    /**
     * Each of the three jscpd thresholds mutated on its own, so a defect in
     * any one of them is reported without depending on the other two.
     */
    #[Test]
    #[DataProvider('jscpdThresholdMutationProvider')]
    public function rejectsJscpdThresholdMutation(string $search, string $replace, string $expectedSubstring): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', str_replace($search, $replace, $json));

        $this->assertGateRejects(self::phpGate(), $dir, $expectedSubstring, ".jscpd.json {$replace}");
    }

    /**
     * .jscpd.json using jscpd's own format names.
     */
    #[Test]
    public function acceptsJscpdOwnFormatNames(): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents(
            $dir . '/.jscpd.json',
            str_replace('"reporters": ["console-full"]', "\"reporters\": [\"console-full\"],\n    \"format\": [\"php\", \"javascript\", \"typescript\", \"jsx\", \"tsx\"]", $json),
        );

        $this->assertGateAccepts(self::phpGate(), $dir, ".jscpd.json using jscpd's own format names");
    }

    /**
     * .jscpd.json declaring no format at all.
     */
    #[Test]
    public function acceptsJscpdDeclaringNoFormatAtAll(): void
    {
        $this->assertGateAccepts(self::phpGate(), $this->jscpdFixture(), '.jscpd.json declaring no format at all');
    }

    /**
     * .jscpd.json saved with a UTF-8 BOM is reported as such, not as malformed.
     */
    #[Test]
    public function rejectsJscpdBom(): void
    {
        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/jscpd.json');
        file_put_contents($dir . '/.jscpd.json', "\xEF\xBB\xBF" . $template);

        $this->assertGateRejects(self::phpGate(), $dir, '.jscpd.json: starts with a UTF-8 BOM', '.jscpd.json saved with a UTF-8 BOM is reported as such, not as malformed');
    }

    /**
     * .jscpd.json not valid JSON.
     */
    #[Test]
    public function rejectsJscpdNotValidJson(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.jscpd.json', '{ not json');

        $this->assertGateRejects(self::phpGate(), $dir, 'not valid JSON', '.jscpd.json not valid JSON');
    }

    /**
     * .jscpd.json with a scalar format instead of a list.
     */
    #[Test]
    public function rejectsJscpdFormatScalarInsteadOfList(): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', str_replace('"reporters": ["console-full"]', "\"reporters\": [\"console-full\"],\n    \"format\": \"ts\"", $json));

        $this->assertGateRejects(self::phpGate(), $dir, 'Use "typescript"', '.jscpd.json with a scalar format instead of a list');
    }

    /**
     * .jscpd.json with a non-string format entry beside a bad one.
     */
    #[Test]
    public function rejectsJscpdFormatNonStringEntryBesideABadOne(): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', str_replace('"reporters": ["console-full"]', "\"reporters\": [\"console-full\"],\n    \"format\": [5, \"ts\"]", $json));

        $this->assertGateRejects(self::phpGate(), $dir, 'Use "typescript"', '.jscpd.json with a non-string format entry beside a bad one');
    }

    /**
     * .jscpd.json with a scalar reporters instead of a list.
     */
    #[Test]
    public function rejectsJscpdReportersScalarInsteadOfList(): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', str_replace('"reporters": ["console-full"]', '"reporters": "console-full"', $json));

        $this->assertGateRejects(self::phpGate(), $dir, '`reporters` must contain', '.jscpd.json with a scalar reporters instead of a list');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function jscpdOmittableFieldProvider(): array
    {
        return self::singleArgProviderRows(['minTokens', 'minLines']);
    }

    /**
     * The "must be present" half of both thresholds: every other fixture
     * always carries the key, so only the ">" comparison was exercised
     * elsewhere.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('jscpdOmittableFieldProvider')]
    public function rejectsJscpdOmittingFieldEntirely(string $field): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', (string) preg_replace("/^.*\"{$field}\".*\$\n/m", '', $json));

        $this->assertGateRejects(self::phpGate(), $dir, $field, ".jscpd.json omitting {$field} entirely");
    }

    /**
     * The plain-text bound, AT the cap rather than past it. Every case in
     * the oversize loop (CheckConsumerConfigTest) writes one byte PAST the bound, where `>` and
     * `>=` agree, so a mutation to `>=` survives all of them — this is the
     * one that does not. .jscpd.json is picked because an unknown top-level
     * key costs it nothing (the shipped template documents that convention
     * with its own "//" key), so the padding introduces no second
     * violation, and the fixture's real defect (`threshold: 1`) is what the
     * assertion requires.
     *
     * @return void
     */
    #[Test]
    public function rejectsJscpdExactlyAtTheSizeCapIsStillReadAndChecked(): void
    {
        $dir  = $this->mkCase();
        $body = (string) json_encode([
            'threshold' => 1,
            'minTokens' => 100,
            'minLines'  => 5,
            'exitCode'  => 1,
            'reporters' => ['console-full'],
            'format'    => ['php'],
        ]);

        file_put_contents($dir . '/.jscpd.json', self::padJsonToCap(self::MAX_TEXT_BYTES, $body));

        $this->assertGateRejects(self::phpGate(), $dir, '`threshold` must be 0', 'a .jscpd.json exactly at the size cap is still read and checked');
    }

    /**
     * An unreadable .jscpd.json is reported rather than skipped.
     */
    #[Test]
    public function rejectsJscpdUnreadable(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->jscpdFixture();
        chmod($dir . '/.jscpd.json', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, '.jscpd.json: exists but cannot be read', 'an unreadable .jscpd.json is reported rather than skipped');
        } finally {
            chmod($dir . '/.jscpd.json', 0o644);
        }
    }

    // -------------------------------------------------------------------
    // jscpd extension-spelling deny list — derived from the gate's own
    // $extensionSpellings table (extensionSpellingsFromGate()).
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function extensionSpellingProvider(): array
    {
        $rows = [];

        foreach (self::extensionSpellingsFromGate() as $pair) {
            [$spelling, $canonical] = explode(':', $pair, 2);
            $rows[$spelling]        = [$spelling, $canonical];
        }

        return $rows;
    }

    /**
     * .jscpd.json using a bare file-extension spelling (e.g. "ts") as a
     * format name, one row per entry the gate's own $extensionSpellings
     * table declares.
     */
    #[Test]
    #[DataProvider('extensionSpellingProvider')]
    public function rejectsJscpdExtensionSpellingAsFormatName(string $spelling, string $canonical): void
    {
        $dir  = $this->jscpdFixture();
        $json = (string) file_get_contents($dir . '/.jscpd.json');
        file_put_contents($dir . '/.jscpd.json', str_replace('"reporters": ["console-full"]', "\"reporters\": [\"console-full\"],\n    \"format\": [\"php\", \"{$spelling}\"]", $json));

        $this->assertGateRejects(self::phpGate(), $dir, "Use \"{$canonical}\"", ".jscpd.json using the \"{$spelling}\" extension as a format name");
    }

    /**
     * Both directions of the bijection between PROVEN_SPELLINGS and the
     * gate's own $extensionSpellings table — a spelling the gate stops
     * rejecting, and one it starts rejecting that this suite never drove a
     * case for.
     */
    #[Test]
    public function extensionSpellingsBijectionHoldsBothDirections(): void
    {
        $gateSpellings = self::extensionSpellingsFromGate();

        foreach (self::PROVEN_SPELLINGS as $pair) {
            self::assertContains($pair, $gateSpellings, 'the gate no longer rejects the spelling `' . explode(':', $pair)[0] . '`, which this suite proves — the entry was dropped or its canonical name changed');
        }

        foreach ($gateSpellings as $pair) {
            self::assertContains($pair, self::PROVEN_SPELLINGS, 'the gate now rejects the spelling `' . explode(':', $pair)[0] . '`, which this suite does not name — add it rather than leaving the entry unexercised');
        }
    }
}
