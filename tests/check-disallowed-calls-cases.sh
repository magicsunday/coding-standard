#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for phpstan/disallowed-calls.neon. Proves the case-folding
# config both LOADS from an installed vendor layout and actually FIRES: each of the
# five banned functions must be reported against tests/consumer/case-folding/.
#
# The control run matters as much as the positive one. A config that loads but
# matches nothing would still make a plain "PHPStan is green" check pass while
# enforcing nothing, and a report seen only in the positive run could equally have
# come from another rule pack in the base. So the same fixture is first analysed
# with base.neon ALONE and must come back clean — only then does a report in the
# second run prove it was disallowed-calls.neon that produced it.
#
# A third run goes through strict.neon, the tier README and AGENTS say delivers the
# bans automatically. That is the only place the strict tier is loaded at all, so the
# run doubles as its smoke test: a broken relative include in strict.neon reds here.
#
# Requires the consumer fixture to be installed (tests/consumer: composer install).
# Run from the package root: bash tests/check-disallowed-calls-cases.sh

set -euo pipefail

# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/harness.sh"

# report_failure <message>
#
# The one reporting helper this file has. Its six report sites used to print and
# increment inline, which left the counter unprovable: `harness_probe_reporters`
# drives a HELPER, and there was none to drive. Four reviewers reported the same
# thing, and the commit that introduced tests/harness.sh claimed both stragglers
# were "closed by the move" — only the verdict half was.
report_failure() {
    printf 'FAIL: %s\n' "$1"
    fails=$((fails + 1))
}

probe_reporters() {
    report_failure 'probe'
}

harness_probe_reporters 1 probe_reporters

# Every increment must sit inside a helper the probe above drives. A report site
# written inline is the defect that recurred in two consecutive rounds, in a
# different harness each time, found by a reviewer rather than by a control — so
# the bar is derived here instead of remembered.
harness_assert_no_stray_increments 1

CONSUMER="$ROOT/tests/consumer"
PHPSTAN="$CONSUMER/.build/bin/phpstan"

if [ ! -x "$PHPSTAN" ]; then
    printf 'FAIL: %s is missing — run `composer install` in tests/consumer first.\n' "$PHPSTAN"
    exit 2
fi


# The banned functions are DERIVED from the shipped config, never hand-kept: a
# sixth ban added to disallowed-calls.neon must not be silently untested, and the
# fixture must not drift away from it either.
CONFIG="$ROOT/phpstan/disallowed-calls.neon"
# Comment lines are stripped first — the file documents an `allowIn` override in a
# commented example, which would otherwise be parsed as a sixth, duplicate ban.
mapfile -t BANNED < <(grep -vE '^[[:space:]]*#' "$CONFIG" \
    | grep -oE "function: '[a-z_]+\(\)'" \
    | sed -E "s/function: '([a-z_]+)\(\)'/\1/")

if [ "${#BANNED[@]}" -eq 0 ]; then
    printf 'FAIL: no banned functions parsed out of %s — the extraction broke.\n' "$CONFIG"
    exit 2
fi
printf 'derived %d banned function(s) from the shipped config: %s\n' "${#BANNED[@]}" "${BANNED[*]}"

# --- CONTROL: the same fixture under base.neon alone must be clean ---
# If this run reports anything, the positive run below proves nothing.
control_out="$(cd "$CONSUMER" && "$PHPSTAN" analyse case-folding \
    --configuration phpstan.neon --error-format=raw --no-progress --memory-limit=-1 2>&1)" \
    && control_rc=0 || control_rc=$?

if [ "$control_rc" -ne 0 ]; then
    report_failure "$(printf 'control: base.neon alone reports on the case-folding fixture, so a\n  report in the positive run would not prove disallowed-calls.neon fired.\n%s' "$control_out")"
else
    printf 'ok (control): base.neon alone is clean on the case-folding fixture\n'
fi

# --- POSITIVE: base.neon + disallowed-calls.neon must report every banned call ---
out="$(cd "$CONSUMER" && "$PHPSTAN" analyse \
    --configuration phpstan-disallowed-calls.neon --error-format=raw --no-progress --memory-limit=-1 2>&1)" \
    && rc=0 || rc=$?

if [ "$rc" -eq 0 ]; then
    report_failure "$(printf 'the case-folding config reported nothing — it loads but does not fire.\n%s' "$out")"
else
    for fn in "${BANNED[@]}"; do
        if grep -qF "Calling ${fn}() is forbidden" <<<"$out"; then
            printf 'ok (reported): %s()\n' "$fn"
        else
            report_failure "$(printf '%s() is not reported by the case-folding config.' "$fn")"
        fi
    done

    # Cardinality, so a stray extra report — or a fixture that stopped covering
    # one ban while another fires twice — cannot hide behind the per-function loop.
    # `grep -c` prints 0 and exits 1 when nothing matches, which under `set -e` would
    # kill the harness right here — before the diagnostic below could say what went
    # wrong. That case is reachable: PHPStan exits non-zero for a config error or for
    # findings from another rule pack too, neither of which contains "is forbidden".
    reported=$(grep -cF 'is forbidden' <<<"$out" || true)
    if [ "$reported" -eq "${#BANNED[@]}" ]; then
        printf 'ok (count): %d report(s) for %d ban(s)\n' "$reported" "${#BANNED[@]}"
    else
        report_failure "$(printf '%d report(s) for %d ban(s) — the fixture and the config have drifted.\n%s' \
            "$reported" "${#BANNED[@]}" "$out")"
    fi
fi

# --- WIRING: the same bans must fire through strict.neon, which is the tier README
# --- and AGENTS say delivers them automatically. Running the tier is what proves it:
# --- an assertion on the include LINE would stay green if strict.neon stopped loading
# --- altogether, and nothing else in the suite loads that file. No count assertion
# --- here — the shipmonk/symplify packs may add findings of their own, and pinning
# --- their number would turn every upstream rule addition into a false failure.
strict_out="$(cd "$CONSUMER" && "$PHPSTAN" analyse \
    --configuration phpstan-strict.neon --error-format=raw --no-progress --memory-limit=-1 2>&1)" \
    && strict_rc=0 || strict_rc=$?

if [ "$strict_rc" -eq 0 ]; then
    report_failure "$(printf 'wiring: the strict tier reported nothing on the case-folding fixture,\n  so the documented automatic inclusion does not hold.\n%s' "$strict_out")"
else
    for fn in "${BANNED[@]}"; do
        if grep -qF "Calling ${fn}() is forbidden" <<<"$strict_out"; then
            printf 'ok (wiring): %s() is reported through strict.neon\n' "$fn"
        else
            report_failure "$(printf 'wiring: %s() is not reported through strict.neon.\n%s' "$fn" "$strict_out")"
        fi
    done
fi

verdict
