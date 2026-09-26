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

use function chr;
use function file_put_contents;
use function json_encode;
use function str_repeat;

/**
 * Fixture-driven cases for the JSONC decode pipeline in
 * bin/consumer-checks/check-biome-tsconfig.php ($stripJsonc, $loadJsonc) and
 * its bin/check-js-config.mjs twin (bin/support/jsonc.mjs): comment and
 * trailing-comma stripping, strict UTF-8, lone surrogates, the 512-level
 * depth cap and the MAX_JSONC_BYTES size cap, for biome.json, tsconfig.json
 * and the package.json adoption probe alike — plus the scrubbing and
 * truncation of the consumer-controlled keys the gate echoes back
 * (bin/support/safe-report-value.php). The decode pipeline must agree with
 * PHP's json_decode() byte for byte, so every case runs BOTH gates against
 * the same fixture through the assertBoth*() helpers; see
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigBiomeTsconfigJsoncTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // JSONC decoding, the size caps, and the scrubbing of what the gate
    // echoes back
    // -------------------------------------------------------------------

    /**
     * The string-protection the trailing-comma pass needs: a comma before
     * a bracket INSIDE a string value is part of the value, not
     * punctuation to strip. A rule GROUP name is interpolated into the
     * violation text, so a corrupted one is visible: drop the string guard
     * and the report reads `linter.rules.sus]picious` instead.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeReportedRuleGroupCarryingCommaBeforeBracketInsideString(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"sus,]picious\": { \"preset\": \"none\" } } }\n}\n");

        $this->assertBothReject($dir, 'linter.rules.sus,]picious', 'biome.json whose reported rule group carries a comma before a bracket inside a string');
    }

    /**
     * A rule-group key is arbitrary bytes chosen by whoever opened the
     * pull request, and this gate runs in the CONSUMER's CI over branch
     * content — see bin/support/safe-report-value.php for why that reaches
     * a workflow command.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenRuleGroupKeyCarriesControlCharacters(): void
    {
        $dir = $this->mkJsCase();
        $esc = chr(27);
        $key = "a{$esc}[2K\n::notice::forged\n##[error]forged\nb";
        file_put_contents($dir . '/biome.json', (string) json_encode([
            'extends' => ['@magicsunday/coding-standard/biome/base.json'],
            'linter'  => ['rules' => [$key => ['recommended' => false]]],
        ]));

        $this->assertBothReportIsInert($dir, 'a?[2K?::notice::forged?##?[error]forged?b', 'a rule-group key carrying control characters');
    }

    /**
     * The `overrides` half, which had no case at all: every other overrides
     * fixture writes a JSON ARRAY, so the index is an int and the guard is
     * a no-op — the gate reaches this site through `is_array()`, which is
     * true for a JSON OBJECT too, so a hostile string key is reachable.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenOverridesKeyCarriesANewline(): void
    {
        $dir = $this->mkJsCase();
        $key = "x\n::error::forged\ny";
        file_put_contents($dir . '/biome.json', (string) json_encode([
            'extends'   => ['@magicsunday/coding-standard/biome/base.json'],
            'overrides' => [$key => ['linter' => ['rules' => ['recommended' => false]]]],
        ]));

        $this->assertBothReportIsInert($dir, 'x?::error::forged?y', 'an overrides key carrying a newline');
    }

    /**
     * The size cap, both sides of the bound. 131072 is read and checked;
     * one byte more is reported as unread rather than scanned.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeExactlyAtTheSizeCapIsStillReadAndChecked(): void
    {
        $dir  = $this->mkJsCase();
        $body = (string) json_encode(['extends' => ['@magicsunday/coding-standard/biome/base.json']]);
        file_put_contents($dir . '/biome.json', self::padJsonToCap(self::MAX_JSONC_BYTES, $body));

        $this->assertBothReject($dir, '`"//"` key', 'a biome.json exactly at the size cap is still read and checked');
    }

    /**
     * A biome.json past the size cap is reported as oversized, not scanned.
     */
    #[Test]
    public function rejectsBiomePastTheSizeCapIsReportedAsOversizedNotScanned(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', self::oversizedJsonBody());

        $this->assertBothReject($dir, 'larger than the ' . self::MAX_JSONC_BYTES . ' bytes this gate checks', 'a biome.json past the size cap is reported as oversized, not scanned');
    }

    /**
     * A tsconfig.json past the size cap is reported as oversized, not scanned.
     */
    #[Test]
    public function rejectsTsconfigPastTheSizeCapIsReportedAsOversizedNotScanned(): void
    {
        // The tsconfig arm is a separate code path from biome.json's own
        // size guard — a gate missing this one silently prints OK for a
        // tsconfig.json it never read.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', self::oversizedJsonBody());

        $this->assertBothReject($dir, 'larger than the ' . self::MAX_JSONC_BYTES . ' bytes this gate checks', 'a tsconfig.json past the size cap is reported as oversized, not scanned');
    }

    /**
     * package.json's own oversize arm, AT the cap rather than past it: GH-109
     * found only the cap+1 case existed, so a mutation narrowing the read
     * bound's `>` to `>=` would (wrongly) treat an at-cap package.json as
     * oversized and reject for the WRONG reason. The assertion below pins
     * the extends violation's own wording rather than the generic
     * `biome/base.json` mention every drift's footer carries regardless of
     * cause, since that substring is satisfied either way.
     *
     * @return void
     */
    #[Test]
    public function rejectsPackageJsonExactlyAtTheSizeCapIsStillReadAndChecked(): void
    {
        $dir = $this->mkCase();
        self::writeMinimalBiomeJson($dir, true);
        $body = (string) json_encode([
            'name'            => 'fixture',
            'devDependencies' => ['@magicsunday/coding-standard' => 'github:magicsunday/coding-standard#1.7.0'],
        ]);

        file_put_contents($dir . '/package.json', self::padJsonToCap(self::MAX_TEXT_BYTES, $body));

        $this->assertBothReject($dir, 'must `extends`', 'a package.json exactly at the size cap is still read and checked');
    }

    /**
     * An oversized package.json is reported once, as itself.
     */
    #[Test]
    public function reportsOnceWhenPackageJsonIsPastTheSizeCap(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"]\n}\n");
        file_put_contents($dir . '/package.json', str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertBothReportsOnce($dir, 'package.json', 'an oversized package.json is reported once, as itself');
    }

    /**
     * A DEL byte in a rule-group key is scrubbed.
     */
    #[Test]
    public function rejectsBiomeDelByteInRuleGroupIsScrubbed(): void
    {
        // The DEL half of the scrub class: removing \x7F from the scrub
        // class would leave the other control-character payloads green.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', (string) json_encode([
            'extends' => ['@magicsunday/coding-standard/biome/base.json'],
            'linter'  => ['rules' => ['a' . chr(127) . 'b' => ['recommended' => false]]],
        ]));

        $this->assertBothReject($dir, 'linter.rules.a?b', 'a DEL byte in a rule-group key is scrubbed');
    }

    /**
     * An overlong rule-group key is truncated with a marker.
     */
    #[Test]
    public function rejectsBiomeOverlongRuleGroupKeyTruncatedWithMarker(): void
    {
        // The truncation arm: a consumer otherwise controls the report's
        // length without bound — measured on the phpunit path, 5000 bytes
        // in produced 5224 bytes out.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', (string) json_encode([
            'extends' => ['@magicsunday/coding-standard/biome/base.json'],
            'linter'  => ['rules' => [str_repeat('z', 400) => ['recommended' => false]]],
        ]));

        $this->assertBothReject($dir, 'linter.rules.' . str_repeat('z', 64) . '…', 'an overlong rule-group key is truncated with a marker');
    }

    /**
     * The multi-byte-safe half of the same truncation, which the fixture
     * above never reaches (every byte in it is ASCII): a 2-byte UTF-8
     * character ("u-umlaut") is placed so its SECOND byte lands exactly on
     * the 64-byte cut point. A working backoff drops the whole character
     * and reports 63 "z"s; a naive cut would instead keep the lead byte
     * alone, decoding to a replacement character rather than this exact
     * substring. Verified against the real PHP gate:
     * `mb_strcut(str_repeat("z", 63) . "\u{fc}" . "x", 0, 64)` returns the
     * identical 63-byte "z" run.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeRuleGroupKeyMultibyteCharacterStraddlingCutIsNotSplit(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', (string) json_encode([
            'extends' => ['@magicsunday/coding-standard/biome/base.json'],
            'linter'  => ['rules' => [str_repeat('z', 63) . "\u{fc}x" => ['recommended' => false]]],
        ]));

        $this->assertBothReject($dir, 'linter.rules.' . str_repeat('z', 63) . '…', 'a rule-group key whose multi-byte character straddles the 64-byte cut is not split');
    }

    /**
     * Tsconfig.json with a block comment.
     */
    #[Test]
    public function acceptsTsconfigWithBlockComment(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    /* A consumer may comment this file. */\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\"\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with a block comment');
    }

    /**
     * Tsconfig.json whose block comment must not swallow the rest.
     */
    #[Test]
    public function rejectsTsconfigBlockCommentMustNotSwallowRestOfDocument(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    /* a \" and a // inside,\n       spread over two lines */\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": false }\n}\n");

        $this->assertBothReject($dir, '`compilerOptions.strict`', 'tsconfig.json whose block comment must not swallow the rest');
    }

    /**
     * Tsconfig.json with an unterminated block comment.
     */
    #[Test]
    public function rejectsTsconfigUnterminatedBlockComment(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\"\n}\n/* never closed");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with an unterminated block comment');
    }

    /**
     * Tsconfig.json with an invalid UTF-8 byte discarded inside a comment.
     */
    #[Test]
    public function acceptsTsconfigInvalidUtf8ByteDiscardedInsideComment(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    // a stray byte: \xFF end\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\"\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with an invalid UTF-8 byte discarded inside a comment');
    }

    /**
     * Tsconfig.json with an invalid UTF-8 byte outside any comment.
     */
    #[Test]
    public function rejectsTsconfigInvalidUtf8ByteOutsideComment(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\", \"junk\": \"\xFF\"\n}\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with an invalid UTF-8 byte outside any comment');
    }

    /**
     * isAsciiWhitespaceByte mirrors the trailing-comma pattern, which has
     * no `/u` modifier and matches only ASCII whitespace — NOT a non-
     * breaking space (U+00A0). This fixture is rejected either way it is
     * scanned, since the leftover NBSP is fatal to JSON parsing on its
     * own; what it pins is that the classifier does not itself misclassify
     * NBSP as whitespace.
     *
     * @return void
     */
    #[Test]
    public function rejectsTsconfigTrailingCommaBeforeNonBreakingSpace(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": true,\xC2\xA0}\n}\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with a trailing comma before a non-breaking space, not a real comma-then-close');
    }

    /**
     * Tsconfig.json with an unpaired UTF-16 surrogate escape.
     */
    #[Test]
    public function rejectsTsconfigUnpairedUtf16SurrogateEscape(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"note\": \"\\uD800\"\n}\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with an unpaired UTF-16 surrogate escape');
    }

    /**
     * Tsconfig.json with a properly paired surrogate escape (an emoji).
     */
    #[Test]
    public function acceptsTsconfigProperlyPairedSurrogateEscape(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"note\": \"\\uD83D\\uDE00\"\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with a properly paired surrogate escape (an emoji)');
    }

    /**
     * A lone surrogate in an object KEY, or inside an array ELEMENT, rather
     * than a top-level string value — the two positions #72 found no fixture
     * for. The node gate scans every string literal of the source text
     * (sourceContainsLoneSurrogate() in bin/support/jsonc.mjs), keys and
     * array members alike; a scan narrowed to values, or a walk over the
     * parsed result that skips keys, would accept one of these while
     * json_decode() rejects both.
     *
     * @return array<string, array{0: string}>
     */
    public static function loneSurrogatePositionProvider(): array
    {
        return [
            'object key'    => ["{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"x\\uD800\": true }\n}\n"],
            'array element' => ["{\n    \"extends\": [\"@magicsunday/coding-standard/tsconfig/base.json\", \"x\\uDC00\"]\n}\n"],
        ];
    }

    /**
     * Rejects a tsconfig.json whose only lone surrogate sits in a key or an array element.
     *
     * @param string $tsconfig The tsconfig.json source.
     */
    #[Test]
    #[DataProvider('loneSurrogatePositionProvider')]
    public function rejectsTsconfigLoneSurrogateOutsideATopLevelValue(string $tsconfig): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', $tsconfig);

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with a lone surrogate in a key or array element');
    }

    /**
     * JSON.parse() collapses a repeated key to its LAST occurrence before
     * any check on the parsed result runs, so an unpaired surrogate sitting
     * only in an EARLIER, overwritten occurrence would go unseen by a check
     * that walked the parsed value — while json_decode() validates every
     * string token as it streams. Verified against the PHP gate on this
     * exact fixture: PHP rejects even though the invalid value is the one
     * the later, valid "note" overwrites.
     *
     * @return void
     */
    #[Test]
    public function rejectsTsconfigUnpairedSurrogateOverwrittenByDuplicateKey(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"note\": \"\\uD800\",\n    \"note\": \"valid\"\n}\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', "tsconfig.json whose only unpaired surrogate sits in a duplicate key's overwritten first occurrence");
    }

    /**
     * json_decode()'s default $depth is 512 and fails once nesting reaches
     * that count — the outermost container counts as depth 1. Measured
     * directly: 511 levels decode cleanly, 512 does not. JSON.parse() has
     * no comparable cap at reachable depths, and the 128 KiB size cap does
     * nothing to bound this on its own — 511 levels costs well under 4 KB.
     *
     * @return void
     */
    #[Test]
    public function rejectsTsconfigNestedToExactly512LevelDepth(): void
    {
        $dir  = $this->mkJsCase();
        $json = '{"extends":"@magicsunday/coding-standard/tsconfig/base.json","deep":'
            . str_repeat('{"a":', 511) . '1' . str_repeat('}', 511) . '}';

        file_put_contents($dir . '/tsconfig.json', $json);

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json nested to exactly the 512-level depth PHP rejects at');
    }

    /**
     * Tsconfig.json nested to exactly the 511-level depth PHP still accepts.
     */
    #[Test]
    public function acceptsTsconfigNestedToExactly511LevelDepth(): void
    {
        $dir  = $this->mkJsCase();
        $json = '{"extends":"@magicsunday/coding-standard/tsconfig/base.json","deep":'
            . str_repeat('{"a":', 510) . '1' . str_repeat('}', 510) . '}';

        file_put_contents($dir . '/tsconfig.json', $json);

        $this->assertBothAccept($dir, 'tsconfig.json nested to exactly the 511-level depth PHP still accepts');
    }

    /**
     * A package.json nested past the 512-level depth cap is reported, not crashed on.
     */
    #[Test]
    public function rejectsPackageJsonNestedPast512LevelDepthCap(): void
    {
        // The npm probe's own depth guard. package.json shares its decode
        // pipeline with biome.json/tsconfig.json; the PHP gate needs no
        // such guard of its own (json_decode() enforces its depth cap
        // natively at every call site).
        $dir  = $this->mkCase();
        $body = str_repeat('{"a":', 511) . '1' . str_repeat('}', 511);
        file_put_contents($dir . '/package.json', '{"devDependencies":' . $body . '}');
        self::writeMinimalBiomeJson($dir, false);

        $this->assertBothReject($dir, 'package.json: is not valid JSON', 'a package.json nested past the 512-level depth cap is reported, not crashed on');
    }

    /**
     * The npm probe's own surrogate guard: package.json shares its decode
     * pipeline with biome.json/tsconfig.json, so a lone surrogate ANYWHERE
     * in the manifest must be reported rather than silently accepted and
     * used for the adoption check. Verified: the JS gate previously
     * accepted this fixture while the PHP gate rejected it — a real
     * accept/reject divergence, not merely a differing message.
     *
     * @return void
     */
    #[Test]
    public function rejectsPackageJsonWithUnpairedSurrogateElsewhereInManifest(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"consumer\",\n    \"description\": \"bad \\uD800 escape\",\n    \"devDependencies\": { \"@magicsunday/coding-standard\": \"^3.0.0\" }\n}\n");
        self::writeTsconfigExtendingWithoutJsonSuffix($dir);

        $this->assertBothReject($dir, 'package.json: is not valid JSON', 'a package.json with an unpaired surrogate elsewhere in the manifest is reported');
    }

    /**
     * A package.json with a properly paired surrogate elsewhere in the manifest is accepted.
     */
    #[Test]
    public function acceptsPackageJsonWithPairedSurrogateElsewhereInManifest(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/package.json', "{\n    \"name\": \"consumer\",\n    \"description\": \"an emoji: \\uD83D\\uDE00\",\n    \"devDependencies\": { \"@magicsunday/coding-standard\": \"^3.0.0\" }\n}\n");
        self::writeTsconfigExtendingWithoutJsonSuffix($dir);

        $this->assertBothAccept($dir, 'a package.json with a properly paired surrogate elsewhere in the manifest is accepted');
    }

    /**
     * Tsconfig.json with a comment splitting a token.
     */
    #[Test]
    public function rejectsTsconfigCommentSplittingAToken(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": tr/* x */ue }\n}\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json with a comment splitting a token');
    }

    /**
     * The `\\.` branch of the string pattern, driven by the only input
     * that needs it: an ESCAPED QUOTE followed by a comment opener. With
     * the escape branch the string is consumed whole and the file parses;
     * without it the pass mis-terminates the string, reads the tail as a
     * comment, strips it, and the gate reports the config as unparseable.
     *
     * @return void
     */
    #[Test]
    public function acceptsTsconfigEscapedQuoteBeforeCommentOpenerInsideString(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": {\n        \"paths\": { \"@app/*\": [\"a \\\" // b\"] }\n    }\n}\n");

        $this->assertBothAccept($dir, 'tsconfig.json with an escaped quote before a comment opener inside a string');
    }

    /**
     * The JSONC tolerance must not extend to genuinely broken input: an
     * unclosed object has to be reported, not read as an empty config that
     * passes every subsequent `?? null` check.
     */
    #[Test]
    public function rejectsTsconfigNotValidJsonc(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"compilerOptions\": { \"strict\": true\n");

        $this->assertBothReject($dir, 'tsconfig.json: not valid JSON(C)', 'tsconfig.json that is not valid JSON(C)');
    }
}
