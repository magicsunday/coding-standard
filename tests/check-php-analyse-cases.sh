#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven case proving that the ROOT phpstan.neon — this package's own
# self-analysis config — actually wires in phpstan/disallowed-function-calls.neon,
# rather than merely appearing to via its `includes:` line.
#
# tests/check-disallowed-calls-cases.sh already proves the ban LIST's own content
# exhaustively, but only through tests/consumer's installed, vendor-nested copy of
# this package — it never runs phpstan.neon itself. A broken or missing include in
# phpstan.neon would leave `composer ci:test:php:analyse` silently green: bin/ and
# tests/ currently call none of the five banned functions, so nothing else in this
# suite would notice. One representative ban is enough here — the list's content is
# not this case's concern, only phpstan.neon's own resolution of it.
#
# The fixture lives in a throwaway directory outside bin/ and tests/, not a tracked
# file under either: phpstan.neon's `paths` recurse through all of tests/ except
# tests/consumer, so a permanent violating fixture anywhere else under tests/ would
# make the real `composer ci:test:php:analyse` fail on every run. PHPStan accepts an
# explicit file path outside its configured `paths` and still applies the loaded
# config's rules to it — verified against this file's own phpstan.neon before this
# case was written.
#
# Run from the package root: bash tests/check-php-analyse-cases.sh

set -euo pipefail

# CDPATH= — see check-disallowed-calls-cases.sh's identical guard.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/harness.sh"

report_failure() { harness_fail "$1"; }

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

PHPSTAN="$ROOT/.build/bin/phpstan"

harness_require_executable "$PHPSTAN" ''

harness_workdir
FIXTURE="$work/probe.php"

# --- CONTROL: a clean fixture must report nothing ---
# If this run reports anything, the positive run below proves nothing: a report
# there could equally have come from the level-6 rule packs alone.
cat > "$FIXTURE" <<'PHP'
<?php

declare(strict_types=1);

function harnessCaseFoldControl(string $s): string
{
    return mb_strtolower($s, 'UTF-8');
}
PHP

control_out="$("$PHPSTAN" analyse --configuration "$ROOT/phpstan.neon" \
    --error-format=raw --no-progress --memory-limit=-1 "$FIXTURE" 2>&1)" \
    && control_rc=0 || control_rc=$?

if [ "$control_rc" -ne 0 ]; then
    report_failure "$(printf 'control: a clean fixture reports against phpstan.neon.\n%s' "$control_out")"
else
    printf 'ok (control): a clean fixture is clean against phpstan.neon\n'
fi

# --- POSITIVE: a banned call must be reported ---
cat > "$FIXTURE" <<'PHP'
<?php

declare(strict_types=1);

function harnessCaseFoldPositive(string $s): string
{
    return strtolower($s);
}
PHP

out="$("$PHPSTAN" analyse --configuration "$ROOT/phpstan.neon" \
    --error-format=raw --no-progress --memory-limit=-1 "$FIXTURE" 2>&1)" \
    && rc=0 || rc=$?

if [ "$rc" -eq 0 ]; then
    report_failure "$(printf 'phpstan.neon does not report strtolower() — its own include of\n  phpstan/disallowed-function-calls.neon has broken.\n%s' "$out")"
elif grep -qF 'Calling strtolower() is forbidden' <<<"$out"; then
    printf 'ok (positive): strtolower() is reported through phpstan.neon\n'
else
    report_failure "$(printf 'phpstan.neon reported something on the positive fixture, but not the\n  expected ban.\n%s' "$out")"
fi

verdict
