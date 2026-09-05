<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateProcess;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function array_filter;
use function array_key_exists;
use function explode;
use function file_put_contents;
use function implode;
use function in_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function unlink;

/**
 * Fixture-driven cases for check-js-configs.sh's manifest_check() — a
 * repository-hygiene gate defined INLINE in that bash test file (there is no
 * separate bin/ source for it, unlike bin/check-consumer-config.php and
 * bin/check-js-config.mjs), migrated off tests/check-js-configs.sh (#79) the
 * same way #78 migrated tests/check-consumer-config-cases.sh.
 *
 * manifest_check() proves this package's OWN package.json against its own
 * conventions: the devEngines.runtime.version floor Node itself does not
 * reliably enforce on an older npm, the engines.node floor the code under
 * bin/ actually needs, the peerDependencies-vs-devDependencies caret-range
 * lockstep the smoke installs, and the biome/base.json $schema URL's version
 * against the same pin. It takes a plain directory and reads package.json
 * plus biome/base.json from it — no packaging pipeline involved — so every
 * case here uses an ordinary fresh per-test fixture() directory, unlike the
 * packaging-pipeline-dependent cases in CheckJsConfigsTest.
 *
 * `#[Group('js-packaging')]` marks this class as PHP-version-invariant the
 * same way CheckJsConfigsTest's own docblock explains — see there for the
 * full reasoning and the matching .github/workflows/ci.yml step.
 *
 * MANIFEST_CHECK_SCRIPT is a byte-for-byte copy of the `node -e '...'` body
 * the now-PHPUnit-migrated check-js-configs.sh passed to node, including its
 * own WHY comments: it is the literal payload under test, not a paraphrase
 * of it, and it has no other home to be read from. runManifestCheck() invokes
 * it the same way the bash original does — via the ROOT environment
 * variable, not an argv position — so it cannot reuse
 * GateTestCase::assertGate*(), which always append the fixture directory as
 * an argv element; it delegates to GateProcess::runRaw() instead, which
 * makes no such assumption. The assertion helpers below are this suite's own
 * thin equivalent of manifest_check()'s bash
 * siblings (manifest_accepts/manifest_rejects/manifest_reports_value), built
 * directly on PHPUnit's own trusted assertion API rather than a hand-rolled
 * grep-based counter — the same reasoning GateTestCase's own docblock gives
 * for needing no bookkeeping self-test of its five assertGate*() decisions.
 * The bash originals' own bookkeeping self-tests of THEMSELVES
 * (probe_must_carry_assertion, probe_negative_assertion, probe_crash_guard,
 * probe_reports_value) are dropped for the same reason and are not ported.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsManifestTest extends GateTestCase
{
    /**
     * The sentence manifest_check() reports when a peerDependencies caret
     * range is not satisfied by the devDependencies pin the smoke actually
     * installs — held once so every case asserting it cannot desynchronise
     * from a rewording of the gate's own message.
     */
    private const string PEER_DRIFT_SENTENCE = 'is not satisfied by the pin the smoke proves';

    /**
     * The sentence manifest_check() reports when a peerDependencies entry has
     * no devDependencies pin proving it.
     */
    private const string NO_PIN_SENTENCE = 'has no devDependencies pin proving it';

    /**
     * The sentence manifest_check() reports when engines.node is not a single
     * ">=X" floor it can verify at all (absent, unparseable, an OR-range, or
     * a syntactically-invalid-semver component) — distinct from
     * CONSUMER_ENGINES_SENTENCE, which is the floor-too-low verdict the shape
     * check's survivors still have to clear.
     */
    private const string CONSUMER_ENGINES_SHAPE_SENTENCE = 'engines.node is not a single ">=X" floor this check can verify';

    /**
     * The sentence manifest_check() reports when engines.node has the right
     * shape but its floor is below the Node version bin/check-js-config.mjs
     * needs.
     */
    private const string CONSUMER_ENGINES_SENTENCE = 'engines.node floor is below the Node version';

    /**
     * A devEngines/devDependencies/peerDependencies body that satisfies every
     * check except the one a $schema-focused case corrupts — the fixed body
     * schemaRejectionProvider()'s cases and rejectsASchemaValueThatIsAnArray()
     * share, mirroring the bash original's schema_rejects() helper.
     *
     * @var array<string, mixed>
     */
    private const array SCHEMA_CASE_PACKAGE_JSON = [
        'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
        'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
        'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
    ];

    /**
     * A byte-for-byte copy of manifest_check()'s own `node -e '...'` body
     * from the now-PHPUnit-migrated check-js-configs.sh — see this class's
     * own docblock for why it is copied verbatim rather than paraphrased.
     */
    private const string MANIFEST_CHECK_SCRIPT = <<<'JS'
const pkg = require(process.env.ROOT + "/package.json");

// The TYPE, before any shape test. `exec` and `test` call ToString on their
// argument, so a one-element array joins straight back to the string and
// satisfies a pattern the value never had.
// `grep -nE "asString[(]" tests/CheckJsConfigsManifestTest.php` lists the
// readers; the pattern wants a literal paren, which this line does not
// carry, so it cannot count itself.
// Declared above the first reader: a `const` is not hoisted, and placing it beside
// a later one has produced a TDZ error twice.
const asString = (value) => (typeof value === "string" ? value : "");

// The first-numeric-group parse both floor readers below need (devEngines and
// engines.node) — why only the first group is taken is in the comment above
// manifest_check. They part ways in more than unparseable-result handling
// now: the devEngines caller (`want`) only guards against that with
// Number.isInteger below; the engines.node caller validates the WHOLE raw
// shape before ever calling this helper, because a value can parse cleanly
// to a valid-looking but wrong floor here (the OR-range case) without being
// unparseable at all — see the shape-check comment further down.
const firstIntGroup = (value) => parseInt(asString(value).match(/(\d+)/)?.[1] ?? "", 10);

// The floor genuinely required by code THIS package ships to a consumer: why
// >=20 specifically is on the sourceContainsLoneSurrogate docblock in
// bin/check-js-config.mjs (String.prototype.isWellFormed), not restated here.
// Bump this only alongside whatever new bin/ code needs a newer runtime API —
// it tracks a different thing than devEngines.runtime.version above and the
// two are not meant to move together.
const MIN_CONSUMER_NODE = 20;

let failed = false;

// Values go on their own INFO line, never into the sentence a control asserts —
// the measurement behind that rule is at manifest_rejects, beside the filtering
// that answers it. Two mechanisms carry it: the `INFO ` prefix the filter keys on,
// and the JSON encoding that keeps a value on ONE line so the filter can reach it.
// peer-name-poison below pins all three, on the three report sites it drives.
// Declared up here for the same hoisting reason as asString above.
const encodeValue = (value) => {
    // The RESULT, not the input: JSON.stringify returns undefined for a function or
    // a symbol as well as for undefined itself, and .replaceAll() on that throws.
    // No fixture drives it and none is added: every value reaching report() is
    // JSON.parse-derived or an error message, and neither can be a function. It is
    // written this way because it costs one line and makes the prototype-chain
    // comment below true, not because the case is reachable.
    const encoded = JSON.stringify(value);

    return encoded === undefined ? "(absent)" : encoded.replaceAll("#[", "#?[");
};

const report = (sentence, values) => {
    console.error(sentence);

    for (const [label, value] of Object.entries(values)) {
        // Two guards on one line. JSON.stringify returns undefined — not a string —
        // for an absent value, and the template would then render the bareword;
        // schema-no-key reaches that. And the encoding blocks a newline or an ESC but
        // not `##[`, which the Actions runner matches UNANCHORED, so a peer name out
        // of a pull request forges a legacy workflow command from mid-line. The PHP
        // gates break the same prefix in bin/support/safe-report-value.php; this is
        // the node reporter, and it needs its own.
        console.error(`INFO     ${label}: ${encodeValue(value)}`);
    }

    failed = true;
};

const want = firstIntGroup(pkg.devEngines?.runtime?.version);
const have = parseInt(process.versions.node.split(".")[0], 10);

if (!Number.isInteger(want)) {
    console.error("package.json declares no parseable devEngines.runtime.version floor");
    process.exit(1);
}
if (have < want) {
    report("the running node is below the devEngines floor", { running: process.versions.node, floor: want });
    process.exit(1);
}

// Only a single, unambiguous ">=" lower bound is evaluated — same reasoning
// as the peerDependencies range check further down, not restated here. This
// is not a hypothetical for engines.node either:
// `>=20 || >=18` reads as floor 20 under a first-digit extraction, but the
// semver OR semantics accept the LOOSER alternative — Node 18 satisfies
// the range — which is exactly the gap this check exists to close. Verified
// with the `semver` package: `semver.satisfies("18.0.0", ">=20 || >=18")` is
// `true`. The same shape also rejects a bare version ("20") or a caret/`.x`
// range (`^20.0.0`, `20.x`) — each implies an upper bound this floor is not
// meant to carry — and rejects `*`/empty, which permits anything at all.
// Absence, a non-string, and an unparseable value all fail the same regex,
// so they collapse into this one verdict too — a fixture-verified table
// (spec-first-rule-change, #32) found no case where telling them apart
// changes what an operator should do about it.
// Each numeric component must also be canonical semver shape — no leading
// zero, and within the upper bound semver itself enforces
// (Number.MAX_SAFE_INTEGER) — rather than any digit run:
// `semver.validRange(">=020")` and
// `semver.validRange(">=99999999999999999")` both return `null`, and
// npm-install-checks checkEngine() (the function build-ideal-tree.js calls,
// referenced on the sourceContainsLoneSurrogate docblock in
// bin/check-js-config.mjs) resolves an unparseable range via
// `semver.satisfies(nodeVersion, range)` — which is `false` for EVERY node
// version against a range semver cannot parse. So a value shaped like a
// floor but outside this grammar does not go unenforced, it makes npm EBADENGINE
// fire unconditionally, for every consumer, regardless of their installed
// Node — the opposite of the floor it appears to declare.
// Held once: both arms below report on the same field, and a report() call
// site that diverges from its sibling by accident (not by design, the way
// the peer-range arms further down each carry their own distinct payload)
// is exactly the drift this file guards against elsewhere.
const declaredEnginesNode = { "declared engines.node": pkg.engines?.node };
const enginesNodeValue = asString(pkg.engines?.node);
const enginesNodeShapeOk = /^>=(0|[1-9]\d*)(\.(0|[1-9]\d*)){0,2}$/.test(enginesNodeValue)
    && enginesNodeValue.slice(2).split(".").every((part) => Number(part) <= Number.MAX_SAFE_INTEGER);

if (!enginesNodeShapeOk) {
    report(`engines.node is not a single ">=X" floor this check can verify (>=${MIN_CONSUMER_NODE} required, for String.prototype.isWellFormed())`,
        declaredEnginesNode);
    process.exit(1);
}

// The shape check above already guarantees a digit run is present, so
// firstIntGroup cannot return NaN here — unlike its other call site (`want`
// above), this one needs no `|| 0`/`Number.isInteger` fallback.
const consumerWant = firstIntGroup(pkg.engines?.node);

if (consumerWant < MIN_CONSUMER_NODE) {
    report(`engines.node floor is below the Node version bin/check-js-config.mjs requires (>=${MIN_CONSUMER_NODE}, for String.prototype.isWellFormed())`,
        declaredEnginesNode);
    process.exit(1);
}

// Every peer range must be satisfied by the pin the smoke actually proves. A
// range naming a major the pin does not carry is the interesting case; a floor
// above the pin is the mirror error and is caught by the same comparison.
const segments = (value) => {
    const parts = String(value).replace(/^[^0-9]*/, "").split(".").map((n) => parseInt(n, 10) || 0);

    return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0];
};

// Compared segment by segment as NUMBERS. A string compare of the joined form
// reads "2.10.0" as below "2.9.0", which is the direction that matters — a minor
// past nine is where the pin normally sits by the time a range is questioned.
const below = (left, right) => {
    for (let i = 0; i < 3; i += 1) {
        if (left[i] !== right[i]) {
            return left[i] < right[i];
        }
    }

    return false;
};

// This WAS the one derived list here without the non-empty anchor its siblings
// carry: removing the peerDependencies block left the loop at zero iterations,
// `failed` false, and the line at the end reporting that the ranges agree having
// compared nothing. The guard three lines down is that anchor.
const peers = Object.entries(pkg.peerDependencies ?? {});

if (peers.length === 0) {
    console.error("package.json declares no peerDependencies — the range/pin lockstep checked nothing");
    failed = true;
}

for (const [name, range] of peers) {
    // hasOwn, not a plain property read: `Object.entries` yields own keys, but the
    // lookup on the other side walks the prototype, so a peer named `constructor`
    // or `toString` resolves to an Object.prototype member. The no-pin arm below
    // then does not fire, the diagnostic names the wrong cause, and the INFO line
    // prints `(absent)` — encodeValue reads the RESULT of JSON.stringify, which is
    // undefined for a function too, so the value is reported as missing rather
    // than crashing the reporter. No apostrophe in this payload: it is single-quoted
    // in bash, and one closes the string (AGENTS.md records the trap).
    const pin = Object.hasOwn(pkg.devDependencies ?? {}, name) ? pkg.devDependencies[name] : undefined;

    if (pin === undefined) {
        report("a peerDependencies entry has no devDependencies pin proving it", { peer: name });
        continue;
    }

    // `segments()` strips everything before the first digit, so it reads `^2.5.5`
    // and `2.5.5` alike — the comparison below would then approve a devDependency
    // that is itself a range, and the smoke would install whatever that range
    // resolves to while reporting the pin as proven. The whole premise of this
    // block is "the version the smoke actually exercises", so the pin has to be
    // one version.
    if (!/^\d+\.\d+\.\d+$/.test(asString(pin))) {
        report("a devDependencies pin is not an exact version — the smoke can only prove the version it installs", { peer: name, pin });
        continue;
    }

    // Only the caret form is evaluated. The comparison below reads the FIRST
    // version and nothing else, so `>=2.5.0 <2.5.5` would be accepted on the
    // strength of its floor while the pin 2.5.5 violates its ceiling. Rejecting
    // the shape is honest; approximating a full semver range here is not, and a
    // range this package cannot check has no business being declared by it.
    if (!/^\^\d+\.\d+\.\d+$/.test(asString(range))) {
        report("a peerDependencies range is not a plain caret range — this check evaluates ^X.Y.Z only, and would otherwise accept a range it cannot verify", { peer: name, range });
        continue;
    }

    const wanted = segments(range);
    const pinned = segments(pin);

    // The npm rule is "no change to the LEFTMOST NON-ZERO element", which is three
    // cases and not two: `^1.2.3` pins the major, `^0.2.3` the minor, `^0.0.3` the
    // patch (`>=0.0.3 <0.0.4`). An all-zero range pins all three. A first version of
    // this took `wanted[0] === 0 ? 2 : 1` and accepted 0.0.9 for `^0.0.3`.
    // No peer here is below 1.0.0, which is why both zero-major arms have their own
    // fixtures: nothing in the current manifest would ever reach them.
    const boundary = wanted.findIndex((part) => part !== 0) + 1 || 3;
    const sameLine = wanted.slice(0, boundary).every((part, at) => part === pinned[at]);

    if (!sameLine || below(pinned, wanted)) {
        report("a peerDependencies range is not satisfied by the pin the smoke proves", { peer: name, range, pin });
    }
}

// The Biome version is written in four places — the devDependencies pin, the peer
// range, the README prose and this `$schema` URL — and each needs its own tie,
// because a Dependabot bump moves the pin alone. This is the tie for the `$schema`.
// Biome never fetches or validates against that URL, so a drift here
// mis-autocompletes in an editor rather than breaking a run, which is exactly why
// nothing else would ever notice it.
//
// Read with readFileSync rather than require: a base config that stops being
// valid JSON belongs to ci:test:json, and swallowing it here as a missing
// `$schema` would name the wrong cause.
const basePath = process.env.ROOT + "/biome/base.json";

// The whole URL, anchored at both ends. Matching the version anywhere in the
// string admitted every value that merely contained `schemas/<X.Y.Z>/`.
const canonicalSchema = /^https:\/\/biomejs\.dev\/schemas\/([0-9]+\.[0-9]+\.[0-9]+)\/schema\.json$/;

let schemaValue;

try {
    schemaValue = JSON.parse(require("node:fs").readFileSync(basePath, "utf8")).$schema;
} catch (error) {
    // Exit rather than fall through, as the gates above already do. The
    // arms below then need no flag to keep them from reporting a second, wrong
    // cause on top of this one — which schema-absent pins through its
    // must-not-carry argument. JSON-encoded because a V8 parse error quotes the
    // offending input, newlines and all, and a raw multi-line value would break
    // out of its INFO line into the stream a control asserts against.
    report("biome/base.json could not be read for its $schema", { "read error": error.message });
    process.exit(1);
}

// The raw value is kept beside the coerced one: asString maps an absent entry
// and a wrongly typed one alike to "", and the no-pin arm below is the one
// reader that must tell them apart.
const biomePinRaw = pkg.devDependencies?.["@biomejs/biome"];
const biomePin = asString(biomePinRaw);
const schemaVersion = canonicalSchema.exec(asString(schemaValue))?.[1] ?? null;


// Absent, null, wrongly typed, unversioned or not the published URL — a finding,
// not a skip. The previous form only compared when both sides were present, so
// deleting the key, or an editor rewriting the value to `…/schemas/latest/…`,
// turned the check off silently while the block still printed that the pins
// agree.
if (schemaVersion === null) {
    report("$schema is not the canonical https://biomejs.dev/schemas/<X.Y.Z>/schema.json", { "offending $schema value": schemaValue });
} else if (biomePinRaw === undefined) {
    report("$schema names a Biome version that no devDependencies entry pins", { "offending $schema value": schemaValue });
} else if (biomePin === "") {
    report("$schema names a Biome version whose devDependencies entry is not a version string", { "devDependencies entry": biomePinRaw });
} else if (schemaVersion !== biomePin) {
    // The one arm whose sentence carries a fixture-derived value, because
    // schema-drift asserts the version. Safe only because canonicalSchema
    // constrains that capture to digits and dots: widen the group and a $schema
    // value can supply the text another control asserts.
    report(`biome/base.json pins $schema at ${schemaVersion}, but the devDependency proving it is a different version`,
        { "devDependencies pin": biomePin });
}

if (failed) {
    process.exit(1);
}

console.log(`INFO     node ${process.versions.node} (devEngines floor >=${want}); peer ranges agree with the pins`);
JS;

    /**
     * Runs manifest_check() against $dir, the same way the bash original's
     * `ROOT="$1" node -e '...'` does: via the ROOT environment variable, not
     * an argv position — manifest_check() never reads process.argv. Delegates
     * to GateProcess::runRaw() rather than reimplementing its spawn-and-
     * capture body a third time, the same "start local, promote on second
     * real need" precedent that method's own docblock documents.
     *
     * @param string $dir The directory manifest_check() reads package.json and biome/base.json from.
     *
     * @return GateResult
     *
     * @throws ProcessStartFailedException If the process could not be started.
     * @throws ProcessTimedOutException    If the process exceeded its timeout.
     * @throws ProcessSignaledException    If the process was killed by a signal.
     */
    private function runManifestCheck(string $dir): GateResult
    {
        return (new GateProcess())->runRaw(['node', '-e', self::MANIFEST_CHECK_SCRIPT], null, ['ROOT' => $dir]);
    }

    /**
     * Writes $packageJson as package.json into this test's own fixture
     * directory, applying the same engines-default injection
     * manifest_fixture()'s node helper applies: an
     * ABSENT `engines` key gets a passing `{"node": ">=20"}` (every fixture
     * whose own point is not engines.node needs one, or it would reject for a
     * new, unintended reason the moment that requirement went live), and an
     * explicit `null` value — the one shape a PHP array literal can express
     * that "just omit the key" cannot, once a caller also wants to prove the
     * absence itself — removes the key entirely rather than keeping a literal
     * null (which would fail the same way, but for the wrong reason: this
     * class's engines-absent case names ITS failure to the SHAPE arm the same
     * way an absent key does, not to a coercion crash).
     *
     * Always targets this test's own fixture directory — there is exactly
     * one caller-visible fixture root per test, so a $dir parameter would be
     * redundant with (and could silently diverge from) $this->fixture()->path().
     *
     * @param array<string, mixed> $packageJson The package.json body, before engines-default injection.
     *
     * @return void
     */
    private function writePackageJson(array $packageJson): void
    {
        if (!array_key_exists('engines', $packageJson)) {
            $packageJson['engines'] = ['node' => '>=20'];
        } elseif ($packageJson['engines'] === null) {
            unset($packageJson['engines']);
        }

        $this->fixture()->writeJson('package.json', $packageJson);
    }

    /**
     * Writes $packageJson (engines-default injected) plus a biome/base.json
     * whose $schema is DERIVED from the devDependencies Biome pin — mirrors
     * manifest_fixture()'s auto-derivation, used by every case whose own
     * point is not the $schema check itself.
     *
     * @param array<string, mixed> $packageJson The package.json body, before engines-default injection.
     *
     * @return string The fixture directory.
     */
    private function manifestFixture(array $packageJson): string
    {
        $dir = $this->fixture()->path();
        $this->writePackageJson($packageJson);

        $pin          = $packageJson['devDependencies']['@biomejs/biome'] ?? null;
        $pinForSchema = is_string($pin) ? $pin : '0.0.0';

        $this->fixture()->writeJson('biome/base.json', ['$schema' => "https://biomejs.dev/schemas/{$pinForSchema}/schema.json"]);

        return $dir;
    }

    /**
     * Writes $packageJson (engines-default injected) plus a biome/base.json
     * whose $schema is $schemaValue VERBATIM — for the fixtures whose own
     * point is a $schema the derivation in manifestFixture() could not
     * produce. $schemaValue is typed mixed because the schema-array case
     * (rejectsASchemaValueThatIsAnArray()) proves the TYPE check, not the
     * shape one — json_encode() renders whatever JSON type it is handed.
     *
     * @param array<string, mixed> $packageJson The package.json body, before engines-default injection.
     * @param mixed                $schemaValue The raw $schema value to write, unmodified.
     *
     * @return string The fixture directory.
     */
    private function manifestFixtureWithSchema(array $packageJson, mixed $schemaValue): string
    {
        $dir = $this->fixture()->path();
        $this->writePackageJson($packageJson);
        $this->fixture()->writeJson('biome/base.json', ['$schema' => $schemaValue]);

        return $dir;
    }

    /**
     * The stream manifest_rejects() asserts against: every "INFO " line
     * removed, since those carry the offending VALUE rather than the
     * sentence a must-carry/must-not-carry check is about — ported from the
     * bash original's `grep -v '^INFO '`.
     *
     * @param string $output The gate's combined stdout+stderr text.
     *
     * @return string The stripped $output, with every "INFO " line removed.
     */
    private static function withoutInfoLines(string $output): string
    {
        $lines = explode("\n", $output);
        $kept  = array_filter($lines, static fn (string $line): bool => !str_starts_with($line, 'INFO '));

        return implode("\n", $kept);
    }

    /**
     * The clean-verdict decision: exit 0. Ported from the bash original's
     * manifest_accepts(). Kept as a real assertSame(), unlike the must-carry
     * checks below: both compared values are plain integers, so neither this
     * call's own custom message nor PHPUnit's own auto-generated
     * failure description for an integer comparison ever re-embeds raw
     * output — only the custom message text can, so that text alone is
     * scrubbed through scrubbedForDiagnostic() before being handed to
     * assertSame(), rather than replacing the real assertion with a manual
     * self::fail() (which would leave this method's own happy path
     * performing no PHPUnit assertion at all).
     *
     * @param string $dir     The directory to run manifest_check() against.
     * @param string $message An optional assertion message.
     *
     * @return void
     *
     * @throws ProcessStartFailedException If the process could not be started.
     * @throws ProcessTimedOutException    If the process exceeded its timeout.
     * @throws ProcessSignaledException    If the process was killed by a signal.
     */
    private function assertManifestAccepts(string $dir, string $message = ''): void
    {
        $result = $this->runManifestCheck($dir);

        self::assertSame(
            0,
            $result->exitCode,
            self::messageOrDefault($message, 'Rejected.', $result->output),
        );
    }

    /**
     * The exit-code/degraded pair assertManifestRejects() and
     * assertManifestReportsValue() both open with: exit non-zero, NOT a crash
     * (manifest_check() exits 1 to reject and Node exits 1 on an uncaught
     * throw alike, so the exit code alone cannot tell them apart —
     * GateResult::isDegraded() carries the same discriminator the bash
     * original's manifest_crashed() does). Kept as real assertNotSame()/
     * assertFalse() calls, the same reasoning assertManifestAccepts() above
     * gives: $result->exitCode is an integer and $result->isDegraded() a
     * bool, so PHPUnit's own auto-generated failure description for either
     * comparison never re-embeds raw output — only each call's own custom
     * message can, so that text is scrubbed through scrubbedForDiagnostic()
     * rather than the real assertion being replaced with a manual
     * self::fail(), which would leave every caller's happy path (a genuine
     * rejection) performing no PHPUnit assertion of its own until the
     * must-carry check further down.
     *
     * @param GateResult $result  The captured run to check.
     * @param string     $message An optional assertion message shared by both checks.
     *
     * @return void
     */
    private static function assertManifestRanAndRejected(GateResult $result, string $message = ''): void
    {
        self::assertNotSame(
            0,
            $result->exitCode,
            self::messageOrDefault($message, 'Accepted, so the check does not discriminate.', $result->output),
        );

        self::assertFalse(
            $result->isDegraded(),
            self::messageOrDefault($message, 'The gate did not run, it died.', $result->output),
        );
    }

    /**
     * The drift-verdict decision: exit non-zero, NOT a crash — see
     * assertManifestRanAndRejected() above, which this method opens with —
     * and the report — with every "INFO " value line removed — carries
     * $mustCarry and, when given, does not carry $mustNotCarry. Ported from
     * the bash original's manifest_ran()/manifest_rejects().
     *
     * @param string      $dir          The directory to run manifest_check() against.
     * @param string      $mustCarry    The substring the filtered report must carry.
     * @param string|null $mustNotCarry A substring the filtered report must NOT carry, or null to skip that check.
     * @param string      $message      An optional assertion message.
     *
     * @return void
     *
     * @throws ProcessStartFailedException If the process could not be started.
     * @throws ProcessTimedOutException    If the process exceeded its timeout.
     * @throws ProcessSignaledException    If the process was killed by a signal.
     */
    private function assertManifestRejects(string $dir, string $mustCarry, ?string $mustNotCarry = null, string $message = ''): void
    {
        $result = $this->runManifestCheck($dir);

        self::assertManifestRanAndRejected($result, $message);

        $asserted = self::withoutInfoLines($result->output);

        // Both containment checks below are a manual str_contains() +
        // self::fail(), never assertStringContainsString()/
        // assertStringNotContainsString(): $asserted is exactly the value a
        // poisoned fixture can carry (see
        // aPeerNamePinOrRangeCannotSupplyTheTextAnotherControlAsserts()
        // further down, which drives this method with a peer name/pin/range
        // deliberately carrying another control's own sentence), and
        // PHPUnit's own Constraint::fail()/failureDescription() mechanism
        // unconditionally re-embeds the FULL, RAW haystack of a
        // failed call into the thrown exception's own message — see
        // tests/CheckJsConfigsTest.php's own
        // assertMessageDoesNotForgeWorkflowCommand() docblock for the dated
        // observation, not repeated here — so a real failure of either
        // constraint here would forge, in PHPUnit's own failure output, the
        // very annotation this gate's own tests exist to prove is prevented.
        if (!str_contains($asserted, $mustCarry)) {
            self::fail(self::messageWithOutput($message, 'Rejected, but not for the tested reason.', $asserted));
        }

        if (($mustNotCarry !== null) && str_contains($asserted, $mustNotCarry)) {
            self::fail(self::messageWithOutput($message, 'Reported a second, wrong cause as well.', $asserted));
        }
    }

    /**
     * The must-carry check against the UNFILTERED stream — unlike
     * assertManifestRejects(), which strips every "INFO " line before it
     * looks. Ported from the bash original's manifest_reports_value(), which
     * exists because the offending value itself lives on an INFO line: the
     * property under test is that the value reaches the operator at all, not
     * merely that some sentence does. Opens with the same
     * assertManifestRanAndRejected() precondition assertManifestRejects()
     * above does; the must-carry check itself is a manual in_array() +
     * self::fail(), never assertContains() — for the identical reason those
     * two methods' own docblocks give: $result->output is exactly the value a
     * poisoned fixture can carry (the peerDependencies-name fixture further
     * down drives this method with a genuine `##[error]forged` value), and a
     * real failure of assertContains() would re-embed it raw into PHPUnit's
     * own failure output.
     *
     * @param string $dir       The directory to run manifest_check() against.
     * @param string $exactLine The exact line the report must carry.
     * @param string $message   An optional assertion message.
     *
     * @return void
     *
     * @throws ProcessStartFailedException If the process could not be started.
     * @throws ProcessTimedOutException    If the process exceeded its timeout.
     * @throws ProcessSignaledException    If the process was killed by a signal.
     */
    private function assertManifestReportsValue(string $dir, string $exactLine, string $message = ''): void
    {
        $result = $this->runManifestCheck($dir);

        self::assertManifestRanAndRejected($result, $message);

        if (!in_array($exactLine, explode("\n", $result->output), true)) {
            self::fail(self::messageWithOutput($message, 'The offending value never reached the operator.', $result->output));
        }
    }

    /**
     * This repository's own package.json and biome/base.json, proven end to
     * end — every fixture-driven case below only proves a REJECT path;
     * without this, every one of them could be tightened into rejecting
     * everything and still pass.
     */
    #[Test]
    public function canonManifestIsAccepted(): void
    {
        // The repository root whose own real package.json/biome/base.json this
        // method proves against, not a synthetic fixture.
        $this->assertManifestAccepts(self::root());
    }

    /**
     * A devEngines.runtime.version floor above the running Node.
     */
    #[Test]
    public function rejectsADevEnginesFloorAboveTheRunningNode(): void
    {
        $dir = $this->manifestFixture([
            'devEngines' => ['runtime' => ['name' => 'node', 'version' => '>=999']],
        ]);

        $this->assertManifestRejects($dir, 'below the devEngines floor');
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: string}>
     */
    public static function enginesNodeRejectionProvider(): array
    {
        return [
            'engines.node absent entirely'                                          => [null, self::CONSUMER_ENGINES_SHAPE_SENTENCE],
            'engines.node has no parseable digits'                                  => [['node' => 'latest'], self::CONSUMER_ENGINES_SHAPE_SENTENCE],
            'engines.node is an OR-range whose loosest alternative is unsupported'  => [['node' => '>=20 || >=18'], self::CONSUMER_ENGINES_SHAPE_SENTENCE],
            'engines.node floor is below what bin/check-js-config.mjs needs'        => [['node' => '>=18'], self::CONSUMER_ENGINES_SENTENCE],
            'engines.node has a leading-zero numeric component'                     => [['node' => '>=020'], self::CONSUMER_ENGINES_SHAPE_SENTENCE],
            "engines.node has a component past semver's own MAX_SAFE_INTEGER bound" => [['node' => '>=99999999999999999'], self::CONSUMER_ENGINES_SHAPE_SENTENCE],
        ];
    }

    /**
     * The six engines.node shapes manifest_check() must reject — either
     * before the floor comparison even runs (the shape check) or at the
     * floor comparison itself.
     *
     * @param array<string, mixed>|null $engines          The `engines` fragment, or null for an absent key.
     * @param string                    $expectedSentence The sentence the rejection must carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('enginesNodeRejectionProvider')]
    public function rejectsAMalformedOrTooLowEnginesNodeFloor(?array $engines, string $expectedSentence): void
    {
        $dir = $this->manifestFixture([
            'devEngines' => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'engines'    => $engines,
        ]);

        $this->assertManifestRejects($dir, $expectedSentence);
    }

    /**
     * A peer range naming another major than the pin.
     */
    #[Test]
    public function rejectsAPeerRangeNamingAnotherMajorThanThePin(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^1.9.0'],
        ]);

        $this->assertManifestRejects($dir, self::PEER_DRIFT_SENTENCE);
    }

    /**
     * The offending pin reaches the operator on its own INFO line — the same
     * fixture as rejectsAPeerRangeNamingAnotherMajorThanThePin(), read
     * through the unfiltered-stream assertion instead.
     */
    #[Test]
    public function reportsThePeerMajorDriftPinOnItsOwnInfoLine(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^1.9.0'],
        ]);

        $this->assertManifestReportsValue($dir, 'INFO     pin: "2.5.5"');
    }

    /**
     * The only accept case that DISCRIMINATES a numeric below() from a string
     * compare of the joined form: a pin whose minor is past nine is exactly
     * what a string compare gets wrong ("2.10.0" sorts below "2.9.0").
     */
    #[Test]
    public function acceptsAPeerPinWhoseMinorIsPastNine(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.10.0'],
            'peerDependencies' => ['@biomejs/biome' => '^2.9.0'],
        ]);

        $this->assertManifestAccepts($dir);
    }

    /**
     * The dotted-floor accept path, one dot component: the shape regex's
     * quantifier must accept a major.minor floor, not just the bare-major
     * form every other case here uses (via manifestFixture()'s injected
     * ">=20").
     */
    #[Test]
    public function acceptsAnEnginesNodeFloorWithAMinorComponent(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
            'engines'          => ['node' => '>=20.1'],
        ]);

        $this->assertManifestAccepts($dir);
    }

    /**
     * The dotted-floor accept path, two dot components (major.minor.patch).
     */
    #[Test]
    public function acceptsAnEnginesNodeFloorWithMajorMinorPatchComponents(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
            'engines'          => ['node' => '>=20.0.0'],
        ]);

        $this->assertManifestAccepts($dir);
    }

    /**
     * No devEngines floor at all.
     */
    #[Test]
    public function rejectsAManifestWithNoDevEnginesFloor(): void
    {
        $dir = $this->manifestFixture([
            'devDependencies' => ['@biomejs/biome' => '2.5.5'],
        ]);

        $this->assertManifestRejects($dir, 'no parseable devEngines');
    }

    /**
     * The floor reader's coercion: an array devEngines.runtime.version would
     * ToString back into a value that satisfies a digit-run pattern (asString
     * exists against exactly this), so this fixture also carries a peer and a
     * pin that agree, or the run would reject on an unrelated anchor instead
     * of the one under test.
     */
    #[Test]
    public function rejectsADevEnginesFloorThatIsNotAString(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => ['>=1']]],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
        ]);

        $this->assertManifestRejects($dir, 'no parseable devEngines');
    }

    /**
     * Caret semantics below 1.0.0 where the MINOR is the compatibility
     * boundary: `^0.2.0` is `>=0.2.0 <0.3.0`, so the pin 0.3.0 does not
     * satisfy it. No peer in the real manifest is below 1.0.0, so this arm
     * has no other way to be reached.
     */
    #[Test]
    public function rejectsAZeroMajorPinPastTheCaretMinor(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '0.3.0'],
            'peerDependencies' => ['@biomejs/biome' => '^0.2.0'],
        ]);

        $this->assertManifestRejects($dir, self::PEER_DRIFT_SENTENCE);
    }

    /**
     * The third caret case, major and minor both zero, where the PATCH is the
     * boundary: `^0.0.3` is `>=0.0.3 <0.0.4`.
     */
    #[Test]
    public function rejectsAZeroMajorZeroMinorPinPastTheCaretPatch(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '0.0.9'],
            'peerDependencies' => ['@biomejs/biome' => '^0.0.3'],
        ]);

        $this->assertManifestRejects($dir, self::PEER_DRIFT_SENTENCE);
    }

    /**
     * The accepting twin of rejectsAZeroMajorZeroMinorPinPastTheCaretPatch():
     * a pin exactly at the caret patch boundary.
     */
    #[Test]
    public function acceptsAZeroMajorZeroMinorPinAtTheCaretPatch(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '0.0.3'],
            'peerDependencies' => ['@biomejs/biome' => '^0.0.3'],
        ]);

        $this->assertManifestAccepts($dir);
    }

    /**
     * The accepting twin of rejectsAZeroMajorPinPastTheCaretMinor(): a pin
     * inside the caret minor range, so the arm above cannot pass by rejecting
     * every 0.x range.
     */
    #[Test]
    public function acceptsAZeroMajorPinInsideTheCaretMinorRange(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '0.2.7'],
            'peerDependencies' => ['@biomejs/biome' => '^0.2.0'],
        ]);

        $this->assertManifestAccepts($dir);
    }

    /**
     * The floor-vs-pin half of the peer check, which no OTHER fixture here
     * reaches: peer-major-drift short-circuits on the major, and every other
     * case's major already agrees.
     */
    #[Test]
    public function rejectsAPeerFloorAboveThePinSameMajor(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.9.0'],
        ]);

        $this->assertManifestRejects($dir, self::PEER_DRIFT_SENTENCE);
    }

    /**
     * A peerDependencies range shape this check cannot evaluate (not a plain
     * caret range) — reported, not assumed satisfied.
     */
    #[Test]
    public function rejectsAPeerRangeThatIsNotAPlainCaretRange(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '>=2.5.0 <2.5.5'],
        ]);

        $this->assertManifestRejects($dir, 'is not a plain caret range');
    }

    /**
     * A peer with no devDependencies pin proving it.
     */
    #[Test]
    public function rejectsAPeerWithNoDevDependenciesPin(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
        ]);

        $this->assertManifestRejects($dir, self::NO_PIN_SENTENCE);
    }

    /**
     * The same "no pin" arm, reached by a peer whose NAME is an
     * Object.prototype member (`constructor`) — a plain property read would
     * resolve it through the prototype and report the WRONG cause instead
     * ("is not an exact version"), which is what the must-not-carry check
     * proves stays absent.
     */
    #[Test]
    public function rejectsAPeerNamedAfterAnObjectPrototypeMemberAsUnpinnedNotMistyped(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0', 'constructor' => '^1.0.0'],
        ]);

        $this->assertManifestRejects($dir, self::NO_PIN_SENTENCE, 'is not an exact version');
    }

    /**
     * @return array<string, mixed>
     */
    private function peerPinArrayPackageJson(): array
    {
        return [
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => ['2.5.5']],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
        ];
    }

    /**
     * The ToString coercion asString() exists against: `["2.5.5"]`
     * stringifies back to a valid version and would slip the exact-version
     * gate without it.
     */
    #[Test]
    public function rejectsADevDependenciesPinThatIsAnArrayAsNotAnExactVersion(): void
    {
        $dir = $this->manifestFixture($this->peerPinArrayPackageJson());

        $this->assertManifestRejects($dir, 'is not an exact version');
    }

    /**
     * A second control on the same fixture: a pin that EXISTS but is not a
     * version string is named as that, not as absent — without it, the
     * $schema type arm could be deleted outright and nothing here would
     * redden.
     */
    #[Test]
    public function namesAnArrayPinAsNotAVersionStringRatherThanAbsent(): void
    {
        $dir = $this->manifestFixture($this->peerPinArrayPackageJson());

        $this->assertManifestRejects($dir, 'is not a version string');
    }

    /**
     * A peerDependencies range that is an array rather than a string.
     */
    #[Test]
    public function rejectsAPeerRangeThatIsAnArray(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => ['^2.5.0']],
        ]);

        $this->assertManifestRejects($dir, 'is not a plain caret range');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function schemaRejectionProvider(): array
    {
        return [
            '$schema lags the pin'                              => ['https://biomejs.dev/schemas/2.4.0/schema.json', 'pins $schema at 2.4.0'],
            '$schema names no X.Y.Z version at all'             => ['https://biomejs.dev/schemas/latest/schema.json', 'is not the canonical'],
            '$schema served from a foreign host'                => ['https://example.invalid/schemas/2.5.5/schema.json', 'is not the canonical'],
            '$schema served over plain http'                    => ['http://biomejs.dev/schemas/2.5.5/schema.json', 'is not the canonical'],
            '$schema on the right host, wrong path'             => ['https://biomejs.dev/x/2.5.5/schema.json', 'is not the canonical'],
            '$schema filename is not schema.json'               => ['https://biomejs.dev/schemas/2.5.5/config.json', 'is not the canonical'],
            '$schema carries a pasted leading space'            => [' https://biomejs.dev/schemas/2.5.5/schema.json', 'is not the canonical'],
            '$schema carries trailing content past schema.json' => ['https://biomejs.dev/schemas/2.5.5/schema.json.evil', 'is not the canonical'],
        ];
    }

    /**
     * Every $schema shape that must be reported, driven against the same
     * fixed devEngines/devDependencies/peerDependencies body — proven to
     * accept on its own by canonManifestIsAccepted() and the accept cases
     * above, so a case here that trips one of those gates first would never
     * reach the $schema check at all.
     *
     * @param string $schemaValue      The $schema value to write into biome/base.json.
     * @param string $expectedSentence The sentence the rejection must carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('schemaRejectionProvider')]
    public function rejectsANonCanonicalSchemaValue(string $schemaValue, string $expectedSentence): void
    {
        $dir = $this->manifestFixtureWithSchema(self::SCHEMA_CASE_PACKAGE_JSON, $schemaValue);

        $this->assertManifestRejects($dir, $expectedSentence);
    }

    /**
     * The TYPE, not the shape — a $schema array whose single element
     * stringifies to the canonical URL must still be reported: asString()
     * coerces a non-string to "", which the canonical-URL regex never
     * matches.
     */
    #[Test]
    public function rejectsASchemaValueThatIsAnArray(): void
    {
        $dir = $this->manifestFixtureWithSchema(self::SCHEMA_CASE_PACKAGE_JSON, ['https://biomejs.dev/schemas/2.5.5/schema.json']);

        $this->assertManifestRejects($dir, 'is not the canonical');
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaNoKeyPackageJson(): array
    {
        return [
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
        ];
    }

    /**
     * A base config with no $schema key at all — the one shape that reaches
     * the report with an ABSENT value.
     */
    #[Test]
    public function rejectsABaseConfigWithNoSchemaKeyAtAll(): void
    {
        $dir = $this->manifestFixture($this->schemaNoKeyPackageJson());
        $this->fixture()->writeJson('biome/base.json', ['note' => 'no $schema here']);

        $this->assertManifestRejects($dir, 'is not the canonical');
    }

    /**
     * The same fixture, read through the value assertion: an absent $schema
     * must reach the operator AS absent, not as the bareword "undefined" a
     * naive template render would otherwise produce.
     */
    #[Test]
    public function reportsAnAbsentSchemaValueAsAbsentNotAsTheWordUndefined(): void
    {
        $dir = $this->manifestFixture($this->schemaNoKeyPackageJson());
        $this->fixture()->writeJson('biome/base.json', ['note' => 'no $schema here']);

        $this->assertManifestReportsValue($dir, 'INFO     offending $schema value: (absent)');
    }

    /**
     * The legacy `##[` grammar, which the JSON encoding does not break: the
     * GitHub Actions runner finds that prefix unanchored, so a peer name
     * carrying it forges a command from mid-line — asserted through the
     * unfiltered-stream helper, since the value lives on an INFO line.
     */
    #[Test]
    public function aPeerNameCannotForgeALegacyWorkflowCommand(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0', '##[error]forged' => '^1.0.0'],
        ]);

        $this->assertManifestReportsValue($dir, 'INFO     peer: "##?[error]forged"');
    }

    /**
     * @return array<string, mixed>
     */
    private function peerNamePoisonPackageJson(): array
    {
        $poisoned = "x\n" . self::PEER_DRIFT_SENTENCE;

        return [
            'devEngines'      => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies' => [
                '@biomejs/biome' => '2.5.5',
                'poison-pin'     => $poisoned,
                'poison-range'   => '2.0.0',
            ],
            'peerDependencies' => [
                '@biomejs/biome' => '^2.5.0',
                'poison-pin'     => '^2.0.0',
                'poison-range'   => $poisoned,
                $poisoned        => '^1.0.0',
            ],
        ];
    }

    /**
     * The oracle's own controls: a peer name, its pin and its range are all
     * poisoned with an embedded newline carrying the peer-drift sentence
     * verbatim — a peer name/pin/range must not be able to supply the text
     * another control asserts. One fixture is enough because report() is one
     * function: the other value routes reach the same two lines.
     */
    #[Test]
    public function aPeerNamePinOrRangeCannotSupplyTheTextAnotherControlAsserts(): void
    {
        $dir = $this->manifestFixture($this->peerNamePoisonPackageJson());

        $this->assertManifestRejects($dir, self::NO_PIN_SENTENCE, self::PEER_DRIFT_SENTENCE);
    }

    /**
     * The must-carry direction the absence check above cannot cover: it only
     * proves the forbidden sentence stays OUT, which an encoder that redacts
     * or mis-escapes a newline-bearing value would satisfy just as well as a
     * correct one. This asserts the REAL encoder's own INFO line: the
     * poisoned peer name reaches the report exactly as JSON.stringify renders
     * it — a literal two-character `\n` escape, not an actual line break —
     * proving the encoder actually ran on this value rather than merely not
     * leaking it.
     */
    #[Test]
    public function thePoisonedPeerNameReachesTheReportCorrectlyEncoded(): void
    {
        $dir = $this->manifestFixture($this->peerNamePoisonPackageJson());

        $this->assertManifestReportsValue($dir, 'INFO     peer: "x\n' . self::PEER_DRIFT_SENTENCE . '"');
    }

    /**
     * A canonical $schema whose version no devDependencies pin proves — the
     * body differs from every other fixture here so this arm is the fixture's
     * ONLY cause (rejectsAPeerWithNoDevDependenciesPin() reaches the same
     * "no pin" arm, but alongside a peer gap).
     */
    #[Test]
    public function rejectsACanonicalSchemaThatNoDevDependenciesPinProves(): void
    {
        $dir = $this->manifestFixtureWithSchema(
            [
                'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
                'devDependencies'  => ['typescript' => '7.0.2'],
                'peerDependencies' => ['typescript' => '^7.0.0'],
            ],
            'https://biomejs.dev/schemas/9.9.9/schema.json',
        );

        $this->assertManifestRejects($dir, 'names a Biome version that no devDependencies entry pins');
    }

    /**
     * A base config that cannot be read at all — reported, and not ALSO as a
     * missing $schema (the must-not-carry check is what makes the catch's
     * process.exit(1) measurable: without it, this arm would report a
     * second, wrong cause on top of its own).
     */
    #[Test]
    public function rejectsAnUnreadableBaseConfigAndNotAlsoAsAMissingSchema(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies'  => ['@biomejs/biome' => '2.5.5'],
            'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
        ]);

        unlink($dir . '/biome/base.json');

        $this->assertManifestRejects($dir, 'could not be read for its', 'is not the canonical');
    }

    /**
     * Zero peerDependencies must not read as "every peer range agrees" — the
     * non-empty anchor every other derived list in this gate carries.
     */
    #[Test]
    public function rejectsAManifestDeclaringNoPeerDependencies(): void
    {
        $dir = $this->manifestFixture([
            'devEngines'      => ['runtime' => ['name' => 'node', 'version' => '>=24']],
            'devDependencies' => ['@biomejs/biome' => '2.5.5'],
        ]);

        $this->assertManifestRejects($dir, 'declares no peerDependencies');
    }

    /**
     * A devDependency that is itself a range rather than an exact version:
     * segments() strips everything before the first digit, so `^2.5.5` and
     * `2.5.5` would otherwise compare identically, approving a range this
     * check cannot actually vouch for.
     */
    #[Test]
    public function rejectsADevDependencyRangeStandingInForAPin(): void
    {
        $dir = $this->manifestFixtureWithSchema(
            [
                'devEngines'       => ['runtime' => ['name' => 'node', 'version' => '>=24']],
                'devDependencies'  => ['@biomejs/biome' => '^2.5.5'],
                'peerDependencies' => ['@biomejs/biome' => '^2.5.0'],
            ],
            'https://biomejs.dev/schemas/2.5.5/schema.json',
        );

        $this->assertManifestRejects($dir, 'is not an exact version');
    }

    /**
     * A crash is not a verdict, and the exit code cannot say which it was:
     * this gate exits 1 to reject and Node exits 1 on an uncaught `require()`
     * failure alike. A package.json that is not JSON at all makes the
     * program's own `require` throw before any check runs — this must be
     * reported as a crash (GateResult::isDegraded()), not read as a clean
     * accept or a genuine drift verdict.
     */
    #[Test]
    public function reportsACrashRatherThanAVerdictOnAMalformedPackageJson(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/package.json', "not json at all\n");
        $this->fixture()->writeJson('biome/base.json', ['$schema' => 'https://biomejs.dev/schemas/2.5.5/schema.json']);

        $result = $this->runManifestCheck($dir);

        self::assertTrue(
            $result->isDegraded(),
            self::diagnosticMessage('A malformed package.json must crash the gate, not silently produce a verdict.', $result->output),
        );

        if (!str_contains($result->output, 'is not valid JSON')) {
            self::fail(self::diagnosticMessage('The crash diagnostic did not report the expected malformed-JSON reason.', $result->output));
        }
    }
}
