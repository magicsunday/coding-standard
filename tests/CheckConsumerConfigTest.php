<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function array_diff;
use function array_filter;
use function chmod;
use function chr;
use function copy;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function mkdir;
use function posix_getuid;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function str_repeat;
use function str_replace;
use function substr_count;
use function unlink;

use const PREG_SET_ORDER;

/**
 * Fixture-driven cases for bin/check-consumer-config.php and its Node twin
 * bin/check-js-config.mjs, migrated off tests/check-consumer-config-cases.sh
 * (#78) — the largest and only DIFFERENTIAL suite of the five, since GH-32
 * added a Node front end for the same biome.json/tsconfig.json contract that
 * the PHP gate also enforces for the non-JS parts of a consumer's config.
 *
 * Every case below either drives the PHP gate alone (the sections that have
 * no Node counterpart — phpunit.xml, .phplint.yml, deptrac.yaml,
 * .editorconfig, .jscpd.json) or drives BOTH gates against the same fixture
 * with the same expected verdict, via the assertBoth*() helpers below — the
 * PHPUnit equivalent of the bash original's assert_*_js dispatch, minus the
 * bookkeeping self-tests (probe_*_shapes, harness_assert_no_stray_increments,
 * probe_zero_match_count_survives_pipefail): those proved the bash dispatcher
 * itself still decided correctly, a concern that does not exist once the
 * dispatch is a handful of straight-line PHP method calls with no shell
 * quoting, `set -e`/pipefail interaction or argument-forwarding trick behind
 * them. GateTestCase's own meta-suite (GateTestCaseTest) already proves its
 * five assertGate*() decisions generically, the same way GH-81's port
 * reasoned about tests/harness.sh's analogous self-test.
 *
 * Each PHPUnit test method gets its own fresh, isolated fixture directory
 * from GateTestCase::fixture() — unlike the bash original, which manually
 * named a subdirectory per case under one shared $work root via mk_case()'s
 * first argument. That argument therefore has no counterpart here; every
 * mkCase()/mkJsCase()/mkUnadoptedCase()/jscpdFixture() call below returns the
 * current test's own fixture directory.
 *
 * The gate-vs-harness lockstep tables (required phpunit.xml root flags,
 * pinned tsconfig flags, the jscpd extension deny-list, and biome's per-
 * language walk) are each proven in BOTH directions: the gate's own list is
 * read from its source at runtime (the same way
 * tests/CheckDisallowedCallsTest.php derives its banned-function list), then
 * compared against an independent, hand-kept list this suite actually drives
 * a case for (REQUIRED_ROOT_FLAGS, PROVEN_LANGUAGES, PROVEN_SPELLINGS, and
 * pinnedFlagsFromGate() vs. the ERGONOMICS_FLAGS/STRICT_FAMILY_FLAGS
 * exceptions). A gate list read alone would only ever catch a flag the gate
 * LOSES; the independent harness-side list is what catches one it GAINS —
 * added but never exercised by a case here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigTest extends GateTestCase
{
    /**
     * Mirrors MAX_JSONC_BYTES in bin/check-consumer-config.php and
     * bin/check-js-config.mjs.
     */
    private const int MAX_JSONC_BYTES = 131072;

    /**
     * Mirrors MAX_TEXT_BYTES in bin/check-consumer-config.php and
     * bin/check-js-config.mjs.
     */
    private const int MAX_TEXT_BYTES = 1048576;

    /**
     * The strict attributes the gate requires on phpunit.xml's root element,
     * held here INDEPENDENTLY of the gate's own list — generating the cases
     * below from requiredRootFlagsFromGate() instead would mean deleting a
     * flag from the gate silently drops its case too. Two lists that must
     * agree (proven via requiredRootFlagsBijectionHoldsBothDirections()) is
     * the shape that discriminates a gate that stopped checking.
     *
     * @var list<non-empty-string>
     */
    private const array REQUIRED_ROOT_FLAGS = [
        'requireCoverageMetadata',
        'beStrictAboutCoverageMetadata',
        'beStrictAboutOutputDuringTests',
        'failOnRisky',
        'failOnWarning',
        'failOnNotice',
        'failOnDeprecation',
        'failOnPhpunitDeprecation',
        'failOnPhpunitNotice',
    ];

    /**
     * Ergonomics flags tsconfig/base.json ships as `true` that are
     * deliberately NOT pinned — turning one off is stricter, not looser, and
     * must not be reported as drift.
     *
     * @var list<non-empty-string>
     */
    private const array ERGONOMICS_FLAGS = ['esModuleInterop', 'resolveJsonModule', 'skipLibCheck'];

    /**
     * The flags `strict: true` switches on as a group. They are not written
     * into tsconfig/base.json themselves — `strict` implies them — so
     * baseFlagsFromTsconfigBase() cannot generate their cases; held here
     * independently so both directions can be checked against the gate's own
     * $pinnedFlags. A consumer may write any family member back individually
     * ("strict": true alongside "strictNullChecks": false compiles code
     * "strict": true alone rejects), so pinning only `strict` pins nothing.
     *
     * @var list<non-empty-string>
     */
    private const array STRICT_FAMILY_FLAGS = [
        'alwaysStrict',
        'noImplicitAny',
        'noImplicitThis',
        'strictBindCallApply',
        'strictBuiltinIteratorReturn',
        'strictFunctionTypes',
        'strictNullChecks',
        'strictPropertyInitialization',
        'useUnknownInCatchVariables',
    ];

    /**
     * The languages this suite knows how to drive through biome's per-
     * language linter.enabled walk. A row the gate gains that is not here
     * fails languageWalkBijectionHoldsBothDirections() rather than shipping
     * unexercised.
     *
     * @var list<non-empty-string>
     */
    private const array PROVEN_LANGUAGES = ['javascript', 'json', 'css', 'graphql', 'grit', 'html'];

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
    // Path / gate helpers
    // -------------------------------------------------------------------

    /**
     * @return string Absolute path to the repository root.
     */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return string Absolute path to tests/consumer, the canonical fixture this gate must accept unmodified.
     */
    private static function canon(): string
    {
        return self::root() . '/tests/consumer';
    }

    /**
     * @return list<string> The PHP gate under test.
     */
    private static function phpGate(): array
    {
        return ['php', self::root() . '/bin/check-consumer-config.php'];
    }

    /**
     * @return list<string> The Node gate under test — the front end GH-32 added for the same biome.json/tsconfig.json contract.
     */
    private static function nodeGate(): array
    {
        return ['node', self::root() . '/bin/check-js-config.mjs'];
    }

    // -------------------------------------------------------------------
    // Differential dispatch — drives both gates against the same fixture
    // with the same expected verdict, mirroring the bash original's
    // assert_*_js wrappers.
    // -------------------------------------------------------------------

    /**
     * The clean-verdict decision, on both the PHP and the Node gate.
     *
     * @param string $dir     The directory to run both gates against.
     * @param string $message An optional assertion message; suffixed with " (node)" for the Node-gate half.
     *
     * @return void
     *
     * @throws AssertionFailedError        If either gate exited non-zero or ran degraded.
     * @throws ProcessStartFailedException If a gate process could not be started.
     * @throws ProcessTimedOutException    If a gate process exceeded its timeout.
     * @throws ProcessSignaledException    If a gate process was killed by a signal.
     */
    private function assertBothAccept(string $dir, string $message = ''): void
    {
        $this->assertGateAccepts(self::phpGate(), $dir, $message);
        $this->assertGateAccepts(self::nodeGate(), $dir, $message !== '' ? "{$message} (node)" : '');
    }

    /**
     * The drift-verdict decision, on both the PHP and the Node gate, with the SAME expected substring.
     *
     * @param string $dir               The directory to run both gates against.
     * @param string $expectedSubstring The substring both reports must carry.
     * @param string $message           An optional assertion message; suffixed with " (node)" for the Node-gate half.
     *
     * @return void
     *
     * @throws AssertionFailedError        If either gate did not reject for the expected reason, or ran degraded.
     * @throws ProcessStartFailedException If a gate process could not be started.
     * @throws ProcessTimedOutException    If a gate process exceeded its timeout.
     * @throws ProcessSignaledException    If a gate process was killed by a signal.
     */
    private function assertBothReject(string $dir, string $expectedSubstring, string $message = ''): void
    {
        $this->assertGateRejects(self::phpGate(), $dir, $expectedSubstring, $message);
        $this->assertGateRejects(self::nodeGate(), $dir, $expectedSubstring, $message !== '' ? "{$message} (node)" : '');
    }

    /**
     * The could-not-run decision, on both the PHP and the Node gate.
     *
     * @param string $dir               The directory to run both gates against.
     * @param string $expectedSubstring The substring both reports must carry.
     * @param string $message           An optional assertion message; suffixed with " (node)" for the Node-gate half.
     *
     * @return void
     *
     * @throws AssertionFailedError        If either gate did not refuse for the expected reason, or ran degraded.
     * @throws ProcessStartFailedException If a gate process could not be started.
     * @throws ProcessTimedOutException    If a gate process exceeded its timeout.
     * @throws ProcessSignaledException    If a gate process was killed by a signal.
     */
    private function assertBothUsageError(string $dir, string $expectedSubstring, string $message = ''): void
    {
        $this->assertGateUsageError(self::phpGate(), $dir, $expectedSubstring, $message);
        $this->assertGateUsageError(self::nodeGate(), $dir, $expectedSubstring, $message !== '' ? "{$message} (node)" : '');
    }

    /**
     * The report-shape decision for consumer-controlled bytes, on both the PHP and the Node gate.
     *
     * @param string      $dir                       The directory to run both gates against.
     * @param string|null $expectedScrubbedSubstring The scrubbed value both reports must carry, or null to skip that check.
     * @param string      $message                   An optional assertion message; suffixed with " (node)" for the Node-gate half.
     *
     * @return void
     *
     * @throws AssertionFailedError        If either inertness check fails, or a gate ran degraded.
     * @throws ProcessStartFailedException If a gate process could not be started.
     * @throws ProcessTimedOutException    If a gate process exceeded its timeout.
     * @throws ProcessSignaledException    If a gate process was killed by a signal.
     */
    private function assertBothReportIsInert(string $dir, ?string $expectedScrubbedSubstring = null, string $message = ''): void
    {
        $this->assertGateReportIsInert(self::phpGate(), $dir, $expectedScrubbedSubstring, $message);
        $this->assertGateReportIsInert(self::nodeGate(), $dir, $expectedScrubbedSubstring, $message !== '' ? "{$message} (node)" : '');
    }

    /**
     * The "reported exactly once, as itself" decision, on both the PHP and the Node gate.
     *
     * @param string $dir        The directory to run both gates against.
     * @param string $filePrefix The file label expected to appear exactly once in both reports.
     * @param string $message    An optional assertion message; suffixed with " (node)" for the Node-gate half.
     *
     * @return void
     *
     * @throws AssertionFailedError        If either report carries zero or more than one matching line, or a gate ran degraded.
     * @throws ProcessStartFailedException If a gate process could not be started.
     * @throws ProcessTimedOutException    If a gate process exceeded its timeout.
     * @throws ProcessSignaledException    If a gate process was killed by a signal.
     */
    private function assertBothReportsOnce(string $dir, string $filePrefix, string $message = ''): void
    {
        $this->assertGateReportsOnce(self::phpGate(), $dir, $filePrefix, $message);
        $this->assertGateReportsOnce(self::nodeGate(), $dir, $filePrefix, $message !== '' ? "{$message} (node)" : '');
    }

    // -------------------------------------------------------------------
    // Fixture builders — mirror the bash original's mk_case()/mk_js_case()/
    // mk_unadopted_case()/jscpd_fixture(), minus the per-case directory
    // name (this test's own fixture() directory already isolates it).
    // -------------------------------------------------------------------

    /**
     * @return string This test's fixture directory, seeded with the canon phpunit.xml.
     */
    private function mkCase(): string
    {
        $dir = $this->fixture()->path();
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml');

        return $dir;
    }

    /**
     * mkCase() plus the canonical biome.json/tsconfig.json and a
     * package.json declaring the npm dependency — the shape every JS/TS
     * extends-contract case below corrupts exactly one part of.
     *
     * @return string This test's fixture directory.
     */
    private function mkJsCase(): string
    {
        $dir = $this->mkCase();
        copy(self::canon() . '/biome.json', $dir . '/biome.json');
        copy(self::canon() . '/tsconfig.json', $dir . '/tsconfig.json');
        self::writeAdoptingPackageJson($dir);

        return $dir;
    }

    /**
     * Writes a package.json declaring the npm devDependency on this
     * package — the one file shape mkJsCase() and the local-extends
     * escape-via-.. fixture (which needs the surrounding directory shape
     * mkJsCase() does not produce) both need byte-for-byte.
     *
     * @param string $dir The directory to write package.json into.
     *
     * @return void
     */
    private static function writeAdoptingPackageJson(string $dir): void
    {
        file_put_contents($dir . '/package.json', <<<'JSON'
            {
                "name": "fixture",
                "devDependencies": {
                    "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"
                }
            }

            JSON);
    }

    /**
     * Writes a minimal, standalone biome.json (no `extends`) whose only
     * content is the top-level `linter.enabled` flag — the "some valid
     * biome.json exists, only adoption/parsing is under test" stand-in used
     * across a wide range of otherwise-unrelated cases in this file.
     *
     * @param string $dir     The directory to write biome.json into.
     * @param bool   $enabled The value of `linter.enabled`.
     *
     * @return void
     */
    private static function writeMinimalBiomeJson(string $dir, bool $enabled): void
    {
        file_put_contents(
            $dir . '/biome.json',
            '{' . "\n    \"linter\": { \"enabled\": " . ($enabled ? 'true' : 'false') . " }\n}\n",
        );
    }

    /**
     * Writes a tsconfig.json extending the shared base config WITHOUT the
     * `.json` suffix — tsc appends it itself, so this resolves to the same
     * file as `.../tsconfig/base.json`.
     *
     * @param string $dir The directory to write tsconfig.json into.
     *
     * @return void
     */
    private static function writeTsconfigExtendingWithoutJsonSuffix(string $dir): void
    {
        file_put_contents(
            $dir . '/tsconfig.json',
            "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base\"\n}\n",
        );
    }

    /**
     * Writes a tsconfig.json extending the shared base config with
     * `compilerOptions.strict` forced back to `false`.
     *
     * @param string $dir The directory to write tsconfig.json into.
     *
     * @return void
     */
    private static function writeTsconfigWithStrictFalse(string $dir): void
    {
        file_put_contents(
            $dir . '/tsconfig.json',
            "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": false }\n}\n",
        );
    }

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
     * Writes a minimal biome.loose.json (`{ "linter": { "enabled": false } }`)
     * — the local `extends` target fixtures use to disable the linter one
     * level removed from the biome.json under test.
     *
     * @param string $dir The directory to write biome.loose.json into.
     *
     * @return void
     */
    private static function writeMinimalBiomeLooseJson(string $dir): void
    {
        file_put_contents(
            $dir . '/biome.loose.json',
            "{ \"linter\": { \"enabled\": false } }\n",
        );
    }

    /**
     * Writes a minimal tsconfig.loose.json (`{ "compilerOptions": {
     * "noUncheckedIndexedAccess": false } }`) — the local `extends` target
     * fixtures use to disable the flag one level removed from the
     * tsconfig.json under test.
     *
     * @param string $dir The directory to write tsconfig.loose.json into.
     *
     * @return void
     */
    private static function writeMinimalTsconfigLooseJson(string $dir): void
    {
        file_put_contents(
            $dir . '/tsconfig.loose.json',
            "{ \"compilerOptions\": { \"noUncheckedIndexedAccess\": false } }\n",
        );
    }

    /**
     * Writes a biome.json extending the shared base config plus a local
     * `./biome.loose.json` second entry, scoped to `src/**` — the shared
     * document under test across the local-extends-target cases, which vary
     * what `./biome.loose.json` itself contains.
     *
     * @param string $dir The directory to write biome.json into.
     *
     * @return void
     */
    private static function writeBiomeWithLocalLooseExtendsTarget(string $dir): void
    {
        file_put_contents(
            $dir . '/biome.json',
            "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.loose.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n",
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
     * The JSON body used to build past-the-size-cap fixtures — a single key
     * whose escaped-quote run alone exceeds MAX_JSONC_BYTES.
     *
     * @return string The oversized JSON body, past MAX_JSONC_BYTES.
     */
    private static function oversizedJsonBody(): string
    {
        return '{"a":' . str_repeat('\\"', 70000);
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

    /**
     * Skips the calling test when running as root: uid 0 bypasses DAC, so
     * mode 000 stays readable and the gate correctly accepts — a false
     * regression, not a real one. CI runs non-root, so the branch stays
     * exercised there.
     *
     * @return void
     */
    private function skipIfRunningAsRoot(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }
    }

    // -------------------------------------------------------------------
    // Gate-source extraction — the lockstep tables below (see the class
    // docblock), read at runtime the same way tests/CheckDisallowedCallsTest.php
    // derives its banned-function list, so a table the gate loses or gains is
    // caught rather than a hand-kept copy silently drifting from it.
    // -------------------------------------------------------------------

    /**
     * @return string The full source of bin/check-consumer-config.php.
     */
    private static function gateSource(): string
    {
        return (string) file_get_contents(self::root() . '/bin/check-consumer-config.php');
    }

    /**
     * Extracts a `$name = ['a', 'b', ...];` block's quoted entries, cross-
     * checked against a plain quoted-string occurrence count in the same
     * block — so an entry the `[A-Za-z]+` pattern cannot see (a digit, an
     * underscore) fails loudly instead of shipping an unexercised entry.
     *
     * @param string $variable The PHP variable name, without its leading `$`.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the block cannot be found, or the two counts disagree, or nothing parsed.
     */
    private static function extractQuotedList(string $variable): array
    {
        $source = self::gateSource();

        if (preg_match('/\$' . preg_quote($variable, '/') . ' = \[(.*?)\];/s', $source, $matches) !== 1) {
            throw new RuntimeException("could not find \${$variable} in bin/check-consumer-config.php");
        }

        $block = $matches[1];

        preg_match_all("/'([A-Za-z]+)'/", $block, $named);
        preg_match_all("/'[^']*'/", $block, $any);

        if (count($any[0]) !== count($named[1])) {
            throw new RuntimeException(
                "the \${$variable} block declares " . count($any[0]) . ' entries but this test parsed '
                . count($named[1]) . ' — widen the extractor rather than leaving one unexercised',
            );
        }

        if ($named[1] === []) {
            throw new RuntimeException("no entries parsed out of \${$variable} — the extraction broke");
        }

        $entries = $named[1];

        /** @var list<non-empty-string> $entries */
        return $entries;
    }

    /**
     * @return list<non-empty-string> The gate's own $requiredRootFlags, read from source.
     */
    private static function requiredRootFlagsFromGate(): array
    {
        return self::extractQuotedList('requiredRootFlags');
    }

    /**
     * @return list<non-empty-string> The gate's own $pinnedFlags, read from source.
     */
    private static function pinnedFlagsFromGate(): array
    {
        return self::extractQuotedList('pinnedFlags');
    }

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
        $source = self::gateSource();

        if (preg_match('/\$extensionSpellings = \[(.*?)\];/s', $source, $matches) !== 1) {
            throw new RuntimeException('could not find $extensionSpellings in bin/check-consumer-config.php');
        }

        $block = $matches[1];
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
        $source = self::gateSource();

        if (preg_match("/foreach \(\['javascript'[^\]]*\]/", $source, $matches) !== 1) {
            throw new RuntimeException('could not find the per-language foreach literal in bin/check-consumer-config.php');
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
    // The canon
    // -------------------------------------------------------------------

    /**
     * Canon fixture.
     */
    #[Test]
    public function canonFixtureIsAccepted(): void
    {
        $this->assertBothAccept(self::canon(), 'canon fixture');
    }

    // -------------------------------------------------------------------
    // phpunit.xml required root flags
    // -------------------------------------------------------------------

    /**
     * Builds a DataProvider row set of the shape `[value => [value]]`, shared
     * by every provider in this class whose rows carry a single value each
     * (a flag, a language, a jscpd field, a filename, or a dependency
     * section) — every provider whose rows carry more than that argument
     * builds its own array literal instead.
     *
     * @param list<non-empty-string> $values The values, each becoming its own row.
     *
     * @return array<string, array{0: string}>
     */
    private static function singleArgProviderRows(array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            $rows[$value] = [$value];
        }

        return $rows;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredRootFlagProvider(): array
    {
        return self::singleArgProviderRows(self::REQUIRED_ROOT_FLAGS);
    }

    /**
     * Phpunit.xml with the given flag set to false.
     */
    #[Test]
    #[DataProvider('requiredRootFlagProvider')]
    public function rejectsRequiredRootFlagSetFalse(string $flag): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace("{$flag}=\"true\"", "{$flag}=\"false\"", $xml));

        $this->assertGateRejects(self::phpGate(), $dir, $flag, "phpunit.xml with {$flag} set to false");
    }

    /**
     * Phpunit.xml with the given flag removed.
     */
    #[Test]
    #[DataProvider('requiredRootFlagProvider')]
    public function rejectsRequiredRootFlagRemoved(string $flag): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents(
            $dir . '/phpunit.xml',
            implode("\n", array_filter(explode("\n", $xml), static fn (string $line): bool => !str_contains($line, "{$flag}=\"true\""))),
        );

        $this->assertGateRejects(self::phpGate(), $dir, $flag, "phpunit.xml with {$flag} removed");
    }

    /**
     * Both directions of the bijection: every flag this suite drives must
     * still be required by the gate, and every flag the gate requires must
     * still be driven by this suite — plus the canon fixture must actually
     * carry every one of them, or the mutation above is a no-op that passes
     * on an unmodified copy already failing for some other reason.
     *
     * @return void
     */
    #[Test]
    public function requiredRootFlagsBijectionHoldsBothDirections(): void
    {
        $gateFlags = self::requiredRootFlagsFromGate();

        self::assertSame([], array_diff(self::REQUIRED_ROOT_FLAGS, $gateFlags), 'the gate no longer requires a phpunit.xml attribute this suite proves');
        self::assertSame([], array_diff($gateFlags, self::REQUIRED_ROOT_FLAGS), 'the gate requires a phpunit.xml attribute this suite does not drive');

        $canonXml = (string) file_get_contents(self::canon() . '/phpunit.xml');

        foreach (self::REQUIRED_ROOT_FLAGS as $flag) {
            self::assertStringContainsString("{$flag}=\"true\"", $canonXml, "the canon phpunit.xml does not set {$flag}=\"true\", so its cases modify nothing");
        }
    }

    /**
     * <source> restrictNotices disabled.
     */
    #[Test]
    public function rejectsSourceRestrictNoticesDisabled(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('restrictNotices="true"', 'restrictNotices="false"', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'restrictNotices', '<source> restrictNotices disabled');
    }

    /**
     * <source> restrictWarnings disabled.
     */
    #[Test]
    public function rejectsSourceRestrictWarningsDisabled(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('restrictWarnings="true"', 'restrictWarnings="false"', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'restrictWarnings', '<source> restrictWarnings disabled');
    }

    // -------------------------------------------------------------------
    // .phplint.yml
    // -------------------------------------------------------------------

    /**
     * .phplint.yml with php under path, not extensions.
     */
    #[Test]
    public function rejectsPhplintPhpUnderWrongBlock(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "path:\n    - php\nextensions:\n    - phtml\n");

        $this->assertGateRejects(self::phpGate(), $dir, '`extensions:` block', '.phplint.yml with php under path, not extensions');
    }

    /**
     * .phplint.yml with CRLF line endings.
     */
    #[Test]
    public function acceptsPhplintCrlfLineEndings(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "path:\r\n    - src\r\n    - tests\r\nextensions:\r\n    - php\r\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml with CRLF line endings');
    }

    /**
     * .phplint.yml listing php after a comment and a blank line, with no final newline.
     */
    #[Test]
    public function acceptsPhplintShapesAfterCommentAndBlankLineWithNoFinalNewline(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "paths:\n    - ./src\nextensions:\n# only PHP\n\n    - php");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml listing php after a comment and a blank line, with no final newline');
    }

    /**
     * .phplint.yml whose `php` sits under a later top-level key, not in extensions.
     */
    #[Test]
    public function rejectsPhplintPhpUnderALaterTopLevelKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "extensions:\n    - phtml\npaths:\n    - php\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must list', '.phplint.yml whose `php` sits under a later top-level key, not in extensions');
    }

    /**
     * .phplint.yml saved with a UTF-8 BOM directly before its first key.
     */
    #[Test]
    public function acceptsPhplintBom(): void
    {
        // As observed against the overtrue/phplint version installed
        // locally (composer.lock is gitignored, so re-check it against
        // YOUR OWN `composer install` output: `grep -A1 '"name":
        // "overtrue/phplint"' composer.lock`), the tool reads a BOM'd
        // config and runs normally, so the gate
        // strips it — the `^extensions` anchor sits at offset 0 and the BOM
        // would displace it, reporting drift in a file the tool obeys.
        // `extensions:` is written first (not copied from templates/, which
        // opens with a comment) so the BOM actually sits before the anchor.
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "\xEF\xBB\xBFextensions:\n    - php\n\npath:\n    - ./src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml saved with a UTF-8 BOM directly before its first key');
    }

    // -------------------------------------------------------------------
    // .editorconfig
    // -------------------------------------------------------------------

    /**
     * A .editorconfig whose first `=` sits behind a long whitespace run. The
     * pattern this guards against was Theta(W^2) — measured end-to-end at
     * 34.56s for a 256 KiB run and 380s for 1 MiB, on a file with no size
     * cap. Without a TIME assertion this case cannot fail on the defect: the
     * verdict is identical either way, only the wait changes.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigWithLargeWhitespaceRunWithinTimeBound(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n[{Makefile,*.mk}]\nindent_style = tab\na"
            . str_repeat(' ', 262144) . "x=y\n",
        );

        $started = microtime(true);
        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig carrying a 256 KiB whitespace run before its first `=`');
        $elapsed = microtime(true) - $started;

        self::assertLessThanOrEqual(5.0, $elapsed, "the .editorconfig parse took {$elapsed}s on a 256 KiB whitespace run — the quadratic shape is back");
    }

    /**
     * .editorconfig with indent_style = tab in [*].
     */
    #[Test]
    public function rejectsEditorconfigStarIndentStyleTab(): void
    {
        // The fixture is canon in every other respect (indent_size, root,
        // Makefile) so the ONLY violation is the [*] indent_style, and the
        // substring discriminates exactly it.
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = tab\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must set `indent_style = space`', '.editorconfig with indent_style = tab in [*]');
    }

    /**
     * As observed on 2026-08-31, editors honour a BOM'd .editorconfig —
     * editorconfig-core-js (not a dependency of this repository, so there is
     * no local copy to re-check this against) reads one and returns its
     * settings, because JavaScript's `\s` matches U+FEFF. PHP's trim() does
     * not, so without the strip the key parses as
     * "\u{FEFF}root" and a file every editor obeys is reported as drift.
     * Written literally (not copied from templates/, whose header would
     * absorb the BOM on a comment line and leave `root = true` untouched,
     * pinning nothing) so the BOM directly abuts the key the strip protects.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigWithBom(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "\xEF\xBB\xBFroot = true\n\n[*]\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig saved with a UTF-8 BOM directly before its first key');
    }

    /**
     * The Makefile arm no other .editorconfig fixture reaches: every one
     * that writes `[{Makefile,*.mk}]` at all sets `indent_style = tab`, so
     * only the section-MISSING half was driven elsewhere. Reducing the
     * condition to `$makefile === null` would leave this the only red — a
     * repository moving its Makefile to spaces edits the value rather than
     * deleting the header.
     *
     * @return void
     */
    #[Test]
    public function rejectsEditorconfigMakefileSectionSetToSpaces(): void
    {
        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/editorconfig');
        file_put_contents($dir . '/.editorconfig', preg_replace('/^indent_style = tab$/m', 'indent_style = space', $template));

        $this->assertGateRejects(self::phpGate(), $dir, '`[{Makefile,*.mk}]` section with `indent_style = tab`', '.editorconfig whose Makefile section sets spaces instead of tab');
    }

    /**
     * The line splitter, justified by three specific bytes. `\R` matches VT,
     * FF and U+0085 as line breaks; U+0085 is the CONTINUATION byte of a
     * two-byte UTF-8 character, so splitting on it cuts a character in half
     * and re-parses the tail as a config line. The poisoned tail sits AFTER
     * the real settings (the map is last-write-wins) and parses as a key
     * that CHANGES a verdict, or the case does not discriminate.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigCommentCarryingContinuationByte(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n# note \xc4\x85 indent_style = tab\n[{Makefile,*.mk}]\nindent_style = tab\n",
        );

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig whose comment carries a U+0085 continuation byte before a settings-shaped tail');
    }

    /**
     * .editorconfig whose comment carries a form feed before a settings-shaped tail.
     */
    #[Test]
    public function acceptsEditorconfigCommentCarryingFormFeed(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n# a form feed \x0c indent_size = 2\n[{Makefile,*.mk}]\nindent_style = tab\n",
        );

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig whose comment carries a form feed before a settings-shaped tail');
    }

    /**
     * Case folding, and the explicit trim charlist beside it. Every other
     * fixture writes lowercase keys separated by plain spaces, so replacing
     * mb_strtolower() with the identity — and the charlist with trim()'s
     * default — would leave the suite green elsewhere. The form feed is the
     * one byte the two charlists disagree on.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigUppercaseKeysAndFormFeeds(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "ROOT = TRUE\n[*]\n\x0cIndent_Style\x0c = Space\nINDENT_SIZE = 4\n[{Makefile,*.mk}]\nIndent_Style = Tab\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig written with uppercase keys and values, and form feeds around a key');
    }

    /**
     * .editorconfig with root inside a section.
     */
    #[Test]
    public function rejectsEditorconfigRootInsideSection(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "[*]\nroot = true\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'root = true', '.editorconfig with root inside a section');
    }

    /**
     * .editorconfig without the Makefile tab override.
     */
    #[Test]
    public function rejectsEditorconfigWithoutMakefileOverride(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 4\n");

        $this->assertGateRejects(self::phpGate(), $dir, '{Makefile,*.mk}', '.editorconfig without the Makefile tab override');
    }

    /**
     * .editorconfig with indent_size = 2 in [*].
     */
    #[Test]
    public function rejectsEditorconfigStarIndentSize2(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 2\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must set `indent_size = 4`', '.editorconfig with indent_size = 2 in [*]');
    }

    /**
     * .editorconfig without a global [*] section.
     */
    #[Test]
    public function rejectsEditorconfigWithoutGlobalStarSection(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*.md]\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must define a global `[*]` section', '.editorconfig without a global [*] section');
    }

    /**
     * .editorconfig with a lowercase {makefile,*.mk} glob.
     */
    #[Test]
    public function rejectsEditorconfigLowercaseMakefileGlob(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 4\n\n[{makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, '{Makefile,*.mk}', '.editorconfig with a lowercase {makefile,*.mk} glob');
    }

    // -------------------------------------------------------------------
    // deptrac.yaml
    // -------------------------------------------------------------------

    /**
     * Deptrac.yaml dropping the shared import.
     */
    #[Test]
    public function rejectsDeptracDroppingTheSharedImport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "deptrac:\n    paths:\n        - src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml dropping the shared import');
    }

    /**
     * Deptrac.yaml importing the shared ruleset.
     */
    #[Test]
    public function acceptsDeptracImportingTheSharedRuleset(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml importing the shared ruleset');
    }

    /**
     * Every line shape the block scan must admit, in one fixture. The last
     * shape is the one that regressed while being fixed: an earlier
     * widening required a newline in every alternative, silently dropping
     * the final line of a file that has none — so the sought entry sits on
     * that last line, with no trailing newline.
     *
     * @return void
     */
    #[Test]
    public function acceptsDeptracSharedImportAfterCommentBlankLineColumnZeroNoFinalNewline(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/deptrac.yaml',
            "deptrac:\n    paths:\n        - src\nimports:\n# why the shared ruleset comes last\n    - some/other.yaml\n\n"
            . '- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml',
        );

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml carrying the shared import after a comment, a blank line, at column 0 and with no final newline');
    }

    /**
     * Deptrac.yaml whose shared import opens on one quote and closes on the other.
     */
    #[Test]
    public function rejectsDeptracMismatchedQuotesAroundSharedImport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - '.build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\"\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', "deptrac.yaml whose shared import opens on one quote and closes on the other");
    }

    /**
     * Deptrac.yaml whose shared path sits under a later top-level key, not in imports.
     */
    #[Test]
    public function rejectsDeptracSharedImportUnderLaterTopLevelKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - some/other.yaml\ndeptrac:\n    paths:\n        - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared path sits under a later top-level key, not in imports');
    }

    /**
     * The two shapes the column-0 alternative used to swallow, both FALSE
     * ACCEPTS: a dash without whitespace after it is not a block sequence
     * entry (`-foreign:` is a top-level key), and `---` starts a new
     * document.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracSharedImportUnderDashPrefixedKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n-foreign:\n    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared import sits under a dash-prefixed key, not in imports');
    }

    /**
     * Deptrac.yaml whose shared import sits in the next YAML document.
     */
    #[Test]
    public function rejectsDeptracSharedImportInNextYamlDocument(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n---\n- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared import sits in the next YAML document');
    }

    /**
     * Deptrac.yaml with the shared path under the wrong key.
     */
    #[Test]
    public function rejectsDeptracSharedPathUnderWrongKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "deptrac:\n    paths:\n        - src\n    exclude_files:\n        - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml with the shared path under the wrong key');
    }

    /**
     * Deptrac.yaml importing a near-miss (notmagicsunday) path.
     */
    #[Test]
    public function rejectsDeptracNearMissVendorNamespace(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - vendor/notmagicsunday/coding-standard/deptrac/layers.yaml\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml importing a near-miss (notmagicsunday) path');
    }

    /**
     * Deptrac.yaml with a quoted import + inline comment.
     */
    #[Test]
    public function acceptsDeptracQuotedImportWithInlineComment(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - 'vendor/magicsunday/coding-standard/deptrac/layers.yaml' # shared ruleset\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml with a quoted import + inline comment');
    }

    /**
     * As observed on 2026-08-31 (deptrac is not a dependency of this
     * repository, so there is no local copy to re-check this against),
     * deptrac answers its own BOM'd config with `no extension able to load
     * "<BOM>imports"` and dies, so there a BOM IS the defect and stripping
     * it would hide one — the gate names that cause rather than reporting a
     * missing import. The template opens with a comment, so the BOM
     * displaces nothing there and `^imports` still matches — the failure is
     * genuinely deptrac's own.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracBom(): void
    {
        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/deptrac.dist.yaml');
        file_put_contents($dir . '/deptrac.yaml', "\xEF\xBB\xBF" . $template);

        $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: starts with a UTF-8 BOM', 'deptrac.yaml saved with a UTF-8 BOM, which deptrac itself refuses to load');
    }

    /**
     * The BOM-anchored counterpart: a consumer file that opens ON the
     * `imports:` key has that anchor displaced by the BOM too, so leaving
     * the BOM in place for the checks below would ALSO fabricate a false
     * "does not import the shared ruleset". Both assertions are required —
     * the count alone is satisfied by one report of the fabricated kind if
     * the strip is dropped along with it.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracBomAnchoredOnImportsAsBomOnlyNoFabricatedReport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "\xEF\xBB\xBFimports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n\ndeptrac:\n    paths:\n        - ./src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: starts with a UTF-8 BOM', "a BOM'd deptrac.yaml that opens on imports: is reported as a BOM");
        $this->assertGateReportsOnce(self::phpGate(), $dir, 'deptrac.yaml', "a BOM'd deptrac.yaml that opens on imports: fabricates no missing-import report");
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
     * POSITIVE: the full canonical template set as a consumer would carry
     * it. The phpunit copy comes from templates/, not the fixture — it is
     * the file a consumer actually copies, and it carries the largest
     * table (required root flags) no gate run had otherwise exercised via
     * the shipped template.
     *
     * @return void
     */
    #[Test]
    public function acceptsFullCanonicalTemplateSetAsShipped(): void
    {
        $dir = $this->fixture()->path();
        copy(self::root() . '/templates/phpunit.xml.dist', $dir . '/phpunit.xml.dist');
        copy(self::root() . '/templates/editorconfig', $dir . '/.editorconfig');
        copy(self::root() . '/templates/jscpd.json', $dir . '/.jscpd.json');
        copy(self::root() . '/templates/phplint.yml', $dir . '/.phplint.yml');

        $this->assertGateAccepts(self::phpGate(), $dir, 'full canonical template set, phpunit included, as templates/ ships it');
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
        $dir = $this->jscpdFixture();
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
     * the oversize loop below writes one byte PAST the bound, where `>` and
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
            self::assertContains($pair, $gateSpellings, "the gate no longer rejects the spelling `" . explode(':', $pair)[0] . '`, which this suite proves — the entry was dropped or its canonical name changed');
        }

        foreach ($gateSpellings as $pair) {
            self::assertContains($pair, self::PROVEN_SPELLINGS, 'the gate now rejects the spelling `' . explode(':', $pair)[0] . '`, which this suite does not name — add it rather than leaving the entry unexercised');
        }
    }

    // -------------------------------------------------------------------
    // phpunit.xml layout checks
    // -------------------------------------------------------------------

    /**
     * <source><include> no longer covering src.
     */
    #[Test]
    public function rejectsPhpunitSourceIncludeNoLongerCoveringSrc(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('<directory>src</directory>', '<directory>lib</directory>', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'must cover the `src` directory', '<source><include> no longer covering src');
    }

    /**
     * Test suite not running tests/.
     */
    #[Test]
    public function rejectsPhpunitTestSuiteNotRunningTests(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('<directory>tests</directory>', '<directory>test</directory>', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'must run the `tests` directory', 'test suite not running tests/');
    }

    /**
     * Tests/Architecture present but not excluded.
     */
    #[Test]
    public function rejectsArchitectureDirectoryPresentButNotExcluded(): void
    {
        $dir = $this->mkCase();
        mkdir($dir . '/tests/Architecture', 0o700, true);

        $this->assertGateRejects(self::phpGate(), $dir, 'must be excluded', 'tests/Architecture present but not excluded');
    }

    /**
     * Tests/Architecture present and excluded.
     */
    #[Test]
    public function acceptsArchitectureDirectoryPresentAndExcluded(): void
    {
        $dir = $this->fixture()->path();
        mkdir($dir . '/tests/Architecture', 0o700, true);
        $xml = (string) file_get_contents(self::canon() . '/phpunit.xml');
        file_put_contents(
            $dir . '/phpunit.xml',
            str_replace('<directory>tests</directory>', "<directory>tests</directory>\n            <exclude>tests/Architecture</exclude>", $xml),
        );

        $this->assertGateAccepts(self::phpGate(), $dir, 'tests/Architecture present and excluded');
    }

    /**
     * Phpunit.xml missing.
     */
    #[Test]
    public function rejectsPhpunitMissing(): void
    {
        $this->assertGateRejects(self::phpGate(), $this->fixture()->path(), 'missing', 'phpunit.xml missing');
    }

    /**
     * Phpunit.xml not well-formed.
     */
    #[Test]
    public function rejectsPhpunitNotWellFormed(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/phpunit.xml', '<phpunit><broken');

        $this->assertGateRejects(self::phpGate(), $dir, 'not well-formed', 'phpunit.xml not well-formed');
    }

    /**
     * Strict config discovered as phpunit.xml.dist.
     */
    #[Test]
    public function acceptsPhpunitXmlDistFallback(): void
    {
        $dir = $this->fixture()->path();
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml.dist');

        $this->assertGateAccepts(self::phpGate(), $dir, 'strict config discovered as phpunit.xml.dist');
    }

    /**
     * Phpunit.xml without a <source> element.
     */
    #[Test]
    public function rejectsPhpunitWithoutSourceElement(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', (string) preg_replace('#<source.*?</source>#s', '', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'missing a <source>', 'phpunit.xml without a <source> element');
    }

    /**
     * XML attribute-value normalisation folds only LITERAL control
     * characters to a space; a character reference survives, so `&#10;`
     * produces a real newline. ESC is not expressible in XML 1.0 at all,
     * which is why this payload carries no escape sequence and the ANSI
     * arm in assertGateReportIsInert() cannot fire here — it is the phpunit
     * counterpart of the biome control-char cases further down.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenPhpunitAttributeValueCarriesACharacterReference(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('failOnRisky="true"', 'failOnRisky="false&#10;::error::forged&#10;  - phpunit.xml: OK"', $xml));

        $this->assertGateReportIsInert(self::phpGate(), $dir, 'false?::error::forged?', 'a phpunit.xml attribute value carrying a character reference');
    }

    /**
     * The CR half of the same class: `&#13;` survives XML attribute-value
     * normalisation and reaches the gate as a real CR, exactly as `&#10;`
     * does above. Verified against this repository's own libxml:
     * `php -r 'echo bin2hex((string) simplexml_load_string("<a x=\"false&#13;::error::forged&#13;\"/>")["x"]);'`
     * prints `...0d3a3a6572726f723a3a666f726765640d` — 0x0d preserved, not
     * folded to 0x20.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenPhpunitAttributeValueCarriesABareCarriageReturn(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('failOnRisky="true"', 'failOnRisky="false&#13;::error::forged&#13;  - phpunit.xml: OK"', $xml));

        $this->assertGateReportIsInert(self::phpGate(), $dir, 'false?::error::forged?', 'a phpunit.xml attribute value carrying a bare carriage return');
    }

    // -------------------------------------------------------------------
    // Size caps and unreadable files — the plain-text bound
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function oversizeFileProvider(): array
    {
        return self::singleArgProviderRows([
            'phpunit.xml',
            '.jscpd.json',
            '.phplint.yml',
            '.editorconfig',
            'deptrac.yaml',
        ]);
    }

    /**
     * Every plain-text reader on this bound, not a sample: a substring
     * assertion alone passes while the gate ALSO fabricates causes.
     * Requiring exactly one report per file is what an earlier version of
     * this check missed — measured before the fix, the phpunit fixture
     * produced two violations and the .editorconfig one four, the extras
     * naming things the files plainly carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('oversizeFileProvider')]
    public function reportsOnceWhenAFileExceedsTheTextSizeCap(string $file): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/' . $file, str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(self::phpGate(), $dir, $file, "an oversized {$file} is reported once, as itself");
    }

    /**
     * An unreadable .phplint.yml reports only that it cannot be read and
     * fabricates no content-drift finding on top of it.
     */
    #[Test]
    public function rejectsUnreadablePhplintAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/phplint.yml');
        file_put_contents($dir . '/.phplint.yml', $template);
        chmod($dir . '/.phplint.yml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, '.phplint.yml: exists but cannot be read', 'an unreadable .phplint.yml reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, '.phplint.yml', 'an unreadable .phplint.yml fabricates no content drift');
        } finally {
            chmod($dir . '/.phplint.yml', 0o644);
        }
    }

    /**
     * The .editorconfig counterpart of the .phplint.yml case above.
     */
    #[Test]
    public function rejectsUnreadableEditorconfigAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/editorconfig');
        file_put_contents($dir . '/.editorconfig', $template);
        chmod($dir . '/.editorconfig', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, '.editorconfig: exists but cannot be read', 'an unreadable .editorconfig reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, '.editorconfig', 'an unreadable .editorconfig fabricates no content drift');
        } finally {
            chmod($dir . '/.editorconfig', 0o644);
        }
    }

    /**
     * The deptrac.yaml counterpart, with an inline fixture rather than the
     * template (its `.build/vendor/...` import path does not match this
     * suite's own fixture layout).
     */
    #[Test]
    public function rejectsUnreadableDeptracAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");
        chmod($dir . '/deptrac.yaml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: exists but cannot be read', 'an unreadable deptrac.yaml reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, 'deptrac.yaml', 'an unreadable deptrac.yaml fabricates no content drift');
        } finally {
            chmod($dir . '/deptrac.yaml', 0o644);
        }
    }

    /**
     * phpunit.xml is the one REQUIRED file, and libxml returns the same
     * false for unreadable as for malformed — so this used to read as a
     * syntax error.
     *
     * @return void
     */
    #[Test]
    public function rejectsUnreadablePhpunitAsUnreadableNotMalformed(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkCase();
        chmod($dir . '/phpunit.xml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, 'phpunit.xml: exists but cannot be read', 'an unreadable phpunit.xml is not reported as malformed XML');
        } finally {
            chmod($dir . '/phpunit.xml', 0o644);
        }
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

        $this->assertBothReject($dir, 'a local `extends` target contains a `"//"` key', "biome.json whose local extends target carries a \"//\" key");
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

        $this->assertBothReject($dir, "a local `extends` target (./biome.loose.json) is larger than the " . self::MAX_JSONC_BYTES . ' bytes', 'biome.json whose local extends target is past the size cap');
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

        $this->assertBothReject($dir, 'must `extends`', "biome.json whose extends is a bare string instead of a list");
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
     * check of its own:
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

        $this->assertBothReject($dir, 'overrides[0].linter.rules.preset', "biome.json dropping the rule floor through an overrides entry");
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

        $this->assertBothReject($dir, "overrides[0].javascript.linter.enabled", "biome.json disabling a language's linter inside an overrides entry");
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

        $this->assertBothReject($dir, "overrides[1].json.formatter.enabled", "biome.json disabling a non-JS language's formatter in the SECOND overrides entry");
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
    // GH-36: the extends chain resolves to the EFFECTIVE config, not just
    // the document's own top level.
    // -------------------------------------------------------------------

    /**
     * Route 1, reproduced from the issue almost verbatim: the shared entry
     * is listed FIRST, a LOCAL second entry disables the linter wholesale,
     * and the document's own top level never mentions `linter` at all — so
     * a check reading only the top level would miss the disable entirely.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeLaterLocalExtendsTargetDisablingLinter(): void
    {
        $dir = $this->mkJsCase();
        self::writeBiomeWithLocalLooseExtendsTarget($dir);
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothReject($dir, '`linter.enabled` must not be false', 'biome.json whose LATER local extends target disables the linter');
    }

    /**
     * The order-sensitive half in the OTHER direction: the shared entry
     * listed AFTER a local override wins the fold and undoes it — the
     * shared base sets `linter.enabled: true` explicitly, so it wins back.
     * A fix that treats the shared entry as a no-op regardless of position
     * passes route 1 above but must still accept HERE.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeSharedExtendsEntryFollowingAndUndoingLocalOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./biome.loose.json\", \"@magicsunday/coding-standard/biome/base.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothAccept($dir, 'biome.json whose shared extends entry follows and undoes a local override');
    }

    /**
     * Biome.json whose local extends target is a legitimate, non-drifting relaxation.
     */
    #[Test]
    public function acceptsBiomeLocalExtendsTargetLegitimateRelaxation(): void
    {
        // A local target that does something ordinary and touches none of
        // the checked toggles must not be reported. `lineWidth` is a
        // formatter ergonomic this gate does not pin.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.wide.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        file_put_contents($dir . '/biome.wide.json', "{ \"formatter\": { \"lineWidth\": 120 } }\n");

        $this->assertBothAccept($dir, 'biome.json whose local extends target is a legitimate, non-drifting relaxation');
    }

    /**
     * Biome.json whose second extends entry is an uninstalled package, not a local file.
     */
    #[Test]
    public function acceptsBiomeSecondExtendsEntryUninstalledPackage(): void
    {
        // A package-scoped entry other than the shared one resolves to no
        // file in this repository, so it must be silently skipped rather
        // than reported or crashed on.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"@some/other-package\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");

        $this->assertBothAccept($dir, 'biome.json whose second extends entry is an uninstalled package, not a local file');
    }

    /**
     * Biome.json whose local extends target escapes the repository via ../.
     */
    #[Test]
    public function acceptsBiomeLocalExtendsTargetEscapingRepositoryViaDotDot(): void
    {
        // A specifier that escapes the repository must not be followed —
        // the escape target genuinely disables the linter, so if it were
        // followed this case would reject; accepting proves it is not.
        //
        // The "repository" is nested one level under this test's own
        // fixture root (rather than using that root's parent, sys_get_temp_dir()
        // itself, as the escape target's home) so the write stays inside a
        // directory FixtureDirectory::cleanup() actually owns and removes.
        $root = $this->fixture()->path();
        $dir  = $root . '/repo';
        mkdir($dir, 0o700);
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml');
        copy(self::canon() . '/tsconfig.json', $dir . '/tsconfig.json');
        self::writeAdoptingPackageJson($dir);
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"../escape.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        file_put_contents($root . '/escape.json', "{ \"linter\": { \"enabled\": false } }\n");

        $this->assertBothAccept($dir, 'biome.json whose local extends target escapes the repository via ../');
    }

    /**
     * Route 2: one rule switched off by name survives every check that
     * only looks at the group-level `recommended`/`preset` floor. Both
     * value shapes Biome's schema allows are driven — a bare string and an
     * options object.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeRuleOffByBareStringValue(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"off\" } } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.suspicious.noDoubleEquals` must not be "off"', "biome.json switching a shared rule off by its bare-string value");
    }

    /**
     * Biome.json switching a shared rule off via its options-object level.
     */
    #[Test]
    public function rejectsBiomeRuleOffByOptionsObjectLevel(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": { \"level\": \"off\" } } } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.suspicious.noDoubleEquals` must not be "off"', "biome.json switching a shared rule off via its options-object level");
    }

    /**
     * Biome.json switching a shared rule off inside an overrides entry.
     */
    #[Test]
    public function rejectsBiomeRuleOffInOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"**/*.legacy.js\"], \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"off\" } } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].linter.rules.suspicious.noDoubleEquals` must not be "off"', 'biome.json switching a shared rule off inside an overrides entry');
    }

    /**
     * Biome.json tightening a shared rule's severity (not drift).
     */
    #[Test]
    public function acceptsBiomeRuleSeverityEscalatedNotOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"error\" } } }\n}\n");

        $this->assertBothAccept($dir, "biome.json tightening a shared rule's severity (not drift)");
    }

    /**
     * Biome.json switching off a rule the shared config never turns on itself.
     */
    #[Test]
    public function acceptsBiomeRuleOffThatSharedConfigNeverTurnsOn(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noExplicitAny\": \"off\" } } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json switching off a rule the shared config never turns on itself');
    }

    /**
     * A rule GROUP literally named after an inherited Object.prototype
     * member: the Node gate's `sharedRules[group]` lookup resolves through
     * the prototype chain rather than `undefined`, so `?? []` never
     * applies — `for...of` over a function throws and crashes the whole
     * gate. The gate must never CRASH on a config, whatever Biome does
     * with it. PHP is unaffected — an array key lookup has no prototype
     * chain.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeRuleGroupNamedToStringDoesNotCrashTheGate(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"toString\": { \"someRule\": \"off\" } } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json with a rule group literally named toString does not crash the gate');
    }

    /**
     * Tsconfig.json whose LATER local extends target disables noUncheckedIndexedAccess.
     */
    #[Test]
    public function rejectsTsconfigLaterLocalExtendsTargetDisablingFlag(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/tsconfig/base.json\", \"./tsconfig.loose.json\"],\n    \"include\": [\"src\"]\n}\n");
        self::writeMinimalTsconfigLooseJson($dir);

        $this->assertBothReject($dir, 'noUncheckedIndexedAccess', 'tsconfig.json whose LATER local extends target disables noUncheckedIndexedAccess');
    }

    /**
     * Tsconfig.json whose shared extends entry follows and undoes a local override.
     */
    #[Test]
    public function acceptsTsconfigSharedExtendsEntryFollowingAndUndoingLocalOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"./tsconfig.loose.json\", \"@magicsunday/coding-standard/tsconfig/base.json\"],\n    \"include\": [\"src\"]\n}\n");
        self::writeMinimalTsconfigLooseJson($dir);

        $this->assertBothAccept($dir, 'tsconfig.json whose shared extends entry follows and undoes a local override');
    }

    /**
     * Tsconfig.json whose local extends target only adds paths (not drift).
     */
    #[Test]
    public function acceptsTsconfigLocalExtendsTargetOnlyAddingPaths(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/tsconfig/base.json\", \"./tsconfig.paths-only.json\"],\n    \"include\": [\"src\"]\n}\n");
        file_put_contents($dir . '/tsconfig.paths-only.json', "{ \"compilerOptions\": { \"paths\": { \"@app/*\": [\"src/*\"] } } }\n");

        $this->assertBothAccept($dir, 'tsconfig.json whose local extends target only adds paths (not drift)');
    }

    /**
     * An empty JSON object decodes to PHP's `[]`, indistinguishable from an
     * empty JSON array — so a naive merge treats an empty overlay OBJECT as
     * a list and replaces the accumulated value outright instead of
     * leaving it untouched. Only the REJECT direction is fixture-tested:
     * wiping a re-enabled `linter` object down to `{}` and correctly
     * preserving it both report the identical (accept) verdict, so only
     * wiping a *disabled* section — removing the very key the check reads
     * — actually diverges into a false accept.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeEmptyTopLevelObjectDoesNotMaskUnresolvedDisable(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.loose.json\"],\n    \"files\": { \"includes\": [\"src/**\"] },\n    \"linter\": {}\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothReject($dir, '`linter.enabled` must not be false', "biome.json whose empty top-level object does not mask an unresolved disable");
    }

    /**
     * `extends` shaped as a JSON OBJECT rather than an array or string is
     * not accepted by either real tool: `Array.isArray` is false for a
     * plain object, so both sides must agree on ACCEPT, matching neither
     * resolving the local target inside it. Found during this suite's own
     * audit round: an early implementation iterated an object's VALUES as
     * extends candidates in PHP but not in Node, so the same config could
     * reach a different verdict on each side — this case pins both sides to
     * the same ACCEPT so that divergence cannot come back unnoticed.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeExtendsAsJsonObjectRatherThanArray(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": { \"shared\": \"@magicsunday/coding-standard/biome/base.json\", \"local\": \"./biome.loose.json\" }\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothAccept($dir, 'biome.json whose extends is a JSON object rather than an array');
    }

    /**
     * Prototype pollution via a literal `"__proto__"` key (CWE-1321):
     * `JSON.parse` creates a real OWN `__proto__` property, but
     * `merged[key]` on the read side falls through to the inherited
     * accessor once the accumulator carries no own `__proto__`, returning
     * the object's actual prototype — treated as ordinary data and
     * reassigned via `[[SetPrototypeOf]]`. Verified: without the fix this
     * fabricated a `files.includes` violation on a document that never set
     * `files` at all. PHP is unaffected — plain arrays have no prototype
     * mechanism.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeProtoKeyDoesNotFabricateFilesIncludesViolation(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"__proto__\": { \"files\": { \"includes\": [\"!**/*\"] } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json carrying a __proto__ key does not fabricate a files.includes violation');
    }

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

        $this->assertBothReject($dir, '`compilerOptions.strict`', "tsconfig.json whose block comment must not swallow the rest");
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
    // The pinned strict flags, derived from the shipped base — the cases
    // are DERIVED from tsconfig/base.json rather than listed by hand, so
    // a strictness flag added there later cannot go unpinned in silence.
    // -------------------------------------------------------------------

    /**
     * @return list<non-empty-string> Every compilerOptions flag tsconfig/base.json ships as `true`.
     *
     * @throws RuntimeException If the base does not decode as JSON carrying compilerOptions, or nothing was found.
     */
    private static function baseFlagsFromTsconfigBase(): array
    {
        $decoded = json_decode((string) file_get_contents(self::root() . '/tsconfig/base.json'), true);

        if (!is_array($decoded) || !is_array($decoded['compilerOptions'] ?? null)) {
            throw new RuntimeException('tsconfig/base.json did not decode as JSON carrying compilerOptions');
        }

        $flags = [];

        foreach ($decoded['compilerOptions'] as $name => $value) {
            if ($value === true) {
                $flags[] = $name;
            }
        }

        if ($flags === []) {
            throw new RuntimeException('read no compilerOptions flags from tsconfig/base.json');
        }

        /** @var list<non-empty-string> $flags */
        return $flags;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function baseFlagProvider(): array
    {
        return self::singleArgProviderRows(self::baseFlagsFromTsconfigBase());
    }

    /**
     * Every compilerOptions flag tsconfig/base.json ships as `true`, turned
     * off individually: an ERGONOMICS_FLAGS member must still be accepted
     * (turning it off is stricter, not looser), every other flag must be
     * rejected.
     */
    #[Test]
    #[DataProvider('baseFlagProvider')]
    public function baseFlagDrift(string $flag): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"{$flag}\": false }\n}\n");

        if (in_array($flag, self::ERGONOMICS_FLAGS, true)) {
            $this->assertBothAccept($dir, "tsconfig.json turning the ergonomics flag {$flag} off");
        } else {
            $this->assertBothReject($dir, "compilerOptions.{$flag}", "tsconfig.json turning the shared strict flag {$flag} off");
        }
    }

    /**
     * Both directions of the two derived lists against the base, plus the
     * gate's own $pinnedFlags bijection — so neither list can outlive
     * tsconfig/base.json or drift from the other.
     *
     * @return void
     */
    #[Test]
    public function pinnedFlagsBijectionHoldsAgainstBaseAndGate(): void
    {
        $baseFlags   = self::baseFlagsFromTsconfigBase();
        $pinnedFlags = self::pinnedFlagsFromGate();

        foreach (self::ERGONOMICS_FLAGS as $flag) {
            self::assertContains($flag, $baseFlags, "ergonomics exception {$flag} is no longer shipped by tsconfig/base.json");
        }

        foreach ($pinnedFlags as $flag) {
            if (in_array($flag, self::STRICT_FAMILY_FLAGS, true)) {
                continue;
            }

            self::assertContains($flag, $baseFlags, "pinned flag {$flag} is no longer shipped by tsconfig/base.json");
        }

        self::assertContains('strict', $baseFlags, 'tsconfig/base.json no longer sets `strict`, so the strict-family pins guard nothing');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function strictFamilyFlagProvider(): array
    {
        return self::singleArgProviderRows(self::STRICT_FAMILY_FLAGS);
    }

    /**
     * A consumer may write any `strict` family member back individually,
     * and TypeScript treats the specific option as an override of the
     * umbrella — so `strict: true` alongside `strictNullChecks: false`
     * compiles code that `strict: true` alone rejects. Pinning only
     * `strict` therefore pins nothing.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('strictFamilyFlagProvider')]
    public function rejectsTsconfigOverridingStrictFamilyFlagWhileKeepingStrict(string $flag): void
    {
        self::assertContains($flag, self::pinnedFlagsFromGate(), "the gate no longer pins the strict-family flag {$flag}");

        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": true, \"{$flag}\": false }\n}\n");

        $this->assertBothReject($dir, "compilerOptions.{$flag}", "tsconfig.json overriding the strict-family flag {$flag} while keeping strict");
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
     * devDependencies (used throughout the rest of this file) was
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
     * A path that is not a directory.
     */
    #[Test]
    public function reportsUsageErrorWhenPathIsNotADirectory(): void
    {
        $this->assertBothUsageError($this->fixture()->path() . '/does-not-exist', 'Not a directory', 'a path that is not a directory');
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

        $this->assertBothReject($dir, '`linter.enabled` must not be false', "biome.json whose per-language block is a string, not an object");
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

    /**
     * PHP-only repo without biome.json or tsconfig.json.
     */
    #[Test]
    public function acceptsPhpOnlyRepoWithoutBiomeOrTsconfig(): void
    {
        $this->assertBothAccept($this->mkCase(), 'PHP-only repo without biome.json or tsconfig.json');
    }
}
