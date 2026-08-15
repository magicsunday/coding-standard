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

# Every work directory this run created, in creation order. See harness_workdir.
declare -a harness_workdir_raw=()

# Every assertion below reports through a helper that both PRINTS and raises this
# counter. The exit code is built from it and from nothing else.
fails=0

# harness_workdir
#
# Creates the throwaway fixture root as $work and removes it on exit.
#
# `CDPATH= cd --` on the result because mktemp honours a relative TMPDIR
# verbatim, and callers use "$work/…" after their own cd — so it has to be
# absolute up front. The order is mktemp, then trap, then canonicalise, and the
# trap reads the RAW path rather than $work — the reason is in the body, and it is
# the opposite of what this header said while the body already did it.
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
    # local is out of scope. An ARRAY, and the trap re-armed over all of it,
    # because bash replaces an EXIT trap rather than chaining it — a second call
    # in one shell would otherwise install a trap that removes only the second
    # path and leak the first. Measured. No caller does that today; every harness
    # is its own process and calls this once.
    harness_workdir_raw+=("$work")
    trap 'rm -rf -- "${harness_workdir_raw[@]}"' EXIT

    work="$(CDPATH= cd -- "$work" && pwd)"
}

# degraded <output>
#
# True when the interpreter emitted a diagnostic of its own — a warning, a
# notice, a parse error or a fatal. Such a run produced no verdict, whatever it
# exited with, so no assertion may read it as one: a crash that prints the
# asserted substring on its way down would otherwise report `ok`.
degraded() {
    grep -qE '^(PHP )?(Warning|Notice|Deprecated|Recoverable fatal error|Fatal error|Parse error|Uncaught)' <<<"$1"
}

# Both directions, by construction, because nothing else exercises this. No fixture
# makes a gate emit a PHP diagnostic — the read paths install a scoped error handler
# — so the pattern is asserted at every assertion helper and was proven at none.
# Measured: replacing the alternation with German words left three harnesses green.
#
# `Recoverable fatal error` is listed separately above: it does not match the
# `Fatal error` alternative, because the anchor requires the line to START there.
for harness_degraded_probe in \
    'PHP Fatal error:  Uncaught Error: x' \
    'PHP Recoverable fatal error:  Argument 1 passed to f()' \
    'Warning: Undefined array key 0 in /x on line 1' \
    'Deprecated: Implicit conversion in /x on line 1'
do
    if ! degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() does not recognise `%s`\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

for harness_degraded_probe in \
    'ok (accepted): a case label' \
    'check-consumer-config: OK — every present template copy matches the stable canon.' \
    '  - phpunit.xml: missing — the strict PHPUnit config is required.'
do
    if degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() misreads `%s` as a diagnostic\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

unset harness_degraded_probe

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

# harness_usage_error <gate> <dir> <label> <substring>
#
# The could-not-run verdict, exit 2. Kept apart from the drift verdict because a
# helper that accepts "any non-zero" lets a setup failure count as a caught
# violation — which is what tightening the phpat harness first surfaced. Three
# harnesses had grown their own copy of this within two rounds of the file that
# exists to stop exactly that, and the three had already drifted apart on stdout
# vs stderr, `FAIL` vs `FAILED`, and branch order.
harness_usage_error() {
    local gate="$1" dir="$2" label="$3" expected="$4" out rc
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    if degraded "$out"; then
        printf 'FAILED (the gate ran degraded — PHP emitted a diagnostic): %s\n%s\n' "$label" "$out" >&2
        fails=$((fails + 1))
    elif [ "$rc" -ne 2 ]; then
        printf 'FAILED (expected the usage exit, got exit %s): %s\n%s\n' "$rc" "$label" "$out" >&2
        fails=$((fails + 1))
    elif ! grep -qF "$expected" <<<"$out"; then
        printf 'FAILED (refused, but not for the tested reason): %s\nexpected to find: %s\n%s\n' "$label" "$expected" "$out" >&2
        fails=$((fails + 1))
    else
        printf 'ok (refused to run, as expected): %s\n' "$label"
    fi
}

# harness_report_is_inert <gate> <dir> <label>
#
# The report-shape assertion for consumer-controlled bytes, shared by the two
# gates that echo them. It asserts what GitHub Actions and a terminal key on — no
# line begins a workflow command, no ESC survives — and the line count, since
# splitting the report is how a forged line reaches column 0.
#
# NOT the absence of the payload text: once the bytes cannot start a line the text
# is inert, and demanding its absence would also pass on a gate that quietly
# stopped reporting the value at all. An earlier version counted `^  - ` prefixed
# lines, which is backwards — an injected line does not carry that prefix, so the
# count stayed at 1 while a literal `::notice::forged` sat at column 0.
#
# One report site, not one per arm: with an increment behind each `elif` the probe
# only ever reaches the arm its own fixture takes, leaving the others free to lose
# theirs. Measured.
harness_report_is_inert() {
    local gate="$1" dir="$2" label="$3" out rc lines reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?
    lines="$(grep -c . <<<"$out" || true)"

    if degraded "$out"; then
        reason='the gate ran degraded — PHP emitted a diagnostic'
    elif [ "$rc" -ne 1 ]; then
        reason="expected the drift verdict, got exit $rc"
    elif grep -q "$(printf '\033')" <<<"$out"; then
        reason='an ANSI escape from a consumer value reached the report'
    elif grep -qE '^::[a-z-]+' <<<"$out"; then
        reason='a consumer value forged a workflow command at line start'
    elif [ "$lines" -gt 4 ]; then
        reason="a consumer value split the report across $lines lines"
    fi

    if [ -n "$reason" ]; then
        printf 'FAILED: %s: %s\n%s\n' "$reason" "$label" "$out" >&2
        fails=$((fails + 1))

        return
    fi

    printf 'ok (rejected, and the report stayed inert): %s\n' "$label"
}

# harness_assert_no_stray_increments <expected-count>
#
# Counts the raw `fails=$((fails + 1))` sites in the CALLING file and requires the
# number to be the one declared there. Every increment must sit inside a helper the
# reporter probe drives; a new report site written inline is the recurring defect
# this whole file exists against, and it recurred in two consecutive rounds despite
# that — once per round, in a different harness each time, each found by a reviewer
# rather than by a control.
#
# Deriving the bar rather than hand-maintaining a probe count means a new inline
# increment fails here instead of shipping unprobed. Raising the number is a
# deliberate edit next to the call, not something a new case does by accident.
harness_assert_no_stray_increments() {
    local file="${BASH_SOURCE[1]}" found
    # -F, not a basic regular expression: `grep -c 'fails=$((fails + 1))'` returns 0
    # against a file that plainly carries the line, so the first version of this
    # guard could never fire — a guard that guards nothing, which is the class it
    # was written against. Measured on tests/harness.sh: `-c` gives 0, `-cF` gives 7.
    found="$(grep -cF 'fails=$((fails + 1))' "$file" || true)"

    if [ "$found" -ne "$1" ]; then
        printf 'FAILED  harness bookkeeping: %s carries %s raw increment(s), expected %s — route a new report site through a probed helper, or raise the number here on purpose\n' \
            "$file" "$found" "$1" >&2
        exit 1
    fi
}
