#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Syntax-checks every shell script under tests/, the analogue of what phplint
# does for the PHP files.
#
# It exists because a syntax error in one of these harnesses does not read as
# one. Bash reports it, aborts before the first assertion, and exits non-zero —
# but the harnesses report their own results line by line, so an aborted run
# prints no FAILED line at all. Anything that judges a run by grepping its
# output for a failure marker then reads the abort as a clean pass, which is
# precisely how a broken harness once looked green: an apostrophe inside a
# comment within an embedded `node -e '…'` block closed the surrounding single
# quote, and the script died at that line having run nothing.
#
# `bash -n` names the line, costs milliseconds, and needs no fixture. Wired as a
# gate rather than left to whoever remembers to look.

set -euo pipefail

# An optional path argument points it at another directory, which is what lets
# tests/lint-shell-cases.sh drive it over fixtures instead of over this repository
# alone — where every run takes the happy path, all harnesses parse, and a gate
# that had stopped checking would be indistinguishable from a clean one. Same
# shape as tests/check-version-lockstep.php's `$argv[1]`.
#
# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="${1:-$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

if [ ! -d "$ROOT" ]; then
    printf 'Not a directory: %s\n' "$ROOT" >&2
    exit 2
fi

failed=0
checked=0

# A private temp file rather than /tmp/$$: two concurrent runs would otherwise
# share it, and the trap makes the cleanup unconditional.
work_error="$(mktemp)"
trap 'rm -f "$work_error"' EXIT

# Every .sh under tests/, discovered rather than listed: a hand-kept list is one
# more copy of "which scripts exist" to forget to update, and a new harness that
# is not in it would ship unchecked.
#
# `find` rather than `git ls-files`, deliberately. The git form is the better
# answer to "which files does this repository track", but it returns EMPTY on a
# checkout git declines to read — a container whose UID does not own the worktree
# hits `detected dubious ownership` and the listing silently becomes nothing.
# This gate's whole purpose is that an empty run must not read as a clean one, so
# it must not depend on a command with a silent-empty failure mode. Measured: the
# git form returned zero files in exactly that container, and only the vacuity
# guard below distinguished it from success.
#
# The prune list is what git would have given for free: `tests/consumer` carries
# an installed vendor tree, and without it this gate lints third-party scripts —
# reporting a foreign syntax error as this repository's, or going red on a
# dependency nobody here wrote.
#
# The whole root is scanned rather than `$ROOT/tests`. A narrowing branch was
# tried and removed: find is recursive and the prune list already excludes
# everything else, so both forms produce the identical seven files here — an
# unproven code path buying nothing, and one that would silently skip a
# root-level script in any layout that does have one.
errors="$work_error"

# The listing is materialised FIRST, so `find`'s exit status is a value this
# script can act on. Left inside a process substitution it is discarded: a
# directory the run cannot descend into makes find print `Permission denied` and
# exit 1, the loop then checks whatever it did reach, `checked` is non-zero, and
# the gate reports OK for a scan that skipped part of the tree. Measured — a root
# holding one readable script beside a mode-000 directory containing a broken one
# reported `lint-shell: OK — 1 shell script(s) parse.` and exit 0.
#
# A partial scan is the same defect as an empty one, one step milder, and this
# gate exists precisely so that "did not look" cannot read as "found nothing".
listing=""
listing="$(find "$ROOT" \
    \( -name .build -o -name vendor -o -name node_modules \) -prune -o \
    -type f -name '*.sh' -print 2>"$errors" | sort)" || {
    printf 'FAILED   could not list %s — the scan is incomplete, so its result says nothing\n' "$ROOT" >&2
    cat "$errors" >&2
    exit 1
}

# find exits 0 while still reporting per-path errors on stderr (an unreadable
# subdirectory is not a fatal error to it), so the status alone is not enough.
if [ -s "$errors" ]; then
    printf 'FAILED   %s could not be scanned completely\n' "$ROOT" >&2
    cat "$errors" >&2
    exit 1
fi

while IFS= read -r script; do
    [ -n "$script" ] || continue
    checked=$((checked + 1))

    if bash -n "$script" 2>"$errors"; then
        printf 'OK       %s\n' "${script#"$ROOT"/}"
    else
        printf 'FAILED   %s\n' "${script#"$ROOT"/}" >&2
        cat "$errors" >&2
        failed=1
    fi
done <<<"$listing"

# A pattern that matches nothing would make this gate pass vacuously — the same
# failure mode the phpat subject-liveness guard exists to prevent, and the one
# this file is otherwise defending against.
if [ "$checked" -eq 0 ]; then
    printf 'FAILED   no shell scripts found to check — the gate matched nothing\n' >&2
    exit 1
fi

if [ "$failed" -ne 0 ]; then
    exit 1
fi

printf 'lint-shell: OK — %d shell script(s) parse.\n' "$checked"
