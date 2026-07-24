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

# assert_rejects <dir> <label>
assert_rejects() {
    local dir="$1" label="$2" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    if [ "$rc" -eq 0 ]; then
        printf 'FAIL (expected reject): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (rejected): %s\n' "$label"
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
assert_rejects "$d" "requireCoverageMetadata disabled"

d="$(mk_case notice-gone)"
sed -i '/failOnNotice="true"/d' "$d/phpunit.xml"
assert_rejects "$d" "failOnNotice removed"

d="$(mk_case source-loose)"
sed -i 's/restrictNotices="true"/restrictNotices="false"/' "$d/phpunit.xml"
assert_rejects "$d" "<source> restrictNotices disabled"

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
assert_rejects "$d" ".phplint.yml with php under path, not extensions"

# --- .editorconfig: [*] uses tabs, spaces only in a narrow section ---
d="$work/editorconfig-wrong-section"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = tab

[*.md]
indent_style = space
indent_size = 4
EC
assert_rejects "$d" ".editorconfig with tabs in [*], spaces only in [*.md]"

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
assert_rejects "$d" ".jscpd.json on the removed v4 reporter name"

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
