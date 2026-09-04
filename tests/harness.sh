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
# It exists because the copies had already drifted apart on the same contract in
# more than one direction — dropping one `fails=$((fails + 1))` anywhere left
# every case printing FAILED at exit 0. One definition cannot drift from itself.

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

# harness_require_executable <path> <install-hint>
#
# Aborts with exit 2 if <path> is not an executable file — the guard every
# PHPStan-driven case file needs before its first invocation, since a missing
# binary would otherwise surface as an opaque shell error ("No such file or
# directory" — every caller invokes it by its full path, never a bare command
# bash would instead report "command not found" for) from deep inside the case
# logic instead of a clear, actionable message naming the install step that
# was skipped. <install-hint> is the caller's own phrase for where to run
# `composer install` (root, or a named subdirectory) — the one thing that
# varies between callers.
harness_require_executable() {
    local path="$1" hint="$2"

    if [ ! -x "$path" ]; then
        printf 'FAIL: %s is missing — run `composer install`%s first.\n' "$path" "$hint"
        exit 2
    fi
}

# harness_mkdir_owned <dir>
#
# Prints 1 if THIS call created <dir>, 0 if it already existed (or could not
# be created for any other reason — treated the same as "not owned" so this
# call never removes something it did not create). The classification comes
# from mkdir's own exit status in the same syscall that would otherwise have
# created the directory, so there is no separate existence-check-then-later-
# act window a second concurrent caller (or a maintainer's own same-named
# directory) could be misread through. Extracted after review flagged the
# only two callers of this logic, both in tests/check-php-cs-fixer-cases.sh,
# as hand-copied duplicates of the same contract — consistent with each
# other so far, but a future edit to one would not have propagated to the
# other; both now call this instead.
harness_mkdir_owned() {
    mkdir -- "$1" 2>/dev/null && echo 1 || echo 0
}

# harness_rmdir_if_owned <dir> <owned>
#
# Removes <dir> only when <owned> is 1 (this invocation created it, per
# harness_mkdir_owned) and only if rmdir succeeds — a directory that gained
# real content since creation is left alone. Never fails the caller: `|| true`
# on the rmdir, and a trailing `true` of its own, because `[ COND ] && { ... }`
# fails the whole function's return status when COND is false AND this is the
# function's own last statement — that failure then aborts an EXIT trap
# calling this before it reaches whatever cleanup follows.
harness_rmdir_if_owned() {
    [ "$2" -eq 1 ] && { rmdir -- "$1" 2>/dev/null || true; }
    true
}

# harness_pad_text_to_cap <bound> <prefix> <filler-char> <suffix> <out-file>
#
# Builds a plain-text document of EXACTLY <bound> bytes: <prefix> and <suffix> are
# kept byte-for-byte, and the gap between them is filled with <filler-char> repeated
# enough times to land the whole document on the cap, for a caller whose
# "at-the-size-cap" fixture is a README pin (<prefix> empty, <suffix> the pin) or a
# comment-terminated config line (<prefix> the real content plus its comment opener,
# <suffix> the newline that closes it). Shared by tests/check-gitattributes-lockstep-cases.sh —
# a JSON-shaped sibling of this builder (padding via a trailing `"//"` key rather than
# a filler run) existed here too until #78 removed its only remaining bash caller;
# the JSON shape now lives once, shared, as GateTestCase::padJsonToCap() (moved there
# by #78 once CheckConsumerConfigTest became a second PHPUnit caller alongside
# CheckVersionLockstepTest) — this bash builder stays separate because a bash
# process cannot share a PHP static method with its PHPUnit callers.
harness_pad_text_to_cap() {
    local bound="$1" prefix="$2" filler="$3" suffix="$4" out_file="$5"
    php -r '
        $bound  = (int) $argv[1];
        $prefix = $argv[2];
        $filler = $argv[3];
        $suffix = $argv[4];
        $pad    = $bound - strlen($prefix) - strlen($suffix);
        $out    = $prefix . str_repeat($filler, $pad) . $suffix;

        if (strlen($out) !== $bound) {
            fwrite(STDERR, sprintf("fixture is %d bytes, not the cap of %d\n", strlen($out), $bound));
            exit(1);
        }

        file_put_contents($argv[5], $out);
    ' "$bound" "$prefix" "$filler" "$suffix" "$out_file"
}

# degraded <output>
#
# True when the interpreter emitted a diagnostic of its own — a PHP warning,
# notice, parse error or fatal, or a Node stack frame / bare-value crash
# preamble. Such a run produced no verdict, whatever it exited with, so no
# assertion may read it as one: a crash that prints the asserted substring on
# its way down would otherwise report `ok` — and it is not only a
# hypothetical for the PHP side: this function now also gates
# harness_run_argv's node-gate callers (assert_*_js, added for #32), where an
# uncaught Node exception exits 1, the SAME code this program's own reject
# path uses, so the exit code cannot tell the two apart either. The Node half
# of the pattern mirrors tests/check-js-configs.sh's own manifest_crashed —
# copied rather than shared, because that function lives in a file this one
# cannot source (a differential-fixture script, not a library).
degraded() {
    grep -qE '^(PHP )?(Warning|Notice|Deprecated|Recoverable fatal error|Fatal error|Parse error|Uncaught)|^[[:space:]]+at |^\[eval\]:[0-9]' <<<"$1"
}

# Both directions, by construction, because nothing else exercises this. No fixture
# makes a gate emit a PHP diagnostic — the read paths install a scoped error handler
# — so the pattern is asserted at every assertion helper and was proven at none.
# Measured: replacing the alternation with German words left three harnesses green.
#
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
    'Uncaught TypeError: f(): Argument #1 must be of type string' \
    "$(node -e 'throw new Error("boom")' 2>&1)" \
    "$(node -e 'throw "a bare string"' 2>&1)"
do
    if ! degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() does not recognise `%s`\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

# One negative per alternative this pattern now carries, and each is the one its
# own anchor decides: the keyword sits mid-line, so only `^` keeps it out. One
# ordinary PHP report line stood here before and was measured to discriminate
# nothing — under the one structural mutation of the PHP half, dropping the
# anchor, it stays a miss. The two node-shaped lines are the same discriminating
# pair tests/check-js-configs.sh's manifest_crashed is itself proven against — a
# real gate report line can legitimately quote "at" or an `[eval]:N`-looking
# fragment inside a value it is reporting on. `'a peerDependencies entry has no
# devDependencies pin proving it'` stood here too, once — it contains none of
# this pattern's trigger substrings at all, so it discriminated nothing under
# ANY mutation of this function and was dropped rather than kept as inert
# filler the comment above would otherwise misdescribe as proven.
for harness_degraded_probe in \
    '  - phpunit.xml: Warning is not a strict flag' \
    'INFO     peer: "   at the start"' \
    'INFO     peer: "[eval]:1"'
do
    if degraded "$harness_degraded_probe"; then
        printf 'FAILED  harness bookkeeping: degraded() misreads `%s` as a diagnostic\n' "$harness_degraded_probe" >&2
        exit 1
    fi
done

unset harness_degraded_probe

# harness_skip_if_root <what a skip explains, e.g. "the unreadable-file case">
#
# Root bypasses DAC, so a `chmod 000` fixture stays readable under it — the
# case would read as a false regression rather than a caught violation. CI
# runs non-root, so the guarded branch stays exercised there regardless.
#
# Returns 0 (skip, message already printed) when running as root, 1 (do not
# skip) otherwise — call as `if ! harness_skip_if_root "..."; then
# ...fixture body...; fi`.
harness_skip_if_root() {
    if [ "$(id -u)" -eq 0 ]; then
        printf 'skip (running as root: mode 000 does not deny read): %s\n' "$1"
        return 0
    fi
    return 1
}

# Driven rather than merely asserted, the same discipline every shared
# decision function in this file is held to: an inverted `if`, or a
# `return 1` swapped for `return 0`, would let every guarded fixture body
# silently never run while CI stayed green. `id` is shadowed with a bash
# function the same way `harness_probe_assert_shapes`/`harness_probe_inert_shapes`
# below shadow `php`: a function definition takes precedence over the PATH
# binary for any invocation at any call depth reached from the shell that
# defined it, command substitution included — so `harness_skip_if_root`'s own
# `"$(id -u)"` sees the probe's canned uid without needing to run as anyone.
# The stub rejects any call not shaped exactly `id -u` — a mutation calling
# `id` bare or `id -g` instead would otherwise still return the canned value
# and pass, verifying nothing about which flag the wrapper actually asks for.
# Run inside a subshell so the shadow and the probe's own variable are gone
# the instant it returns, the same way the `verdict` direction-check further
# down uses a subshell rather than `unset -f` afterward.
probe_skip_if_root_wiring() {
    id() {
        [ "$#" -eq 1 ] && [ "$1" = '-u' ] || return 2
        printf '%s\n' "$harness_fake_uid"
    }

    harness_fake_uid=0
    harness_skip_if_root 'probe: root' >/dev/null || return 1

    harness_fake_uid=1000
    harness_skip_if_root 'probe: not root' >/dev/null && return 1

    return 0
}

if ! (probe_skip_if_root_wiring); then
    printf 'FAILED  harness bookkeeping: harness_skip_if_root does not correctly gate on root vs non-root\n' >&2
    exit 1
fi

# verdict
#
# The run's single exit point. **Do not turn the `exit` below into `return`.**
#
# `verdict` must be able to END the run, not just leave a function: a caller may call
# it anywhere, and with `return` such a call would fall through and the script would
# carry on. No caller does that today — every harness calls it once, as its last line,
# and its early aborts are bare `exit 1` — so the reason below is the load-bearing one.
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
# every green run of every harness that sources this file would go red first.
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
# decide-then-report-once shape. Every increment in this file goes through here or
# through harness_fail, which is what makes the caller-side bar meaningful; the bar
# itself is declared at the foot of this file rather than restated here.
harness_settle() {
    if [ -n "$1" ]; then
        printf 'FAILED (%s): %s\n%s\n' "$1" "$2" "$3" >&2
        fails=$((fails + 1))

        return
    fi

    printf 'ok (%s): %s\n' "$4" "$2"
}

# harness_decide_accepts <out> <rc> <label>
#
# The clean-verdict decision, split out of harness_accepts so a caller driving a
# DIFFERENT interpreter (harness_run_argv, added for the node gate in #32) gets
# the same decision without a second `php`-shaped top-level function. Added
# alongside harness_accepts rather than in place of it: harness_accepts keeps its
# existing <gate> <dir> <label> signature and every existing caller, and now
# dispatches to this.
harness_decide_accepts() {
    local out="$1" rc="$2" label="$3" reason=''

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
    elif [ "$rc" -ne 0 ]; then
        reason="expected accept, got exit $rc"
    fi

    harness_settle "$reason" "$label" "$out" 'accepted'
}

# harness_accepts <gate> <dir> <label>
#
# The clean verdict, exit 0.
harness_accepts() {
    local gate="$1" dir="$2" label="$3" out rc
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    harness_decide_accepts "$out" "$rc" "$label"
}

# harness_decide_rejects <out> <rc> <label> <expected>
#
# See harness_decide_accepts for why this is split out.
harness_decide_rejects() {
    local out="$1" rc="$2" label="$3" expected="$4" reason=''

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
    elif [ "$rc" -ne 1 ]; then
        reason="expected the drift verdict, got exit $rc"
    elif [ -z "$expected" ]; then
        reason='the must-carry argument is empty, so it would assert nothing'
    elif ! grep -qF -- "$expected" <<<"$out"; then
        reason="rejected, but not for the tested reason; expected to find: $expected"
    fi

    harness_settle "$reason" "$label" "$out" 'rejected on the tested violation'
}

# degraded() recognising the shape is not the same as harness_decide_rejects
# ACTING on it: rc=1 is both this program's reject exit code and Node's
# uncaught-exception exit code, and the must-carry substring is checked with a
# plain grep — so a crash whose text happens to contain it satisfies every
# OTHER condition harness_decide_rejects checks. Driven rather than merely
# asserted, the same discipline tests/check-js-configs.sh's own crashing_gate
# probe uses for the identical reason (search that file for "The crash guard,
# driven rather than asserted"): a probe that stubs degraded() itself would
# prove the regex again, not the wiring around it.
probe_degraded_reaches_reject_decision() {
    local crash
    crash="$(node -e 'throw new Error("boom biome/base.json")' 2>&1)"

    # The asserted substring is one the crash text DOES contain (biome/base.json,
    # from the thrown message) and rc is 1 — both conditions harness_decide_rejects
    # would otherwise read as a genuine reject verdict. Without the degraded()
    # branch reaching this decision, the grep for the substring succeeds and the
    # call reports ok, raising nothing — which is the false "ok" this fix exists
    # to prevent.
    harness_decide_rejects "$crash" 1 \
        'bookkeeping self-test — a node crash whose text contains the must-carry substring' \
        'biome/base.json'
}

harness_probe_reporters 1 probe_degraded_reaches_reject_decision \
    'harness_decide_rejects reports a Node crash as ok once its text happens to contain the must-carry substring'

# harness_decide_reports_once <out> <rc> <label> <file prefix>
#
# See harness_decide_accepts for why this is split out — added for #32's node
# gate to reach the same "reported once, as itself" property `assert_reports_once`
# checks on the PHP side, which `harness_decide_rejects` cannot express: that
# helper greps for the PRESENCE of one substring, not for "and nothing further
# was said about this file" — the property a read-failure path needs, since the
# defect it guards against is an EXTRA fabricated violation rather than a
# missing one.
harness_decide_reports_once() {
    local out="$1" rc="$2" label="$3" prefix="$4" count reason=''
    count="$(grep -cF -- "- $prefix:" <<<"$out" || true)"

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
    elif [ "$rc" -ne 1 ]; then
        reason="expected the drift verdict, got exit $rc"
    elif [ "$count" -ne 1 ]; then
        reason="expected exactly one $prefix violation, got $count"
    fi

    harness_settle "$reason" "$label" "$out" 'reported exactly once'
}

# The same wiring gap probe_degraded_reaches_reject_decision closes for
# harness_decide_rejects, extended to this sibling: a Node crash whose text
# happens to contain the "- <prefix>:" needle EXACTLY ONCE, at rc=1, would
# otherwise satisfy the count check too — degraded() has to be the reason this
# still fails, not an accident of the count. Constructed rather than a literal
# `throw`, because `node -e`'s crash reporter echoes the offending source
# line verbatim, which unavoidably duplicates any message text embedded in it
# onto a second line — undercutting the "exactly one" premise this probe
# needs to hold without degraded()'s gate. The single `at ...` line still
# makes this a genuine instance of the Node-crash SHAPE degraded() matches
# (the property under test), even though it did not originate from an actual
# uncaught exception; harness_decide_rejects' own probe above can afford a
# literal throw only because its check has no count for a fabricated shape to
# accidentally satisfy.
probe_degraded_reaches_reports_once_decision() {
    local crash
    crash="$(node -e 'process.stderr.write("- biome.json: pretend\n    at fakeFn (/x:1:1)\n"); process.exit(1)' 2>&1)"

    harness_decide_reports_once "$crash" 1 \
        'bookkeeping self-test — a node-crash-shaped line whose text contains the must-carry prefix exactly once' \
        'biome.json'
}

harness_probe_reporters 1 probe_degraded_reaches_reports_once_decision \
    'harness_decide_reports_once reports a node-crash-shaped line as ok once its text happens to satisfy the count check'

# harness_rejects <gate> <dir> <label> <substring the report must carry>
#
# The drift verdict. Exactly exit 1, not merely non-zero: 2 is the could-not-run
# exit (harness_usage_error) and 255 a fatal, and accepting either let a crash
# whose stack trace happened to contain the asserted substring report `ok`. Both
# sibling harnesses were tightened for that separately, at different times, and
# each kept a different message — which is the drift this file exists to end.
harness_rejects() {
    local gate="$1" dir="$2" label="$3" expected="$4" out rc
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    harness_decide_rejects "$out" "$rc" "$label" "$expected"
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

# harness_fail's increment, driven once here rather than in each caller. The other
# increment site is harness_settle, driven by harness_probe_assert_shapes and
# harness_probe_inert_shapes below; each states its own arm count as the argument to
# harness_probe_reporters, so no number is repeated here to drift from it.
harness_probe_fail() {
    harness_fail 'bookkeeping self-test'
}

harness_probe_reporters 1 harness_probe_fail 'harness_fail does not raise the failure counter'

# harness_usage_error <gate> <dir> <label> <substring>
#
# The could-not-run verdict, exit 2. Kept apart from the drift verdict because a
# helper that accepts "any non-zero" lets a setup failure count as a caught
# violation, and because the harnesses had already grown their own copy of this
# and drifted apart on stdout vs stderr, `FAIL` vs `FAILED`, and branch order.
harness_usage_error() {
    local gate="$1" dir="$2" label="$3" expected="$4" out rc
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    harness_decide_usage_error "$out" "$rc" "$label" "$expected"
}

# harness_decide_usage_error <out> <rc> <label> <expected>
#
# See harness_decide_accepts for why this is split out.
#
# One report site, for the reason harness_report_is_inert states below and this
# function did not follow: with an increment behind each arm, the reporter probe
# only ever reaches the arm its own fixture takes. Measured — the probe drives a
# path that is not a directory, which lands in the substring arm, so deleting the
# increment from the exit-code arm left a real gate regression printing FAILED and
# the run exiting 0.
harness_decide_usage_error() {
    local out="$1" rc="$2" label="$3" expected="$4" reason=''

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
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
harness_report_is_inert() { # <gate> <dir> <label> [<scrubbed payload the report must carry>] [<expected exit code, default 1>]
    local gate="$1" dir="$2" label="$3" out rc
    out="$(php "$gate" "$dir" 2>&1)" && rc=0 || rc=$?

    # "${@:4}" — not "$4"/"$5" behind an if/else — is what carries whether a
    # 4th and/or 5th argument was PASSED at all: it expands to zero, one or
    # two words accordingly, so harness_decide_report_is_inert's own `$#` and
    # `${5:-1}` see the same presence/absence its own arms decide on.
    harness_decide_report_is_inert "$out" "$rc" "$label" "${@:4}"
}

# harness_decide_report_is_inert <out> <rc> <label> [<scrubbed payload the report must carry>] [<expected exit code, default 1>]
#
# See harness_decide_accepts for why this is split out.
#
# The 5th argument (GH-42) is appended, defaulted — never spliced before the
# existing must-carry slot — so every pre-existing 3/4-argument caller is
# unaffected: `${5:-1}` keeps the drift verdict (exit 1) as the default this
# function has always checked. It exists because a gate whose "could not run"
# class is genuinely exit 2 rather than exit 1 (tests/check-release-tag-lockstep.php's
# shape-check rejection, which cannot reach exit 1 at all — see that gate's own
# cases file) still needs the same six scrub/forgery checks below; duplicating
# them in a per-suite local function was tried first and reverted; a single
# defaulted parameter is what this project's own append-only-parameter rule
# already prescribes for exactly this shape of change.
harness_decide_report_is_inert() {
    local out="$1" rc="$2" label="$3" lines reason='' expected_rc="${5:-1}"
    lines="$(grep -c . <<<"$out" || true)"

    if degraded "$out"; then
        reason='the gate ran degraded — it emitted a diagnostic'
    elif [ "$rc" -ne "$expected_rc" ]; then
        reason="expected exit $expected_rc, got exit $rc"
    elif grep -q "$(printf '\033')" <<<"$out"; then
        reason='an ANSI escape from a consumer value reached the report'
    elif grep -qE '^[[:space:]]*::[A-Za-z0-9_-]+' <<<"$out"; then
        reason='a consumer value forged a `::` workflow command'
    elif grep -qF -- '##[' <<<"$out"; then
        reason='a consumer value forged a legacy `##[…]` workflow command'
    elif grep -q "$(printf '\r')" <<<"$out"; then
        # CR is its own line break to the runner, which reads child output with
        # StreamReader.ReadLine() — LF, CR or CRLF all end a line. The `::` arm above
        # cannot see it, because grep splits on LF only, so a bare CR opens a line that
        # arm never examines. Measured: dropping \r from safeReportValue's class left
        # every suite green before this existed.
        reason='a consumer value carried a bare carriage return, which opens a line to the runner'
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

# harness_run_argv <dir> <cmd...>
#
# Runs <cmd...> <dir>, capturing stdout+stderr and the exit code into
# HARNESS_OUT / HARNESS_RC. Every gate-specific assertion above hardcodes
# `php "$gate" "$dir"`, which cannot flex to a second interpreter — added for a
# caller driving a node-side gate (bin/check-js-config.mjs, added for #32)
# alongside the PHP one. A caller builds its own thin accept/reject wrapper
# around this and around the already-shared, already-probed harness_settle and
# degraded, rather than this file growing a second `php`-shaped function family
# per interpreter it is asked to drive — harness_accepts and friends stay
# exactly as proven by harness_probe_assert_shapes, unmodified.
harness_run_argv() {
    local dir="$1"
    shift
    # shellcheck disable=SC2034 # read by the caller that sources this file, not by anything in it
    HARNESS_OUT="$("$@" "$dir" 2>&1)" && HARNESS_RC=0 || HARNESS_RC=$?
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
# one of them leaves every suite that calls it green. Measured. They are driven here instead,
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

    harness_fake_report="$(printf '  - x: before\rafter')"
    harness_report_is_inert php /nonexistent 'probe: a bare carriage return'

    harness_fake_report="$(printf 'a\nb\nc\nd\ne')"
    harness_report_is_inert php /nonexistent 'probe: the report was split'

    harness_fake_report='PHP Warning:  the gate emitted a diagnostic'
    harness_report_is_inert php /nonexistent 'probe: the gate ran degraded'

    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: an empty must-carry' ''

    # The exit-code arm, which the arms above cannot reach: they all stub `return 1`,
    # and every real fixture rejects with 1. Without this, a gate that started
    # ACCEPTING a poisoned fixture would be reported as "rejected, and the report
    # stayed inert".
    harness_fake_rc=0
    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: the gate did not reject at all'
    harness_fake_rc=1

    # The must-carry arm proper, which every arm above skips: the empty-must-carry
    # probe passes a fourth argument but an EMPTY one, so this is the one place
    # `harness_decide_report_is_inert`'s final `elif` — a NON-empty must-carry that
    # never reached the report — can be reached at all. Before this, its only guard
    # was a real gate's own fixture (a caller in a fourth file passing that
    # argument), which stops proving anything the day that caller is refactored or
    # deleted. A clean report with a must-carry value it plainly does not contain
    # drives this arm alone: every shape arm above requires the value to be ABSENT
    # from an otherwise clean report to reach here, and none of
    # ESC/`::`/`##[`/CR/split/degraded fires on it.
    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: a must-carry value absent from the report' \
        'a payload this report never carries'

    # The 5th-argument arm (GH-42): a caller can declare an expected exit code
    # other than the drift-verdict default (1) — check-release-tag-lockstep-cases.sh
    # does, for the one poison case whose forge-prone value is rejected before
    # the drift verdict is even reachable. Without this arm, `${5:-1}` silently
    # degrading to "any exit code is accepted" (dropping the comparison
    # entirely) would stay green: every probe above stubs `harness_fake_rc=1`
    # and passes no 5th argument, so none of them exercises this parameter at
    # all. `harness_fake_rc` deliberately stays 1 here while the 5th argument
    # declares 2, so the mismatch is what this arm's `reason` must catch.
    harness_fake_report='  - x: nothing wrong here'
    harness_report_is_inert php /nonexistent 'probe: a declared exit code the gate did not actually return' \
        'nothing wrong here' 2
}

harness_probe_reporters 10 harness_probe_inert_shapes \
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

# The "reverse direction" of a derived-vs-proven lockstep control: after a
# caller's loop has walked every DERIVED entry and marked each one it
# recognised in $3 (an associative array the caller populates as
# `seen[$key]=1` while it runs), this asserts every key of the PROVEN table $2
# was marked. A proven key the loop never reached means the derived source
# dropped or renamed the entry, and whatever per-row assertion the loop runs
# never even ran for it — silence that reads as success.
#
# Calls the CALLER's own `fail`, resolved dynamically at call time the same
# way `harness_probe_reporters` below calls a caller-supplied driver by name —
# this file defines no `fail` of its own (see `harness_fail` instead, a
# differently-shaped reporter with its own callers).
#
# $1 is a printf format string with exactly one %s for the key, already
# through `safe_report` (the key can come from consumer-controlled JSON) —
# callers keep their own message wording this way rather than sharing one
# generic sentence, since the two current call sites name a different source
# file and a different verb ("dropped rather than retargeted" vs "dropped
# rather than renamed").
#
# $2/$3 order is unenforced: swapping them makes this check silently vacuous
# for both real callers below, since `seen`'s keys are always a subset of
# `proven`'s there — nothing (not bash, not shellcheck, not the probe just
# below, which only ever exercises already-correctly-ordered arrays) would
# catch a future swap. Deliberately not guarded at runtime: that subset
# relationship is an artifact of how the two current callers populate `seen`,
# not part of this function's general contract, so enforcing it here would
# overfit one call shape onto a general-purpose helper. Getting the order
# right is on the caller — read this comment before adding a third one.
harness_assert_lockstep_complete() { # <fail-fmt> <proven-array-name> <seen-array-name>
    local -n harness_lockstep_proven="$2"
    local -n harness_lockstep_seen="$3"
    local harness_lockstep_key

    for harness_lockstep_key in "${!harness_lockstep_proven[@]}"; do
        if [ -z "${harness_lockstep_seen[$harness_lockstep_key]:-}" ]; then
            # shellcheck disable=SC2059 # $1 is a caller-supplied printf format, by design
            fail "$(printf "$1" "$(safe_report "$harness_lockstep_key")")"
        fi
    done
}

# Both real call sites (extensionMappings rows, jscpd formats) only ever populate
# `seen` with keys that already passed the `proven` lookup, so neither can drive
# the fail arm from this repository's own fixtures alone — the same shape
# harness_probe_assert_shapes documents above for the shared decision helpers.
# `fail`/`safe_report` are not yet defined at file-source time (the sourcing
# script defines its own, after sourcing this file), so the probe supplies its
# own — `fail` delegates to `harness_fail`, the file's own already-counted
# reporter, rather than incrementing `fails` itself, or this probe would add a
# THIRD raw increment site and break the "2" at the foot of this file.
harness_probe_assert_lockstep_complete() {
    fail() { harness_fail "$1"; }
    safe_report() { printf '%s' "$1"; }

    # shellcheck disable=SC2034 # read via a `local -n` nameref inside
    # harness_assert_lockstep_complete, called by name two lines below —
    # a nameref target passed as a string argument is not traceable statically.
    declare -A harness_lockstep_probe_proven=([a]=1 [b]=1)
    # shellcheck disable=SC2034 # same as above
    declare -A harness_lockstep_probe_seen_complete=([a]=1 [b]=1)
    # shellcheck disable=SC2034 # same as above
    declare -A harness_lockstep_probe_seen_incomplete=([a]=1)

    harness_assert_lockstep_complete 'probe: dropped %s' harness_lockstep_probe_proven harness_lockstep_probe_seen_complete
    harness_assert_lockstep_complete 'probe: dropped %s' harness_lockstep_probe_proven harness_lockstep_probe_seen_incomplete
}

harness_probe_reporters 1 harness_probe_assert_lockstep_complete \
    'harness_assert_lockstep_complete does not fire when a proven key was never seen'

# The "run a tool, reject it with a diagnostic" triad written at every
# negative control in tests/check-js-configs.sh: the tool's outcome is wrong
# in two different ways — it ACCEPTED input the control expects rejected, or
# it rejected for a reason other than the one under test. Only the REJECTING
# direction fits this shape; the mirrored "must accept, and a forbidden
# pattern in the log is the failure" control (an asset import left alone by
# useImportExtensions) is a different assertion and is not this function.
#
# $1 is the tool's own exit status, already captured by the caller — how the
# tool is invoked differs at every site (a wrapped `biome_ci`/`run_tsc`, or a
# bare `npx … jscpd`), so this function never runs the tool itself. $2 the log
# file, attached as an excerpt to EVERY `fail` this reports (the nine sites
# this replaced were inconsistent — three attached it on the accepted branch,
# six did not — always attaching it is the union of what they wrote, never a
# loss). $3/$4/$5 the three messages this triad can report, in outcome order:
# accepted-when-it-should-reject, pass, rejected-for-the-wrong-reason. An
# optional `-i` before the pattern list makes matching case-insensitive for
# every pattern in this call (two of the current call sites need it, the rest
# don't); every pattern is ERE (`grep -E`) and ALL of them must match — AND,
# not OR, since a `|` alternation inside one pattern already gets the OR case.
harness_assert_tool_rejects() { # <status> <log> <accepted-msg> <pass-msg> <mismatch-msg> [-i] <pattern>...
    local harness_reject_status="$1" harness_reject_log="$2"
    local harness_reject_accepted_msg="$3" harness_reject_pass_msg="$4" harness_reject_mismatch_msg="$5"
    local -a harness_reject_grep_flags=(-qE)
    local harness_reject_pattern

    shift 5

    if [ "${1:-}" = '-i' ]; then
        harness_reject_grep_flags=(-qiE)
        shift
    fi

    # A caller mistake — no pattern operand at all — would otherwise fall
    # through the loop below with zero iterations and land on the PASS
    # branch: a tool that failed for a completely unrelated reason would be
    # reported as "the rule fired." Fail loudly instead of degrading the
    # discriminating power the whole triad exists for.
    if [ "$#" -eq 0 ]; then
        fail "harness bookkeeping: harness_assert_tool_rejects called with no pattern to check"
        return
    fi

    # Same failure shape, one operand narrower: an EMPTY pattern (a caller
    # passing "" rather than omitting the argument) is not caught by the
    # count check above, and `grep -qE ''` matches any non-empty log line —
    # so the mismatch branch below would never fire for it either, silently
    # degrading the same discriminating power the count guard exists to
    # protect.
    for harness_reject_pattern in "$@"; do
        if [ -z "$harness_reject_pattern" ]; then
            fail "harness bookkeeping: harness_assert_tool_rejects called with an empty pattern operand"
            return
        fi
    done

    if [ "$harness_reject_status" -eq 0 ]; then
        fail "$harness_reject_accepted_msg" "$harness_reject_log"
        return
    fi

    for harness_reject_pattern in "$@"; do
        if ! grep "${harness_reject_grep_flags[@]}" "$harness_reject_pattern" "$harness_reject_log"; then
            fail "$harness_reject_mismatch_msg" "$harness_reject_log"
            return
        fi
    done

    pass "$harness_reject_pass_msg"
}

# None of the real call sites can drive the accepted/mismatch/empty-pattern arms
# from this repository's own fixtures — every one is a negative control where the
# tool is EXPECTED to reject on a green run, so `status` is never 0 there and the
# pattern list is never empty. Same shape and same reason as
# harness_probe_assert_lockstep_complete above. `fail`/`pass` delegate to this
# file's own already-counted `harness_fail` (a no-op for `pass`, since a pass
# reports nothing that needs counting) rather than reimplementing a reporter, for
# the same "would add a THIRD raw increment site" reason.
harness_probe_assert_tool_rejects() {
    fail() { harness_fail "$1"; }
    pass() { :; }

    local harness_reject_probe_log
    harness_reject_probe_log="$(mktemp)" || return

    printf 'the pattern is here\n' > "$harness_reject_probe_log"

    # accepted (status 0) -> must fail
    harness_assert_tool_rejects 0 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' 'pattern'

    # rejected, matching single pattern -> must pass
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' 'pattern is here'

    # rejected, non-matching pattern -> must fail (wrong reason)
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' 'a substring never in the log'

    # rejected, AND semantics: first matches, second does not -> must fail
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' 'pattern is here' 'a substring never in the log'

    # rejected, case-insensitive match via -i -> must pass
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' -i 'THE PATTERN'

    # rejected, no pattern operand at all -> must fail (the guard above)
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch'

    # rejected, an EMPTY pattern operand among real ones -> must fail (the
    # narrower guard above; `grep -qE ''` would otherwise match the log
    # unconditionally and this call would wrongly pass)
    harness_assert_tool_rejects 1 "$harness_reject_probe_log" \
        'probe: accepted' 'probe: pass' 'probe: mismatch' 'pattern is here' ''

    rm -f "$harness_reject_probe_log"
}

harness_probe_reporters 5 harness_probe_assert_tool_rejects \
    'a triad arm of harness_assert_tool_rejects no longer decides'

# This file's own increments, through the same helper the callers use: harness_settle
# and harness_fail, the two report sites every caller now routes through. Called from
# the top level of the file that DEFINES it, `${BASH_SOURCE[1]}` resolves to this file
# — verified — so a second, near-identical function for the purpose was one copy too
# many, and the copy had drifted: only the caller-facing one separated "cannot read"
# from "wrong count".
harness_assert_no_stray_increments 2
