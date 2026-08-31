#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for the JSON lint gate.
#
# Run against this repository alone, tests/lint-json.php only ever takes the
# happy path — every shipped JSON file parses — so a green CI is
# indistinguishable from a gate that cannot fail, or one whose discovery quietly
# stopped finding anything at all. These cases put it in each failing state on
# purpose, and prove the DISCOVERY itself — not a hand-kept list, and the
# defect #41 exists to close — actually finds what it claims to and prunes what
# it should.

set -euo pipefail

# CDPATH= because the target starts with neither /, ./ nor ../ and would
# otherwise be searched in CDPATH, resolving to a foreign tree.
root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$root/tests/harness.sh"
harness_workdir

gate="$root/tests/lint-json.php"

# The gate's exit code carries the verdict, so it is captured rather than piped:
# under `set -o pipefail` a `php … | grep` would report the deliberately failing
# run as a harness error.

# Thin wrappers over the shared definitions in tests/harness.sh.
assert_accepts()         { harness_accepts         "$gate" "$@"; }
assert_rejects()         { harness_rejects         "$gate" "$@"; }
assert_report_is_inert() { harness_report_is_inert "$gate" "$@"; }

# harness_report_is_inert exists for the REJECT verdict (exit 1) only, because
# every other gate's report sites sit on that path. This gate ALSO echoes a
# consumer-controlled file name on its ACCEPT path — every well-formed file gets
# printed too — which is the common case, not the rare one: most files a pull
# request adds parse just fine. No shared helper covers "accepted, and the
# report stayed inert", so this one is local, the same way
# tests/check-js-configs.sh keeps its own manifest_crashed rather than sharing
# tests/harness.sh's degraded().
assert_ok_report_is_inert() { # <dir> <label> [<scrubbed payload the report must carry>]
    local dir="$1" label="$2" out rc reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
    elif [ "$rc" -ne 0 ]; then
        reason="expected accept, got exit $rc"
    elif grep -qF -- '##[' <<<"$out"; then
        reason="a consumer-controlled file name forged a legacy \`##[…]\` workflow command"
    elif [ "$#" -gt 2 ] && ! grep -qF -- "$3" <<<"$out"; then
        # Absence alone is also satisfied by a value that never reached the report
        # at all — inert BY OMISSION rather than by scrubbing, the exact gap
        # harness_decide_report_is_inert's own must-carry argument exists to close
        # on the reject path. Without this arm, a regression that silently dropped
        # the OK line for a suspicious file name (rather than scrubbing and
        # printing it) would report ok here: rc=0, and no `##[` anywhere in $out
        # because nothing about the file was printed at all.
        reason='the scrubbed value never reached the report — inert by omission, not by scrubbing'
    fi

    harness_settle "$reason" "$label" "$out" 'accepted, and the report stayed inert'
}

# Driven rather than merely asserted, the same discipline every shared decision
# function in tests/harness.sh is held to: `php` is shadowed so each arm is
# reached without a gate that produces it, and the count is the discriminator —
# delete any arm and one increment goes missing.
probe_assert_ok_inert_shapes() {
    php() { printf '%s\n' "$harness_fake_report"; return "$harness_fake_rc"; }

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_fake_rc=0
    assert_ok_report_is_inert /nonexistent 'probe: accepts, the gate ran degraded'

    harness_fake_report='OK       x'
    harness_fake_rc=1
    assert_ok_report_is_inert /nonexistent 'probe: accepts, the gate did not accept'

    harness_fake_report='OK       x##[error]forged'
    harness_fake_rc=0
    assert_ok_report_is_inert /nonexistent 'probe: accepts, a forged sequence reached the report'

    harness_fake_report='OK       nothing-about-the-file-at-all'
    harness_fake_rc=0
    assert_ok_report_is_inert /nonexistent 'probe: accepts, the scrubbed value never reached the report' 'x##?[forged'
}

harness_probe_reporters 4 probe_assert_ok_inert_shapes \
    'assert_ok_report_is_inert has an arm that no longer decides'

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

# The name several fixtures below share to prove safeReportValue() wiring at
# their own report site: it breaks a legacy `##[…]` workflow command the same
# way tests/CheckVersionLockstepTest.php's own
# reportIsInertWhenAPackageJsonVersionAttemptsToForgeALegacyWorkflowCommand
# case does, and the double `#` is what would survive an unscrubbed report and
# reach the runner mid-line, not only at column 0.
forged='1.7.0##[error]forged.json'
scrubbed='1.7.0##?[error]forged.json'

# --- discovery: the canon ---
d="$work/canon"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/a.json"
assert_accepts "$d" "a directory carrying one well-formed JSON file"

# --- vacuity guard: a scan that matches nothing must not read as a clean run ---
# The same failure mode tests/check-version-lockstep.php's own vacuity guard
# exists to prevent — a README documenting no pin at all — applied here to a
# directory listing instead of to a regex match.
d="$work/no-json"
mkdir -p "$d"
printf 'not json\n' > "$d/readme.md"
assert_rejects "$d" "a directory with no JSON files at all" "matched nothing"

# --- malformed content ---
d="$work/malformed"
mkdir -p "$d"
printf '{\n    "a":\n' > "$d/broken.json"
assert_rejects "$d" "a malformed JSON file" "INVALID  broken.json"

# --- missing: a dangling symlink is discovered but resolves to nothing ---
# The discovery walk lists it as a directory entry named *.json; is_file()
# resolves the target and reports false. This is the one way "missing" can still
# occur once the file list is no longer hand-kept — a name in an array that was
# never created cannot happen when the array itself came from what is actually
# on disk.
d="$work/dangling-symlink"
mkdir -p "$d"
ln -s -- "$d/does-not-exist" "$d/dangling.json"
assert_rejects "$d" "a dangling symlink named *.json" "MISSING  dangling.json"

# --- unreadable: permissions revoked ---
# Skipped for uid 0, the same as tests/CheckConsumerConfigTest.php's own
# unreadable-config cases: root bypasses DAC, so mode 000 stays readable and
# this would read as a false regression rather than a caught violation. CI
# runs non-root, so the branch stays exercised there.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-file case\n'
else
    d="$work/unreadable"
    mkdir -p "$d"
    printf '{"a": 1}\n' > "$d/locked.json"
    chmod 000 "$d/locked.json"
    assert_rejects "$d" "a JSON file with no read permission" "UNREADABLE  locked.json"
    chmod 644 "$d/locked.json"
fi

# --- pruning: vendored/installed trees and VCS metadata are never scanned ---
# One well-formed file at the top proves the run is not vacuous; a MALFORMED
# file inside each pruned directory proves the prune actually skips content
# rather than merely not adding it to some other list — a gate that pruned
# nothing here would still read these as violations and reject.
d="$work/pruned-dirs"
mkdir -p "$d/.build" "$d/vendor" "$d/node_modules" "$d/.git"
printf '{"a": 1}\n' > "$d/kept.json"
printf 'not json\n' > "$d/.build/broken.json"
printf 'not json\n' > "$d/vendor/broken.json"
printf 'not json\n' > "$d/node_modules/broken.json"
printf 'not json\n' > "$d/.git/broken.json"
assert_accepts "$d" "malformed JSON under .build/vendor/node_modules/.git is never scanned"

# --- excluded: both EXCLUDED_JSON_FILES entries proven together, the same
# one-fixture-many-arms shape the pruned-dirs case above already uses ---
# tests/consumer/tsconfig.json is JSONC by design (`tsc` accepts comments and
# trailing commas there); package-lock.json is npm's own gitignored,
# locally-generated lockfile. Each is given content that would otherwise fail
# (a comment for one, plain non-JSON for the other), so the accept only holds
# if BOTH are actually skipped rather than merely absent from some other list.
d="$work/excluded-entries"
mkdir -p "$d/tests/consumer"
printf '{"a": 1}\n' > "$d/kept.json"
printf '{\n    // a comment, not valid strict JSON\n    "a": 1,\n}\n' > "$d/tests/consumer/tsconfig.json"
printf 'not json\n' > "$d/package-lock.json"
assert_accepts "$d" "tests/consumer/tsconfig.json and package-lock.json are both excluded"

# --- root argument: a nonexistent directory reports rather than crashing, and
# the path itself cannot forge a workflow command in that report either ---
# The argument is under this script's own control (never a discovered file
# name), but safeReportValue() wraps it anyway — proven here rather than
# assumed, the same way every discovered-file report site is proven rather
# than assumed.
assert_report_is_inert "$work/${forged}-does-not-exist-at-all" \
    "a nonexistent root directory named to carry a legacy workflow command" "$scrubbed"

# --- safeReportValue wiring: the file name is consumer-controlled, not this
# repository's own choice, once discovery replaces the hand-kept list. Each
# report site is proven separately — with an increment behind each `if`/`elif`
# a probe only ever reaches the arm its own fixture takes, leaving the others
# free to lose their scrub.
d="$work/inert-invalid"
mkdir -p "$d"
printf '{\n    "a":\n' > "$d/$forged"
assert_report_is_inert "$d" "a malformed file named to carry a legacy workflow command" "$scrubbed"

d="$work/inert-missing"
mkdir -p "$d"
ln -s -- "$d/does-not-exist" "$d/$forged"
assert_report_is_inert "$d" "a dangling symlink named to carry a legacy workflow command" "$scrubbed"

# Same root-uid skip as the "unreadable: permissions revoked" case above.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-file inert case\n'
else
    d="$work/inert-unreadable"
    mkdir -p "$d"
    printf '{"a": 1}\n' > "$d/$forged"
    chmod 000 "$d/$forged"
    assert_report_is_inert "$d" "an unreadable file named to carry a legacy workflow command" "$scrubbed"
    chmod 644 "$d/$forged"
fi

d="$work/inert-ok"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/$forged"
assert_ok_report_is_inert "$d" "a well-formed file named to carry a legacy workflow command" "$scrubbed"

# --- discovery actually recurses into an ORDINARY nested directory ---
# Every other fixture above puts its interesting file at the fixture root, or
# nested only under a name the prune list already covers. A scanner that only
# ever looked at $root's immediate entries would still pass every case above —
# this is the one that requires descending through a plain, unpruned directory.
d="$work/nested-discovery"
mkdir -p "$d/config/sub"
printf '{\n    "a":\n' > "$d/config/sub/deep.json"
assert_rejects "$d" "a malformed file nested under an ordinary, unpruned directory" "INVALID  config/sub/deep.json"

# --- a symlinked DIRECTORY is never descended into either ---
# Not because of anything this gate's own code decides — verified by mutation
# that no code change here affects it — but because
# RecursiveDirectoryIterator::hasChildren() itself reports false for a
# symlinked entry (see discoverJsonFiles()'s own docblock). Pinned as a
# regression guard: a well-meaning future edit that adds FOLLOW_SYMLINKS
# thinking it is needed for something else would silently start walking a
# malformed *.json inside the real target and reporting it as this gate's own
# finding.
mkdir -p "$work/target-dir"
printf 'not json\n' > "$work/target-dir/leaked.json"
d="$work/symlinked-dir"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/kept.json"
ln -s -- "$work/target-dir" "$d/linked"
assert_accepts "$d" "a symlinked directory is never descended into"

# --- symlink escape: a LIVE symlink must not be read through, even when its
# target exists and is valid JSON ---
# The dangling-symlink case above already forced is_file() to fail; that alone
# does not prove this gate refuses to FOLLOW a working symlink. Without the
# is_link() check, a leaf `escape.json` pointing at real, well-formed JSON
# outside the scan root would resolve, read, parse and report OK — turning
# every report line into an oracle for what exists, is readable and happens to
# be valid JSON at an arbitrary path the symlink names.
outside="$work/outside-target.json"
printf '{"a": 1}\n' > "$outside"
d="$work/symlink-escape"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/kept.json"
ln -s -- "$outside" "$d/escape.json"
assert_rejects "$d" "a live symlink to a well-formed file outside the scan root" "MISSING  escape.json"

# --- root argument: an EXISTING file (not a directory) reports the same
# "Not a directory" verdict as a path that does not exist at all ---
# realpath() only fails for a path that is entirely absent; it resolves an
# existing non-directory just fine, which would otherwise fall through to the
# generic "Could not scan" handler below with a less specific message.
f="$work/a-plain-file"
printf 'not a directory\n' > "$f"
assert_rejects "$f" "the root argument is an existing file, not a directory" "Not a directory"

# --- an unreadable subdirectory aborts the WHOLE scan, not just that branch,
# and the exception message this throws cannot forge a workflow command either ---
# RecursiveIteratorIterator is left to throw rather than swallowing a
# per-directory failure (see discoverJsonFiles()'s own docblock). The directory
# name is chosen to carry the same forged sequence as the other inert-* cases:
# UnexpectedValueException::getMessage() embeds the full path it failed to
# open, so an unscrubbed catch block would put this repository's own directory
# NAME — not just a discovered file name — into the report. No must-carry
# argument here, deliberately: the message also embeds the fixture's full
# mktemp path ahead of the directory name, and safeReportValue()'s 64-byte cap
# can truncate the forged segment away entirely before this assertion ever
# sees it — which is a safe outcome, not a failure to detect. What must hold
# regardless of where the cap lands is that no RAW `##[` survives, which
# harness_report_is_inert already checks unconditionally.
# Same root-uid skip as the two file-based unreadable cases above — root
# bypasses DAC on a directory too, so the locked directory would still be
# enumerable (and empty), and the run would exit 1 through the VACUITY guard
# instead of through the scan-abort path this case exists to exercise: a
# silent pass for the wrong reason, not a caught violation either way.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-subdirectory case\n'
else
    d="$work/scan-aborts"
    mkdir -p "$d/${forged}-dir"
    chmod 000 "$d/${forged}-dir"
    # Two separate assertions, not one: `assert_report_is_inert` alone would
    # also pass a regression that silently SKIPPED the unreadable directory
    # instead of aborting on it — the scan would then find nothing at all and
    # exit 1 through the vacuity guard's own static, already-inert message,
    # which is a caught violation for the WRONG reason. "Could not scan" is a
    # literal prefix outside any safeReportValue() call, so it survives the
    # 64-byte cap regardless of where the (possibly truncated) forged segment
    # lands — unlike that segment, it is safe to assert verbatim here.
    assert_rejects "$d" "an unreadable subdirectory aborts the whole scan" "Could not scan"
    assert_report_is_inert "$d" "an unreadable subdirectory aborts the whole scan, inertly"
    chmod 700 "$d/${forged}-dir"
fi

# --- a directory nested at MAX_SCAN_DEPTH fails the whole scan, rather than
# silently completing without it ---
# A well-formed kept.json at the top would defeat the vacuity guard on its
# own, and a malformed file past the depth bound would never be reached to
# report — exactly the "partial scan reads as a clean one" failure mode this
# gate's own vacuity guard exists to rule out for an EMPTY scan, reached here
# through depth instead of through a hand-kept list. Built to EXACTLY
# MAX_SCAN_DEPTH nested directories, not one level past it: the throw fires on
# `$depth >= MAX_SCAN_DEPTH`, so a chain one level deeper than necessary would
# still trip a `>` mutant of that comparison and not tell the two apart.
d="$work/too-deep"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/kept.json"
deep="$d"
for _ in $(seq 1 20); do
    deep="$deep/nested"
done
mkdir -p "$deep"
assert_rejects "$d" "a directory nested exactly MAX_SCAN_DEPTH levels deep fails the scan rather than silently stopping" "Could not scan"

# --- the level immediately BELOW that bound is still accepted ---
# The companion to the case above: without it, a regression that tightened the
# comparison (rejecting one level earlier than intended) would pass every
# other fixture in this file, since none of them nest this deep at all.
d="$work/just-shallow-enough"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/kept.json"
deep="$d"
for _ in $(seq 1 19); do
    deep="$deep/nested"
done
mkdir -p "$deep"
assert_accepts "$d" "a directory nested one level short of MAX_SCAN_DEPTH is still scanned"

# --- the largest file this gate reads whole has a bound, checked at the read
# rather than measured after it ---
# A gate that read every discovered file unbounded is exactly what a hand-kept
# list never exposed — every file it named was one this repository's own
# authors wrote, a few kilobytes at most. Discovery removes that guarantee: a
# pull request can add a *.json file of any size under an unpruned path.
d="$work/oversize"
mkdir -p "$d"
printf '{"a": 1}\n' > "$d/kept.json"
head -c 1048577 /dev/zero | tr '\0' '9' > "$d/huge.json"
assert_rejects "$d" "a JSON file past the size this gate reads whole" "TOO LARGE  huge.json"

# --- the level immediately AT that bound is still accepted ---
# The companion to the case above, the same pairing MAX_SCAN_DEPTH gets a few
# lines up: without it, a regression that rejected one byte earlier than
# intended (comparing `>=` instead of `>`) would pass every other fixture in
# this file, since none of them build a file this exact size. Exactly
# MAX_JSON_LINT_BYTES bytes of valid JSON: a 6-byte prefix, a 2-byte suffix,
# and padding filling the rest.
d="$work/at-cap"
mkdir -p "$d"
printf '{"a":"' > "$d/exact.json"
head -c 1048568 /dev/zero | tr '\0' '9' >> "$d/exact.json"
printf '"}' >> "$d/exact.json"
assert_accepts "$d" "a JSON file exactly at the size this gate reads whole is still accepted"

# --- vacuity guard, reached the OTHER way: files exist, but every one of them
# is on the exclusion list ---
# The "no JSON files at all" case above reaches the same message through
# pre-filter emptiness (the walk finds nothing). This one reaches it through
# post-filter emptiness — discovery finds files, and EXCLUDED_JSON_FILES
# removes every one of them — a route the pre-filter fixture cannot exercise.
d="$work/all-excluded"
mkdir -p "$d/tests/consumer"
printf '{\n    // JSONC by design\n    "a": 1\n}\n' > "$d/tests/consumer/tsconfig.json"
printf 'not json\n' > "$d/package-lock.json"
assert_rejects "$d" "every discovered file is on the exclusion list" "matched nothing"

verdict
