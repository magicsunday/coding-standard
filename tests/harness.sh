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

# Guard against a second source. Not because of the trap — sourcing arms none, the
# `trap … EXIT` lives inside harness_workdir and a second source only redefines the
# function. The damage is `fails=0` below: it discards every failure recorded so
# far, so a run that had earned exit 1 exits 0. Measured with the guard removed:
# `fails=7`, source again, `fails=0`.
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

    # The trap holds the RAW mktemp path in its own variable, and is armed before
    # the canonicalisation. Two reasons, and the second is why `trap … "$work"`
    # alone does not do:
    #
    #   - `cd` can fail (a race, a removal, a permission change) and `set -e` then
    #     aborts with the directory already created. Reproduced.
    #   - on that path the substitution has ALREADY overwritten `$work` with the
    #     empty string, so a trap reading `$work` would `rm -rf ""` and leave the
    #     directory behind — the leak, with a trap that looks like it covers it.
    #
    # Not `local`: an EXIT trap fires after the function has returned, where a
    # local is out of scope.
    harness_workdir_raw="$work"
    trap 'rm -rf "$harness_workdir_raw"' EXIT

    work="$(CDPATH= cd -- "$work" && pwd)"
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
# The run's single exit point. **The `exit` below is the enforcement — do not turn
# it into `return`.** That is the opposite of what two earlier versions of this
# comment claimed, both of which measured only the bare call, which is the one
# form where the two spellings agree. The full matrix, under `set -euo pipefail`
# with `fails=3`:
#
#     call form                exit 1   return 1
#     verdict                     1        1
#     verdict || true             1        0     <-- the hole
#     if verdict; then :; fi      1        0     <-- the hole
#     ( verdict )                 1        1
#     verdict | cat               1        1
#     x=$(verdict)                1        1
#
#     for lib in exit return; do
#         printf 'fails=0\nverdict(){ [ "$fails" -ne 0 ] && { %s 1; }; :; }\n' "$lib" > /tmp/v.sh
#         bash -c 'set -euo pipefail; . /tmp/v.sh; fails=3; verdict || true' >/dev/null 2>&1
#         echo "$lib -> $?"
#     done
#
# With `exit`, no calling context can neutralise the verdict: `||` marks its left
# side as tested and suppresses `set -e`, but `exit` leaves the shell rather than
# the function, so there is nothing left to suppress. With `return`, that same
# `||` is exactly the hole — and `return` is the idiomatic choice for a sourced
# library, which is why this needs saying rather than assuming.
#
# Callers still use the bare form. It reads as the verdict it is, and it keeps the
# behaviour identical should this ever become a `return`.
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

    # The driver is checked BEFORE it is called, because the count is otherwise the
    # probe's only discriminator: a misspelled or missing driver raises nothing and
    # is reported as a broken reporting helper — the diagnosis defect this harness
    # family rejects everywhere else ("rejected, but not for the tested reason").
    # It fails closed either way; the cost is the reader's next hour.
    if ! declare -F -- "$driver" >/dev/null; then
        printf 'FAILED  harness bookkeeping: the probe driver `%s` is not a defined function\n' "$driver" >&2
        exit 1
    fi

    if ! (
        fails=0
        "$driver"
        [ "$fails" -eq "$expected" ]
    ) >/dev/null 2>&1; then
        printf 'FAILED  harness bookkeeping: a reporting helper does not raise the failure counter\n' >&2
        exit 1
    fi
}
