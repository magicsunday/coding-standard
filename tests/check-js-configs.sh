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

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

failed=0

pass() { printf 'OK       %s\n' "$1"; }
fail() { printf 'FAILED   %s\n' "$1" >&2; failed=1; }

# --- pack and install exactly as a consumer receives the package -------------

tarball="$(cd "$root" && npm pack --pack-destination "$work" --silent | tail -n1)"

cd "$work"
npm init -y >/dev/null 2>&1

# The tools come from the root devDependencies, which are pinned exactly. An
# unpinned `npm install @biomejs/biome` would resolve to whatever is newest at
# the moment CI runs, so a release on the tool's side could red the build on a
# day nothing changed here — and worse, a green run would not say which version
# it proved. Dependabot bumps the pins; this smoke is what vets the bump.
tools="$(node -e 'const d=require("'"$root"'/package.json").devDependencies;
process.stdout.write(Object.entries(d).map(([n, v]) => n + "@" + v).join(" "))')"

if [ -z "$tools" ]; then
    fail "no devDependencies in package.json — nothing to pin the smoke to"
    exit 1
fi

printf 'INFO     tools under test: %s\n' "$tools"

# shellcheck disable=SC2086 # deliberate word splitting: one npm arg per tool
npm install --no-audit --no-fund --silent "$work/$tarball" $tools >/dev/null 2>&1

# Prove the `files` allow-list actually shipped the configs.
for config in biome/base.json tsconfig/base.json; do
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

if npx --no-install biome ci --error-on-warnings --colors=off . >"$work/biome.log" 2>&1; then
    pass "biome ci — shared config loads and the clean fixture passes"
else
    fail "biome ci — shared config rejected or the clean fixture reported findings"
    sed -n '1,40p' "$work/biome.log" >&2
fi

if npx --no-install tsc -p tsconfig.json >"$work/tsc.log" 2>&1; then
    pass "tsc — shared config loads and the clean fixture compiles"
else
    fail "tsc — shared config rejected or the clean fixture failed to compile"
    sed -n '1,40p' "$work/tsc.log" >&2
fi

# --- controls: the shared rules must actually bite ---------------------------

# `noDoubleEquals` is "error" in the shared linter block; a consumer that only
# inherited Biome's own recommended set would still flag it, so pair it with
# formatter drift (2-space indent, single quotes), which is purely ours.
cat > src/dirty.ts <<'TS'
export const loose = (a: string, b: string): boolean => {
  return a == b;
};
TS

if npx --no-install biome ci --error-on-warnings --colors=off . >"$work/biome-dirty.log" 2>&1; then
    fail "biome control — a rule violation passed, the shared linter is not in force"
else
    pass "biome control — rule violation rejected"
fi

rm src/dirty.ts

# `noUncheckedIndexedAccess` comes only from the shared base; without it this
# compiles cleanly, so a consumer silently dropping the extends would go unnoticed.
cat > src/unchecked.ts <<'TS'
export const first = (values: string[]): string => {
    const value: string = values[0];

    return value;
};
TS

if npx --no-install tsc -p tsconfig.json >"$work/tsc-dirty.log" 2>&1; then
    fail "tsc control — noUncheckedIndexedAccess did not bite, the shared base is not in force"
else
    pass "tsc control — noUncheckedIndexedAccess rejected the unchecked index"
fi

rm src/unchecked.ts

exit "$failed"
