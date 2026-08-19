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

# The raw mktemp path the EXIT trap removes. See harness_workdir.
harness_workdir_raw=''

# Every spelling of "increment the counter" that a new report site might use, and
# CODE only — a `#` line mentioning the idiom is prose, and counting it would make
# a caller raise its declared bar, which then licenses one real stray increment.
#
# A bare `fails` was tried and is wrong in both directions at once: case LABELS
# legitimately carry the word ("unparseable subject fails closed"), and every read
# of the counter in this file would count too — turning this file's own bar into a
# hand-kept number that rots. The alternation discriminates instead.
#
# `-E`, because whether an UNESCAPED `fails=$((fails + 1))` matches as a BASIC
# regular expression differs between implementations. Observed 2026-08-15, and
# this is the probe that discriminates — the count command below cannot, it
# returns the same number under both:
#
#     printf 'fails=$((fails + 1))\n' > /tmp/p
#     grep -c 'fails=$((fails + 1))' /tmp/p     # GNU grep 3.8 -> 1, ugrep 7.5.0 -> 0
#
# Comment lines are filtered out by the caller, not by an anchor in this pattern.
# `^[^#]*` was tried and undercounts: `[^#]*` cannot step over a `#`, so any code
# line carrying `$#`, `${#arr[@]}` or a `#` in a string before the increment stops
# matching — measured, four real increment lines counted as one, and an UNDERCOUNT
# leaves the guard passing.
#
# `fails+=1` is its own alternative because none of the others can reach it, and
# it is the commonest bash increment there is. The arithmetic and `let`
# alternatives deliberately OVER-match: they take anything naming `fails` inside
# `((…))` or after `let`, so a read or a reset written that way counts too. That
# is the loud direction. Requiring an operator instead was tried and reverted — it
# turned `let fails=fails+1` into a silent miss. A spelling list cannot be
# completed; a bound that fails loudly does not need to be.
harness_increment_pattern='fails\+=|\(\([^)]*fails|let[[:space:]]+["'"'"']?\+*fails'


# Every assertion below reports through a helper that both PRINTS and raises this
# counter. The exit code is built from it and from nothing else.
fails=0

# harness_workdir
#
# Creates the throwaway fixture root as $work and removes it on exit.
#
# `CDPATH= cd --` on the result because mktemp honours a relative TMPDIR
# verbatim, and callers use "$work/…" from inside a subshell that has cd'd
# elsewhere (check-js-configs.sh's `npm pack --pack-destination "$work"` runs
# under `cd "$root"`) — so it has to be absolute up front. The order is mktemp, then trap, then canonicalise, and the
# trap reads the RAW path rather than $work — why, in the body.
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
    # local is out of scope. One scalar, because every harness is its own process
    # and calls this exactly once — a cumulative array was carried here for a
    # second call that no caller makes.
    harness_workdir_raw="$work"
    trap 'rm -rf -- "$harness_workdir_raw"' EXIT

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
# One line per alternative, not a sample of them. Four of the seven stood here and the
# comment claimed both directions "by construction"; dropping `Notice|` from the
# pattern left every suite green. `Recoverable fatal error` needs its own line because
# it does not match the `Fatal error` alternative — the anchor requires the line to
# START there.
for harness_degraded_probe in \
    'PHP Fatal error:  Uncaught Error: x' \
    'PHP Recoverable fatal error:  Argument 1 passed to f()' \
    'PHP Parse error:  syntax error, unexpected token ";"' \
    'Warning: Undefined array key 0 in /x on line 1' \
    'Notice: Only variables should be passed by reference in /x on line 1' \
    'Deprecated: Implicit conversion in /x on line 1' \
    'Uncaught TypeError: f(): Argument #1 must be of type string'
do
    if ! degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() does not recognise `%s`\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

# One negative, and it is the one the ANCHOR decides: the keyword sits mid-line, so
# only `^` keeps it out. Three ordinary report lines stood here before and were
# measured to discriminate nothing — under the one structural mutation of this
# pattern, dropping the anchor, all three stay a miss.
for harness_degraded_probe in \
    '  - phpunit.xml: Warning is not a strict flag'
do
    if degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() misreads `%s` as a diagnostic\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

unset harness_degraded_probe

# verdict
#
# The run's single exit point. **Do not turn the `exit` below into `return`.**
#
# Callers may call `verdict` anywhere, not only as their last line — re-derive with
# `grep -n '^[[:space:]]*verdict' tests/*.sh`, which finds one inside an `if` body in
# check-js-configs.sh, 1500 lines before the end, whose whole purpose is to abort
# before the smoke packs anything. With `return` that call returns from the function
# and the script packs, installs and runs on. The tail verdict would still exit 1, so
# the failure is not lost — the early abort is.
#
# The same hole in the other direction: `||` suppresses `set -e` for its left side, so
# a caller writing `verdict || true` would continue at exit 0. `exit` has nothing left
# to suppress. `return` is the idiomatic choice for a sourced library, which is why
# this needs saying rather than assuming.
verdict() {
    if [ "$fails" -ne 0 ]; then
        printf '\n%d case(s) failed.\n' "$fails" >&2
        exit 1
    fi

    printf '\nAll cases passed.\n'
}

# The direction that can fail silently, proven before any case runs. A subshell, so
# the `exit` cannot end the caller and the counter cannot be touched. The other
# direction needs no probe: were `verdict` to stop exiting 0 on a clean counter,
# every green run of all five harnesses would go red first.
if ( fails=1; verdict ) >/dev/null 2>&1; then
    printf 'FAILED  harness bookkeeping: a non-zero counter does not reach the exit code\n' >&2
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
harness_probe_reporters() { # <expected> <driver> [<what a failure means>]
    local expected="$1" driver="$2"
    local note="${3:-a reporting helper does not raise the failure counter}"

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
        printf 'FAILED  harness bookkeeping: %s\n' "$note" >&2
        exit 1
    fi
}

# harness_settle <reason> <label> <output> <ok-note>
#
# The report tail both shared assertions grew once they were converted to the
# decide-then-report-once shape. One increment site for the whole file, which is
# also what makes the caller-side bar meaningful: harness.sh's own count is now 1.
harness_settle() {
    if [ -n "$1" ]; then
        printf 'FAILED (%s): %s\n%s\n' "$1" "$2" "$3" >&2
        fails=$((fails + 1))

        return
    fi

    printf 'ok (%s): %s\n' "$4" "$2"
}

# harness_accepts <gate> <dir> <label>
#
# The clean verdict, exit 0.
harness_accepts() {
    local gate="$1" dir="$2" label="$3" out rc reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    if degraded "$out"; then
        reason='the gate ran degraded — PHP emitted a diagnostic'
    elif [ "$rc" -ne 0 ]; then
        reason="expected accept, got exit $rc"
    fi

    harness_settle "$reason" "$label" "$out" 'accepted'
}

# harness_rejects <gate> <dir> <label> <substring the report must carry>
#
# The drift verdict. Exactly exit 1, not merely non-zero: 2 is the could-not-run
# exit (harness_usage_error) and 255 a fatal, and accepting either let a crash
# whose stack trace happened to contain the asserted substring report `ok`. Both
# sibling harnesses were tightened for that separately, at different times, and
# each kept a different message — which is the drift this file exists to end.
harness_rejects() {
    local gate="$1" dir="$2" label="$3" expected="$4" out rc reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    if degraded "$out"; then
        reason='the gate ran degraded — PHP emitted a diagnostic'
    elif [ "$rc" -ne 1 ]; then
        reason="expected the drift verdict, got exit $rc"
    elif [ -z "$expected" ]; then
        reason='the must-carry argument is empty, so it would assert nothing'
    elif ! grep -qF -- "$expected" <<<"$out"; then
        reason="rejected, but not for the tested reason; expected to find: $expected"
    fi

    harness_settle "$reason" "$label" "$out" 'rejected on the tested violation'
}

# harness_fail <message>
#
# The bookkeeping failure a caller raises outside an assertion — a fixture it
# could not build, a derived list that came back empty. Three harnesses had three
# spellings of this, on two different streams.
harness_fail() {
    printf 'FAILED (harness): %s\n' "$1" >&2
    fails=$((fails + 1))
}

# harness_fail's increment, driven once here rather than in each caller. The two
# increment sites in this file are harness_settle (probed by
# harness_probe_inert_shapes below, six arms, six increments) and this one.
harness_probe_fail() {
    harness_fail 'bookkeeping self-test'
}

harness_probe_reporters 1 harness_probe_fail 'harness_fail does not raise the failure counter'

# harness_usage_error <gate> <dir> <label> <substring>
#
# The could-not-run verdict, exit 2. Kept apart from the drift verdict because a
# helper that accepts "any non-zero" lets a setup failure count as a caught
# violation — which is what tightening the phpat harness first surfaced. Three
# harnesses had grown their own copy of this within two rounds of the file that
# exists to stop exactly that, and the three had already drifted apart on stdout
# vs stderr, `FAIL` vs `FAILED`, and branch order.
harness_usage_error() {
    local gate="$1" dir="$2" label="$3" expected="$4" out rc reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    # One report site, for the reason harness_report_is_inert states below and this
    # function did not follow: with an increment behind each arm, the reporter probe
    # only ever reaches the arm its own fixture takes. Measured — the probe drives a
    # path that is not a directory, which lands in the substring arm, so deleting the
    # increment from the exit-code arm left a real gate regression printing FAILED and
    # the run exiting 0.
    if degraded "$out"; then
        reason='the gate ran degraded — PHP emitted a diagnostic'
    elif [ "$rc" -ne 2 ]; then
        reason="expected the usage exit, got exit $rc"
    elif [ -z "$expected" ]; then
        reason='the must-carry argument is empty, so it would assert nothing'
    elif ! grep -qF -- "$expected" <<<"$out"; then
        reason="refused, but not for the tested reason; expected to find: $expected"
    fi

    harness_settle "$reason" "$label" "$out" 'refused to run, as expected'
}

# harness_report_is_inert <gate> <dir> <label>
#
# The report-shape assertion for consumer-controlled bytes, shared by every gate
# that echoes them. It asserts what GitHub Actions and a terminal key on, and the
# runner reads TWO command grammars with different anchoring: `::cmd::` must start
# the line, but only after the runner's own TrimStart(); the legacy `##[cmd]` is
# found with IndexOf and needs no line start at all. Both grammars are derived in
# bin/support/safe-report-value.php, which names the runner sources — one copy, so
# one place goes stale when the runner changes.
#
# One deliberate over-match in the `::` arm: the name class admits digits and
# underscore, because a stop token is registered verbatim rather than validated
# against a fixed set.
#
# One deliberate GAP, stated rather than papered over. .NET's TrimStart also removes
# non-ASCII spaces (NBSP, the U+2000 block, U+3000), which POSIX `[[:space:]]` does
# not, so a payload led by one of those would reach the runner past this arm. Two
# attempts to close it failed in ways worth recording: `\xc2` inside an ERE is not a
# byte escape in GNU grep — it reads as the literal text `xc2`, so the arm covered
# nothing while looking wider — and a shell-inserted lead byte matches only the FIRST
# byte of a two-byte sequence, leaving the continuation byte where `::` must be.
# Closing it properly means enumerating whole UTF-8 sequences, and the route needs
# the scrub to be broken first, so the enumeration guards a second-order case. Left
# open on purpose; re-derive in a container, never on this host, whose grep is ugrep
# and is Unicode-aware for `[[:space:]]`:
#
#     printf '\xc2\xa0::Error::x\n' > /tmp/p
#     docker run --rm -v /tmp:/t debian:stable-slim grep -cE '^[[:space:]]*::' /t/p
#
# The line count is the third arm, since splitting the report is how a forged line
# reaches column 0.
#
# The optional fourth argument is the SCRUBBED payload: absence alone is also
# satisfied by a value that never arrived. Its RAW form would be the wrong assertion —
# it is the scrub this pins, not the omission. An earlier version counted `^  - ` prefixed
# lines, which is backwards — an injected line does not carry that prefix, so the
# count stayed at 1 while a literal `::notice::forged` sat at column 0.
#
# One report site, not one per arm: with an increment behind each `elif` the probe
# only ever reaches the arm its own fixture takes, leaving the others free to lose
# theirs. Measured.
harness_report_is_inert() { # <gate> <dir> <label> [<scrubbed payload the report must carry>]
    local gate="$1" dir="$2" label="$3" out rc lines reason=''
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?
    lines="$(grep -c . <<<"$out" || true)"

    if degraded "$out"; then
        reason='the gate ran degraded — PHP emitted a diagnostic'
    elif [ "$rc" -ne 1 ]; then
        reason="expected the drift verdict, got exit $rc"
    elif grep -q "$(printf '\033')" <<<"$out"; then
        reason='an ANSI escape from a consumer value reached the report'
    elif grep -qE '^[[:space:]]*::[A-Za-z0-9_-]+' <<<"$out"; then
        reason='a consumer value forged a `::` workflow command'
    elif grep -qF -- '##[' <<<"$out"; then
        reason='a consumer value forged a legacy `##[…]` workflow command'
    elif [ "$lines" -gt 4 ]; then
        reason="a consumer value split the report across $lines lines"
    elif [ "$#" -gt 3 ] && [ -z "$4" ]; then
        # Not a verdict about the gate: a caller asked for the must-carry check and
        # handed it nothing. `grep -qF -- ""` matches everything, so without this the
        # arm would accept silently — the fail-open shape this helper exists against.
        reason='the must-carry argument is empty, so it would assert nothing'
    elif [ "$#" -gt 3 ] && ! grep -qF -- "$4" <<<"$out"; then
        reason='the scrubbed value never reached the report — inert by omission, not by scrubbing'
    fi

    harness_settle "$reason" "$label" "$out" 'rejected, and the report stayed inert'
}

# Every arm of the three shared assertions, driven once. Per-caller copies of this
# were what the shared helpers replaced; what the callers could NOT prove is that
# each arm still decides, because no fixture makes a gate reject for the wrong
# reason or exit 2 where 1 was due. Measured before this existed: deleting the
# must-carry arm of harness_rejects left all four suites green.
#
# `php` is shadowed by a function whose output and exit code the driver sets, so
# an arm is reached without a gate that produces it. The count is the discriminator:
# delete any arm and one increment goes missing.
#
# Each degraded arm's must-carry is a substring the degraded FIXTURE prints. That is
# load-bearing and was wrong once: with a must-carry the fixture does not contain, the
# case violates the must-carry arm too, so deleting the degraded arm falls through and
# still increments — the count stays 10 and the probe passes on an arm that no longer
# decides. Measured, in both directions.
harness_probe_assert_shapes() {
    php() { printf '%s\n' "$harness_fake_report"; return "$harness_fake_rc"; }

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_fake_rc=0
    harness_accepts php /nonexistent 'probe: accepts, the gate ran degraded'

    harness_fake_report='  - x: a drift verdict'
    harness_fake_rc=1
    harness_accepts php /nonexistent 'probe: accepts, the gate did not accept'

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_fake_rc=1
    harness_rejects php /nonexistent 'probe: rejects, the gate ran degraded' 'diagnostic'

    harness_fake_report='  - x: refused to run'
    harness_fake_rc=2
    harness_rejects php /nonexistent 'probe: rejects, not the drift verdict' 'refused'

    harness_fake_report='  - x: a drift verdict'
    harness_fake_rc=1
    harness_rejects php /nonexistent 'probe: rejects, an empty must-carry' ''
    harness_rejects php /nonexistent 'probe: rejects, the wrong reason' 'a substring the report never prints'

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_fake_rc=2
    harness_usage_error php /nonexistent 'probe: usage, the gate ran degraded' 'diagnostic'

    harness_fake_report='  - x: a drift verdict'
    harness_fake_rc=1
    harness_usage_error php /nonexistent 'probe: usage, not the usage exit' 'drift'

    harness_fake_report='  - x: refused to run'
    harness_fake_rc=2
    harness_usage_error php /nonexistent 'probe: usage, an empty must-carry' ''
    harness_usage_error php /nonexistent 'probe: usage, the wrong reason' 'a substring the report never prints'
}

harness_probe_reporters 10 harness_probe_assert_shapes \
    'a shared assertion has an arm that no longer decides'

# The shape arms above cannot be reached from the fixtures. With the scrub intact no
# fixture's report matches any of them — the `::` arm needs the sequence at column 0
# after leading blanks, and a scrubbed value never puts it there — so deleting any
# one of them leaves all three suites green. Measured. They are driven here instead,
# one crafted report per grammar. The shape calls pass three arguments so the count
# stays one increment per arm; the two that drive the must-carry arms pass four.
#
# `php` is shadowed rather than a stub gate written to disk: harness_probe_reporters
# runs its driver in a subshell, so the override cannot escape, and this needs no
# fixture tree, no interpreter and no cleanup. It covers the degraded arm too, which
# is otherwise probed as a pattern and not as wiring.
harness_probe_inert_shapes() {
    php() { printf '%s\n' "$harness_fake_report"; return "$harness_fake_rc"; }
    harness_fake_rc=1

    harness_fake_report="  - x: $(printf '\033')[2K forged"
    harness_report_is_inert php /nonexistent 'probe: an ESC reached the report'

    harness_fake_report='  ::error::forged'
    harness_report_is_inert php /nonexistent 'probe: a `::` command at line start'

    harness_fake_report='  - x: mid-line ##[error]forged'
    harness_report_is_inert php /nonexistent 'probe: a legacy `##[…]` command'

    harness_fake_report="$(printf 'a\nb\nc\nd\ne')"
    harness_report_is_inert php /nonexistent 'probe: the report was split'

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_report_is_inert php /nonexistent 'probe: the gate ran degraded'

    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: an empty must-carry' ''

    # The exit-code arm, which the six above cannot reach: they all stub `return 1`,
    # and every real fixture rejects with 1. Without this, a gate that started
    # ACCEPTING a poisoned fixture would be reported as "rejected, and the report
    # stayed inert".
    harness_fake_rc=0
    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: the gate did not reject at all'
    harness_fake_rc=1
}

harness_probe_reporters 7 harness_probe_inert_shapes \
    'harness_report_is_inert has an arm that no longer decides'

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
# Counting, split out of the guard so it can be driven against a fixture: the guard
# itself keys on `${BASH_SOURCE[1]}`, which no probe can set.
#
# `grep -o` piped into `wc -l`, not `grep -c`. Whether `-o` changes what `-c`
# counts is implementation-defined — POSIX specifies `-c` over selected LINES and
# does not specify `-o` at all — and on the greps these tests run under it does
# not, so two increments sharing a line read as one and a new report site ships
# unprobed. That is the silent direction. Re-derive on whichever grep is on PATH:
#
#     two='fails=$((fails + 1)) ; fails=$((fails + 1))'
#     printf '%s\n' "$two" | grep -coE 'fails=\$\(\('            # the rejected form
#     printf '%s\n' "$two" | grep -oE  'fails=\$\(\(' | wc -l    # the form used
#
# Both are printed because only the second one can contradict the code. The first
# answer names the IMPLEMENTATION, not an expiry date. Measured 2026-08-19: GNU
# grep 3.8 -> 1, the busybox in the buildbox image -> 1, ugrep 7.8.4 -> 2, because
# ugrep counts matches once `-o` is given. So a 2 there does not retire this note;
# it says the grep answering is not one CI runs. The second must answer 2 on every
# grep these tests can run under — measured 2 on ugrep 7.8.4 and busybox — and `-c`
# may replace the pipe only once the first answers 2 everywhere too.
# Full-line comments are filtered here rather than anchored away in the pattern.
harness_count_increments() { # <file>
    grep -vE '^[[:space:]]*#' -- "$1" | grep -oE "$harness_increment_pattern" | wc -l
}

# The counting unit is a control in its own right, and the one this guard got
# wrong once: it fails in the direction that stays green. One fixture line per
# property the answer depends on — two sites sharing a line (the `grep -o | wc -l`
# choice above), a full-line comment (the filter), a trailing comment (the filter's
# anchor), a case label carrying the word (must NOT count), then one line per
# alternative. The two that OVER-match each get a line that only names the counter,
# counted on purpose: that is what reddens if either is re-narrowed to demand an
# operator.
#
# `@` stands in for `fails` and is substituted at run time, so these lines are not
# themselves report sites: this file counts its own increments at the bottom, and
# literal idioms here would raise that bar and license real strays one for one.
harness_probe_increment_counting() {
    # One number, read by both the comparison and the message, so the two cannot
    # disagree. Hand-counting it got the answer wrong once already: the first
    # fixture line carries TWO report sites, not one.
    local expected=9
    local fixture probe

    fixture="$(printf '%s\n' \
        '@=$((@ + 1)) ; @=$((@ + 1))' \
        '    # @=$((@ + 1))' \
        '@=$((@ + 1)) # note' \
        'assert_rejects "$d" "a subject @ closed" "x"' \
        '(( @ > 0 ))' \
        '@+=1' \
        'let "@ += 1"' \
        "let '@ += 1'" \
        'let ++@' \
        'let @=0' | sed 's/@/fails/g')"

    # Process substitution rather than a temp file: no EXIT trap is armed yet at
    # SOURCE time, and arming one here would be replaced by harness_workdir's later.
    probe="$(harness_count_increments <(printf '%s\n' "$fixture"))" || true

    # `-z` first: `[ "" -ne 9 ]` exits 2 for a syntax error, which an `if` reads as
    # FALSE and the guard passes — the fail-open shape this file records against
    # itself further down.
    if [ -z "$probe" ] || [ "$probe" -ne "$expected" ]; then
        printf 'FAILED  harness bookkeeping: the increment counter answers %s on its own fixture, expected %s — it is not counting every position the pattern admits, the comment filter stopped filtering, or it started counting a label\n' \
            "$probe" "$expected" >&2
        exit 1
    fi
}

# Top level, beside the degraded() and reporter probes: it then runs for every
# harness that sources this file, whether or not that harness reaches the guard.
harness_probe_increment_counting

harness_assert_no_stray_increments() {
    local file="${BASH_SOURCE[1]}" found

    # `${BASH_SOURCE[1]}` is whatever path the caller was invoked with — CI runs
    # `bash tests/<name>.sh`, i.e. relative. No caller reaches this guard after a
    # `cd`; one that did would find the path unreadable, which the arm below
    # reports loudly. Re-deriving an absolute path here was tried and removed: the
    # branch could not execute, and the `cd` its comment named happens 24 lines
    # after the call it claimed to protect.
    #
    # grep exits 1 for "no match" (a legitimate zero) and 2 for "cannot read". Folding
    # both into `|| true` made an unreadable file produce an empty `found`, whose
    # `[ "" -ne 1 ]` errors out and reads as FALSE inside the `if` — the guard passed.
    # Measured: a caller that had cd'd reached the end with three stray increments and
    # exit 0. It is the guard against false greens, so it must not be one.
    # Readability up front, because the count below runs through a pipe and pipefail
    # would fold "cannot read" back into "no match" — the fail-open shape this guard
    # already had once.
    if [ ! -r "$file" ]; then
        printf 'FAILED  harness bookkeeping: cannot read %s to count its increments\n' "$file" >&2
        exit 1
    fi

    # Both greps exit 1 on no match, which is a legitimate zero here; `wc -l` has
    # already printed it by then. Unreadability is settled above, so this cannot
    # fold a read error back into a zero.
    found="$(harness_count_increments "$file")" || true

    if [ "$found" -ne "$1" ]; then
        printf 'FAILED  harness bookkeeping: %s carries %s raw increment(s), expected %s — route a new report site through a probed helper, or raise the number here on purpose\n' \
            "$file" "$found" "$1" >&2
        exit 1
    fi
}

# This file's own increments, through the same helper the callers use: harness_settle
# and harness_fail, the two report sites every caller now routes through. Called from
# the top level of the file that DEFINES it, `${BASH_SOURCE[1]}` resolves to this file
# — verified — so a second, near-identical function for the purpose was one copy too
# many, and the copy had drifted: only the caller-facing one separated "cannot read"
# from "wrong count".
harness_assert_no_stray_increments 2
