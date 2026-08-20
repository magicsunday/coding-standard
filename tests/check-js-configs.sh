#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Consumer smoke for the JS/TS configs, the analogue of the PHP consumer smoke:
# it packs this package the way npm ships it (so the `files` allow-list is
# exercised, not the working tree), installs it into a throwaway project and
# runs Biome and tsc against the shared configs.
#
# The control runs matter as much as the green ones. `biome ci` FAILS CLOSED on
# an unparseable config, but so does a real finding — a smoke that only asserts
# "clean source passes" would have stayed green while the config was unloadable.
# The negative cases prove the configs are actually in force.

set -euo pipefail

# `CDPATH= cd --` because CI invokes this as `bash tests/check-js-configs.sh`:
# the cd target is then `tests/..`, which starts with neither `/`, `./` nor
# `../` and is therefore searched in CDPATH. An exported CDPATH both redirects
# it to a foreign tree and prints the resolved path, so $root would become a
# two-line value pointing at the wrong checkout — which npm pack would then
# pack. Same for $work: mktemp honours a relative TMPDIR verbatim, and every
# "$work/…" below is used after the cd, so it has to be absolute up front.
root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$root/tests/harness.sh"
harness_workdir

# safe_report <value>
#
# The bash-side counterpart of encodeValue() below, for the report sites that are
# printf and not node. Everything this file echoes about the package manifest, the
# tarball or the shared configs is pull-request content in this repository's own
# `pull_request` job, and the runner scans the job log for workflow commands: a
# devDependency name carrying a newline put a forged `::error::` at column 0, and a
# `files` entry carrying `##[` forged the legacy form mid-line. Both reproduced.
#
# Newlines to `?` first, because a value that cannot start a line cannot start a
# command; then the remaining control bytes and DEL; then the legacy prefix broken
# the way bin/support/safe-report-value.php breaks it, for the reason stated there.
safe_report() {
    printf '%s' "$1" | tr '\n\r' '??' | tr -d '\000-\010\013\014\016-\037\177' | sed 's/#\[/#?[/g'
}

pass() { printf 'OK       %s\n' "$1"; }

# The optional second argument is a log to excerpt, so each failure carries its
# own diagnostic instead of leaving the CI log with a bare FAILED line.
fail() {
    printf 'FAILED   %s\n' "$1" >&2
    fails=$((fails + 1))

    if [ "$#" -gt 1 ]; then
        # The one report route in this file that reaches COLUMN 0. Every other line
        # carries a constant prefix (`OK       `, `FAILED   `, `INFO     `), so a value
        # inside it cannot open a line; a tool's log is printed as it came. Biome
        # echoes an unknown config key verbatim, newline included, so a key spelled
        # "\n::error::forged" in a consumer's biome.json arrives at column 0 — measured
        # against the real binary.
        #
        # Not the same scrub as safe_report, and deliberately: the excerpt is
        # multi-line by design, so newlines stay. What has to go instead is the
        # sequence at the START of a line, plus CR — the runner reads with
        # StreamReader.ReadLine(), which treats a bare CR as a line break, and the
        # C0 class below keeps 13 on purpose so a CRLF log stays readable.
        sed -n '1,40p' "$2" \
            | tr '\r' '?' \
            | tr -d '\000-\010\013\014\016-\037\177' \
            | sed -e 's/#\[/#?[/g' -e 's/^[[:space:]]*::/  ?::/' >&2
    fi
}

# `fail` is this harness's only reporter, so one call proves the chain. The
# shared probe drives it in a subshell and asserts the counter rose; without that,
# a lost increment would degrade every control into a print statement and the run
# would say FAILED on every line while exiting 0.
probe_reporters() {
    fail 'bookkeeping self-test'
}

harness_probe_reporters 1 probe_reporters

# The gate's own output, asserted the way the three PHP suites assert theirs.
#
# This file had no such control and twenty report sites; the PHP side has roughly
# twenty controls between three gates. Both halves of the gap were reachable, and both
# were reproduced end to end: a devDependency name forged a `::error::` at column 0
# through the tools line, and a `files` entry forged the legacy `##[` form.
#
# Two designs were tried before this one, and both belong in the record because they
# read as coverage while providing none. A source grep enumerating the four variable
# names known to be tainted was blind by construction — the two holes that survived it
# were in DERIVED-LIST loops, which bring their own loop variables (`$documented` off
# README prose, `$format` off a JSON array). Inverting it to flag every interpolation
# and subtract the safe ones turned into a `sed` parser: it cannot tell a message from
# a redirect target or a log-path argument, and reported nineteen sites of which
# thirteen were neither.
#
# The property is about the OUTPUT, so it is asserted on the output: the run must carry
# neither grammar nor an ESC, which is what GitHub Actions and a terminal key on.
#
# What it does NOT do, stated because two earlier designs claimed coverage they did not
# have and this one must not: it drives the report HELPERS over a poisoned value, not
# every call site. A new report line added without safe_report is invisible to it —
# measured, stripping safe_report from three real sites leaves this green. The PHP side
# has the right shape (harness_report_is_inert runs the real binary over a real
# fixture); doing the same here needs the smoke's loops driven over a poisoned $root,
# which is tracked rather than bolted on. What this pins is the helpers, the encoder
# and the log-excerpt route, each in both directions.
harness_probe_report_inertness() {
    local poisoned forged out
    poisoned="$(mktemp -d)"
    # Every byte class the two scrubs handle, so dropping any one of them is visible:
    # a newline (opens a line), a CR (opens a line to the runner, invisible to grep),
    # both command grammars, and an ESC.
    forged="$(printf 'x\n::error title=pwned::forged ##[error]legacy \033[2K\rcr')"

    FORGED="$forged" node -e '
const fs = require("node:fs");
const forged = process.env.FORGED;
const dir = process.argv[1];

fs.writeFileSync(dir + "/package.json", JSON.stringify({
    name: "poisoned",
    files: ["biome", forged],
    devDependencies: { [forged]: "1.0.0" },
    peerDependencies: { [forged]: "^1.0.0" },
}));
' "$poisoned"

    # Only the value-reporting helpers are driven, not the whole gate: the point is the
    # report shape, and a full run needs a registry. Each call is the real function.
    out="$(
        {
            printf 'INFO     tools under test: %s\n' "$(safe_report "$forged")"
            pass "declared and packed: $(safe_report "$forged")"
            fail "declared in package.json \"files\" but absent from the tarball: $(safe_report "$forged")"
            fail "templates/jscpd.json names the format \"$(safe_report "$forged")\", which this smoke has no fixture extension for" 
            fail "README documents $(safe_report "$forged") $(safe_report "$forged") but package.json pins $(safe_report "$forged")"
            fail "biome/base.json maps the .$(safe_report "$forged") extension, which this smoke has no proven target for"
            printf '%s\n' 'x' '    ::error::forgedByATool' 'mid ##[error]legacyFromATool' "$(printf 'cr\rforged')" > "$poisoned/tool.log"
            fail 'a tool rejected the fixture' "$poisoned/tool.log"
            ROOT="$poisoned" node -e '
const pkg = require(process.env.ROOT + "/package.json");
const encodeValue = (value) => {
    const encoded = JSON.stringify(value);

    return encoded === undefined ? "(absent)" : encoded.replaceAll("#[", "#?[");
};
for (const [name, range] of Object.entries(pkg.peerDependencies)) {
    console.error("a peerDependencies range is not satisfied by the pin the smoke proves");
    console.error(`INFO     peer: ${encodeValue(name)}   range: ${encodeValue(range)}`);
}'
        } 2>&1
    )"

    rm -rf -- "$poisoned"

    if grep -qE '^[[:space:]]*::[A-Za-z0-9_-]+' <<<"$out"; then
        fail "bookkeeping self-test — a consumer value forged a \`::\` workflow command at line start"
    fi

    if grep -qF -- '##[' <<<"$out"; then
        fail "bookkeeping self-test — a consumer value forged a legacy \`##[…]\` workflow command"
    fi

    if grep -q "$(printf '\033')" <<<"$out"; then
        fail "bookkeeping self-test — an ANSI escape from a consumer value reached the report"
    fi

    # CR is its own line break to the runner: it reads child output with
    # StreamReader.ReadLine(), which ends a line on LF, CR, or CRLF. The three arms
    # above cannot see it, because grep splits on LF only — so a bare CR opens a line
    # they never examine, and dropping either scrub's CR handling was invisible.
    if grep -q "$(printf '\r')" <<<"$out"; then
        fail "bookkeeping self-test — a bare carriage return reached the report, which opens a line to the runner"
    fi

    # The accepting direction, one per ROUTE. A single must-carry over the union is
    # satisfied by any one of them, so retiring the others would go unnoticed — the
    # inert-by-omission shape this family keeps producing.
    if ! grep -qF -- 'tools under test: x?::error' <<<"$out"; then
        fail "bookkeeping self-test — the bash report route printed no scrubbed payload"
    fi

    # The node encoder ESCAPES the newline rather than translating it — `\n`, two
    # characters — which is its containment mechanism and a different one from the bash
    # side's `?`. Asserting the bash spelling here was wrong and this arm caught it.
    if ! grep -qF -- 'INFO     peer: "x\n::error' <<<"$out"; then
        fail "bookkeeping self-test — the node encoder printed no scrubbed payload"
    fi

    if ! grep -qF -- '  ?::error::forgedByATool' <<<"$out"; then
        fail "bookkeeping self-test — the log-excerpt route printed no scrubbed payload"
    fi
}

harness_probe_report_inertness

# See the sibling harnesses: the bar is derived, not remembered.
harness_assert_no_stray_increments 1

# One definition per tool. A control only proves anything if it runs the exact
# invocation the green run does, and a copy-paste only promises that.
biome_ci() { npx --no-install biome ci --error-on-warnings --colors=off . >"$1" 2>&1; }
run_tsc()  { npx --no-install tsc -p tsconfig.json >"$1" 2>&1; }

# --- pack and install exactly as a consumer receives the package -------------

# Two failure shapes, and `|| true` is what lets the guard below see both. A pack
# that FAILS makes the pipeline non-zero under pipefail, and a plain assignment
# would abort the script one line before the guard that names the cause. A pack
# that SUCCEEDS can still come back empty — npm writes the tarball name to stdout,
# and `--silent`, which stood here until 725e6cf, silenced that too — `set -e` never catches it;
# the install would then be handed a directory and fail as a misleading
# "check package.json files". `--loglevel=error` keeps npm's own diagnosis, which
# is the only one either shape produces.
tarball="$(cd "$root" && npm pack --pack-destination "$work" --loglevel=error | tail -n1)" || true

if [ -z "$tarball" ] || [ ! -f "$work/$tarball" ]; then
    fail "npm pack produced no tarball — cannot run the smoke"
    exit 1
fi

cd "$work"
npm init -y >/dev/null 2>&1

# The tools come from the root devDependencies, which are pinned exactly. An
# unpinned `npm install @biomejs/biome` would resolve to whatever is newest at
# the moment CI runs, so a release on the tool's side could red the build on a
# day nothing changed here — and worse, a green run would not say which version
# it proved. Dependabot bumps the pins; this smoke is what vets the bump.
# `|| true` so the guard below can report: with the key ABSENT rather than empty,
# Object.entries(undefined) throws and node exits 1, which under `set -e` would
# abort the script at the assignment and leave the CI log with a node stack trace
# instead of this script's own diagnostic.
tools="$(ROOT="$root" node -e 'const d=require(process.env.ROOT + "/package.json").devDependencies;
process.stdout.write(Object.entries(d).map(([n, v]) => n + "@" + v).join(" "))' 2>/dev/null)" || true

if [ -z "$tools" ]; then
    fail "no devDependencies in package.json — nothing to pin the smoke to"
    exit 1
fi

printf 'INFO     tools under test: %s\n' "$(safe_report "$tools")"

# Enforce the devEngines floor rather than documenting it. Why the REPOSITORY's
# own development/CI floor lives in `devEngines` — not `engines` — is in the
# README, under "The npm side is not the mirror image of the Composer side":
# written out there once instead of a third time here, since the copy that
# drifted first was one of these restatements. What matters at this call site:
# npm's own check cannot be RELIED on, because older npm ignores `devEngines`
# entirely. That half carries the argument on its own. The half that stood
# beside it — "the js job never runs an install at the repository root" — is
# true and does not support the conclusion: npm runs the check before
# `install`, `ci` AND `run`, and the job's one step is `npm run ci:test:js` in
# that root. So on current npm the two overlap for THIS floor, and only this
# gate covers an old one. Re-derive rather than trusting the sentence:
#
#     curl -s https://raw.githubusercontent.com/npm/cli/latest/docs/lib/content/configuring-npm/package-json.md \
#         | grep -n 'will run before'
#
# `engines.node`, checked separately a few lines down, is NOT this same floor
# under a different name — see the comment on MIN_CONSUMER_NODE below for why.
# Take the FIRST numeric group, not every digit in the string: stripping all
# non-digits reads the ordinary spelling ">=24.0.0" as the floor 2400, which is
# above every real version, so the check would hard-fail on a runner that
# satisfies the floor. Only ">=24" happens to survive that, and the floor will
# not stay dot-free forever.
# Taken as a function over a package.json directory rather than inline, so the
# fixtures below can drive its failure paths. Run against this repository alone it
# only ever takes the happy path: CI pins node-version 24 and the floor says >=24,
# so `have` equals `want` on every lane and the comparison fires in NEITHER
# direction — inverting `<` to `>` left it green while it enforced nothing. Same
# vacuity the version gate got a fixture harness for in this branch; the Node-side
# self-checks did not get the same treatment until now.
#
# It also checks the second hand-written copy of the proven versions: the peer
# RANGES. The smoke proves the exact devDependencies pins, and the README calls
# the ranges "the versions the shared configs are proven against" — but nothing
# tied the two together, so widening `^2.5.0` to `^1.9.0` would have left the
# package declaring compatibility with a Biome major whose parser rejects
# `linter.rules.preset` outright, with a green suite.
manifest_check() {
    ROOT="$1" node -e '
const pkg = require(process.env.ROOT + "/package.json");

// The TYPE, before any shape test. `exec` and `test` call ToString on their
// argument, so a one-element array joins straight back to the string and
// satisfies a pattern the value never had.
// `grep -nE "asString[(]" tests/check-js-configs.sh` lists the readers; the
// pattern wants a literal paren, which this line does not carry, so it cannot
// count itself.
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
// >=20 specifically is on the containsLoneSurrogate docblock in
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
// Held once: both arms below report on the same field, and a report() call
// site that diverges from its sibling by accident (not by design, the way
// the peer-range arms further down each carry their own distinct payload)
// is exactly the drift this file guards against elsewhere.
const declaredEnginesNode = { "declared engines.node": pkg.engines?.node };

if (!/^>=\d+(\.\d+){0,2}$/.test(asString(pkg.engines?.node))) {
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
'
}

manifest_check "$root" || {
    fail "package.json — the Node floor or a peer range does not hold"
    exit 1
}

# The failure paths, driven over fixtures. Without them the block above is a
# statement rather than a check.
manifest_fixtures="$work/manifest-fixtures"

# Each fixture also gets a biome/base.json whose $schema is derived from the
# fixture body. The check reads that file unconditionally — a missing one is a
# finding, not a skip — so a fixture without one would fail for a reason it was
# not built to measure.
#
# The derivation covers a fixture that varies the Biome pin. A fixture whose pin is
# not a version string trips the $schema type arm whatever the base.json says, so
# it reports two causes by construction; one whose pin is a string but not a
# version passes its own base.json instead, because the derived URL would carry
# `schemas/^2.5.5/` and trip the canonical arm rather than the one under test.
manifest_fixture() { # <name> <package.json body> [<raw JSON $schema value>]
    mkdir -p "$manifest_fixtures/$1/biome"

    # A passing engines.node, unless the body already has an opinion. Only
    # engines_node_rejects() writes the key itself, for the fixtures whose own
    # point IS engines.node; every other fixture tests something unrelated
    # (the devEngines floor, a peer range, the $schema tie) and would
    # otherwise all reject for a new, unintended reason the moment that
    # requirement went live — the same drift the $schema derivation two lines
    # down exists to prevent for THAT key.
    # `"engines": null` is the escape hatch for the one input class auto-injection
    # would otherwise make impossible to construct: a body that wants the key
    # genuinely ABSENT.
    #
    # Checked explicitly rather than trusted to `set -e`: unlike the pin
    # derivation below, whose crash degrades to a harmless malformed $schema
    # string, a crash HERE would otherwise silently write an EMPTY
    # package.json. The explicit `exit 1` is what makes this reliable, NOT the
    # caller's assignment shape — this file never sets
    # `shopt -s inherit_errexit`, so a plain failing command in here (as
    # opposed to an unconditional `exit`) would NOT abort the surrounding
    # `$(manifest_fixture …)` subshell even when the CALLER assigns it
    # directly; a future check added to this function needs the same explicit
    # `if ! …; then …; exit 1; fi` shape, not a bare assignment. What the
    # caller's shape DOES decide is whether that `exit 1` — once it fires —
    # is itself observed: a caller that assigns the substitution directly
    # (`dir="$(manifest_fixture …)"`, several call sites below, including the
    # engines.node fixtures via engines_node_rejects) gets a clean abort. One
    # embedded as an argument to another command (`manifest_rejects
    # "$(manifest_fixture …)" …`, as `floor-above-runtime` right below does)
    # does not: `set -e` does not act on a substitution's exit status in that
    # position, so the run continues with an empty dir and additionally
    # reports manifest_check's own "did not run, it died" — misattributed, but
    # not silent, since this diagnostic already printed. Measured, not
    # assumed: `bash -c 'set -e; f(){ exit 1; }; g(){ :; }; g "$(f)"; echo
    # after'` prints `after`, while `bash -c 'set -e; f(){ exit 1; }; x="$(f)";
    # echo after'` does not.
    local body
    if ! body="$(BODY="$2" node -e 'const pkg = JSON.parse(process.env.BODY);
if (pkg.engines === undefined) {
    pkg.engines = { node: ">=20" };
} else if (pkg.engines === null) {
    delete pkg.engines;
}
process.stdout.write(JSON.stringify(pkg));' 2>&1)"; then
        printf 'manifest_fixture %s: package.json body is not valid JSON — %s\n' "$1" "$body" >&2
        exit 1
    fi

    printf '%s\n' "$body" > "$manifest_fixtures/$1/package.json"

    # The optional third argument is written verbatim and skips the derivation
    # entirely — for the fixtures whose whole point is a $schema the derivation
    # could not produce.
    local schema="${3:-}"

    if [ -z "$schema" ]; then
        # The ternary writes "0.0.0" for anything that is not a string, so the pin
        # cannot come out empty. A crashed derivation writes `schemas//schema.json`
        # instead, and the fixture then reports a non-canonical $schema alongside
        # whatever it was built to measure.
        local pin
        pin="$(BODY="$2" node -e 'const p = JSON.parse(process.env.BODY).devDependencies?.["@biomejs/biome"];
process.stdout.write(typeof p === "string" ? p : "0.0.0")')"
        schema="\"https://biomejs.dev/schemas/$pin/schema.json\""
    fi

    printf '{"$schema": %s}\n' "$schema" > "$manifest_fixtures/$1/biome/base.json"

    printf '%s' "$manifest_fixtures/$1"
}

# A crash is not a verdict, and the exit code cannot say which it was: this program
# exits 1 to reject, and node exits 1 on an uncaught throw as well. Measured — a
# `throw` at the top of manifest_check carrying the asserted sentences reported OK
# on most controls, so the whole block proved nothing while printing green. Node
# prints a stack frame and this program never does, which is the discriminator the
# exit code is not. A throw of a NON-Error value prints no frame at all, only the
# crash preamble naming the source position — hence the second alternative, and the
# second positive probe below that would otherwise leave it unmeasured. The shared
# `degraded` cannot serve here: its alternation is PHP-shaped and matches none of
# node's output.
manifest_crashed() { # <combined output>
    grep -qE '^[[:space:]]+at |^\[eval\]:[0-9]' <<<"$1"
}

# Both directions, and both throw shapes. `crashing-gate` below does crash the gate,
# but through `require`, which throws an Error and prints stack frames — the bare
# string probe is the only thing reaching the second alternative. Without these two
# an unprobed pattern would be asserted at every control and proven at none, which
# is the hole the shared `degraded` probe above it closes for the PHP side.
for manifest_crash_probe in \
    "$(node -e 'throw new Error("boom")' 2>&1)" \
    "$(node -e 'throw "a bare string"' 2>&1)"
do
    if ! manifest_crashed "$manifest_crash_probe"; then
        printf 'FAILED  harness bookkeeping: manifest_crashed does not recognise a node crash\n' >&2
        exit 1
    fi
done

# The gate's own diagnostics, including the shape a fixture-controlled value takes
# once report() has encoded it onto an INFO line.
for manifest_crash_probe in \
    'a peerDependencies entry has no devDependencies pin proving it' \
    'INFO     peer: "   at the start"' \
    'INFO     peer: "[eval]:1"'
do
    if manifest_crashed "$manifest_crash_probe"; then
        printf 'FAILED  harness bookkeeping: manifest_crashed misreads `%s` as a crash\n' "$manifest_crash_probe" >&2
        exit 1
    fi
done

# The prelude both assertion helpers need, held once: two copies of the crash check
# is how the sibling harnesses drifted apart in the first place. Sets `out`; returns
# 1 when it has already reported.
manifest_ran() { # <dir> <label>
    if out="$(manifest_check "$1" 2>&1)"; then
        fail "$2 — accepted, so the check does not discriminate"
        return 1
    fi

    if manifest_crashed "$out"; then
        fail "$2 — the gate did not run, it died: $out"
        return 1
    fi
}

# The assertion is matched against the diagnostic with every INFO line removed.
# Those lines carry the offending VALUE, and this helper greps the whole stream —
# so a `$schema` reading `… is not satisfied by the pin the smoke proves` satisfied the
# peer-drift control on a manifest whose peers agreed. Measured; peer-name-poison
# below is the standing control.
#
# The optional fourth argument is text the diagnostic must NOT carry. Without it a
# guard whose job is to SUPPRESS a second, wrong cause cannot be measured at all:
# deleting it leaves the first cause printed, the exit non-zero and every control
# green.
manifest_rejects() { # <dir> <label> <substring it must carry> [<substring it must not>]
    local out asserted
    manifest_ran "$1" "$2" || return 0

    # `grep -v` exits 1 when it filters everything away, which is an empty
    # assertable diagnostic rather than an error. Defensive against `set -e` only:
    # no path reaches it today, because `report` always prints its sentence
    # line before the INFO one, and every other rejection is a bare console.error.
    asserted="$(grep -v '^INFO ' <<<"$out")" || asserted=""

    if ! grep -qF -- "$3" <<<"$asserted"; then
        fail "$2 — rejected, but not for the tested reason: $out"
    elif [ "$#" -gt 3 ] && grep -qF -- "$4" <<<"$asserted"; then
        fail "$2 — reported a second, wrong cause as well: $out"
    else
        pass "$2"
    fi
}

manifest_rejects "$(manifest_fixture floor-above-runtime \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=999" } } }')" \
    "manifest control — a floor above the running Node is reported" \
    "below the devEngines floor"

# engines.node used to be forbidden outright; #32 flipped that to required,
# because bin/check-js-config.mjs now ships real code to a consumer's Node
# (see the comment above manifest_check's engines.node arm). The four
# fixtures below drive the REJECT side of that flip, split across the two
# checks that arm now runs in sequence: the shape check (absent, unparseable,
# and the OR-range whose EFFECTIVE floor a first-digit read cannot see — the
# case CodeRabbit's PR #70 review found; the mechanics are on the shape-check
# comment above manifest_check's engines.node arm, not restated here) and the
# floor comparison the shape check's survivors still have to clear (too-low).
# The ACCEPT side needs no fixture of its own — every other manifest_accepts
# case below relies on
# manifest_fixture's auto-injected ">=20", and `manifest_check "$root"` above
# already proves the real repository's own ">=20" passes end to end.
#
# Both sentences held once each, same reason as peer_drift_sentence/
# no_pin_sentence further down: a separate literal per call site
# desynchronises on the first rewording of report()'s message, and the
# mutation surfaces as an honest test failure rather than a silent pass
# either way — but only if the sentence lives in one place.
consumer_engines_shape_sentence='engines.node is not a single ">=X" floor this check can verify'
consumer_engines_sentence='engines.node floor is below the Node version'

# Same fixed-body-vary-one-fragment shape as schema_rejects() further down;
# assigns the substitution rather than embedding it, same reason as that
# helper's own `dir="$(manifest_fixture …)"` — only the assignment form gives
# manifest_fixture's crash guard (the comment above it) something for
# `set -e` to act on.
engines_node_rejects() { # <name> <engines JSON fragment> <label> <sentence>
    local dir
    dir="$(manifest_fixture "$1" \
        "{ \"devEngines\": { \"runtime\": { \"name\": \"node\", \"version\": \">=24\" } }, \"engines\": $2 }")"

    manifest_rejects "$dir" "$3" "$4"
}

engines_node_rejects engines-absent 'null' \
    "manifest control — a package.json with no engines.node at all is reported" \
    "$consumer_engines_shape_sentence"

engines_node_rejects engines-node-not-parseable '{ "node": "latest" }' \
    "manifest control — an engines.node value with no parseable digits is reported" \
    "$consumer_engines_shape_sentence"

engines_node_rejects engines-node-or-range '{ "node": ">=20 || >=18" }' \
    "manifest control — an OR-range whose loosest alternative permits an unsupported Node is reported, not accepted at its first alternative's floor" \
    "$consumer_engines_shape_sentence"

engines_node_rejects engines-too-low '{ "node": ">=18" }' \
    "manifest control — an engines.node floor below what bin/check-js-config.mjs needs is reported" \
    "$consumer_engines_sentence"

# The asserted sentences, held once each. Every reader needs the identical bytes:
# the controls that assert one, and the poison values that prove no fixture can
# supply it. As separate literals they desynchronised on the first rewording, and
# the poison control then reported OK under the very mutation it names.
peer_drift_sentence='is not satisfied by the pin the smoke proves'
no_pin_sentence='has no devDependencies pin proving it'

peer_major_drift="$(manifest_fixture peer-major-drift \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^1.9.0" } }')"

manifest_rejects "$peer_major_drift" \
    "manifest control — a peer range naming another major than the pin is reported" \
    "$peer_drift_sentence"

# The poison controls further down all forbid text, and "the value never arrived"
# satisfies every one of them: deleting report()'s INFO emission outright left the
# whole set green, measured. So the containment was pinned in both its mechanisms
# and in neither direction. This asserts against the UNFILTERED stream, which is
# why manifest_rejects cannot host it — that helper greps the stream with the INFO
# lines already removed.
manifest_reports_value() { # <dir> <label> <exact line the diagnostic must carry>
    local out
    manifest_ran "$1" "$2" || return 0

    if ! grep -qxF -- "$3" <<<"$out"; then
        fail "$2 — the offending value never reached the operator: $out"
    else
        pass "$2"
    fi
}

manifest_reports_value "$peer_major_drift" \
    "manifest control — the offending value reaches the operator on its own INFO line" \
    'INFO     pin: "2.5.5"'

# The only ACCEPT case among the manifest controls, and the only one that
# DISCRIMINATES a numeric `below()` from a string compare of the joined form.
# `peer-floor-above-pin` further down reaches `below()` too — same major, so the
# short-circuit does not fire — but it rejects under either implementation, so it
# cannot tell them apart. This one can: a pin whose minor is past nine is exactly
# what a string compare gets wrong, since "2.10.0" sorts below "2.9.0". Without it,
# replacing below()'s body with a string compare changed no verdict anywhere.
manifest_accepts() { # <dir> <label>
    local out
    if out="$(manifest_check "$1" 2>&1)"; then
        pass "$2"
    else
        fail "$2 — rejected: $out"
    fi
}

manifest_accepts "$(manifest_fixture peer-minor-past-nine \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.10.0" },
       "peerDependencies": { "@biomejs/biome": "^2.9.0" } }')" \
    "manifest control — a pin whose minor is past nine satisfies a lower caret floor"

manifest_rejects "$(manifest_fixture no-devengines \
    '{ "devDependencies": { "@biomejs/biome": "2.5.5" } }')" \
    "manifest control — a package.json with no devEngines floor is reported" \
    "no parseable devEngines"

# The floor reader's coercion. Without asString the array stringifies to `>=1`, the
# digit matches and the floor gate passes — so the fixture needs peers and a pin that
# agree, or the run exits 1 on an unrelated anchor and the control rides on that
# instead. With them, dropping the coercion turns this case into "accepted, so the
# check does not discriminate".
manifest_rejects "$(manifest_fixture floor-not-a-string \
    '{ "devEngines": { "runtime": { "name": "node", "version": [">=1"] } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }')" \
    "manifest control — a devEngines floor that is not a string is reported as unparseable" \
    "no parseable devEngines"

# Caret semantics below 1.0.0, where the MINOR is the compatibility boundary:
# `^0.2.0` is `>=0.2.0 <0.3.0`, so the pin 0.3.0 does NOT satisfy it. Comparing the
# major alone accepted this. No peer in the real manifest is below 1.0.0, so this
# arm has no other way to be reached — and that is precisely why the fixture exists.
manifest_rejects "$(manifest_fixture peer-zero-major-minor-drift \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "0.3.0" },
       "peerDependencies": { "@biomejs/biome": "^0.2.0" } }')" \
    "manifest control — a 0.x pin past the caret minor is reported" \
    "$peer_drift_sentence"

# The THIRD caret case, where major and minor are both zero and the PATCH is the
# boundary: `^0.0.3` is `>=0.0.3 <0.0.4`. A first version of the fix took the minor
# as the boundary for every zero-major range and accepted 0.0.9 here.
manifest_rejects "$(manifest_fixture peer-zero-minor-patch-drift \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "0.0.9" },
       "peerDependencies": { "@biomejs/biome": "^0.0.3" } }')" \
    "manifest control — a 0.0.x pin past the caret patch is reported" \
    "$peer_drift_sentence"

manifest_accepts "$(manifest_fixture peer-zero-minor-exact \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "0.0.3" },
       "peerDependencies": { "@biomejs/biome": "^0.0.3" } }')" \
    "manifest control — a 0.0.x pin at the caret patch is accepted"

# The accepting twin, so the arm above cannot pass by rejecting every 0.x range.
manifest_accepts "$(manifest_fixture peer-zero-major-in-range \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "0.2.7" },
       "peerDependencies": { "@biomejs/biome": "^0.2.0" } }')" \
    "manifest control — a 0.x pin inside the caret minor is accepted"

# The floor-vs-pin half of the peer check, which no case reached: peer-major-drift
# short-circuits on the major, peer-without-pin continues before it, and the real
# manifest satisfies both. So dropping `below()` entirely kept every case green
# and the numeric-comparison rationale above it enforced nothing.
manifest_rejects "$(manifest_fixture peer-floor-above-pin \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.9.0" } }')" \
    "manifest control — a peer floor above the pin, same major, is reported" \
    "$peer_drift_sentence"

manifest_rejects "$(manifest_fixture peer-range-not-caret \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": ">=2.5.0 <2.5.5" } }')" \
    "manifest control — a range shape this check cannot evaluate is reported, not assumed satisfied" \
    "is not a plain caret range"

manifest_rejects "$(manifest_fixture peer-without-pin \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }')" \
    "manifest control — a peer with no pin proving it is reported" \
    "$no_pin_sentence"

# The same arm, reached by a peer whose NAME is an Object.prototype member. A plain
# `pkg.devDependencies?.[name]` resolves it through the prototype, so the pin reads
# as present, this arm is skipped and the next one names the wrong cause — which is
# what the must-not-carry pins.
manifest_rejects "$(manifest_fixture peer-named-after-a-prototype-member \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0", "constructor": "^1.0.0" } }')" \
    "manifest control — a peer named after an Object.prototype member reports as unpinned, not as mistyped" \
    "$no_pin_sentence" \
    "is not an exact version"

# The ToString coercion asString exists against (see its comment), on the
# exact-version gate: `["2.5.5"]` stringifies back to a valid version and slips it,
# which is what makes this fixture the coercion discriminator. The poison duty lives
# with peer-name-poison, which carries the identical payload on this same report site.
peer_pin_array="$(manifest_fixture peer-pin-array \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": ["2.5.5"] },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }')"

manifest_rejects "$peer_pin_array" \
    "manifest control — a devDependencies pin that is an array is reported" \
    "is not an exact version"

# A second control on the same fixture: it legitimately reports more than one cause,
# and each cause needs its own must-carry. Without this one the $schema type arm can
# be deleted outright and nothing reddens — measured.
manifest_rejects "$peer_pin_array" \
    "manifest control — a pin that exists but is not a version string is named as that, not as absent" \
    "is not a version string"

manifest_rejects "$(manifest_fixture peer-range-array \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": ["^2.5.0"] } }')" \
    "manifest control — a peerDependencies range that is an array is reported" \
    "is not a plain caret range"

# The $schema check, driven both ways. The accepting direction is proven by
# manifest_check "$root" above, against this repository's real biome/base.json;
# the cases below overwrite the fixture config to prove the rejecting one. A case
# that trips one of the gates above the peer loop never reaches this block at all,
# so its derived $schema proves nothing either way.
#
# The mutation each fixture below earned is recorded in the commit that added it,
# not here: a copy here is a coverage claim with nothing behind it, and successive
# versions of exactly that copy were falsified by review.
schema_rejects() { # <name> <raw JSON $schema value> <label> <must carry> [<must not carry>]
    local dir name value
    name="$1"
    value="$2"
    shift 2

    dir="$(manifest_fixture "$name" \
        '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
           "devDependencies": { "@biomejs/biome": "2.5.5" },
           "peerDependencies": { "@biomejs/biome": "^2.5.0" } }' "$value")"

    manifest_rejects "$dir" "$@"
}

schema_rejects schema-drift '"https://biomejs.dev/schemas/2.4.0/schema.json"' \
    "manifest control — a base config whose \$schema lags the pin is reported" \
    "pins \$schema at 2.4.0"

schema_rejects schema-unversioned '"https://biomejs.dev/schemas/latest/schema.json"' \
    "manifest control — a \$schema naming no X.Y.Z version is reported, not skipped" \
    "is not the canonical"

schema_rejects schema-foreign-host '"https://example.invalid/schemas/2.5.5/schema.json"' \
    "manifest control — a \$schema served from a foreign host is reported" \
    "is not the canonical"

schema_rejects schema-plain-http '"http://biomejs.dev/schemas/2.5.5/schema.json"' \
    "manifest control — a \$schema served over plain http is reported" \
    "is not the canonical"

schema_rejects schema-wrong-path '"https://biomejs.dev/x/2.5.5/schema.json"' \
    "manifest control — a \$schema on the right host but the wrong path is reported" \
    "is not the canonical"

schema_rejects schema-wrong-filename '"https://biomejs.dev/schemas/2.5.5/config.json"' \
    "manifest control — a \$schema whose filename is not schema.json is reported" \
    "is not the canonical"

schema_rejects schema-leading-space '" https://biomejs.dev/schemas/2.5.5/schema.json"' \
    "manifest control — a \$schema with a pasted leading space is reported" \
    "is not the canonical"

schema_rejects schema-suffixed '"https://biomejs.dev/schemas/2.5.5/schema.json.evil"' \
    "manifest control — a \$schema carrying trailing content past schema.json is reported" \
    "is not the canonical"

# The type, not the shape — the coercion asString exists against, on this reader.
schema_rejects schema-array '["https://biomejs.dev/schemas/2.5.5/schema.json"]' \
    "manifest control — a \$schema array stringifying to the canonical URL is reported" \
    "is not the canonical"

# Every other fixture writes `{"$schema": <value>}`, so this is the one shape that
# reaches report() with an ABSENT value.
schema_no_key="$(manifest_fixture schema-no-key \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }')"
printf '{"note": "no $schema here"}\n' > "$schema_no_key/biome/base.json"

manifest_rejects "$schema_no_key" \
    "manifest control — a base config with no \$schema key is reported" \
    "is not the canonical"

manifest_reports_value "$schema_no_key" \
    "manifest control — an absent \$schema reaches the operator as absent, not as the word undefined" \
    'INFO     offending $schema value: (absent)'

# The legacy `##[` grammar, which the JSON encoding does NOT break: the runner finds
# that prefix unanchored, so a peer name carrying it forges a command from mid-line.
# Asserted through the helper that reads the UNFILTERED stream, because the value
# lives on an INFO line and manifest_rejects strips those before it looks.
manifest_reports_value "$(manifest_fixture peer-name-legacy-prefix \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0", "##[error]forged": "^1.0.0" } }')" \
    "manifest control — a peer name cannot forge a legacy workflow command" \
    'INFO     peer: "##?[error]forged"'


# The oracle's own controls — the measurement they stand on is at manifest_rejects,
# beside the filtering that answers it. Poisoned here: a peer name, its pin and its
# range. The payload carries an embedded newline, so it pins the JSON encoding as
# well as the `INFO ` prefix — without the encoding a value spills past its own line
# into the stream the assertion reads, which is the same hole one level down. One
# fixture is enough because `report()` is one function: the other value routes reach
# the same two lines, so nothing is left unpinned by not repeating the payload.
manifest_rejects "$(manifest_fixture peer-name-poison \
    "{ \"devEngines\": { \"runtime\": { \"name\": \"node\", \"version\": \">=24\" } },
       \"devDependencies\": { \"@biomejs/biome\": \"2.5.5\",
                             \"poison-pin\": \"x\\n$peer_drift_sentence\",
                             \"poison-range\": \"2.0.0\" },
       \"peerDependencies\": { \"@biomejs/biome\": \"^2.5.0\",
                             \"poison-pin\": \"^2.0.0\",
                             \"poison-range\": \"x\\n$peer_drift_sentence\",
                             \"x\\n$peer_drift_sentence\": \"^1.0.0\" } }")" \
    "manifest control — a peer name, pin or range cannot supply the text another control asserts" \
    "$no_pin_sentence" \
    "$peer_drift_sentence"

# A canonical $schema whose version nothing pins. The body differs from the one
# the helper writes so that this arm is the fixture only cause — peer-without-pin
# reaches the same arm, but reports the peer gap alongside it.
schema_no_pin="$(manifest_fixture schema-no-pin \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "typescript": "7.0.2" },
       "peerDependencies": { "typescript": "^7.0.0" } }' \
    '"https://biomejs.dev/schemas/9.9.9/schema.json"')"

manifest_rejects "$schema_no_pin" \
    "manifest control — a canonical \$schema no pin proves is reported, not skipped" \
    "names a Biome version that no devDependencies entry pins"

schema_absent="$(manifest_fixture schema-absent \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }')"
rm -f "$schema_absent/biome/base.json"

# A base config that cannot be read is reported here rather than skipped: a check
# whose subject disappears must fail, or removing the file removes the check. The
# fourth argument is what makes the catch's `process.exit(1)` measurable — turn it
# back into a `failed = true` and this arm reports a second, wrong cause on top of
# its own, which without the argument no control would see.
manifest_rejects "$schema_absent" \
    "manifest control — an unreadable base config is reported, and not also as a missing \$schema" \
    "could not be read for its" \
    "is not the canonical"

# Zero peer ranges must not read as "every peer range agrees". Every other derived
# list in this harness carries this anchor; this loop was the gap.
manifest_rejects "$(manifest_fixture peers-absent \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" } }')" \
    "manifest control — a manifest declaring no peerDependencies is reported" \
    "declares no peerDependencies"

# A devDependency that is itself a range. `segments()` strips everything before
# the first digit, so `^2.5.5` and `2.5.5` compare identically — the range would
# be approved as the pin the smoke proves, while npm installs whatever it
# resolves to. The fixture writes its own base config, because manifest_fixture
# derives the $schema from the pin and `schemas/^2.5.5/` would trip the check
# above instead of this one.
range_as_pin="$(manifest_fixture range-as-pin \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "^2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.5.0" } }' \
    '"https://biomejs.dev/schemas/2.5.5/schema.json"')"

manifest_rejects "$range_as_pin" \
    "manifest control — a devDependency range standing in for a pin is reported" \
    "is not an exact version"

# The must-not-carry argument is a control in its own right, and an unproven one:
# disabling it leaves every case above green — measured. Same shape as
# probe_reporters at the top of this file. The driver has to be a fixture that
# legitimately reports a SECOND cause, so that forbidding it raises the counter
# exactly once; range-as-pin is one, and the substring below is a stable prefix of
# its second diagnostic rather than the derived version that follows.
probe_negative_assertion() {
    manifest_rejects "$range_as_pin" \
        'bookkeeping self-test — the must-not-carry argument' \
        'is not an exact version' \
        'pins $schema at'
}

harness_probe_reporters 1 probe_negative_assertion \
    'manifest_rejects ignores its must-not-carry argument'

# The crash guard, driven rather than asserted — it shipped with nothing exercising
# it, which is the shape it exists against. A package.json that is not JSON makes the
# program's own `require` throw before any check runs.
crashing_gate="$manifest_fixtures/crashing-gate"
mkdir -p "$crashing_gate/biome"
printf 'not json at all\n' > "$crashing_gate/package.json"
printf '{"$schema": "https://biomejs.dev/schemas/2.5.5/schema.json"}\n' > "$crashing_gate/biome/base.json"

# The asserted substring is one the CRASH prints. With the guard the case reports
# that the gate died; without it the substring is found and the case reports ok — so
# the expected count drops from 1 to 0 and the probe fires. Asserting a substring the
# crash does NOT print cannot discriminate: both paths report a failure, for
# different reasons, and the count is the same.
#
# Which makes the probe rest on a foreign system's wording, so the premise is checked
# rather than assumed: the tail below is V8's, it did not exist before Node 20, and if
# it is reworded the probe silently stops discriminating instead of going red.
crash_substring='is not valid JSON'
crash_probe_out="$(manifest_check "$crashing_gate" 2>&1)" || true

if ! grep -qF -- "$crash_substring" <<<"$crash_probe_out"; then
    printf 'FAILED  harness bookkeeping: the crash no longer prints `%s`, so probe_crash_guard discriminates nothing\n' \
        "$crash_substring" >&2
    exit 1
fi

probe_crash_guard() {
    manifest_rejects "$crashing_gate" \
        'bookkeeping self-test — the crash guard' \
        "$crash_substring"
}

harness_probe_reporters 1 probe_crash_guard \
    'manifest_rejects accepts a crashed gate as a verdict'

# The third assertion helper, driven like its two siblings. Replacing its body with a
# bare `pass` silently retires both value controls — the only thing standing between
# the poison set and a value that never arrived at all.
probe_reports_value() {
    manifest_reports_value "$peer_major_drift" \
        'bookkeeping self-test — the value helper' \
        'INFO     pin: "an INFO line this report never prints"'
}

harness_probe_reporters 1 probe_reports_value \
    'manifest_reports_value accepts a report that never carried the value'

rm -rf "$manifest_fixtures"

# A registry hiccup or a bad pin would otherwise abort the script here with no
# output at all — the same red as a genuine config regression, and with the EXIT
# trap deleting $work there is nothing left in the CI log to tell them apart.
# shellcheck disable=SC2086 # deliberate word splitting: one npm arg per tool
if ! npm install --no-audit --no-fund "$work/$tarball" $tools >"$work/npm-install.log" 2>&1; then
    fail "npm install failed — cannot run the smoke" "$work/npm-install.log"
    exit 1
fi

# Prove the `files` allow-list actually shipped the configs — read from the
# TARBALL npm produced, not from a re-implementation of npm's semantics. A walk
# over the `files` entries has to reproduce glob expansion and the default-ignore
# list (`*.orig`, `.DS_Store`, …) to stay in step; reading what npm actually
# packed agrees with it by construction.
# No filter in this pipe, and that is load-bearing: `sed -n …p` exits 0 when it
# prints nothing, so an empty listing still reaches the checks below. A `grep` here
# would not — under `set -o pipefail` its no-match exit 1 fails the whole pipeline,
# and the tarball-carrying-no-config case would report "could not list the tarball
# contents", pointing the reader at tar. One was here once; do not re-add one
# without `|| true`.
#
packed=""
packed="$(tar -tzf "$work/$tarball" | sed -n 's~^package/~~p' | sort)" || {
    fail "could not list the tarball contents — nothing to verify"
    exit 1
}

# There used to be a "packed but not installed" loop here, checking each tarball
# entry against node_modules, and an empty-tarball guard beneath it. The guard went
# the same way, for the same CLASS of reason — both were unreachable — but on a
# different mechanism: `npm pack` ships package.json whatever `files` says, so
# `$packed` cannot be empty after a successful pack. Re-derive with
# `tar -tzf "$work/$tarball" | grep -c '^package/package.json'` -> 1, or see
# https://docs.npmjs.com/cli/configuring-npm/package-json#files (observed
# 2026-08-15). A second unreachable guard, put in place while removing the first. It could not fail: `npm install` of that exact
# tarball extracts every entry it lists, and a file that stops shipping leaves the
# listing at the same time, so the loop was asking whether a set contains itself.
# What it looked like it proved is proved below (`files` against the tarball) and
# by the Biome and tsc runs further down, which load the installed configs for
# real. Its `${#shipped[@]} -eq 0` guard was unreachable for a second reason: a
# here-string always feeds `mapfile` one line, so the array is never empty.

# The tarball is the artefact, but `files` is the declaration: every entry of it
# must be represented, or a directory silently stops shipping and the tarball
# listing alone has nothing to say about it.
#
# Captured first: a non-zero command inside a `for` word-list does NOT trip
# `set -e`, so a `files` that is missing, misspelled or not an array would skip
# the loop entirely — switching this assertion off while npm falls back to packing
# the whole repository.
declared=""
declared="$(ROOT="$root" node -e 'process.stdout.write(require(process.env.ROOT + "/package.json").files.join("\n"))')" || true

if [ -z "$declared" ]; then
    fail "could not read the files allow-list from package.json — the declared-entry check did not run"
    exit 1
fi

while IFS= read -r entry; do
    entry="${entry%/}"

    # An entry may be a directory OR a plain file, so both shapes count. Matched
    # literally, since a glob entry would otherwise behave as a regex.
    #
    # Fed from a here-string rather than a pipe: `grep -q` exits at the first
    # match, and under `set -o pipefail` the SIGPIPE that kills the upstream
    # `printf` then decides the pipeline, so a match reads as a miss once the
    # listing outgrows the pipe buffer. Measured: identical at 100 entries,
    # spuriously absent at 1000. Latent today — `files` holds two entries — and it
    # fails towards a false red, but the shape is the one the other harnesses
    # already avoid.
    if grep -qxF -- "$entry" <<<"$packed" \
        || grep -q -- "^$(printf '%s' "$entry" | sed 's/[][\.*^$\/]/\\&/g')/" <<<"$packed"; then
        pass "declared and packed: $(safe_report "$entry")"
    else
        fail "declared in package.json \"files\" but absent from the tarball: $(safe_report "$entry")"
    fi
done <<<"$declared"

# The delivery this smoke does NOT pack. `npm pack` reads the working tree; the
# only documented install path is `github:magicsunday/coding-standard#<tag>`, which
# pacote fetches from GitHub's codeload archive with `.gitattributes` export-ignore
# already applied. So `files` and the export-ignore list are two hand-kept allow-lists
# over one delivery and only one was exercised — and this repository has already made
# the mistake once: commit 1a2291e reverted a `/package.json export-ignore` that broke
# every consumer's install, found by hand rather than by a gate.
#
# `git check-attr` runs git's own attribute resolution rather than grepping the file,
# so it answers for whatever pattern happens to match, and it reads THIS repository's
# .gitattributes — the copy that bit, not the template.
#
# bin/support/safe-report-value.php is in the list for the Composer side of the same
# question: both shipped gates `require_once` it, and nothing else asserts it survives
# into the dist archive.
# The list is DERIVED from `files` — the same allow-list the tarball check above
# reads — plus package.json itself and the Composer side's shared helper, neither of
# which `files` covers. A hand-kept list here would drift from `files` the moment an
# entry is added, which is the drift this pair of allow-lists is about.
#
# Each entry is expanded into its ANCESTOR CHAIN, because that is how .gitattributes
# matches and how `git archive` prunes: `/bin/support export-ignore` sets the
# attribute on the directory and leaves `bin/support/safe-report-value.php`
# unspecified, while the archive drops the whole subtree. Asking about the leaf alone
# answers a question nobody asked — measured, that spelling passed the first version
# of this control.
exported_paths="$(printf '%s\n' "$declared" package.json bin/support/safe-report-value.php \
    | awk 'NF { n = split($0, part, "/"); path = ""; for (i = 1; i <= n; i++) { path = (i == 1 ? part[i] : path "/" part[i]); print path } }' \
    | sort -u)"

while IFS= read -r exported; do
    [ -n "$exported" ] || continue
    attr="$(git -C "$root" check-attr export-ignore -- "$exported" 2>/dev/null)" || attr=''

    if [ -z "$attr" ]; then
        fail "cannot resolve export-ignore for $(safe_report "$exported") — git could not answer, so this control did not run"
    elif [ "$attr" != "$exported: export-ignore: unspecified" ]; then
        fail "$(safe_report "$exported") is export-ignored, so a github: install and the Composer dist archive both lose it: $(safe_report "$attr")"
    else
        pass "reaches a consumer through the git archive: $(safe_report "$exported")"
    fi
done <<<"$exported_paths"

# The fourth hand-kept copy of the tool versions, and until this control the only one
# nothing read: the $schema URL is tied to the pin roughly 700 lines above, the peer
# ranges are checked against the pins above, and these sit in README prose. npm
# Dependabot bumps here are auto-merged, so the prose drifts silently on the one path
# that is not reviewed.
readme_pins_wrong=0

while IFS='|' read -r tool documented; do
    [ -n "$tool" ] || continue
    actual="$(ROOT="$root" TOOL="$tool" node -e 'const d = require(process.env.ROOT + "/package.json").devDependencies;
process.stdout.write(String(d[process.env.TOOL] ?? ""))' 2>/dev/null)" || actual=''

    if [ "$actual" != "$documented" ]; then
        readme_pins_wrong=$((readme_pins_wrong + 1))
        fail "README documents $(safe_report "$tool") $(safe_report "$documented") but package.json pins $(safe_report "${actual:-nothing}")"
    fi
done < <(ROOT="$root" node -e '
const fs = require("node:fs");
const readme = fs.readFileSync(process.env.ROOT + "/README.md", "utf8");
const tools = ["@biomejs/biome", "typescript", "jscpd"];

for (const tool of tools) {
    const found = readme.match(new RegExp("`" + tool.replace("/", "\\/") + " ([0-9][^`]*)`"));

    if (found !== null) {
        process.stdout.write(tool + "|" + found[1] + "\n");
    }
}' 2>/dev/null || true)

# The anchor every other derived list in this harness carries: with the prose
# reworded, the loop above would run zero times and report agreement having compared
# nothing.
if [ "$(ROOT="$root" node -e '
const fs = require("node:fs");
const readme = fs.readFileSync(process.env.ROOT + "/README.md", "utf8");
process.stdout.write(String(["@biomejs/biome", "typescript", "jscpd"]
    .filter((t) => new RegExp("`" + t.replace("/", "\\/") + " [0-9]").test(readme)).length));' 2>/dev/null)" != "3" ]; then
    fail "README no longer documents all three tool versions in the shape this control reads — reword the control, not only the prose"
elif [ "$readme_pins_wrong" -eq 0 ]; then
    pass "README tool versions match the devDependencies pins"
fi

# --- a consumer extending both shared configs --------------------------------

mkdir -p src

# The consumer configs are the SAME files the lockstep gate's canon fixture uses.
# Keeping one copy means the gate and the real tools can never disagree about
# what the canon is: tests/check-consumer-config-cases.sh proves the gate accepts
# them, and this smoke proves Biome and tsc actually load them.
cp "$root/tests/consumer/biome.json" biome.json
cp "$root/tests/consumer/tsconfig.json" tsconfig.json

# Formatted to the shared ruleset: 4 spaces, double quotes, semicolons, template
# literal instead of concatenation, arrow function, strict equality.
cat > src/clean.ts <<'TS'
export const greet = (name: string): string => `hi ${name}`;

export const isSame = (left: string, right: string): boolean => left === right;
TS

# Note for whoever edits tests/consumer/biome.json: Biome checks its own config
# file regardless of `files.includes`, so a reformat there reds this run with a
# message that points at the shared config instead of at the reformat.
if biome_ci "$work/biome.log"; then
    pass "biome ci — shared config loads and the clean fixture passes"
else
    fail "biome ci — shared config rejected or the clean fixture reported findings" "$work/biome.log"
fi

if run_tsc "$work/tsc.log"; then
    pass "tsc — shared config loads and the clean fixture compiles"
else
    fail "tsc — shared config rejected or the clean fixture failed to compile" "$work/tsc.log"
fi

# --- controls: the shared rules must actually bite ---------------------------
#
# Every control below asserts the DIAGNOSTIC, never the exit status. A non-zero
# exit is worth nothing here: `biome ci` also exits non-zero when the config is
# unloadable, when a fixture has unrelated formatter drift, and when `npx
# --no-install` cannot find the binary at all — so an exit-status control reports
# "the rule is in force" in exactly the situations where nothing was in force.
# Verified rather than reasoned: with `"linter": { "enabled": false }` grafted
# onto the shared base, the previous form of the control below still printed OK,
# because the fixture's 2-space indent failed the formatter check on its own.

# `noDoubleEquals` is "error" in the shared linter block.
cat > src/dirty.ts <<'TS'
export const loose = (a: string, b: string): boolean => {
    return a == b;
};
TS

if biome_ci "$work/biome-dirty.log"; then
    fail "biome control — a rule violation passed, the shared linter is not in force"
elif grep -q 'lint/suspicious/noDoubleEquals' "$work/biome-dirty.log"; then
    pass "biome control — rule violation rejected"
else
    fail "biome control — biome ci failed, but not on noDoubleEquals" "$work/biome-dirty.log"
fi

rm src/dirty.ts

# The formatter half of the standard, as its own control with its own cause —
# the two used to share one fixture, which is what let the linter control pass
# on the formatter's finding.
cat > src/unformatted.ts <<'TS'
export const wide = (value: string): string => {
  return value;
};
TS

# The pattern has to be unique to this branch. `format|Formatter` is not: the
# shared config's own key names contain it, so a CONFIGURATION error — the state
# these controls exist to distinguish from a finding — matches it too. Assert the
# fixture and the diagnostic, as the tsc control does.
if biome_ci "$work/biome-format.log"; then
    fail "biome control — formatter drift passed, the shared formatter is not in force"
elif grep -q 'src/unformatted.ts' "$work/biome-format.log" && grep -q 'File content differs from formatting output' "$work/biome-format.log"; then
    pass "biome control — formatter drift rejected"
else
    fail "biome control — biome ci failed, but not on the unformatted fixture" "$work/biome-format.log"
fi

rm src/unformatted.ts

# A second control, aimed at the recommended FLOOR rather than an explicit rule.
# `noDebugger` is in Biome's recommended set and is deliberately not listed in
# biome/base.json, so it only fires while the preset is actually on — which is
# what makes this the fixture that must produce a finding for the `preset` key.
cat > src/debugger.ts <<'TS'
export const trace = (): void => {
    debugger;
};
TS

if biome_ci "$work/biome-preset.log"; then
    fail "biome control — the recommended rule preset is not in force"
elif grep -q 'lint/suspicious/noDebugger' "$work/biome-preset.log"; then
    pass "biome control — the recommended preset rejected a debugger statement"
else
    fail "biome control — biome ci failed, but not on noDebugger; the preset may be off" "$work/biome-preset.log"
fi

rm src/debugger.ts

# The house rule the shared config exists to carry: a local ESM import spells the
# extension `.js`, in TypeScript sources too, because that is what TS ESM emits
# and what tsc resolves. Both spellings are checked, since the interesting part
# is that they disagree — without the `extensionMappings` table Biome demanded
# `.ts`, which tsc then rejects with TS5097 unless allowImportingTsExtensions is
# on. No spelling satisfied both tools, so every consumer would have had to
# override the rule the base is meant to settle.
cat > src/imported.ts <<'TS'
export const value = 1;
TS

cat > src/importer.ts <<'TS'
import { value } from "./imported.js";

export const doubled = (): number => value * 2;
TS

if biome_ci "$work/biome-import-js.log"; then
    pass "biome — the house .js import extension is accepted"
else
    fail "biome — the house .js import extension was rejected" "$work/biome-import-js.log"
fi

if run_tsc "$work/tsc-import-js.log"; then
    pass "tsc — the same import compiles"
else
    fail "tsc — the house .js import extension failed to compile" "$work/tsc-import-js.log"
fi

# The control: an extensionless import must still be reported, so the rule is
# relaxed in spelling only, not switched off.
cat > src/importer.ts <<'TS'
import { value } from "./imported";

export const doubled = (): number => value * 2;
TS

if biome_ci "$work/biome-import-bare.log"; then
    fail "biome control — an extensionless import passed, useImportExtensions is not in force"
elif grep -q 'lint/correctness/useImportExtensions' "$work/biome-import-bare.log"; then
    pass "biome control — extensionless import rejected"
else
    fail "biome control — biome ci failed, but not on useImportExtensions" "$work/biome-import-bare.log"
fi


rm src/importer.ts

# Every ROW of the mapping table, not just the `ts` one the pair above happens to
# use. The table has four (ts/tsx -> js, mts -> mjs, cts -> cjs), and a row nobody
# drives can be edited to the wrong target — `"mts": "js"` — without a single
# assertion turning red, handing the consumer a SAFE autofix that rewrites an
# `.mts` import to a path that does not exist. That is the exact breakage the
# table replaced `forceJsExtensions` to avoid, reached through an untested row.
#
# The mapping is Biome's, so tsc is not run on these; the source extension is what
# selects the row, and the import spells the mapped target.
# The table is READ from biome/base.json, and the expected target is held HERE.
# Both halves are needed and each catches what the other cannot.
#
# Derivation alone was worse than the hand list it replaced: reading the target
# out of the same table the assertion then verifies makes the table answer its
# own question. Measured — corrupting the row to `"mts": "js"` produced a fixture
# importing `./mod.js` from a `.mts` source, which is exactly what that corrupted
# table asks for, so Biome accepted it and the control reported the row "in
# force". Biome does not check that the imported path exists, so nothing else
# could have made it discriminate.
#
# A hand list alone was the original defect: it catches a row that changes or
# disappears and never one that APPEARS, so a fifth row shipped unproven.
#
# So: the derived list decides WHICH rows run (an unknown one fails), and this
# table decides what each row must map to (a changed target fails). The
# completeness check after the loop closes the third case, a row deleted from the
# base — the derived list simply would not carry it, and no assertion would run.
declare -A expected_target=(
    [ts]=js
    [tsx]=js
    [mts]=mjs
    [cts]=cjs
)

mappings="$(ROOT="$root" node -e 'const m = require(process.env.ROOT + "/biome/base.json")
    .linter.rules.correctness.useImportExtensions.options.extensionMappings;
process.stdout.write(Object.entries(m).map(([from, to]) => from + " " + to).join("\n"))')" || true

if [ -z "$mappings" ]; then
    fail "could not read extensionMappings from biome/base.json — the mapping controls did not run"
    exit 1
fi

declare -A seen_row=()

# `ts` is already covered by the .js import pair above, which additionally proves
# the tsc direction; the rest need a source file in their own extension.
while IFS=' ' read -r source_ext target_ext; do
    [ -n "$source_ext" ] || continue

    want="${expected_target[$source_ext]:-}"

    if [ -z "$want" ]; then
        fail "biome/base.json maps the .$(safe_report "$source_ext") extension, which this smoke has no proven target for — add one rather than shipping the row unproven"
        continue
    fi

    seen_row[$source_ext]=1

    if [ "$target_ext" != "$want" ]; then
        fail "biome/base.json maps .$(safe_report "$source_ext") to .$(safe_report "$target_ext"); the target this smoke proves is .$want"
        continue
    fi

    [ "$source_ext" = "ts" ] && continue

    printf 'export const value = 1;\n' > "src/mod.$source_ext"
    printf 'import { value } from "./mod.%s";\n\nexport const doubled = (): number => value * 2;\n' \
        "$want" > "src/use.$source_ext"

    if biome_ci "$work/biome-map-$source_ext.log"; then
        pass "biome — the extensionMappings row .$(safe_report "$source_ext") -> .$want is accepted"
    else
        fail "biome — an import spelling .$want from a .$(safe_report "$source_ext") source was rejected; the mapping row is wrong or missing" "$work/biome-map-$source_ext.log"
    fi

    # The accepting run above is satisfied by "Biome said nothing", which is also
    # what a Biome that does not analyse this extension at all says. That is the
    # difference between the row being IN FORCE and merely not objected to, and only
    # the negative direction can tell them apart: the same source importing a
    # spelling the row does NOT map must be reported, by useImportExtensions
    # specifically. Without this, the hand table catches a changed target and the
    # completeness loop catches a dropped row, but neither catches Biome ceasing to
    # honour the option for that extension.
    printf 'import { value } from "./mod";\n\nexport const tripled = (): number => value * 3;\n' \
        > "src/bare.$source_ext"

    if biome_ci "$work/biome-map-neg-$source_ext.log"; then
        fail "biome — an extensionless import from a .$(safe_report "$source_ext") source was accepted, so useImportExtensions is not in force for that extension"
    elif grep -q 'lint/correctness/useImportExtensions' "$work/biome-map-neg-$source_ext.log"; then
        pass "biome — the extensionMappings row .$(safe_report "$source_ext") -> .$want is in force"
    else
        fail "biome — biome ci failed on a .$(safe_report "$source_ext") source, but not on useImportExtensions" "$work/biome-map-neg-$source_ext.log"
    fi

    rm "src/bare.$source_ext"
    rm "src/mod.$source_ext" "src/use.$source_ext"
done <<<"$mappings"

# A row DELETED from the base leaves the derived list without that line, so the
# loop never reaches it and no assertion runs — silence that reads as success.
for source_ext in "${!expected_target[@]}"; do
    if [ -z "${seen_row[$source_ext]:-}" ]; then
        fail "biome/base.json no longer maps the .$(safe_report "$source_ext") extension, which this smoke proves — the row was dropped rather than retargeted"
    fi
done

# The other half of the same option choice, and the one that made the blunt
# spelling wrong for a SHARED base. `forceJsExtensions: true` settles the TS/tsc
# conflict above, but it rewrites the suggestion for every extension rather than
# the TypeScript ones: measured against Biome 2.5.5, a stylesheet and a JSON asset
# import are both reported, each carrying a SAFE fix that points at a `.js` path
# which does not exist — so `biome check --write` or an editor save-action breaks
# the consumer's build silently. `extensionMappings` maps ts/tsx→js and
# mts/cts→mjs/cjs and leaves everything else alone. Without a fixture importing a
# non-TS asset the smoke proved only the direction that both spellings share.
# Both fixtures are written already formatted to the shared ruleset. Biome checks
# every file it is pointed at, so a one-line stylesheet would fail on formatter
# drift and the control would report "not on useImportExtensions" — a red that
# says nothing about the rule under test.
cat > src/theme.css <<'CSS'
body {
    color: red;
}
CSS

cat > src/palette.json <<'JSON'
{ "accent": "#b60205" }
JSON

cat > src/assets.ts <<'TS'
import palette from "./palette.json";

import "./theme.css";

export const accent = (): unknown => palette;
TS

if biome_ci "$work/biome-asset-imports.log"; then
    pass "biome — a stylesheet and a JSON asset import are left alone"
elif grep -q 'lint/correctness/useImportExtensions' "$work/biome-asset-imports.log"; then
    fail "biome — an asset import was told to add a .js extension; the base is back on a blanket rewrite" "$work/biome-asset-imports.log"
else
    fail "biome — the asset fixture failed, but not on useImportExtensions" "$work/biome-asset-imports.log"
fi

rm src/assets.ts src/theme.css src/palette.json src/imported.ts

# The two resolution latitudes the gate hard-codes, asserted against the tools
# that grant them rather than against the gate's own fixtures. bin/check-consumer-
# config.php accepts an extensionless specifier for tsconfig and requires the
# suffix for Biome, on the strength of a prose comment naming tsc 7.0.2 and Biome
# 2.5.5. A pinned-version bump that changed either resolver would leave every
# fixture case green while the gate rejected working consumer configs — this is
# the only place both real tools run, so the asymmetry is pinned here.
# Minimal only in the dimension under test. Dropping `noEmit` and `include` as
# well would make tsc EMIT into the shared fixture tree and sweep every .ts under
# $work rather than src/ — the restore below puts the config back but nothing
# removes a stray `src/clean.js`, and the next assertion that reads the tree would
# take tsc's own output for a fixture.
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base",\n    "compilerOptions": { "noEmit": true },\n    "include": ["src"]\n}\n' > tsconfig.json

if run_tsc "$work/tsc-extensionless.log"; then
    pass "tsc — resolves the extensionless specifier, as the gate assumes"
else
    fail "tsc — no longer resolves the extensionless specifier; the gate's suffixOptional=true is wrong" "$work/tsc-extensionless.log"
fi

cp "$root/tests/consumer/tsconfig.json" tsconfig.json

printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base"]\n}\n' > biome.json

if biome_ci "$work/biome-extensionless.log"; then
    fail "biome — resolved the extensionless specifier; the gate rejects a config that works" "$work/biome-extensionless.log"
elif grep -qi 'not found\|could not resolve' "$work/biome-extensionless.log"; then
    pass "biome — refuses the extensionless specifier, as the gate assumes"
else
    fail "biome — failed on the extensionless specifier, but not by failing to resolve it" "$work/biome-extensionless.log"
fi

cp "$root/tests/consumer/biome.json" biome.json

# The shared base deliberately carries NO `vcs` block, and this run is why: with
# `vcs.useIgnoreFile: true` in it, Biome aborts with `couldn't find an ignore
# file` in any consumer that has no .gitignore beside its config — a
# configuration error, not a finding, so the whole run dies. Excluding build
# output is the consumer's call, made where the build output is known; the base
# must not make an ignore file a precondition for loading at all.
#
# That guarantee holds only while this fixture has no .gitignore, which was
# recorded in prose and asserted by nothing: a later step, an npm version that
# scaffolds one, or a copied fixture would void it silently and every run would
# stay green.
if [ -e .gitignore ]; then
    fail "the fixture grew a .gitignore — the no-vcs-block guarantee is no longer proven by the runs above"
else
    pass "the fixture carries no .gitignore, so the base is proven loadable without one"
fi

# `noUncheckedIndexedAccess` comes only from the shared base; without it this
# compiles cleanly, so a consumer silently dropping the extends would go unnoticed.
cat > src/unchecked.ts <<'TS'
export const first = (values: string[]): string => {
    const value: string = values[0];

    return value;
};
TS

# Same rule as the biome controls, and the exposure here is concrete: if the
# `files` allow-list ever stopped shipping tsconfig/, tsc would exit non-zero
# with TS5083 "Cannot read file …/tsconfig/base.json" — and an exit-status
# control would report the shared base as in force precisely because it is gone.
if run_tsc "$work/tsc-dirty.log"; then
    fail "tsc control — noUncheckedIndexedAccess did not bite, the shared base is not in force"
elif grep -q 'unchecked.ts' "$work/tsc-dirty.log" && grep -q 'TS2322' "$work/tsc-dirty.log"; then
    pass "tsc control — noUncheckedIndexedAccess rejected the unchecked index"
else
    fail "tsc control — tsc failed, but not on the unchecked index" "$work/tsc-dirty.log"
fi

rm src/unchecked.ts

# --- templates/jscpd.json: the format names, against jscpd itself ------------
#
# The template's own note explains why this control has to exist: jscpd's `format`
# takes FORMAT names, and an unknown one is NOT an error — it silently analyses
# nothing and the run reports a clean tree. Measured: on two near-identical
# TypeScript functions, `["ts"]` prints "No duplicates found" and exits 0 while
# `["typescript"]` reports the clone and exits 1.
#
# The lockstep gate can only deny-list the six extension spellings that look
# right; it cannot tell whether the names the template writes are names jscpd
# still recognises. Nothing else in the repository runs jscpd, so a rename or a
# dropped format on the tool's side would leave every consumer with a green JS/TS
# clone gate that detects nothing. That is the exact "looks active, enforces
# nothing" failure this template was widened to close.
#
# The list is DERIVED from the template rather than hand-picked here, so a name
# added there without a fixture cannot ship: driving only `typescript` left
# `javascript`, `jsx` and `tsx` — the other three this change added — unproven,
# and misspelling any of them stayed green through the whole suite.
cp "$root/templates/jscpd.json" .jscpd.json

# BIOME is the tool that refuses a config carrying an unknown key — the trap its
# shared base fell into once. jscpd does not: it reads JSON5 and ignores `"//"`,
# which is why the template can carry its note there at all. The runs below use
# the template verbatim rather than a stripped copy, because the copy a consumer
# makes is what the gate checks.

# The one file extension jscpd parses each format name from. `php` is IN here:
# it was skipped on the reasoning that "the PHP half is exercised by the
# Composer-side gates", which is not true — nothing in this repository runs jscpd
# except this block, so `php` was the one format name excluded from both
# directions of the bijection while being the one every consumer of a PHP
# standard copies. jscpd tokenises PHP in Node, so covering it costs no runtime.
declare -A jscpd_extension=(
    [php]=php
    [javascript]=js
    [typescript]=ts
    [jsx]=jsx
    [tsx]=tsx
)

# The two bodies are IDENTICAL and only the exported name differs. jscpd matches
# token sequences, so renaming the parameters as well — the shape a hand-written
# "near-identical" pair naturally takes — breaks the sequence and the fixture finds
# nothing for a reason that has nothing to do with the format names. Typed
# annotations would do the same on a .js/.jsx fixture, so the body carries none.
jscpd_body() { # <name> <extension>
    if [ "$2" = "php" ]; then
        cat <<PHP
<?php

function $1(array \$values): string {
    \$total = array_sum(\$values);
    \$average = count(\$values) === 0 ? 0 : \$total / count(\$values);
    \$highest = count(\$values) === 0 ? 0 : max(\$values);
    \$lowest = count(\$values) === 0 ? 0 : min(\$values);
    \$spread = \$highest - \$lowest;
    \$count = count(\$values);
    \$label = \$count === 1 ? 'value' : 'values';

    return sprintf('%d %s: total %d, average %d, spread %d', \$count, \$label, \$total, \$average, \$spread);
}
PHP
        return
    fi

    cat <<TS
export const $1 = (values) => {
    const total = values.reduce((carry, value) => carry + value, 0);
    const average = values.length === 0 ? 0 : total / values.length;
    const highest = values.length === 0 ? 0 : Math.max(...values);
    const lowest = values.length === 0 ? 0 : Math.min(...values);
    const spread = highest - lowest;
    const count = values.length;
    const label = count === 1 ? "value" : "values";

    return \`\${count} \${label}: total \${total}, average \${average}, spread \${spread}\`;
};
TS
}

jscpd_formats="$(ROOT="$root" node -e 'process.stdout.write(
    require(process.env.ROOT + "/templates/jscpd.json").format.join("\n"))')" || true

if [ -z "$jscpd_formats" ]; then
    fail "could not read the format list from templates/jscpd.json — the jscpd controls did not run"
    exit 1
fi

declare -A seen_format=()

while IFS= read -r format; do
    [ -n "$format" ] || continue
    extension="${jscpd_extension[$format]:-}"

    if [ -z "$extension" ]; then
        fail "templates/jscpd.json names the format \"$(safe_report "$format")\", which this smoke has no fixture extension for — add one rather than shipping it unproven"
        continue
    fi

    seen_format[$format]=1

    rm -rf jscpd-fixture
    mkdir -p jscpd-fixture/src
    jscpd_body summarise "$extension" > "jscpd-fixture/src/one.$extension"
    jscpd_body describe "$extension" > "jscpd-fixture/src/two.$extension"

    # minTokens/minLines come from the template; the fixture has to clear them, or
    # a clean run would prove the thresholds rather than the format names.
    if npx --no-install jscpd --config .jscpd.json --pattern "**/*.$extension" jscpd-fixture/src > "$work/jscpd-$format.log" 2>&1; then
        fail "jscpd control — no clone found in two identical .$(safe_report "$extension") files; the \"$(safe_report "$format")\" format name no longer analyses anything" "$work/jscpd-$format.log"
    elif grep -qiE 'clone|duplicat' "$work/jscpd-$format.log"; then
        pass "jscpd — the template's \"$(safe_report "$format")\" format name is recognised and the clone is found"
    else
        fail "jscpd control — the \"$(safe_report "$format")\" run failed, but not by reporting the clone" "$work/jscpd-$format.log"
    fi
done <<<"$jscpd_formats"

# A format DROPPED from the template leaves the derived list without that line,
# so the loop never reaches it and no assertion runs — the same silence the
# extensionMappings completeness pass above exists for. Every consumer copying
# the narrowed template would then run a clone gate blind to that format.
for format in "${!jscpd_extension[@]}"; do
    if [ -z "${seen_format[$format]:-}" ]; then
        fail "templates/jscpd.json no longer names the \"$(safe_report "$format")\" format, which this smoke proves — the entry was dropped rather than renamed"
    fi
done

rm -rf jscpd-fixture .jscpd.json

verdict
