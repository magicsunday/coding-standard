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
# and this gate must not resurrect that as a demand. The on-disk artifact sits at
# "#/biome.json", not "biome.json": ltrim() only strips a leading `/`, so an
# UN-skipped comment line would mis-parse $matches[1] as the literal path
# "#/biome.json" — placing the file there is what makes this case actually
# discriminate a removed comment-skip guard (it would then resolve and, since
# .gitattributes never declares "#/biome.json", flip this case to a rejection)
# rather than passing either way regardless of whether the guard exists. ---
d="$(mk_case commented-out-not-required)"
mkdir -p "$d/.github" "$d/#"
printf '#/biome.json    export-ignore\n/.github        export-ignore\n' > "$d/templates/gitattributes"
: > "$d/#/biome.json"
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

# --- a LATER negation for the same path overrides an earlier positive — the
# gitattributes(5) last-line-wins rule. The single-line case above cannot catch a
# parser that only ever APPENDS on the positive token and never removes on the
# negative one: such a parser reports this path satisfied even though the file's
# real, git-effective state for it is NOT export-ignored — a green-while-red gap
# proven by mutation (reverting $parseExportIgnorePaths to the append-only shape
# turns this case's rejection into a false accept, along with the same-line and
# !export-ignore cases below, which share the same negation-handling branch). ---
d="$(mk_case negation-overrides-earlier-positive)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n/.github    -export-ignore\n' > "$d/.gitattributes"
assert_rejects "$d" "a later -export-ignore line for the same path overrides an earlier export-ignore" \
    '/.github: missing `export-ignore`'

# --- the same rule in the other direction: a later positive overrides an earlier
# negation, so the path IS satisfied — proves this is genuinely last-line-wins and
# not merely "any negation anywhere wins" ---
d="$(mk_case positive-overrides-earlier-negation)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    -export-ignore\n/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a later export-ignore line for the same path overrides an earlier negation"

# --- the SAME rule one level down: two tokens for one path on a SINGLE line, not
# two lines. A parser deciding a line by "does export-ignore appear anywhere in its
# attribute list" (checked before "-export-ignore") cannot tell `export-ignore
# -export-ignore` (real git verdict: unset) from `-export-ignore export-ignore`
# (real git verdict: set) — both tokens are simply present either way. Only
# iterating the tokens in order and letting each overwrite the state as it is
# reached reproduces git's real, git-effective last-TOKEN-wins rule here too
# (verified against a real checkout: `git check-attr export-ignore` on a line
# ending in `-export-ignore` reports `unset` regardless of an earlier token on that
# same line). ---
d="$(mk_case same-line-negation-overrides-earlier-token)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore -export-ignore\n' > "$d/.gitattributes"
assert_rejects "$d" "a later -export-ignore TOKEN on the same line overrides an earlier export-ignore token" \
    '/.github: missing `export-ignore`'

d="$(mk_case same-line-positive-overrides-earlier-token)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    -export-ignore export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a later export-ignore TOKEN on the same line overrides an earlier negation token"

# --- gitattributes(5) has a THIRD token form, not just `attr`/`-attr`: `!attr`
# ("unspecified" -- resets to unset, as if no rule had matched). A parser that
# only recognises `export-ignore`/`-export-ignore` leaves $state untouched for
# `!export-ignore`, so an earlier positive survives -- reproduced against a real
# checkout: `git archive` of a commit whose .gitattributes reads
# `/x export-ignore` then `/x !export-ignore` still includes /x, and
# `git check-attr` reports `unspecified` for it. ---
d="$(mk_case unspecified-token-overrides-earlier-positive)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n/.github    !export-ignore\n' > "$d/.gitattributes"
assert_rejects "$d" "a later !export-ignore line resets an earlier export-ignore to unspecified" \
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

# --- a symlinked own .gitattributes must not be followed to its target's
# content — is_file() alone follows the link, so without is_link() this would
# read $target's satisfying entry and pass. Git itself does not read a
# symlinked .gitattributes for attribute purposes either (git check-attr on a
# real checkout reports it unspecified), so the archive this gate certifies
# would silently disagree with the archive git actually produces ---
d="$(mk_case own-file-is-symlink)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n' > "$d/gitattributes-target"
ln -s -- gitattributes-target "$d/.gitattributes"
assert_rejects "$d" "a symlinked own .gitattributes is treated as absent, not followed to its target" \
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

# --- a symlinked templates/gitattributes must not be followed to its
# target's content — unlike a symlinked own .gitattributes (treated as
# absent, above), this repository's own release process never produces a
# symlinked template file, so following it is a setup failure (exit 2), not
# drift. Without an is_link() guard, $readOrExit would follow the link via
# file_get_contents() and parse the target's content as if it were
# templates/gitattributes, echoing fragments of an arbitrary file the gate's
# author did not intend it to read (GH-114). ---
d="$(mk_case template-is-symlink)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes-target"
ln -s -- gitattributes-target "$d/templates/gitattributes"
assert_usage_error "$d" "a symlinked templates/gitattributes reports as unreadable, not followed to its target" \
    'Cannot read'

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

# --- path containment: a template entry escaping the fixture root via `..` must
# not resolve outside it. Reproduced against the real gate before the realpath()
# fix landed: with a bare `ltrim($path, '/')`, this exact entry resolved to a real
# file OUTSIDE the reviewed repository and was reported as a violation for a path
# that has nothing to do with this repository — the gate must instead treat it as
# not applicable, the same verdict an absent path gets. ---
d="$(mk_case path-traversal-not-applicable)"
outside="$work/traversal-target"
: > "$outside"
printf '../traversal-target    export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_accepts "$d" "a template path escaping the repository root via .. is not applicable, not a violation"

# --- a template line whose path has no attribute list at all (no whitespace after
# it) must not be treated as a requirement — the shape the block-parse regex is
# built to reject. /orphan-path exists on disk and is NOT export-ignored in the
# fixture's own .gitattributes, so a parser that loosened the regex enough to match
# a bare path would turn this into a false violation; keeping the file present
# makes that regression observable rather than vacuously passing either way. ---
d="$(mk_case malformed-line-no-attributes)"
mkdir -p "$d/.github"
: > "$d/orphan-path"
printf '/orphan-path\n/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a template line with a bare path and no attribute list is skipped, not treated as a requirement"

# --- a template line naming a canonical-integer-string path (no leading slash)
# must not crash the applicability check. PHP casts such a string USED AS AN
# ARRAY KEY to an int, so $parseExportIgnorePaths()'s $state map would hand back
# an int where its own signature promises list<string> — and this file declares
# strict_types=1, so that int reaching ltrim()'s string-typed first parameter
# throws an uncaught TypeError instead of this gate's own graceful exit path. The
# numeric line sits FIRST so the crash (if the strval() fix regresses) happens
# before /.github is ever reached. ---
d="$(mk_case numeric-path-does-not-crash)"
mkdir -p "$d/.github"
printf '123    export-ignore\n/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a template line naming a bare numeric path does not crash the applicability check"

# --- a NUL byte embedded in a captured path (\S does not exclude it) must not
# crash the gate with an uncaught ValueError out of realpath() -- PHP 8+ rejects
# any NUL-byte path unconditionally, not a strict_types-only behavior -- verified
# identically on PHP 8.3/8.4/8.5, and reproduced against the pre-fix code. The
# poisoned line sits FIRST so the crash, if the guard regresses, happens before
# /.github is ever reached. ---
d="$(mk_case nul-byte-in-path-does-not-crash)"
mkdir -p "$d/.github"
printf '/orphan\x00suffix    export-ignore\n/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a NUL byte embedded in a template path does not crash the applicability check"

# --- a UTF-8 BOM at the start of templates/gitattributes must not corrupt the
# FIRST parsed path -- reproduced against the pre-fix code: the BOM bytes
# attached to the leading path token, it could never realpath()-resolve, and a
# genuinely-required, genuinely-missing entry was silently treated as "not
# applicable" -- a false ACCEPT that hid real drift, the worst failure mode for
# a drift-detection gate. ---
d="$(mk_case bom-prefixed-template-still-parses)"
mkdir -p "$d/.github"
printf '\xEF\xBB\xBF/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_rejects "$d" "a UTF-8 BOM at the start of templates/gitattributes does not corrupt the first parsed path" \
    '/.github: missing `export-ignore`'

# --- the same tolerance in the other file: a BOM-prefixed .gitattributes must
# still be recognised as satisfying a requirement. ---
d="$(mk_case bom-prefixed-own-file-still-satisfies)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '\xEF\xBB\xBF/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a UTF-8 BOM at the start of .gitattributes does not stop it from satisfying a requirement"

# --- a SINGLE `if`-shaped strip only removes one BOM. Two concatenated UTF-8
# BOMs reproduce the exact false-accept the single-strip fix was written to
# close, one BOM deeper -- reproduced against the single-strip code. Looping
# closes the whole stacked-BOM class instead of the next report finding three. ---
d="$(mk_case double-bom-prefixed-template-still-parses)"
mkdir -p "$d/.github"
printf '\xEF\xBB\xBF\xEF\xBB\xBF/.github    export-ignore\n' > "$d/templates/gitattributes"
printf '' > "$d/.gitattributes"
assert_rejects "$d" "two concatenated UTF-8 BOMs do not corrupt the first parsed path either" \
    '/.github: missing `export-ignore`'

# --- at-cap: content exactly AT MAX_GITATTRIBUTES_BYTES must still be read in
# full and compared, not silently truncated. The oversize cases above only prove
# content past the cap is rejected; a bound shrunk by mutation would still trip
# those (they sit far past any plausible shrunk value) while truncating a
# legitimate file near the real cap — this is the counterpart that catches that,
# mirroring tests/check-version-lockstep-cases.sh's own at-cap-package-json/
# at-cap-readme pair, via the same shared harness_pad_text_to_cap that file's own
# at-cap-readme case now uses too. Padding is a trailing comment line, verified by
# the helper's own self-check to land the file at EXACTLY the cap before the gate
# ever sees it. ---
body='/.github    export-ignore
'

d="$(mk_case at-cap-template)"
mkdir -p "$d/.github"
harness_pad_text_to_cap 1048576 "$body# " a $'\n' "$d/templates/gitattributes"
printf '/.github    export-ignore\n' > "$d/.gitattributes"
assert_accepts "$d" "a templates/gitattributes exactly at the size cap is still read in full"

d="$(mk_case at-cap-own)"
mkdir -p "$d/.github"
printf '/.github    export-ignore\n' > "$d/templates/gitattributes"
harness_pad_text_to_cap 1048576 "$body# " a $'\n' "$d/.gitattributes"
assert_accepts "$d" "a .gitattributes exactly at the size cap is still read in full"

verdict
