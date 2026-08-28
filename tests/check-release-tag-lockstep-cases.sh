#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for the release tag lockstep gate (GH-42).
#
# Run against this repository alone, the gate only ever takes the happy path —
# whatever tag package.json currently names either does not exist yet (nothing
# to check) or, once this repository's own release procedure has run, is an
# ancestor of HEAD. These cases put it in every OTHER state on purpose.
#
# Unlike every sibling gate's cases file, this gate's fixtures are not static
# file trees: the gate shells out to `git ls-remote`/`git fetch` against a
# CHECK_RELEASE_TAG_REMOTE, so each case builds a small real bare repository to
# play that role rather than writing files the gate merely reads.

set -euo pipefail

# CDPATH= because the target starts with neither /, ./ nor ../ and would
# otherwise be searched in CDPATH, resolving to a foreign tree.
root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$root/tests/harness.sh"
harness_workdir

gate="$root/tests/check-release-tag-lockstep.php"

# Every fixture commit and tag needs a git identity, and tag creation must not
# depend on (or trip over) whatever gpg-signing configuration the machine
# running this suite happens to carry globally — set once, LOCALLY, on each
# fixture repository below rather than via `git config --global`, which would
# mutate the real identity of whoever runs this suite. mk_repo_pair applies
# these to every fixture it creates; no case sets git config itself.
git_local_identity() { # <dir>
    git -C "$1" config user.email 'check-release-tag-lockstep-fixtures@example.invalid'
    git -C "$1" config user.name 'check-release-tag-lockstep fixtures'
    # This repository's own tags ARE annotated (`git for-each-ref
    # refs/tags/1.8.0 --format='%(objecttype)'` reports `tag`, not `commit`),
    # and tag_and_push below creates them the same way — these two overrides
    # only stop that creation from failing on a machine whose global config
    # additionally demands a signature this throwaway identity cannot produce.
    git -C "$1" config tag.gpgSign false
    git -C "$1" config tag.forceSignAnnotated false
    # commit.gpgSign is a SEPARATE key from the two above — every `git commit`
    # this file makes (mk_repo_pair's own initial commit included) is a real
    # commit, not a tag, and is otherwise silently signed with whatever
    # identity the machine running this suite has configured globally,
    # defeating the whole point of the throwaway user.email/user.name above.
    # Reproduced on a machine with `commit.gpgsign=true` set globally: every
    # fixture commit came back `Good "git" signature for` the real developer's
    # key, and on a machine with that flag set but no non-interactive signing
    # key/agent available, `git commit` fails or hangs outright — aborting
    # this whole suite under `set -euo pipefail` at the very first
    # mk_repo_pair call, before the tag-signing case above is ever reached.
    git -C "$1" config commit.gpgSign false
}

# mk_repo_pair <name> <version>
#
# Creates a bare "$work/<name>-origin.git" and a working clone at "$work/<name>"
# whose package.json already names <version> and is committed and pushed to
# `main` — the state every case starts from, whether or not it goes on to tag
# anything. Echoes the working clone's path, the harness convention every
# other cases file's mk_case follows.
mk_repo_pair() {
    local name="$1" version="$2"
    local origin="$work/$name-origin.git"
    local dir="$work/$name"

    git init -q --bare "$origin"
    git init -q "$dir"
    git_local_identity "$dir"
    git -C "$dir" remote add origin "$origin"

    printf '{\n    "version": "%s"\n}\n' "$version" > "$dir/package.json"
    git -C "$dir" add package.json
    git -C "$dir" commit -q -m 'Initial commit'
    git -C "$dir" push -q origin HEAD:main

    printf '%s' "$dir"
}

# tag_and_push <dir> <version>
#
# Tags <dir>'s current HEAD as <version>, annotated, and pushes the tag to
# origin — the shape this repository's own releases use (see
# git_local_identity's comment).
tag_and_push() {
    local dir="$1" version="$2"
    git -C "$dir" tag -m "Release $version" "$version"
    git -C "$dir" push -q origin --tags
}

# Thin wrappers over the shared definitions in tests/harness.sh. Every case
# below names its own fixture's bare origin as CHECK_RELEASE_TAG_REMOTE by the
# "$dir-origin.git" convention mk_repo_pair's naming already fixes — a case
# that needs a DIFFERENT remote (unreachable, poisoned, or genuinely absent to
# exercise the 'origin' default) sets CHECK_RELEASE_TAG_REMOTE itself and calls
# the underlying harness_* function directly instead of through these.
assert_accepts()         { CHECK_RELEASE_TAG_REMOTE="$1-origin.git" harness_accepts         "$gate" "$@"; }
assert_rejects()          { CHECK_RELEASE_TAG_REMOTE="$1-origin.git" harness_rejects         "$gate" "$@"; }
assert_usage_error()     { CHECK_RELEASE_TAG_REMOTE="$1-origin.git" harness_usage_error     "$gate" "$@"; }
assert_report_is_inert() { CHECK_RELEASE_TAG_REMOTE="$1-origin.git" harness_report_is_inert "$gate" "$@"; }

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
# One custom check below (PROBE_REF cleanup) uses harness_fail directly rather
# than a raw increment, so this file's own bar stays 0 like every case file
# that adds no report site of its own.
harness_assert_no_stray_increments 0

# --- shape 1: no tag on the remote yet — the state `main` is in, briefly and
# by design, between a version-bump push and the tag push that follows it.
# Nothing to check yet, not a violation (see this gate's own docblock for why:
# npm already fails loudly for a consumer who tries the pin before it exists) ---
d="$(mk_repo_pair not-found 1.0.0)"
assert_accepts "$d" "no matching tag on the remote yet is nothing to check, not a violation"

# --- the tag exists and IS HEAD — the state a release push leaves `main` in
# the instant the tag is also pushed, before anything else lands ---
d="$(mk_repo_pair resolved-is-head 1.0.0)"
tag_and_push "$d" 1.0.0
assert_accepts "$d" "a resolved tag that names HEAD itself is accepted"

# --- the tag exists and is an ANCESTOR of HEAD, with ordinary commits on top
# and package.json's version left UNCHANGED — the actual, common shape of
# `main` between two releases (see README.md's "Releasing this package"
# section for why this is the case a tree-equality design got backwards).
# This case is what makes that regression impossible to reintroduce silently:
# a reverted ancestor-check (back to tree equality) turns this exact fixture
# into a false reject, since the follow-up commit changes HEAD's tree but not
# the tag's. ---
d="$(mk_repo_pair resolved-ancestor 1.0.0)"
tag_and_push "$d" 1.0.0
printf 'an ordinary follow-up file, not a version bump\n' > "$d/drift.txt"
git -C "$d" add drift.txt
git -C "$d" commit -q -m 'Ordinary follow-up commit'
git -C "$d" push -q origin HEAD:main
assert_accepts "$d" "a tag that is an ancestor of HEAD, with ordinary commits on top, is accepted"

# --- the drift this gate actually exists to catch: the tag resolves to a
# commit this branch's own history never contains at all — built the same
# deterministic way the fetch-fails alien fixture below is, a SEPARATE,
# unrelated commit graph, so there is no ancestry to find regardless of how
# far `main` has moved ---
# Named distinctly from "resolved-not-ancestor-origin.git" on purpose: that is
# the path mk_repo_pair below would derive automatically from the fixture
# name it is given, and creating it here FIRST made that later, unrelated
# push collide with this one's history — observed once, surfaced only as
# noisy `! [rejected] ... (fetch first)` stderr from a push whose result this
# case never even uses (CHECK_RELEASE_TAG_REMOTE is set explicitly below).
orphan_origin="$work/orphan-history-origin.git"
git init -q --bare "$orphan_origin"
orphan_seed="$work/orphan-history-seed"
git init -q "$orphan_seed"
git_local_identity "$orphan_seed"
printf 'unrelated history, never a commit on the fixture under test\n' > "$orphan_seed/orphan.txt"
git -C "$orphan_seed" add orphan.txt
git -C "$orphan_seed" commit -q -m 'Orphan commit'
git -C "$orphan_seed" remote add origin "$orphan_origin"
git -C "$orphan_seed" push -q origin HEAD:main
tag_and_push "$orphan_seed" 1.0.0
d="$(mk_repo_pair resolved-not-ancestor 1.0.0)"
CHECK_RELEASE_TAG_REMOTE="$orphan_origin" harness_rejects "$gate" "$d" \
    "a tag resolving to a commit outside this branch's history is reported" "MISMATCH"

# --- the remote cannot be reached at all — a transport failure, distinct from
# "no matching tag" (git ls-remote --exit-code answers 2 for the latter and
# some OTHER non-zero code, with a `fatal:` line on stderr, for this) ---
d="$(mk_repo_pair remote-unreachable 1.0.0)"
CHECK_RELEASE_TAG_REMOTE="$work/does-not-exist" harness_usage_error "$gate" "$d" \
    "an unreachable remote is reported as a setup failure, not as a missing tag" "Could not query"

# --- package.json's version is not shaped like a git tag — it is repository
# content, about to become part of a `refs/tags/<version>` argument handed to
# git, and this gate refuses it up front rather than letting an unrecognisable
# ref simply fail to resolve (which would misreport a malformed version as
# shape 1, "nothing to check yet") ---
d="$(mk_repo_pair not-tag-shaped '1.0.0_hotfix')"
assert_usage_error "$d" "a package.json version not shaped like a tag is refused, not silently treated as unresolved" \
    "not shaped like a version tag"

# --- CHECK_RELEASE_TAG_REMOTE unset entirely: the default must genuinely be
# the literal string 'origin', not merely "something ls-remote accepts" — a
# repository whose ONLY remote is named something else proves it, since the
# gate must fail trying to resolve a remote literally named 'origin' rather
# than falling through to whatever remote does exist ---
d="$(mk_repo_pair default-remote-name 1.0.0)"
git -C "$d" remote rename origin upstream

# No CHECK_RELEASE_TAG_REMOTE prefix here, unlike every other case in this
# file: `VAR=value command` only ever SETS a variable for one call, so every
# other case's assignment-prefixed call leaves it unset again immediately
# afterward — this call already runs in that same unset state, which is
# exactly the state under test.
harness_usage_error "$gate" "$d" \
    "with no override, the queried remote is literally 'origin'" "query origin for"

# --- the SAME default, reached through the other disjunct: CHECK_RELEASE_TAG_REMOTE
# set to an EXPLICIT empty string rather than left unset. A realistic shape in
# GitHub Actions — an `env:` value interpolated from an unset vars/secrets
# context evaluates to `''`, not an absent variable — and a genuinely distinct
# code path (`getenv()` returns a string, not `false`), so the unset case
# above cannot discriminate a regression that dropped this arm. ---
d="$(mk_repo_pair default-remote-name-empty-override 1.0.0)"
git -C "$d" remote rename origin upstream
CHECK_RELEASE_TAG_REMOTE='' harness_usage_error "$gate" "$d" \
    "an explicitly empty override falls back to 'origin' the same way an unset one does" "query origin for"

# --- a tag that resolves via ls-remote (the ref advertisement) but cannot
# actually be fetched (its objects are unreachable on the remote) — a real,
# non-racy git failure mode (a corrupted or partially-pruned remote), built
# deterministically here via a SEPARATE, unrelated history the fixture under
# test has never fetched from, so the missing object cannot already be
# present locally the way it would be for any commit reachable from the
# fixture's own HEAD. Constructed once, not timed: this is not the read-fetch
# race a comment elsewhere in this file's sibling gates documents as
# unreachable to fixture, it is a permanent broken remote state. ---
# Named "…-ALIEN-…", not "fetch-fails-origin.git": that latter spelling is the
# exact path mk_repo_pair's OWN naming convention derives from the fixture
# name "fetch-fails" below, and this repo must stay a physically separate bare
# repository from the one mk_repo_pair creates — reusing the same path by
# coincidence would silently make this case's real origin the mk_repo_pair one
# instead of the deliberately corrupted one, without changing a single
# assertion.
alien="$work/fetch-fails-ALIEN-origin.git"
git init -q --bare "$alien"
alien_seed="$work/fetch-fails-alien-seed"
git init -q "$alien_seed"
git_local_identity "$alien_seed"
printf 'alien content unrelated to the fixture under test\n' > "$alien_seed/alien.txt"
git -C "$alien_seed" add alien.txt
git -C "$alien_seed" commit -q -m 'Alien commit'
git -C "$alien_seed" remote add origin "$alien"
git -C "$alien_seed" push -q origin HEAD:refs/heads/alien-tmp
git -C "$alien_seed" tag -m 'Release 1.0.0' 1.0.0
git -C "$alien_seed" push -q origin --tags
# The branch only existed to get the commit onto the remote; removing it
# leaves the TAG as the sole reason the corrupted object is still advertised,
# matching how this gate would encounter it — resolvable, unfetchable.
git -C "$alien_seed" push -q origin :refs/heads/alien-tmp
alien_commit="$(git -C "$alien_seed" rev-parse '1.0.0^{commit}')"
rm -f "$alien/objects/${alien_commit:0:2}/${alien_commit:2}"
d="$(mk_repo_pair fetch-fails 1.0.0)"
CHECK_RELEASE_TAG_REMOTE="$alien" harness_usage_error "$gate" "$d" \
    "a tag that resolves but cannot be fetched is reported as a setup failure" "could not fetch it"

# --- a tag that resolves AND fetches successfully but is not shaped like
# something with a commit at all — a lightweight tag pointing directly at a
# BLOB rather than a commit or an annotated tag object (git tag accepts any
# object type for a lightweight tag). `<ref>^{commit}` has nothing to peel
# through on a blob, so this is the "fetched successfully, still cannot
# proceed" arm distinct from every arm above it. ---
d="$(mk_repo_pair fetch-ok-not-a-commit 1.0.0)"
blob="$(git -C "$d" hash-object -w --stdin <<< 'just a blob, not a commit')"
git -C "$d" tag 1.0.0 "$blob"
git -C "$d" push -q origin --tags
assert_usage_error "$d" "a tag resolving to a blob rather than a commit is reported as a setup failure" \
    "could not resolve it to a commit"

# --- HEAD itself cannot be resolved to a commit — an unborn HEAD (a repository
# with no local commit at all, though its remote already carries a real,
# fetchable, resolvable tag). Every other error-exit branch in this gate has a
# discriminating case; this one did not until an independent review found the
# gap: deleting the check entirely (falling through to an empty/garbage
# $headTreeSha equivalent) survived every other case in this file silently. ---
d="$work/unborn-head"
mkdir -p "$d"
git init -q "$d"
git_local_identity "$d"
origin="$work/unborn-head-origin.git"
git init -q --bare "$origin"
seed="$work/unborn-head-seed"
git init -q "$seed"
git_local_identity "$seed"
printf '{\n    "version": "1.0.0"\n}\n' > "$seed/package.json"
git -C "$seed" add package.json
git -C "$seed" commit -q -m 'Initial commit'
git -C "$seed" remote add origin "$origin"
git -C "$seed" push -q origin HEAD:main
tag_and_push "$seed" 1.0.0
printf '{\n    "version": "1.0.0"\n}\n' > "$d/package.json"
git -C "$d" remote add origin "$origin"
CHECK_RELEASE_TAG_REMOTE="$origin" harness_usage_error "$gate" "$d" \
    "an unborn local HEAD is reported as a setup failure, not misread as a resolvable commit" \
    "Could not determine whether"

# --- a genuinely SHALLOW checkout (the `actions/checkout` default this
# repository's own CI runs under — `fetch-depth: 1`, unset because none of
# the `build` job's OTHER steps need history) has no local parent graph for
# `merge-base --is-ancestor` to walk at all, even when the tag really is an
# ancestor. Reproduced independently against a real `git clone --depth 1` of
# THIS repository before this case existed: `merge-base --is-ancestor <the
# real 1.8.0 tag commit> HEAD` answered 1 ("not an ancestor") on a checkout
# where it demonstrably is one — the exact false-MISMATCH class the ancestry
# redesign above exists to close, just moved from "tree comparison" to
# "shallow-history unavailability". `git clone --depth 1` is used here
# rather than a hand-rolled shallow marker, since a shallow repository's
# on-disk shape (a `.git/shallow` file, a specific commit-graph state) is not
# something worth reverse-engineering when git already produces it for free. ---
d="$(mk_repo_pair shallow-checkout 1.0.0)"
tag_and_push "$d" 1.0.0
printf 'a second ordinary commit, so the tag is an ancestor rather than HEAD itself\n' > "$d/drift.txt"
git -C "$d" add drift.txt
git -C "$d" commit -q -m 'Ordinary follow-up commit'
git -C "$d" push -q origin HEAD:main

# Cloned from the BARE ORIGIN, not from $d: that is what makes this clone's
# own git-configured `origin` remote genuinely be "$d-origin.git", the same
# topology `actions/checkout` produces against the real GitHub remote — a
# shallow clone of $d itself would leave `origin` pointing at $d, which
# `--unshallow` cannot deepen the same way (it has no established
# remote-tracking relationship to repair). No CHECK_RELEASE_TAG_REMOTE
# override below for the same reason: the default 'origin' already resolves
# correctly here, matching production more faithfully than forcing one would.
#
# `--branch main` is load-bearing, not decoration: the bare origin's own
# HEAD symref still points at whatever `init.defaultBranch` this machine
# defaults to (never explicitly set to `main` anywhere in this file, since
# every other case only ever pushes to `refs/heads/main` directly and never
# clones), so a plain `git clone` here silently checked out nothing and
# left package.json absent — caught only once this case actually ran.
# `file://` is load-bearing too: a bare filesystem PATH triggers git's local
# hardlink-clone optimisation, which silently IGNORES `--depth` entirely
# ("warning: --depth is ignored in local clones") and produces a full,
# non-shallow copy that could never discriminate this fix from a reverted
# one — only a real transport protocol (file://, same as https:// for this
# purpose) actually honours it.
shallow_clone="$work/shallow-checkout-shallow-clone"
git clone -q --no-tags --depth 1 --branch main -- "file://$work/shallow-checkout-origin.git" "$shallow_clone"
git_local_identity "$shallow_clone"
harness_accepts "$gate" "$shallow_clone" \
    "a shallow checkout is deepened before the ancestry check runs, rather than false-failing"

# --- the local PROBE_REF this gate fetches a resolved tag into must not
# survive the run — checked directly rather than inferred, since every case
# above only proves the EXIT verdict, not this side effect. The success path
# is the one asserted: it is the only one that reaches both the fetch AND the
# ancestry-check call before the shared cleanup site, so it is the arm most
# exposed to a cleanup call that was dropped or misplaced. ---
d="$(mk_repo_pair cleanup-after-success 1.0.0)"
tag_and_push "$d" 1.0.0
out="$(CHECK_RELEASE_TAG_REMOTE="$work/cleanup-after-success-origin.git" php "$gate" "$d" 2>&1)" && rc=0 || rc=$?
leftover="$(git -C "$d" for-each-ref refs/check-release-tag-lockstep/)"

# The exit code is checked HERE, not left to `|| true`: a "leftover" empty
# because the gate itself never got far enough to create PROBE_REF would
# read as "cleaned up" vacuously, proving nothing about the cleanup call
# this case exists to check. Other cases in this file already cover an
# unsuccessful run's own verdict; this one specifically needs a run that
# actually SUCCEEDED before its side effect can mean anything.
if [ "$rc" -ne 0 ]; then
    harness_fail "the fixture setup itself did not reach a successful run (exit $rc), so this case proves nothing about cleanup: $out"
elif [ -n "$leftover" ]; then
    harness_fail "PROBE_REF (refs/check-release-tag-lockstep/probe) was not deleted after a successful run: $leftover"
else
    printf 'ok (cleaned up): the local probe ref does not survive a successful run\n'
fi

# --- the cleanup call's own exit code (line ~314 in the gate) is checked and
# surfaced as a diagnostic without escalating into the gate's own verdict —
# deliberately NOT covered by a case here. A pre-existing lock file at
# PROBE_REF's path was tried first and rejected: git's ref-locking is
# symmetric across create/update/delete, so a lock in place BEFORE the run
# blocks the earlier FETCH (which also writes PROBE_REF) at "could not fetch
# it" and the cleanup call is never reached at all — measured, not assumed;
# that attempt failed with exactly this shape. Making cleanup specifically
# fail while the preceding fetch specifically succeeds needs the lock to
# appear in the narrow window between them, which needs actual concurrency
# — the same class of gap tests/check-release-tag-lockstep-cases.sh's own
# "fetch-fails" case comment already documents as unreachable to a
# deterministic fixture for the identical reason. ---

# --- safeReportValue wiring, on the two operands this gate itself echoes
# rather than merely passing through git's own stderr: CHECK_RELEASE_TAG_REMOTE
# (test-controlled here, but the same value a misconfigured environment could
# set) and package.json's `version` on the one path that echoes it BEFORE the
# shape check has ruled out anything forge-prone — the "not shaped like a
# version tag" message itself, which by definition handles a version the
# shape check has NOT yet approved. Both proven with a real fixture rather
# than assumed, the same discipline every sibling gate's own poison case
# applies. The second case passes `2` as harness_report_is_inert's own 5th
# argument: isVersionTagShaped() rejects a forge-prone version before anything
# else runs, at exit 2, by construction — the shape check IS the reason
# nothing forge-prone can ever reach the drift verdict (exit 1) via $version,
# so this is the one call in this file that needs the non-default expected
# exit code the shared helper now takes (GH-42). ---
forged='pwned##[error]forged'
scrubbed='pwned##?[error]forged'

# The remote case needs the drift verdict specifically (a genuine
# not-an-ancestor mismatch), not merely a resolvable tag: only a REAL,
# working bare repository can produce git's own success output, so the
# forged text has to live in that repository's own PATH rather than in a
# value this gate merely fails to resolve — an unreachable path exits 2 the
# same way the version case does, which would not exercise this arm of
# safeReportValue at all.
#
# $forged sits directly after "$work/", with no literal prefix ahead of it:
# safeReportValue() caps the echoed $remote at 64 bytes, and $work is a
# variable-length mktemp -d path this file does not control — any literal
# text between "$work/" and $forged eats into that budget for every byte it
# adds, and a longer TMPDIR on some other machine could push the scrubbed
# marker past the cut silently (measured: a "poison-remote-origin-" prefix
# left only a 2-character margin against this harness's own actual $work
# length). A literal SUFFIX after $forged costs nothing here, since only
# what precedes it in the string affects whether the truncation keeps it.
poison_origin="$work/$forged-origin.git"
git init -q --bare "$poison_origin"
poison_seed="$work/poison-remote-seed"
git init -q "$poison_seed"
git_local_identity "$poison_seed"
printf 'unrelated history\n' > "$poison_seed/orphan.txt"
git -C "$poison_seed" add orphan.txt
git -C "$poison_seed" commit -q -m 'Orphan commit'
git -C "$poison_seed" remote add origin "$poison_origin"
git -C "$poison_seed" push -q origin HEAD:main
tag_and_push "$poison_seed" 1.0.0
d="$(mk_repo_pair poison-remote 1.0.0)"
CHECK_RELEASE_TAG_REMOTE="$poison_origin" harness_report_is_inert "$gate" "$d" \
    "a forged remote path cannot inject a workflow command into the report" "$scrubbed"

d="$(mk_repo_pair poison-version "1.0.0-$forged")"
CHECK_RELEASE_TAG_REMOTE="$d-origin.git" harness_report_is_inert "$gate" "$d" \
    "a forged package.json version cannot inject a workflow command into the report" "$scrubbed" 2

# --- an IO failure on package.json reads as one, via the shared
# readPackageJsonVersion() this gate now shares with
# tests/check-version-lockstep.php — one representative case to prove THIS
# gate is actually wired to it; the byte-cap/oversize/malformed-JSON branches
# of that shared function are exhaustively covered by
# tests/check-version-lockstep-cases.sh already, against the identical
# function both gates now call, so re-testing every one of its branches here
# too would prove nothing this suite does not already inherit. ---
d="$work/missing-package-json"
mkdir -p "$d"
git init -q "$d"
git_local_identity "$d"
: > "$d/.gitkeep"
git -C "$d" add .gitkeep
git -C "$d" commit -q -m 'Initial commit'
assert_usage_error "$d" "a repository with no package.json reports as unreadable" "Cannot read"

verdict
