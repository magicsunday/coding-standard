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

work="$(mktemp -d)"
work="$(CDPATH= cd -- "$work" && pwd)"
trap 'rm -rf "$work"' EXIT

failed=0

pass() { printf 'OK       %s\n' "$1"; }

# The optional second argument is a log to excerpt, so each failure carries its
# own diagnostic instead of leaving the CI log with a bare FAILED line.
fail() {
    printf 'FAILED   %s\n' "$1" >&2
    failed=1

    if [ "$#" -gt 1 ]; then
        sed -n '1,40p' "$2" >&2
    fi
}

# The bookkeeping is proven before anything relies on it. Every control below
# reports through `fail`, which both PRINTS and sets the flag the exit code is
# built from — so if that assignment is lost, each control degrades into a print
# statement and the run says FAILED on every line while exiting 0. Measured on the
# sibling harness: dropping the increment left a drifted gate reporting failures
# with exit 0, i.e. the whole layer silently off. Nothing else can catch that,
# because the controls it disables are the only things that would have reported.
#
# Run in a subshell, so the probe cannot touch the real flag.
if ! ( failed=0; fail 'bookkeeping self-test'; [ "$failed" -eq 1 ] ) >/dev/null 2>&1; then
    printf 'FAILED  harness bookkeeping: fail() does not raise the failure flag\n' >&2
    exit 1
fi

# One definition per tool. A control only proves anything if it runs the exact
# invocation the green run does, and a copy-paste only promises that.
biome_ci() { npx --no-install biome ci --error-on-warnings --colors=off . >"$1" 2>&1; }
run_tsc()  { npx --no-install tsc -p tsconfig.json >"$1" 2>&1; }

# --- pack and install exactly as a consumer receives the package -------------

# `npm pack --silent` can exit 0 with empty stdout, so `set -e` does not catch
# it; the install below would then be handed a directory rather than a tarball
# and the failure would surface as a misleading "check package.json files".
tarball="$(cd "$root" && npm pack --pack-destination "$work" --loglevel=error | tail -n1)"

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

printf 'INFO     tools under test: %s\n' "$tools"

# Enforce the devEngines floor rather than documenting it. The floor is declared
# in `devEngines` rather than `engines` on purpose: `engines` is consumer-facing —
# npm evaluates it on every install and prints EBADENGINE in the CONSUMER's log —
# and this package publishes two JSON files with no code that runs on Node at all,
# so it has no business constraining a consumer's runtime. `devEngines` constrains
# only this repository, which is where the floor belongs. It also means npm no
# longer checks anything on our behalf here, so this gate is now the only
# enforcement rather than a belt to npm's braces.
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

const want = parseInt(String(pkg.devEngines?.runtime?.version ?? "").match(/(\d+)/)?.[1] ?? "", 10);
const have = parseInt(process.versions.node.split(".")[0], 10);

if (!Number.isInteger(want)) {
    console.error("package.json declares no parseable devEngines.runtime.version floor");
    process.exit(1);
}
if (pkg.engines?.node !== undefined) {
    console.error("package.json declares engines.node — the Node floor belongs in devEngines, which does not ride into a consumer install");
    process.exit(1);
}
if (have < want) {
    console.error(`node ${process.versions.node} is below the devEngines floor >=${want}`);
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

let failed = false;

for (const [name, range] of Object.entries(pkg.peerDependencies ?? {})) {
    const pin = pkg.devDependencies?.[name];

    if (pin === undefined) {
        console.error(`peerDependencies declares ${name}, but no devDependencies pin proves it`);
        failed = true;
        continue;
    }

    // Only the caret form is evaluated. The comparison below reads the FIRST
    // version and nothing else, so `>=2.5.0 <2.5.5` would be accepted on the
    // strength of its floor while the pin 2.5.5 violates its ceiling. Rejecting
    // the shape is honest; approximating a full semver range here is not, and a
    // range this package cannot check has no business being declared by it.
    if (!/^\^\d+\.\d+\.\d+$/.test(range)) {
        console.error(`peerDependencies ${name} ${range} is not a plain caret range — this check evaluates ^X.Y.Z only, and would otherwise accept a range it cannot verify`);
        failed = true;
        continue;
    }

    const wanted = segments(range);
    const pinned = segments(pin);

    if ((wanted[0] !== pinned[0]) || below(pinned, wanted)) {
        console.error(`peerDependencies ${name} ${range} is not satisfied by the pinned ${pin} the smoke proves`);
        failed = true;
    }
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

manifest_fixture() { # <name> <package.json body>
    mkdir -p "$manifest_fixtures/$1"
    printf '%s\n' "$2" > "$manifest_fixtures/$1/package.json"
    printf '%s' "$manifest_fixtures/$1"
}

manifest_rejects() { # <dir> <label> <substring the diagnostic must carry>
    local out
    if out="$(manifest_check "$1" 2>&1)"; then
        fail "$2 — accepted, so the check does not discriminate"
    elif grep -qF -- "$3" <<<"$out"; then
        pass "$2"
    else
        fail "$2 — rejected, but not for the tested reason: $out"
    fi
}

manifest_rejects "$(manifest_fixture floor-above-runtime \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=999" } } }')" \
    "manifest control — a floor above the running Node is reported" \
    "below the devEngines floor"

manifest_rejects "$(manifest_fixture engines-readded \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } }, "engines": { "node": ">=24" } }')" \
    "manifest control — a re-added engines.node is reported" \
    "belongs in devEngines"

manifest_rejects "$(manifest_fixture peer-major-drift \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^1.9.0" } }')" \
    "manifest control — a peer range naming another major than the pin is reported" \
    "is not satisfied by the pinned"

# The only ACCEPT case among the manifest controls, and the one that reaches the
# numeric comparison at all. Every rejection above short-circuits before it —
# peer-major-drift on the major, peer-without-pin on the missing pin — so the
# whole `false` return of below() rested on this repository's own single-digit
# pins, and replacing its body with a string compare of the joined form changed
# no verdict anywhere. A pin whose minor is past nine is exactly what a string
# compare gets wrong: "2.10.0" sorts below "2.9.0".
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

# The floor-vs-pin half of the peer check, which no case reached: peer-major-drift
# short-circuits on the major, peer-without-pin continues before it, and the real
# manifest satisfies both. So dropping `below()` entirely kept every case green
# and the numeric-comparison rationale above it enforced nothing.
manifest_rejects "$(manifest_fixture peer-floor-above-pin \
    '{ "devEngines": { "runtime": { "name": "node", "version": ">=24" } },
       "devDependencies": { "@biomejs/biome": "2.5.5" },
       "peerDependencies": { "@biomejs/biome": "^2.9.0" } }')" \
    "manifest control — a peer floor above the pin, same major, is reported" \
    "is not satisfied by the pinned"

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
    "no devDependencies pin proves it"

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
# The `grep` filter is neutralised with `|| true`, or it decides the diagnostic:
# under `set -o pipefail` a no-match exit 1 fails the whole pipeline, so a tarball
# carrying no config at all — the regression this block exists to catch, `files`
# losing `biome`/`tsconfig` — reported "could not list the tarball contents" and
# pointed the reader at tar, while the guard below that names the real cause could
# never run. Filtering is not an error condition; only `tar` failing is.
listing=""
listing="$(tar -tzf "$work/$tarball" | sed -n 's~^package/~~p' | { grep -E '\.(json|md)$' || true; } | sort)" || {
    fail "could not list the tarball contents — nothing to verify"
    exit 1
}

mapfile -t shipped <<<"$listing"

if [ "${#shipped[@]}" -eq 0 ] || [ -z "${shipped[0]}" ]; then
    fail "the npm tarball carries no config files at all — check package.json \"files\""
    exit 1
fi

for config in "${shipped[@]}"; do
    if [ -f "node_modules/@magicsunday/coding-standard/$config" ]; then
        pass "packed: $config"
    else
        fail "packed: $config — in the tarball but not installed"
    fi
done

# The tarball is the artefact, but `files` is the declaration: every entry of it
# must be represented, or a directory silently stops shipping while the loop above
# stays green on whatever else is in there.
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
    if grep -qxF -- "$entry" <<<"$listing" \
        || grep -q -- "^$(printf '%s' "$entry" | sed 's/[][\.*^$\/]/\\&/g')/" <<<"$listing"; then
        pass "declared and packed: $entry"
    else
        fail "declared in package.json \"files\" but absent from the tarball: $entry"
    fi
done <<<"$declared"

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
        fail "biome/base.json maps the .$source_ext extension, which this smoke has no proven target for — add one rather than shipping the row unproven"
        continue
    fi

    seen_row[$source_ext]=1

    if [ "$target_ext" != "$want" ]; then
        fail "biome/base.json maps .$source_ext to .$target_ext; the target this smoke proves is .$want"
        continue
    fi

    [ "$source_ext" = "ts" ] && continue

    printf 'export const value = 1;\n' > "src/mod.$source_ext"
    printf 'import { value } from "./mod.%s";\n\nexport const doubled = (): number => value * 2;\n' \
        "$want" > "src/use.$source_ext"

    if biome_ci "$work/biome-map-$source_ext.log"; then
        pass "biome — the extensionMappings row .$source_ext -> .$want is in force"
    else
        fail "biome — an import spelling .$want from a .$source_ext source was rejected; the mapping row is wrong or missing" "$work/biome-map-$source_ext.log"
    fi

    rm "src/mod.$source_ext" "src/use.$source_ext"
done <<<"$mappings"

# A row DELETED from the base leaves the derived list without that line, so the
# loop never reaches it and no assertion runs — silence that reads as success.
for source_ext in "${!expected_target[@]}"; do
    if [ -z "${seen_row[$source_ext]:-}" ]; then
        fail "biome/base.json no longer maps the .$source_ext extension, which this smoke proves — the row was dropped rather than retargeted"
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

# jscpd refuses a config carrying an unknown key, and the template's note lives in
# `"//"` — the same trap the Biome base fell into once. It is legal here (jscpd
# reads JSON5 and ignores it), but the copy a consumer makes is what the gate
# checks, so the runs below use the template verbatim rather than a stripped copy.

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
        fail "templates/jscpd.json names the format \"$format\", which this smoke has no fixture extension for — add one rather than shipping it unproven"
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
        fail "jscpd control — no clone found in two identical .$extension files; the \"$format\" format name no longer analyses anything" "$work/jscpd-$format.log"
    elif grep -qiE 'clone|duplicat' "$work/jscpd-$format.log"; then
        pass "jscpd — the template's \"$format\" format name is recognised and the clone is found"
    else
        fail "jscpd control — the \"$format\" run failed, but not by reporting the clone" "$work/jscpd-$format.log"
    fi
done <<<"$jscpd_formats"

# A format DROPPED from the template leaves the derived list without that line,
# so the loop never reaches it and no assertion runs — the same silence the
# extensionMappings completeness pass above exists for. Every consumer copying
# the narrowed template would then run a clone gate blind to that format.
for format in "${!jscpd_extension[@]}"; do
    if [ -z "${seen_format[$format]:-}" ]; then
        fail "templates/jscpd.json no longer names the \"$format\" format, which this smoke proves — the entry was dropped rather than renamed"
    fi
done

rm -rf jscpd-fixture .jscpd.json

exit "$failed"
