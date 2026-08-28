#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for the .gitattributes lockstep gate (GH-38).
#
# Run against this repository alone, the gate only ever takes the happy path —
# every applicable templates/gitattributes entry is already mirrored in
# .gitattributes, so a green CI is indistinguishable from a gate that cannot
# fail. These cases put it in each failing state on purpose.

set -euo pipefail

# CDPATH= because the target starts with neither /, ./ nor ../ and would
# otherwise be searched in CDPATH, resolving to a foreign tree.
root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$root/tests/harness.sh"
harness_workdir

gate="$root/tests/check-gitattributes-lockstep.php"

# Thin wrappers over the shared definitions in tests/harness.sh.
assert_accepts()         { harness_accepts         "$gate" "$@"; }
assert_rejects()         { harness_rejects         "$gate" "$@"; }
assert_usage_error()     { harness_usage_error     "$gate" "$@"; }
assert_report_is_inert() { harness_report_is_inert "$gate" "$@"; }

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

mk_case() { # <name>
    local dir="$work/$1"
    mkdir -p "$dir/templates"
    printf '%s' "$dir"
}

# --- the canon: every applicable template entry is mirrored --------------------
d="$(mk_case canon)"
mkdir -p "$d/.github" "$d/tests"
printf '/.github    export-ignore\n/tests      export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n/tests      export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "own .gitattributes carries every applicable template entry"

# --- the qualifier: a template entry naming a path this repository does not
# have is not required — the whole difficulty this gate exists to get right
# (templates/gitattributes lists rector.php, infection.json5 and friends for a
# CONSUMER, and this package ships none of them as a root file of its own) ---
d="$(mk_case not-applicable)"
printf '/rector.php    export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_accepts "$d" "a template entry naming a path this repository does not have is not required"

# --- a commented-out template directive is not a requirement, even when the
# path exists — templates/gitattributes keeps biome.json/tsconfig.json export-
# ignore INACTIVE on purpose (a github: dependency's prepare script needs them),
# and this gate must not resurrect that as a demand ---
d="$(mk_case commented-out-not-required)"
mkdir -p "$d/.github"
printf '#/biome.json    export-ignore\n/.github        export-ignore\n' > "$d/templates/gitattributes"
: > "$d/biome.json"
printf '/.github        export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a commented-out template directive is not required even though the path exists"

# --- an applicable entry missing from .gitattributes entirely ---
d="$(mk_case missing-entry)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_rejects "$d" "an applicable template entry missing from .gitattributes entirely" \
    '/.github: missing `export-ignore`'

# --- a negated attribute must not satisfy the requirement — proves the check
# matches the exact token `export-ignore`, not merely the path's presence ---
d="$(mk_case negated-attribute)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    -export-ignore\n' > "$d/.gitattributes"
assert_rejects "$d" "a negated -export-ignore attribute does not satisfy the requirement" \
    '/.github: missing `export-ignore`'

# --- a path present with only an unrelated attribute is still missing the one
# this gate asserts ---
d="$(mk_case unrelated-attribute)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    linguist-vendored\n' > "$d/.gitattributes"
assert_rejects "$d" "a path present with only an unrelated attribute is still missing export-ignore" \
    '/.github: missing `export-ignore`'

# --- export-ignore among several attributes on the same line is still
# recognised — the same tolerance real gitattributes files use ---
d="$(mk_case multi-attribute)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore linguist-vendored\n' > "$d/.gitattributes"
assert_accepts "$d" "export-ignore among several attributes on the same line is still recognised"

# --- no .gitattributes at all is real drift, not a skip — is_file() is checked
# before the read so this is not misreported as an IO failure either ---
d="$(mk_case own-file-absent)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
assert_rejects "$d" "a repository with no .gitattributes at all is reported as drift, not skipped" \
    '/.github: missing `export-ignore`'

# --- two applicable entries: a gate that stopped after the first match would
# pass this file's own canon case, so both misses need their own assertion ---
d="$(mk_case two-missing)"
mkdir -p "$d/.github" "$d/tests"
printf '/.github    export-ignore\n/tests      export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_rejects "$d" "the first of two missing entries is reported" '/.github: missing'
assert_rejects "$d" "the second of two missing entries is reported as well" '/tests: missing'

# --- a template declaring no active export-ignore entry at all cannot drive
# this gate — the same vacuity guard tests/check-version-lockstep.php applies
# to a README documenting no pin. Distinct from "none of the entries apply
# here", which is the legitimate pass two cases up. ---
d="$(mk_case template-vacuous)"
printf '# just a comment, no directive\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_rejects "$d" "a template declaring no active export-ignore entry cannot drive the gate" \
    'declares no active'

# --- a missing templates/gitattributes is a setup failure, not a content
# defect — distinct from a missing .gitattributes, which is the drift the
# gate exists to report ---
d="$(mk_case missing-template)"
rmdir "$d/templates"
assert_usage_error "$d" "a missing templates/gitattributes reports as unreadable" 'Cannot read'

# --- IO failures: an unreadable file must report as such rather than as a
# content defect. Skipped for uid 0: root bypasses DAC, so mode 000 stays
# readable and both cases would read as a false regression — the same skip
# every other harness in this repository applies for the identical reason. ---
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-file cases\n'
else
    d="$(mk_case unreadable-template)"
    printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
    chmod 000 "$d/templates/gitattributes"
    assert_usage_error "$d" "an unreadable templates/gitattributes reports as unreadable" 'Cannot read'
    chmod 644 "$d/templates/gitattributes"

    d="$(mk_case unreadable-own)"
    mkdir -p "$d/.github"
    printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
    printf '/.github    export-ignore\n' > "$d/.gitattributes"
    chmod 000 "$d/.gitattributes"
    assert_usage_error "$d" "an unreadable .gitattributes reports as unreadable, not as absent" 'Cannot read'
    chmod 644 "$d/.gitattributes"
fi

# --- oversize: read past the bound is reported as oversize, not scanned —
# both files, since each read site holds its own bound check. 1 byte past the
# 1048576-byte cap, matching readCapped()'s own "at the bound" vs "past it"
# semantics. ---
d="$(mk_case oversize-template)"
{ printf '# '; head -c 1048577 /dev/zero | tr '\0' 'a'; printf '\n'; } > "$d/templates/gitattributes"
assert_usage_error "$d" "a templates/gitattributes past the size bound is reported as oversize, not scanned" \
    'is larger than'

d="$(mk_case oversize-own)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
{ printf '# '; head -c 1048577 /dev/zero | tr '\0' 'a'; printf '\n'; } > "$d/.gitattributes"
assert_usage_error "$d" "an oversize .gitattributes is reported as oversize, not scanned" \
    'is larger than'

# --- safeReportValue wiring: a template path name is echoed into the
# violation report verbatim once scrubbed, and it is pull-request branch
# content in this repository's own CI just as much as it is in every
# consumer's — the same trust boundary bin/support/safe-report-value.php
# documents. Proven with a real fixture rather than assumed. ---
forged='pwned##[error]forged'
scrubbed='pwned##?[error]forged'
d="$(mk_case poison-path-name)"
printf '/%s    export-ignore\n' "$forged" > "$d/templates/gitattributes"
: > "$d/$forged"
printf '' > "$d/.gitattributes"
assert_report_is_inert "$d" \
    "a template path name carrying a legacy workflow-command prefix does not forge one" \
    "$scrubbed"

verdict
