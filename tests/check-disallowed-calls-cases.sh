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
# Requires the consumer fixture to be installed (tests/consumer: composer install).
# Run from the package root: bash tests/check-disallowed-calls-cases.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONSUMER="$ROOT/tests/consumer"
PHPSTAN="$CONSUMER/vendor/bin/phpstan"

if [ ! -x "$PHPSTAN" ]; then
    printf 'FAIL: %s is missing — run `composer install` in tests/consumer first.\n' "$PHPSTAN"
    exit 2
fi

fails=0

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

# --- WIRING: strict.neon must include the config, or the documented "a repository
# --- reaching the strict tier gets it automatically" claim is false. The positive
# --- run below goes through base + disallowed-calls directly, so without this
# --- assertion the include could be deleted and the suite would stay green.
if grep -qE '^\s*-\s*disallowed-calls\.neon\s*$' "$ROOT/phpstan/strict.neon"; then
    printf 'ok (wiring): strict.neon includes disallowed-calls.neon\n'
else
    printf 'FAIL (wiring): strict.neon does not include disallowed-calls.neon, so the\n'
    printf '  documented automatic inclusion in the strict tier does not hold.\n'
    fails=$((fails + 1))
fi

# --- CONTROL: the same fixture under base.neon alone must be clean ---
# If this run reports anything, the positive run below proves nothing.
control_out="$(cd "$CONSUMER" && "$PHPSTAN" analyse case-folding \
    --configuration phpstan.neon --error-format=raw --no-progress --memory-limit=-1 2>&1)" \
    && control_rc=0 || control_rc=$?

if [ "$control_rc" -ne 0 ]; then
    printf 'FAIL (control): base.neon alone reports on the case-folding fixture, so a\n'
    printf '  report in the positive run would not prove disallowed-calls.neon fired.\n%s\n' "$control_out"
    fails=$((fails + 1))
else
    printf 'ok (control): base.neon alone is clean on the case-folding fixture\n'
fi

# --- POSITIVE: base.neon + disallowed-calls.neon must report every banned call ---
out="$(cd "$CONSUMER" && "$PHPSTAN" analyse \
    --configuration phpstan-disallowed-calls.neon --error-format=raw --no-progress --memory-limit=-1 2>&1)" \
    && rc=0 || rc=$?

if [ "$rc" -eq 0 ]; then
    printf 'FAIL: the case-folding config reported nothing — it loads but does not fire.\n%s\n' "$out"
    fails=$((fails + 1))
else
    for fn in "${BANNED[@]}"; do
        if grep -qF "Calling ${fn}() is forbidden" <<<"$out"; then
            printf 'ok (reported): %s()\n' "$fn"
        else
            printf 'FAIL: %s() is not reported by the case-folding config.\n' "$fn"
            fails=$((fails + 1))
        fi
    done

    # Cardinality, so a stray extra report — or a fixture that stopped covering
    # one ban while another fires twice — cannot hide behind the per-function loop.
    reported=$(grep -cF 'is forbidden' <<<"$out")
    if [ "$reported" -eq "${#BANNED[@]}" ]; then
        printf 'ok (count): %d report(s) for %d ban(s)\n' "$reported" "${#BANNED[@]}"
    else
        printf 'FAIL: %d report(s) for %d ban(s) — the fixture and the config have drifted.\n%s\n' \
            "$reported" "${#BANNED[@]}" "$out"
        fails=$((fails + 1))
    fi
fi

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
