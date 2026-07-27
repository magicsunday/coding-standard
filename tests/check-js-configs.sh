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
tools=""
tools="$(ROOT="$root" node -e 'const d=require(process.env.ROOT + "/package.json").devDependencies;
process.stdout.write(Object.entries(d).map(([n, v]) => n + "@" + v).join(" "))' 2>/dev/null)" || true

if [ -z "$tools" ]; then
    fail "no devDependencies in package.json — nothing to pin the smoke to"
    exit 1
fi

printf 'INFO     tools under test: %s\n' "$tools"

# Enforce the engines floor rather than documenting it. npm only WARNS on
# EBADENGINE unless engine-strict is set, so without this a CI runner drifting
# below the floor — or a `node-version` edit — would go green and the floor would
# quietly mean nothing.
# Take the FIRST numeric group, not every digit in the string: stripping all
# non-digits reads the ordinary spelling ">=24.0.0" as the floor 2400, which is
# above every real version, so the check would hard-fail on a runner that
# satisfies the floor. Only ">=24" happens to survive that, and the floor will
# not stay dot-free forever.
ROOT="$root" node -e '
const pkg = require(process.env.ROOT + "/package.json");
const want = parseInt(String(pkg.engines?.node ?? "").match(/(\d+)/)?.[1] ?? "", 10);
const have = parseInt(process.versions.node.split(".")[0], 10);
if (!Number.isInteger(want)) {
    console.error("package.json declares no parseable engines.node floor");
    process.exit(1);
}
if (have < want) {
    console.error(`node ${process.versions.node} is below the engines floor >=${want}`);
    process.exit(1);
}
console.log(`INFO     node ${process.versions.node} (engines floor >=${want})`);
'

# A registry hiccup or a bad pin would otherwise abort the script here with no
# output at all — the same red as a genuine config regression, and with the EXIT
# trap deleting $work there is nothing left in the CI log to tell them apart.
# shellcheck disable=SC2086 # deliberate word splitting: one npm arg per tool
if ! npm install --no-audit --no-fund "$work/$tarball" $tools >"$work/npm-install.log" 2>&1; then
    fail "npm install failed — cannot run the smoke" "$work/npm-install.log"
    exit 1
fi

# Prove the `files` allow-list actually shipped the configs. Derived from the
# working tree rather than listed here, so a shared config added later is covered
# without anyone remembering to extend this loop.
mapfile -t shipped < <(cd "$root" && find biome tsconfig -name '*.json' | sort)

if [ "${#shipped[@]}" -eq 0 ]; then
    fail "found no shared configs in the working tree — the source layout changed"
    exit 1
fi

for config in "${shipped[@]}"; do
    if [ -f "node_modules/@magicsunday/coding-standard/$config" ]; then
        pass "packed: $config"
    else
        fail "packed: $config — missing from the npm tarball (check package.json \"files\")"
    fi
done

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
# is that they disagree — without `forceJsExtensions` Biome demanded `.ts`, which
# tsc then rejects with TS5097 unless allowImportingTsExtensions is on. No
# spelling satisfied both tools, so every consumer would have had to override the
# rule the base is meant to settle.
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

rm src/importer.ts src/imported.ts

# The shared base deliberately carries NO `vcs` block, and this run is why: with
# `vcs.useIgnoreFile: true` in it, Biome aborts with `couldn't find an ignore
# file` in any consumer that has no .gitignore beside its config — a
# configuration error, not a finding, so the whole run dies. Excluding build
# output is the consumer's call, made where the build output is known; the base
# must not make an ignore file a precondition for loading at all. This fixture
# has no .gitignore, so the runs above prove the base stays loadable without one.

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

exit "$failed"
