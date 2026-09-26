<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use MagicSunday\CodingStandard\Test\GateTestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function copy;
use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function posix_getuid;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_repeat;

/**
 * Shared base for the fixture-driven suites of bin/check-consumer-config.php
 * and its Node twin bin/check-js-config.mjs, migrated off
 * tests/check-consumer-config-cases.sh (#78) — the largest and only
 * DIFFERENTIAL suite of the five, since GH-32 added a Node front end for the
 * same biome.json/tsconfig.json contract that the PHP gate also enforces for
 * the non-JS parts of a consumer's config.
 *
 * The suite mirrors the gate's own split (#48, GH-52): the gate is a thin
 * orchestrator requiring one bin/consumer-checks/check-*.php file per
 * contract, and the cases are one final class per contract, each extending
 * this one — so a case for a new contract, or a new rule in an existing one,
 * goes into the class named after the check-*.php file that implements it:
 *
 *   - check-phpunit-xml.php    -> tests/CheckConsumerConfigPhpunitXmlTest.php
 *   - check-jscpd-json.php     -> tests/CheckConsumerConfigJscpdJsonTest.php
 *   - check-phplint-yml.php    -> tests/CheckConsumerConfigPhplintYmlTest.php
 *   - check-editorconfig.php   -> tests/CheckConsumerConfigEditorconfigTest.php
 *   - check-deptrac-yaml.php   -> tests/CheckConsumerConfigDeptracYamlTest.php
 *   - check-biome-tsconfig.php -> one class per seam of that file, which is
 *     past 1000 lines on its own: CheckConsumerConfigBiomeTsconfigTest (the
 *     extends specifier and the effective config's toggles/rule walk),
 *     ...ExtendsChainTest (GH-36's extends-chain fold), ...JsoncTest (the
 *     JSONC decode pipeline, its size caps and the scrubbing of what it
 *     reports), ...AdoptionTest (the npm-dependency adoption gate and the
 *     checks that deliberately do not wait for it) and ...PinnedFlagsTest
 *     (the tsconfig flags derived from tsconfig/base.json)
 *
 * Cases that belong to no single contract — the canon, the full template
 * set, the shared plain-text size cap and unreadable-file handling of
 * helpers.php's readBounded(), the usage error — stay in
 * tests/CheckConsumerConfigTest.php, the orchestrator's own suite.
 *
 * Every case either drives the PHP gate alone (the sections that have no
 * Node counterpart — phpunit.xml, .phplint.yml, deptrac.yaml, .editorconfig,
 * .jscpd.json) or drives BOTH gates against the same fixture with the same
 * expected verdict, via the assertBoth*() helpers below — the PHPUnit
 * equivalent of the bash original's assert_*_js dispatch, minus the
 * bookkeeping self-tests (probe_*_shapes, harness_assert_no_stray_increments,
 * probe_zero_match_count_survives_pipefail): those proved the bash dispatcher
 * itself still decided correctly, a concern that does not exist once the
 * dispatch is a handful of straight-line PHP method calls with no shell
 * quoting, `set -e`/pipefail interaction or argument-forwarding trick behind
 * them. GateTestCase's own meta-suite (GateTestCaseTest) already proves its
 * five assertGate*() decisions generically, the same way GH-81's port
 * reasoned about tests/harness.sh's analogous self-test. The assertBoth*()
 * helpers live here, and ONLY here, so the differential property — every
 * biome/tsconfig case runs both gates against the identical fixture — has a
 * single definition however many classes the cases are spread over (#75).
 *
 * Each PHPUnit test method gets its own fresh, isolated fixture directory
 * from GateTestCase::fixture() — unlike the bash original, which manually
 * named a subdirectory per case under one shared $work root via mk_case()'s
 * first argument. That argument therefore has no counterpart here; every
 * mkCase()/mkJsCase()/mkUnadoptedCase()/jscpdFixture() call returns the
 * current test's own fixture directory.
 *
 * The gate-vs-harness lockstep tables (required phpunit.xml root flags,
 * pinned tsconfig flags, the jscpd extension deny-list, and biome's per-
 * language walk) are each proven in BOTH directions: the gate's own list is
 * read from its source at runtime (the same way
 * tests/CheckDisallowedCallsTest.php derives its banned-function list, via
 * the gateSource()/extractQuotedList() extractors below), then compared
 * against an independent, hand-kept list the contract's own class actually
 * drives a case for (REQUIRED_ROOT_FLAGS, PROVEN_LANGUAGES, PROVEN_SPELLINGS,
 * and pinnedFlagsFromGate() vs. the ERGONOMICS_FLAGS/STRICT_FAMILY_FLAGS
 * exceptions). A gate list read alone would only ever catch a flag the gate
 * LOSES; the independent harness-side list is what catches one it GAINS —
 * added but never exercised by a case.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class AbstractConsumerConfigTestCase extends GateTestCase
{
    /**
     * Mirrors MAX_JSONC_BYTES in bin/check-consumer-config.php and
     * bin/check-js-config.mjs.
     */
    protected const int MAX_JSONC_BYTES = 131072;

    /**
     * Mirrors MAX_TEXT_BYTES in bin/check-consumer-config.php and
     * bin/check-js-config.mjs.
     */
    protected const int MAX_TEXT_BYTES = 1048576;

    // -------------------------------------------------------------------
    // Path / gate helpers
    // -------------------------------------------------------------------

    /**
     * @return string Absolute path to tests/consumer, the canonical fixture this gate must accept unmodified.
     */
    protected static function canon(): string
    {
        return self::root() . '/tests/consumer';
    }

    /**
     * @return list<string> The PHP gate under test.
     */
    protected static function phpGate(): array
    {
        return ['php', self::root() . '/bin/check-consumer-config.php'];
    }

    /**
     * @return list<string> The Node gate under test — the front end GH-32 added for the same biome.json/tsconfig.json contract.
     */
    protected static function nodeGate(): array
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
    protected function assertBothAccept(string $dir, string $message = ''): void
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
    protected function assertBothReject(string $dir, string $expectedSubstring, string $message = ''): void
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
    protected function assertBothUsageError(string $dir, string $expectedSubstring, string $message = ''): void
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
    protected function assertBothReportIsInert(string $dir, ?string $expectedScrubbedSubstring = null, string $message = ''): void
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
    protected function assertBothReportsOnce(string $dir, string $filePrefix, string $message = ''): void
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
    protected function mkCase(): string
    {
        $dir = $this->fixture()->path();
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml');

        return $dir;
    }

    /**
     * mkCase() plus the canonical biome.json/tsconfig.json and a
     * package.json declaring the npm dependency — the shape every JS/TS
     * extends-contract case corrupts exactly one part of.
     *
     * @return string This test's fixture directory.
     */
    protected function mkJsCase(): string
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
    protected static function writeAdoptingPackageJson(string $dir): void
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
     * across a wide range of otherwise-unrelated cases in the subclasses.
     *
     * @param string $dir     The directory to write biome.json into.
     * @param bool   $enabled The value of `linter.enabled`.
     *
     * @return void
     */
    protected static function writeMinimalBiomeJson(string $dir, bool $enabled): void
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
    protected static function writeTsconfigExtendingWithoutJsonSuffix(string $dir): void
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
    protected static function writeTsconfigWithStrictFalse(string $dir): void
    {
        file_put_contents(
            $dir . '/tsconfig.json',
            "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": false }\n}\n",
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
    protected static function writeBiomeWithLocalLooseExtendsTarget(string $dir): void
    {
        file_put_contents(
            $dir . '/biome.json',
            "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.loose.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n",
        );
    }

    /**
     * The JSON body used to build past-the-size-cap fixtures — a single key
     * whose escaped-quote run alone exceeds MAX_JSONC_BYTES.
     *
     * @return string The oversized JSON body, past MAX_JSONC_BYTES.
     */
    protected static function oversizedJsonBody(): string
    {
        return '{"a":' . str_repeat('\\"', 70000);
    }

    /**
     * Skips the calling test when running as root: uid 0 bypasses DAC, so
     * mode 000 stays readable and the gate correctly accepts — a false
     * regression, not a real one. CI runs non-root, so the branch stays
     * exercised there.
     *
     * @return void
     */
    protected function skipIfRunningAsRoot(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }
    }

    // -------------------------------------------------------------------
    // Gate-source extraction — the lockstep tables the contract classes
    // prove (see the class docblock), read at runtime the same way
    // tests/CheckDisallowedCallsTest.php derives its banned-function list, so
    // a table the gate loses or gains is caught rather than a hand-kept copy
    // silently drifting from it. Each table's own reader lives in the class
    // of the contract that declares it; the shared primitives live here.
    // -------------------------------------------------------------------

    /**
     * @param string $relativePath A gate source file, relative to the repo root —
     *                             the check-*.php split under bin/consumer-checks/
     *                             (GH-48), not necessarily the orchestrator itself.
     *
     * @return string The full source of that file.
     */
    protected static function gateSource(string $relativePath): string
    {
        return (string) file_get_contents(self::root() . '/' . $relativePath);
    }

    /**
     * Extracts a `$name = ['a', 'b', ...];` block's quoted entries, cross-
     * checked against a plain quoted-string occurrence count in the same
     * block — so an entry the `[A-Za-z]+` pattern cannot see (a digit, an
     * underscore) fails loudly instead of shipping an unexercised entry.
     *
     * @param string $variable     The PHP variable name, without its leading `$`.
     * @param string $relativePath The gate source file this variable is declared in.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the block cannot be found, or the two counts disagree, or nothing parsed.
     */
    protected static function extractQuotedList(string $variable, string $relativePath): array
    {
        $source = self::gateSource($relativePath);

        if (preg_match('/\$' . preg_quote($variable, '/') . ' = \[(.*?)\];/s', $source, $matches) !== 1) {
            throw new RuntimeException("could not find \${$variable} in {$relativePath}");
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

    // -------------------------------------------------------------------
    // Data-provider rows
    // -------------------------------------------------------------------

    /**
     * Builds a DataProvider row set of the shape `[value => [value]]`, shared
     * by every provider in the subclasses whose rows carry a single value each
     * (a flag, a language, a jscpd field, a filename, or a dependency
     * section) — every provider whose rows carry more than that argument
     * builds its own array literal instead.
     *
     * @param list<non-empty-string> $values The values, each becoming its own row.
     *
     * @return array<string, array{0: string}>
     */
    protected static function singleArgProviderRows(array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            $rows[$value] = [$value];
        }

        return $rows;
    }
}
