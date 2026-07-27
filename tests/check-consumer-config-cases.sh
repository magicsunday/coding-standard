#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for bin/check-consumer-config.php. Proves the lockstep gate
# ACCEPTS the canon and REJECTS each drift class — including the section-scoping
# edge cases (a `- php` under the wrong YAML list, editorconfig indent set only in a
# narrow section while `[*]` uses tabs) that a naive per-line regex would miss.
#
# Run from the package root: bash tests/check-consumer-config-cases.sh

set -euo pipefail

# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GATE="$ROOT/bin/check-consumer-config.php"
FIXTURE="$ROOT/tests/consumer"

fails=0

# assert_accepts <dir> <label>
assert_accepts() {
    local dir="$1" label="$2" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    if [ "$rc" -ne 0 ]; then
        printf 'FAIL (expected accept): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (accepted): %s\n' "$label"
    fi
}

# assert_rejects <dir> <label> <expected-substring>
#
# Requires a nonzero exit AND that the report names the SPECIFIC violation under
# test — so a case cannot "pass" because it was rejected for an unrelated reason
# (a missing phpunit.xml, a broken fixture), which would give false confidence.
assert_rejects() {
    local dir="$1" label="$2" expected="$3" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    if [ "$rc" -eq 0 ]; then
        printf 'FAIL (expected reject): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    elif ! grep -qF "$expected" <<<"$out"; then
        printf 'FAIL (rejected, but not for the tested reason): %s\n  expected substring: %s\n%s\n' "$label" "$expected" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (rejected on the tested violation): %s\n' "$label"
    fi
}

# assert_reports_once <dir> <label> <file prefix>
#
# The two assert_* helpers above grep for the PRESENCE of one substring, so they
# cannot express "and nothing further was said about this file" — which is exactly
# the property a read-failure path needs, since the defect it guards against is an
# EXTRA fabricated violation rather than a missing one.
assert_reports_once() {
    local dir="$1" label="$2" prefix="$3" out rc count
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    count="$(grep -cF -- "- $prefix:" <<<"$out" || true)"

    if [ "$rc" -eq 0 ]; then
        printf 'FAIL (expected reject): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    elif [ "$count" -ne 1 ]; then
        printf 'FAIL (expected exactly one %s violation, got %s): %s\n%s\n' "$prefix" "$count" "$label" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (reported exactly once): %s\n' "$label"
    fi
}

# The canonical fixture must be accepted.
assert_accepts "$FIXTURE" "canon fixture"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# --- phpunit.xml drift classes ---
mk_case() {
    local name="$1"
    local dir="$work/$name"
    mkdir -p "$dir"
    cp "$FIXTURE/phpunit.xml" "$dir/phpunit.xml"
    printf '%s' "$dir"
}

d="$(mk_case cov-off)"
sed -i 's/requireCoverageMetadata="true"/requireCoverageMetadata="false"/' "$d/phpunit.xml"
assert_rejects "$d" "requireCoverageMetadata disabled" "requireCoverageMetadata"

d="$(mk_case notice-gone)"
sed -i '/failOnNotice="true"/d' "$d/phpunit.xml"
assert_rejects "$d" "failOnNotice removed" "failOnNotice"

d="$(mk_case source-loose)"
sed -i 's/restrictNotices="true"/restrictNotices="false"/' "$d/phpunit.xml"
assert_rejects "$d" "<source> restrictNotices disabled" "restrictNotices"

# --- .phplint.yml: `- php` present but OUTSIDE the extensions block ---
d="$work/phplint-wrong-block"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.phplint.yml" <<'YML'
path:
    - php
extensions:
    - phtml
YML
assert_rejects "$d" ".phplint.yml with php under path, not extensions" "\`extensions:\` block"

# --- deptrac.yaml: present but dropping the shared import (silent arch-drop) ---
d="$work/deptrac-no-import"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
deptrac:
    paths:
        - src
YML
assert_rejects "$d" "deptrac.yaml dropping the shared import" "must import the shared"

# --- deptrac.yaml: valid, imports the shared ruleset (build-dir path prefix) ---
d="$work/deptrac-ok"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml
deptrac:
    paths:
        - src
YML
assert_accepts "$d" "deptrac.yaml importing the shared ruleset"

# --- deptrac.yaml: shared path present but under the WRONG key (not imports) ---
d="$work/deptrac-wrong-key"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
deptrac:
    paths:
        - src
    exclude_files:
        - vendor/magicsunday/coding-standard/deptrac/layers.yaml
YML
assert_rejects "$d" "deptrac.yaml with the shared path under the wrong key" "must import the shared"

# --- deptrac.yaml: near-miss vendor namespace must NOT satisfy the gate ---
d="$work/deptrac-near-miss"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - vendor/notmagicsunday/coding-standard/deptrac/layers.yaml
deptrac:
    paths:
        - src
YML
assert_rejects "$d" "deptrac.yaml importing a near-miss (notmagicsunday) path" "must import the shared"

# --- deptrac.yaml: quoted scalar + trailing comment is accepted ---
d="$work/deptrac-quoted"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - 'vendor/magicsunday/coding-standard/deptrac/layers.yaml' # shared ruleset
deptrac:
    paths:
        - src
YML
assert_accepts "$d" "deptrac.yaml with a quoted import + inline comment"

# --- .editorconfig: [*] indent_style flipped to tab (only that dimension drifts) ---
# The fixture is canon in every other respect (indent_size, root, Makefile) so the
# ONLY violation is the [*] indent_style, and the substring discriminates exactly it.
d="$work/editorconfig-star-tab"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = tab
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with indent_style = tab in [*]" "must set \`indent_style = space\`"

# Editors honour a BOM'd .editorconfig — editorconfig-core-js reads one and
# returns its settings, because JavaScript's `\s` matches U+FEFF. PHP's trim()
# does not, so without the strip the key parses as "\u{FEFF}root" and a file
# every editor obeys is reported as drift.
d="$(mk_case editorconfig-bom)"
printf '\xEF\xBB\xBF' > "$d/.editorconfig"
cat "$ROOT/templates/editorconfig" >> "$d/.editorconfig"
assert_accepts "$d" ".editorconfig saved with a UTF-8 BOM"

# --- .jscpd.json: stale v4 reporter name ---
d="$work/jscpd-v4"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["consoleFull"]
}
JSON
assert_rejects "$d" ".jscpd.json on the removed v4 reporter name" "console-full"

# --- .jscpd.json: minLines raised to an effectively disabling value ---
d="$work/jscpd-minlines"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 9999,
    "exitCode": 1,
    "reporters": ["console-full"]
}
JSON
assert_rejects "$d" ".jscpd.json with minLines raised to disable detection" "minLines"

# --- POSITIVE: the full canonical template set as a consumer would carry it ---
d="$work/canon-full"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cp "$ROOT/templates/editorconfig" "$d/.editorconfig"
cp "$ROOT/templates/jscpd.json" "$d/.jscpd.json"
cp "$ROOT/templates/phplint.yml" "$d/.phplint.yml"
assert_accepts "$d" "full canonical template set"

# --- .editorconfig: root = true moved below a section header (invalid position) ---
d="$work/editorconfig-root-in-section"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
[*]
root = true
indent_style = space
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with root inside a section" "root = true"

# --- .editorconfig: the Makefile tab override dropped ---
d="$work/editorconfig-no-makefile"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 4
EC
assert_rejects "$d" ".editorconfig without the Makefile tab override" "{Makefile,*.mk}"

# --- phpunit.xml: <source> restrictWarnings loosened (twin of the tested restrictNotices) ---
d="$(mk_case source-warnings)"
sed -i 's/restrictWarnings="true"/restrictWarnings="false"/' "$d/phpunit.xml"
assert_rejects "$d" "<source> restrictWarnings disabled" "restrictWarnings"

# --- .jscpd.json: each zero-tolerance threshold, one per fixture ---
jscpd_fixture() { # <dir> <mutation-jq-free sed on the good json>
    local dir="$1"
    mkdir -p "$dir"
    cp "$FIXTURE/phpunit.xml" "$dir/phpunit.xml"
    cat > "$dir/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["console-full"]
}
JSON
}

d="$work/jscpd-threshold"; jscpd_fixture "$d"
sed -i 's/"threshold": 0/"threshold": 5/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json threshold raised above zero" "threshold"

d="$work/jscpd-exitcode"; jscpd_fixture "$d"
sed -i 's/"exitCode": 1/"exitCode": 0/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json exitCode not 1" "exitCode"

d="$work/jscpd-mintokens"; jscpd_fixture "$d"
sed -i 's/"minTokens": 100/"minTokens": 9999/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json minTokens raised to disable detection" "minTokens"

# The format-name footgun the widened template warns about: an extension
# spelling is not an error, it analyses nothing and reports a clean run.
d="$work/jscpd-format-ts"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": ["php", "ts"]/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json using the \"ts\" extension as a format name" 'Use "typescript"'

d="$work/jscpd-format-js"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": ["js"]/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json using the \"js\" extension as a format name" 'Use "javascript"'

# The counterpart, so the check cannot be satisfied by rejecting every format:
# the canonical names must pass, as must a config that declares none at all
# (jscpd then applies its own defaults, which still detects).
d="$work/jscpd-format-canonical"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": ["php", "javascript", "typescript", "jsx", "tsx"]/' "$d/.jscpd.json"
assert_accepts "$d" ".jscpd.json using jscpd's own format names"

d="$work/jscpd-format-absent"; jscpd_fixture "$d"
assert_accepts "$d" ".jscpd.json declaring no format at all"

# --- phpunit.xml layout checks: source include, testsuite dir, Architecture exclude ---
d="$(mk_case no-src-include)"
# The only <directory>src</directory> is the <source><include> one (the suite uses
# tests); point it elsewhere so `src` is no longer covered.
sed -i 's#<directory>src</directory>#<directory>lib</directory>#' "$d/phpunit.xml"
assert_rejects "$d" "<source><include> no longer covering src" "must cover the \`src\` directory"

d="$(mk_case no-tests-suite)"
sed -i 's#<directory>tests</directory>#<directory>test</directory>#' "$d/phpunit.xml"
assert_rejects "$d" "test suite not running tests/" "must run the \`tests\` directory"

# The tests/Architecture exclude branch only fires when that dir exists. The canon
# fixture has no <exclude> line, so with the dir present it must be REJECTED...
d="$work/arch-not-excluded"
mkdir -p "$d/tests/Architecture"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
assert_rejects "$d" "tests/Architecture present but not excluded" "must be excluded"

# ...and the paired positive control proves the gate keys on the EXCLUDE line, not
# merely on the directory's existence: same dir present, but the suite now excludes
# it → accepted. Without this pair a gate that rejected on the dir alone would pass.
d="$work/arch-excluded"
mkdir -p "$d/tests/Architecture"
sed 's#<directory>tests</directory>#<directory>tests</directory>\n            <exclude>tests/Architecture</exclude>#' "$FIXTURE/phpunit.xml" > "$d/phpunit.xml"
assert_accepts "$d" "tests/Architecture present and excluded"

# --- phpunit.xml: entirely missing, and not-well-formed ---
d="$work/no-phpunit"
mkdir -p "$d"
assert_rejects "$d" "phpunit.xml missing" "missing"

d="$work/phpunit-malformed"
mkdir -p "$d"
printf '<phpunit><broken' > "$d/phpunit.xml"
assert_rejects "$d" "phpunit.xml not well-formed" "not well-formed"

# --- phpunit.xml.dist fallback: the gate must find the canon under the .dist name ---
d="$work/phpunit-dist"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml.dist"
assert_accepts "$d" "strict config discovered as phpunit.xml.dist"

# --- .phplint.yml with CRLF line endings must still be accepted (the block regex
#     normalises \r first, so `- php\r` under extensions is not false-failed) ---
d="$work/phplint-crlf"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'path:\r\n    - src\r\n    - tests\r\nextensions:\r\n    - php\r\n' > "$d/.phplint.yml"
assert_accepts "$d" ".phplint.yml with CRLF line endings"

# --- .editorconfig: [*] indent_size flipped to 2 (the sibling of the indent_style case) ---
d="$work/editorconfig-star-size2"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 2

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with indent_size = 2 in [*]" "must set \`indent_size = 4\`"

# --- phpunit.xml: <source> element absent entirely ---
d="$(mk_case no-source)"
sed -i '/<source/,/<\/source>/d' "$d/phpunit.xml"
assert_rejects "$d" "phpunit.xml without a <source> element" "missing a <source>"

# --- .jscpd.json: not valid JSON ---
d="$work/jscpd-broken"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf '{ not json' > "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json not valid JSON" "not valid JSON"

# --- .editorconfig: no global [*] section at all ---
d="$work/editorconfig-no-star"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*.md]
indent_style = space
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig without a global [*] section" "must define a global \`[*]\` section"

# --- .editorconfig: lowercase Makefile glob (case-sensitive, does not match Makefile) ---
d="$work/editorconfig-lowercase-makefile"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 4

[{makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with a lowercase {makefile,*.mk} glob" "{Makefile,*.mk}"

# --- biome.json / tsconfig.json: the JS/TS extends contract ------------------
#
# mk_js_case gives a case the required phpunit.xml plus the canonical JS configs,
# so each case below can corrupt exactly one of them and be rejected for that
# reason alone.
mk_js_case() {
    local dir
    dir="$(mk_case "$1")"
    cp "$FIXTURE/biome.json" "$dir/biome.json"
    cp "$FIXTURE/tsconfig.json" "$dir/tsconfig.json"
    # The extends contract is asserted only for a repository that actually
    # consumes the npm package, so every case below has to declare it — the
    # non-adopter cases further down leave this out on purpose.
    cat > "$dir/package.json" <<'JSON'
{
    "name": "fixture",
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"
    }
}
JSON
    printf '%s' "$dir"
}

# The canon pair must be accepted — including the JSONC comment tsconfig.json
# legitimately carries, which a plain json_decode would have rejected.
d="$(mk_js_case js-canon)"
assert_accepts "$d" "canonical biome.json + tsconfig.json (with a JSONC comment)"

# The bug this package shipped: a "//" note key is valid JSON but makes Biome
# refuse the entire config.
d="$(mk_js_case biome-note-key)"
cat > "$d/biome.json" <<'JSON'
{
    "//": "shared config for this repo",
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_rejects "$d" "biome.json with a \"//\" note key" '`"//"` key'

# The same key nested one level down is just as fatal — Biome rejects unknown
# keys at any depth, so a top-level-only check would pass this vacuously.
d="$(mk_js_case biome-note-key-nested)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": {
        "//": "our overrides",
        "enabled": true
    }
}
JSON
assert_rejects "$d" "biome.json with a nested \"//\" key" '`"//"` key'

# The plain "no extends" case lives with the adoption pair further down, where it
# is the counterpart to the same config in a repository that has not adopted.

# A near-miss package name must NOT satisfy the extends check — the optional path
# prefix has to end at a segment boundary, the same rule the deptrac import uses.
d="$(mk_js_case biome-lookalike-extends)"
printf '{\n    "extends": ["notmagicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending a look-alike package" "biome/base.json"

# Reaching the same file through an explicit node_modules path is legitimate.
d="$(mk_js_case biome-node-modules-path)"
printf '{\n    "extends": ["./node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_accepts "$d" "biome.json extending via an explicit node_modules path"

# The pnpm layout reaches the package through a second node_modules segment.
d="$(mk_js_case biome-pnpm-path)"
printf '{\n    "extends": ["./node_modules/.pnpm/@magicsunday+coding-standard@1.7.0/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_accepts "$d" "biome.json extending via a pnpm node_modules path"

# An arbitrary local path that merely LOOKS like the package must not count: both
# tools would load that file instead of the installed one, so accepting it would
# report a link to a config nobody shares.
d="$(mk_js_case biome-local-lookalike)"
printf '{\n    "extends": ["./fixtures/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending a local look-alike copy outside node_modules" "biome/base.json"

# A literal `node_modules/` segment somewhere in an arbitrary path is not this
# repository's node_modules — both of these are loaded by the real tools INSTEAD
# of the installed package, which is the whole failure mode.
d="$(mk_js_case biome-nested-lookalike)"
printf '{\n    "extends": ["./fixtures/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending through a node_modules under an unrelated path" "biome/base.json"

d="$(mk_js_case biome-foreign-repo)"
printf '{\n    "extends": ["../../other-repo/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending another repository's node_modules" "biome/base.json"

# Biome does NOT resolve an extensionless specifier — verified, it answers with
# `module not found` — so the gate must not accept one either.
d="$(mk_js_case biome-extensionless)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending without the .json suffix" "biome/base.json"

# The scope is part of the package name. Neither tool resolves the unscoped
# spelling — Biome answers `module not found`, tsc `TS6053: File … not found` —
# so accepting it would report a link that cannot exist.
d="$(mk_js_case biome-unscoped)"
printf '{\n    "extends": ["magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json extending the unscoped package name" "biome/base.json"

d="$(mk_js_case ts-unscoped)"
printf '{\n    "extends": "magicsunday/coding-standard/tsconfig/base.json"\n}\n' > "$d/tsconfig.json"
assert_rejects "$d" "tsconfig.json extending the unscoped package name" "tsconfig/base.json"

# A specifier that is not a string at all must report as a missing link rather
# than fail the gate on a type error.
d="$(mk_js_case biome-extends-not-a-string)"
printf '{\n    "extends": 5\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json whose extends is not a specifier at all" "biome/base.json"

d="$(mk_js_case biome-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "enabled": false }
}
JSON
assert_rejects "$d" "biome.json with the linter disabled" "\`linter.enabled\` must not be false"

d="$(mk_js_case biome-recommended-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "recommended": false } }
}
JSON
assert_rejects "$d" "biome.json with the recommended set disabled" "\`linter.rules.recommended\`"

# The formatter is half the shared standard; disabling it is the same class of
# drift as disabling the linter, and shares the loop that reports it.
d="$(mk_js_case biome-formatter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "formatter": { "enabled": false }
}
JSON
assert_rejects "$d" "biome.json with the formatter disabled" "\`formatter.enabled\` must not be false"

# `preset: "none"` is the modern spelling of `recommended: false` — Biome
# deprecated the boolean in 2.5 — and silences exactly the same rules. Checking
# only the deprecated spelling would leave the current one unguarded.
d="$(mk_js_case biome-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "preset": "none" } }
}
JSON
assert_rejects "$d" "biome.json with the rule preset set to none" "\`linter.rules.preset\`"

# The counterpart: the preset a consumer is SUPPOSED to keep must pass, so the
# check above cannot be satisfied by rejecting the key outright.
d="$(mk_js_case biome-preset-recommended)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "preset": "recommended" } }
}
JSON
assert_accepts "$d" "biome.json keeping the recommended rule preset"

# Biome carries `recommended`/`preset` on every rule GROUP as well, so switching
# one group off drops that group's floor while the top-level keys stay untouched.
# Verified: with this, `biome ci` passes a file containing `debugger;`.
d="$(mk_js_case biome-group-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "preset": "none" } } }
}
JSON
assert_rejects "$d" "biome.json switching one rule group's preset to none" "linter.rules.suspicious.preset"

d="$(mk_js_case biome-group-recommended-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "correctness": { "recommended": false } } }
}
JSON
assert_rejects "$d" "biome.json switching one rule group's recommended off" "linter.rules.correctness.recommended"

# An overrides entry has its own linter/formatter block, so one matching `**`
# disables the shared standard for every file while the top level reads enabled.
d="$(mk_js_case biome-override-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["**"], "linter": { "enabled": false } }
    ]
}
JSON
assert_rejects "$d" "biome.json disabling the linter through an overrides entry" "overrides[0].linter.enabled"

d="$(mk_js_case biome-override-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["src/**"], "linter": { "rules": { "preset": "none" } } }
    ]
}
JSON
assert_rejects "$d" "biome.json dropping the rule floor through an overrides entry" "overrides[0].linter.rules.preset"

# Biome carries linter/formatter a THIRD time, per language — and there it
# silences the shared standard for every file of that language while the
# top-level keys still read as enabled. Verified against 2.5.5: with this config
# a `==` comparison and a 2-space indent both pass.
d="$(mk_js_case biome-language-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": { "linter": { "enabled": false } }
}
JSON
assert_rejects "$d" "biome.json disabling the linter for a whole language" "javascript.linter.enabled"

d="$(mk_js_case biome-language-formatter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": { "formatter": { "enabled": false } }
}
JSON
assert_rejects "$d" "biome.json disabling the formatter for a whole language" "javascript.formatter.enabled"

# The counterpart: a per-language block that only sets style options is normal
# consumer use and must not be reported.
d="$(mk_js_case biome-language-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": { "formatter": { "quoteStyle": "single" } }
}
JSON
assert_accepts "$d" "biome.json setting a per-language style option"

# A legitimate overrides entry — narrowing a single rule for one path — must not
# be reported, or the check would push consumers off a feature they need.
d="$(mk_js_case biome-override-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        {
            "includes": ["tests/**"],
            "linter": { "rules": { "suspicious": { "noExplicitAny": "off" } } }
        }
    ]
}
JSON
assert_accepts "$d" "biome.json narrowing a single rule for one path through overrides"

# A malformed config must be reported as such rather than silently skipped —
# json_decode returns null, which an `?? null` read cannot tell from "absent".
d="$(mk_js_case biome-malformed)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json"\n' > "$d/biome.json"
assert_rejects "$d" "biome.json that is not valid JSON(C)" "not valid JSON(C)"

# biome.jsonc is Biome's own alternative filename; the gate must find it there
# too. Asserted as a REJECT, because that is the only shape that proves discovery:
# an accept case stays green when the .jsonc candidate is dropped from the list —
# the fixture then has no biome file at all and the whole block is skipped.
d="$(mk_js_case biome-jsonc)"
rm "$d/biome.json"
cat > "$d/biome.jsonc" <<'JSON'
{
    // A jsonc file exists precisely so a consumer can comment it.
    "//": "and this note key makes it unloadable",
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_rejects "$d" "biome.jsonc is discovered, parsed with comments, and named in the report" "biome.jsonc: "

d="$(mk_js_case biome-jsonc-clean)"
rm "$d/biome.json"
cat > "$d/biome.jsonc" <<'JSON'
{
    // A jsonc file exists precisely so a consumer can comment it.
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_accepts "$d" "a clean biome.jsonc is accepted"

d="$(mk_js_case ts-no-extends)"
printf '{\n    "compilerOptions": { "strict": true }\n}\n' > "$d/tsconfig.json"
assert_rejects "$d" "tsconfig.json without the shared extends" "tsconfig/base.json"

d="$(mk_js_case ts-strict-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": false }
}
JSON
assert_rejects "$d" "tsconfig.json overriding strict to false" "\`compilerOptions.strict\`"

# The subtler override: `strict` stays on, but the flag the shared base adds ON
# TOP of strict is switched off. This is the realistic drift, and a check that
# only looked at `strict` would miss it.
d="$(mk_js_case ts-unchecked-index-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": true, "noUncheckedIndexedAccess": false }
}
JSON
assert_rejects "$d" "tsconfig.json disabling noUncheckedIndexedAccess" "noUncheckedIndexedAccess"

# An extends ARRAY is legal in TypeScript 5+; the shared base may sit anywhere in it.
d="$(mk_js_case ts-extends-array)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": ["./tsconfig.paths.json", "@magicsunday/coding-standard/tsconfig/base.json"],
    "compilerOptions": { "noEmit": true }
}
JSON
assert_accepts "$d" "tsconfig.json with the shared base in an extends array"

# Ergonomics flags are deliberately NOT pinned: turning skipLibCheck off is
# stricter, not looser, and must not be reported as drift.
d="$(mk_js_case ts-skiplibcheck-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "skipLibCheck": false }
}
JSON
assert_accepts "$d" "tsconfig.json turning skipLibCheck off (stricter, not drift)"

# A trailing comma is legal in tsconfig.json and must not read as malformed.
d="$(mk_js_case ts-trailing-comma)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "noEmit": true,
    },
}
JSON
assert_accepts "$d" "tsconfig.json with trailing commas"

# A "//" sequence INSIDE a string is not a comment — stripping it would corrupt
# the document and turn a valid consumer config into a false rejection.
d="$(mk_js_case ts-url-in-string)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "paths": { "@app/*": ["https://example.com/not-a-comment/*"] }
    }
}
JSON
assert_accepts "$d" "tsconfig.json with a // inside a string value"

# tsc appends `.json` itself, so this resolves to the very same file — verified
# with 7.0.2. Rejecting it would report drift on a working consumer config.
d="$(mk_js_case ts-extensionless)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base"\n}\n' > "$d/tsconfig.json"
assert_accepts "$d" "tsconfig.json extending without the .json suffix"

# The string-protection the trailing-comma pass needs: a comma before a bracket
# INSIDE a string value is part of the value, not punctuation to strip. Stripping
# it silently rewrote the consumer's path.
d="$(mk_js_case ts-comma-in-string)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "paths": { "@app/*": ["a,] b, } c"] }
    }
}
JSON
assert_accepts "$d" "tsconfig.json with a comma before a bracket inside a string"

# Block comments: the accept case, and the discriminating one — a multi-line
# block carrying a quote and a `//` must be closed rather than swallow the rest
# of the document, which would decode to an empty config that passes everything.
d="$(mk_js_case ts-block-comment)"
cat > "$d/tsconfig.json" <<'JSON'
{
    /* A consumer may comment this file. */
    "extends": "@magicsunday/coding-standard/tsconfig/base.json"
}
JSON
assert_accepts "$d" "tsconfig.json with a block comment"

d="$(mk_js_case ts-block-comment-swallow)"
cat > "$d/tsconfig.json" <<'JSON'
{
    /* a " and a // inside,
       spread over two lines */
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": false }
}
JSON
assert_rejects "$d" "tsconfig.json whose block comment must not swallow the rest" "\`compilerOptions.strict\`"

# A comment placed inside a token must not fuse the halves back together.
d="$(mk_js_case ts-comment-in-token)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "strict": tr/* x */ue }\n}\n' > "$d/tsconfig.json"
assert_rejects "$d" "tsconfig.json with a comment splitting a token" "not valid JSON(C)"

# Escaped backslashes inside a string must survive the pass intact.
d="$(mk_js_case ts-escapes)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "paths": { "@app/*": ["src\\vendor\\*"] }
    }
}
JSON
assert_accepts "$d" "tsconfig.json with backslash escapes inside a string"

# The JSONC tolerance must not extend to genuinely broken input: an unclosed
# object has to be reported, not read as an empty config that passes every
# subsequent `?? null` check.
d="$(mk_js_case ts-malformed)"
printf '{\n    "compilerOptions": { "strict": true\n' > "$d/tsconfig.json"
assert_rejects "$d" "tsconfig.json that is not valid JSON(C)" "not valid JSON(C)"

# --- the adoption gate -------------------------------------------------------
#
# Three existing consumers ship a standalone biome.json today and pull this
# package over Composer. If the extends contract keyed on the file being present,
# their next `composer update` would red a build for a link they never claimed —
# and they could not fix it, because a consumer cannot pin an npm tag that does
# not exist yet. So the assertions key on the npm dependency being declared.
mk_unadopted_case() {
    local dir
    dir="$(mk_case "$1")"
    printf '{\n    "name": "fixture",\n    "devDependencies": { "typescript": "^7.0.2" }\n}\n' > "$dir/package.json"
    printf '%s' "$dir"
}

d="$(mk_unadopted_case js-unadopted-biome)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_accepts "$d" "standalone biome.json in a repo that has not adopted the npm package"

d="$(mk_unadopted_case js-unadopted-tsconfig)"
printf '{\n    "compilerOptions": { "strict": false }\n}\n' > "$d/tsconfig.json"
assert_accepts "$d" "standalone tsconfig.json in a repo that has not adopted the npm package"

# A repo with no package.json at all is the same case, and is the shape every
# PHP-only consumer has.
d="$(mk_case js-no-package-json)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_accepts "$d" "standalone biome.json with no package.json at all"

# A parse failure, unlike the `"//"` key, IS gated on adoption — this reader is
# not Biome's, so it can reject a file the real tool accepts, and reporting that
# to a repository which never claimed the link is the failure the adoption gate
# exists to prevent.
d="$(mk_unadopted_case js-unadopted-malformed)"
printf '{\n    "linter": { "enabled": true\n' > "$d/biome.json"
assert_accepts "$d" "malformed biome.json in a repo that has not adopted the npm package"

d="$(mk_js_case js-adopted-malformed)"
printf '{\n    "linter": { "enabled": true\n' > "$d/biome.json"
assert_rejects "$d" "malformed biome.json once the npm package is declared" "not valid JSON(C)"

# Both tools read a BOM-prefixed config and honour it; json_decode does not. A
# reader stricter than the tools reports a defect in a file that loads fine.
d="$(mk_js_case biome-bom)"
printf '\xEF\xBB\xBF' > "$d/biome.json"
cat "$FIXTURE/biome.json" >> "$d/biome.json"
assert_accepts "$d" "biome.json saved with a UTF-8 BOM"

d="$(mk_js_case ts-bom)"
printf '\xEF\xBB\xBF' > "$d/tsconfig.json"
cat "$FIXTURE/tsconfig.json" >> "$d/tsconfig.json"
assert_accepts "$d" "tsconfig.json saved with a UTF-8 BOM"

# The probe that decides whether any of this runs must not fail open: an
# unreadable package.json would otherwise switch the entire JS/TS contract off
# while the gate still printed OK.
d="$(mk_case js-package-json-malformed)"
printf '{\n    "devDependencies": {\n' > "$d/package.json"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects "$d" "an unparseable package.json is reported, not treated as non-adoption" "package.json"

# A package.json with a BOM is readable by npm, so it must not be reported — and
# the dependency inside it must still be seen.
d="$(mk_case js-package-json-bom)"
printf '\xEF\xBB\xBF{\n    "devDependencies": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' > "$d/package.json"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects "$d" "a BOM-prefixed package.json is still read for the dependency" "biome/base.json"

# The exception that proves the gate is not simply switched off: a `"//"` key
# makes the config unloadable for Biome whether or not it extends anything, so
# that one check stays unconditional.
d="$(mk_unadopted_case js-unadopted-note-key)"
cat > "$d/biome.json" <<'JSON'
{
    "//": "shared config for this repo",
    "linter": { "enabled": true }
}
JSON
assert_rejects "$d" "\"//\" key is reported even without adoption" '`"//"` key'

# And the counterpart: once the dependency IS declared, the full contract is back.
d="$(mk_js_case js-adopted-no-extends)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects "$d" "biome.json without extends once the npm package is declared" "biome/base.json"

# --- the pinned strict flags, derived from the shipped base ------------------
#
# The gate pins a hand-written list of compilerOptions; tsconfig/base.json ships
# a set. Today they agree, and nothing holds them there — a strictness flag added
# to the shared base later would go unpinned in silence, which is precisely what
# this gate exists to prevent one layer down. So the cases are DERIVED from the
# base rather than listed here: every flag it ships is either pinned or a named
# ergonomics exception, and neither list may outlive the other.
ergonomics=(esModuleInterop resolveJsonModule skipLibCheck)

# Membership asked the same way in every direction, so the three set relations
# below read as one property rather than three spellings of it.
contains() { # <needle> <haystack…>
    local needle="$1" candidate
    shift

    for candidate in "$@"; do
        if [ "$candidate" = "$needle" ]; then
            return 0
        fi
    done

    return 1
}

# The derived loops can fail before any gate run, so they report directly.
report_failure() { # <message>
    printf 'FAIL (harness): %s\n' "$1" >&2
    fails=$((fails + 1))
}

mapfile -t base_flags < <(php -r '
    $options = json_decode(file_get_contents($argv[1]), true)["compilerOptions"];

    foreach ($options as $name => $value) {
        if ($value === true) {
            echo $name, "\n";
        }
    }
' "$ROOT/tsconfig/base.json")

# A scan that collected nothing must not read as "everything is classified".
if [ "${#base_flags[@]}" -eq 0 ]; then
    report_failure 'read no compilerOptions flags from tsconfig/base.json'
fi

# The gate's own list, so the lockstep is a real bijection rather than a
# one-directional check. Without it a flag can outlive the base: dropped from
# tsconfig/base.json it generates no case, while the gate keeps rejecting every
# consumer that turns it off — a red for a flag the shared base no longer sets.
mapfile -t pinned_flags < <(
    sed -n '/\$pinnedFlags = \[/,/\];/p' "$ROOT/bin/check-consumer-config.php" \
        | grep -oE "'[A-Za-z]+'" \
        | tr -d "'"
)

if [ "${#pinned_flags[@]}" -eq 0 ]; then
    report_failure 'read no $pinnedFlags entries from bin/check-consumer-config.php'
fi

for flag in "${base_flags[@]}"; do
    d="$(mk_js_case "ts-flag-$flag")"
    printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "%s": false }\n}\n' "$flag" > "$d/tsconfig.json"

    if contains "$flag" "${ergonomics[@]}"; then
        assert_accepts "$d" "tsconfig.json turning the ergonomics flag $flag off"
    else
        assert_rejects "$d" "tsconfig.json turning the shared strict flag $flag off" "compilerOptions.$flag"
    fi
done

# The other two directions, so neither list can outlive the base it describes.
for flag in "${ergonomics[@]}"; do
    if ! contains "$flag" "${base_flags[@]}"; then
        report_failure "ergonomics exception $flag is no longer shipped by tsconfig/base.json"
    fi
done

for flag in "${pinned_flags[@]}"; do
    if ! contains "$flag" "${base_flags[@]}"; then
        report_failure "pinned flag $flag is no longer shipped by tsconfig/base.json"
    fi
done

# --- the remaining branches --------------------------------------------------

# The adoption probe reads three dependency sections; only one was exercised.
for section in dependencies optionalDependencies; do
    d="$(mk_case "js-adopted-via-$section")"
    printf '{\n    "%s": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' "$section" > "$d/package.json"
    printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
    assert_rejects "$d" "the npm dependency declared under $section counts as adoption" "biome/base.json"
done

# Every overrides case so far put the violation at index 0, so a walk that only
# inspected the first entry would pass them all — and the index in the message
# was never proven to track the real one.
d="$(mk_js_case biome-override-second-entry)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["tests/**"], "linter": { "rules": { "suspicious": { "noExplicitAny": "off" } } } },
        { "includes": ["**"], "linter": { "enabled": false } }
    ]
}
JSON
assert_rejects "$d" "a violation in the SECOND overrides entry is reported with its index" "overrides[1].linter.enabled"

# jscpd's `format` as a bare string rather than a list: the deny-list loop would
# skip it, so the spelling that scans nothing would pass through the escape
# hatch the check exists to close.
d="$work/jscpd-format-scalar"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": "ts"/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a scalar format instead of a list" 'Use "typescript"'

# An unreadable config is not a syntax error, and reporting it as one sends the
# reader to fix the wrong thing. Every read site gets a case, because two of them
# fail OPEN without one: an unreadable .jscpd.json leaves the gate printing OK for
# a config it never read, and an unreadable package.json switches the ENTIRE JS/TS
# contract off while still printing OK.
#
# Skipped for uid 0: root bypasses DAC, so mode 000 stays readable, the gate
# correctly accepts and every one of these would read as a false regression. CI
# runs non-root, so the branches stay exercised there — the skip line is printed
# rather than silent so the omission is visible when it happens.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-config cases\n'
else
    d="$(mk_unadopted_case js-unreadable-biome)"
    cp "$FIXTURE/biome.json" "$d/biome.json"
    chmod 000 "$d/biome.json"
    assert_rejects "$d" "an unreadable biome.json reports as unreadable, not as malformed" "biome.json: exists but cannot be read"
    chmod 644 "$d/biome.json"

    d="$(mk_js_case js-unreadable-tsconfig)"
    chmod 000 "$d/tsconfig.json"
    assert_rejects "$d" "an unreadable tsconfig.json reports as unreadable, not as malformed" "tsconfig.json: exists but cannot be read"
    chmod 644 "$d/tsconfig.json"

    # Fails open without the guard: the gate would print OK for a config it never read.
    d="$work/jscpd-unreadable"; jscpd_fixture "$d"
    chmod 000 "$d/.jscpd.json"
    assert_rejects "$d" "an unreadable .jscpd.json is reported rather than skipped" ".jscpd.json: exists but cannot be read"
    chmod 644 "$d/.jscpd.json"

    # Fails open too, and wider: this one silences the whole JS/TS contract.
    d="$(mk_case js-package-json-unreadable)"
    printf '{\n    "devDependencies": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' > "$d/package.json"
    printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
    chmod 000 "$d/package.json"
    assert_rejects "$d" "an unreadable package.json does not switch the JS/TS contract off" "package.json: exists but cannot be read"
    chmod 644 "$d/package.json"

    # But a repository with NO JS config is never probed, so a broken package.json
    # there is not this gate's business — it would be a red for a contract that has
    # nothing to check.
    d="$(mk_case php-only-broken-package-json)"
    printf '{\n    "devDependencies": {\n' > "$d/package.json"
    assert_accepts "$d" "a PHP-only repo is not probed for the JS/TS contract at all"

    # These three degraded differently: the read failure WAS reported, and then the
    # content assertions ran against an empty string and fabricated more.
    for pair in phplint.yml:.phplint.yml editorconfig:.editorconfig; do
        template="${pair%%:*}"
        target="${pair##*:}"
        d="$(mk_case "unreadable-$template")"
        cp "$ROOT/templates/$template" "$d/$target"
        chmod 000 "$d/$target"
        assert_rejects "$d" "an unreadable $target reports only that it cannot be read" "$target: exists but cannot be read"
        assert_reports_once "$d" "an unreadable $target fabricates no content drift" "$target"
        chmod 644 "$d/$target"
    done

    d="$(mk_case unreadable-deptrac)"
    printf 'imports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n' > "$d/deptrac.yaml"
    chmod 000 "$d/deptrac.yaml"
    assert_rejects "$d" "an unreadable deptrac.yaml reports only that it cannot be read" "deptrac.yaml: exists but cannot be read"
    assert_reports_once "$d" "an unreadable deptrac.yaml fabricates no content drift" "deptrac.yaml"
    chmod 644 "$d/deptrac.yaml"

    # phpunit.xml is the one REQUIRED file, and libxml returns the same false for
    # unreadable as for malformed — so this used to read as a syntax error.
    d="$(mk_case unreadable-phpunit)"
    chmod 000 "$d/phpunit.xml"
    assert_rejects "$d" "an unreadable phpunit.xml is not reported as malformed XML" "phpunit.xml: exists but cannot be read"
    chmod 644 "$d/phpunit.xml"
fi

# The silent-skip arms: a malformed sub-node must not quietly drop the checks
# below it in a gate whose whole purpose is not to pass silently.
d="$(mk_js_case biome-override-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": ["not-an-object", { "includes": ["**"], "linter": { "enabled": false } }]
}
JSON
assert_rejects "$d" "a non-object overrides entry does not hide the next one" "overrides[1].linter.enabled"

d="$(mk_js_case biome-rules-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "enabled": false, "rules": "off" }
}
JSON
assert_rejects "$d" "a scalar linter.rules does not hide the enabled check" "linter.enabled"

d="$(mk_js_case biome-group-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": "info", "correctness": { "preset": "none" } } }
}
JSON
assert_rejects "$d" "a scalar rule group does not hide the next group" "linter.rules.correctness.preset"

d="$work/jscpd-format-non-string"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": [5, "ts"]/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a non-string format entry beside a bad one" 'Use "typescript"'

# Every entry of the deny-list table, so a wrong canonical name in any row ships
# pinned rather than unexercised.
for pair in js:javascript mjs:javascript cjs:javascript ts:typescript mts:typescript cts:typescript; do
    spelling="${pair%%:*}"
    canonical="${pair##*:}"
    d="$work/jscpd-format-$spelling"; jscpd_fixture "$d"
    sed -i "s/\"reporters\": \[\"console-full\"\]/\"reporters\": [\"console-full\"],\n    \"format\": [\"php\", \"$spelling\"]/" "$d/.jscpd.json"
    assert_rejects "$d" ".jscpd.json using the \"$spelling\" extension as a format name" "Use \"$canonical\""
done

# `reporters` takes the same list-vs-scalar mistake as `format`, and without the
# is_array guard the gate dies on in_array() with a TypeError instead of
# reporting — a crash where a finding belongs.
d="$work/jscpd-reporters-scalar"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": "console-full"/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a scalar reporters instead of a list" "console-full"

# The "must be present" half of both thresholds: every other fixture always
# carries the key, so only the ">" comparison was exercised.
d="$work/jscpd-no-mintokens"; jscpd_fixture "$d"
sed -i '/"minTokens"/d' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json omitting minTokens entirely" "minTokens"

d="$work/jscpd-no-minlines"; jscpd_fixture "$d"
sed -i '/"minLines"/d' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json omitting minLines entirely" "minLines"

# A repo with no JS at all must stay accepted — these configs are optional.
d="$(mk_case no-js)"
assert_accepts "$d" "PHP-only repo without biome.json or tsconfig.json"

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
