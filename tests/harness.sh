#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Shared bookkeeping for the fixture harnesses under tests/. Sourced, never run:
#
#     root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
#     . "$root/tests/harness.sh"
#
# The caller resolves its own $root — it needs one to find this file — and this
# file owns everything that was previously retyped into each harness: the work
# directory, the failure counter, the degraded-run guard, the verdict, and the
# probes that prove the counter reaches the exit code.
#
# It exists because the copies had already drifted apart. tests/
# check-phpat-subjects-cases.sh still carried `assert_rejects` on the loose
# `[ "$rc" -eq 0 ]` contract while its siblings had been tightened to
# `[ "$rc" -ne 1 ]`, and two of the five harnesses had no bookkeeping probe at
# all — so dropping one `fails=$((fails + 1))` in either left every case
# printing FAILED at exit 0. One definition cannot drift from itself.

# Guard against a second source: re-running the probes is harmless, but re-arming
# the EXIT trap would delete the work directory of whichever caller sourced first.
if [ -n "${HARNESS_SH_LOADED:-}" ]; then
    return 0
fi
HARNESS_SH_LOADED=1

# Every assertion below reports through a helper that both PRINTS and raises this
# counter. The exit code is built from it and from nothing else.
fails=0

# harness_workdir
#
# Creates the throwaway fixture root as $work and removes it on exit.
#
# `CDPATH= cd --` on the result because mktemp honours a relative TMPDIR
# verbatim, and callers use "$work/…" after their own cd — so it has to be
# absolute up front. The mktemp-then-canonicalise-then-trap order matters: an
# unset $work can never reach the `rm -rf`, because the trap is armed last and
# `set -e` aborts on a failing mktemp before it.
harness_workdir() {
    work="$(mktemp -d)"
    work="$(CDPATH= cd -- "$work" && pwd)"
    trap 'rm -rf "$work"' EXIT
}

# degraded <output>
#
# True when the interpreter emitted a diagnostic of its own — a warning, a
# notice, a parse error or a fatal. Such a run produced no verdict, whatever it
# exited with, so no assertion may read it as one: a crash that prints the
# asserted substring on its way down would otherwise report `ok`.
degraded() {
    grep -qE '^(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)' <<<"$1"
}

# verdict
#
# The run's single exit point. Call it BARE — `verdict`, never `verdict || exit 1`
# — and that is the whole of the contract. A `||` is what breaks it: it marks the
# left-hand side as tested, which suppresses `set -e` for it, so the earlier
# `verdict || exit 1` could be edited to `verdict || true` and a run with failures
# would exit 0 while both probes below stayed green. The defect sat one hop past
# its own control.
#
# `exit` vs `return` inside the function is NOT the distinguishing part, contrary
# to what an earlier version of this comment claimed. Measured under `set -euo
# pipefail`, with a bare call and with a line following it, both forms end the
# script at 1:
#
#     for form in exit return; do
#         printf 'v(){ [ "$f" -ne 0 ] && { %s 1; }; :; }\nf=1\nv\nprintf x\n' "$form" > /tmp/c.sh
#         bash -c 'set -euo pipefail; . /tmp/c.sh' >/dev/null 2>&1; echo "$form -> $?"
#     done
#
# `exit` is kept because it states the intent at the point of no return; the
# probes below prove the function discriminates, and the bare call is what carries
# that into the script's status.
verdict() {
    if [ "$fails" -ne 0 ]; then
        printf '\n%d case(s) failed.\n' "$fails" >&2
        exit 1
    fi

    printf '\nAll cases passed.\n'
}

# Both directions of the verdict, proven before any case runs. Subshells, so the
# `exit` cannot end the caller and the counter cannot be touched.
if ( fails=1; verdict ) >/dev/null 2>&1; then
    printf 'FAILED  harness bookkeeping: a non-zero counter does not reach the exit code\n' >&2
    exit 1
fi

if ! ( fails=0; verdict ) >/dev/null 2>&1; then
    printf 'FAILED  harness bookkeeping: a clean run does not exit 0\n' >&2
    exit 1
fi

# harness_probe_reporters <expected-count> <driver-function>
#
# Drives the caller's OWN reporting helpers down their failing path and asserts
# the counter rose once per call. The helpers differ per gate, so the caller
# supplies them; what is shared is the reason to check.
#
# Without it each helper degrades into a print statement if its increment is
# lost: the run says FAILED on every line and still exits 0. Measured on a
# sibling: dropping the increment left a drifted gate printing `FAIL (harness)`
# at exit 0, the whole derived lockstep layer silently off. Nothing else can
# catch that, because the guards it disables are the only things that would have
# reported.
#
# <expected-count> is passed rather than derived so that a helper the driver
# stops calling fails the probe instead of quietly lowering the bar.
harness_probe_reporters() {
    local expected="$1" driver="$2"

    if ! (
        fails=0
        "$driver"
        [ "$fails" -eq "$expected" ]
    ) >/dev/null 2>&1; then
        printf 'FAILED  harness bookkeeping: a reporting helper does not raise the failure counter\n' >&2
        exit 1
    fi
}
