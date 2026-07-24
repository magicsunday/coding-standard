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

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
