#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven case proving that the ROOT .php-cs-fixer.dist.php — this
# package's own self-lint config (GH-83) — actually applies the shared house
# style, rather than merely appearing to via its ->setFinder()/require chain.
#
# Before this gate existed, this package's own first-party PHP under bin/,
# tests/ and php-cs-fixer/ was style-checked only incidentally, by the
# "Consumer smoke" step running against the INSTALLED tests/consumer fixture,
# which never sees those directories at all. A broken require of
# php-cs-fixer/base.php, or a Finder that resolved to an empty set, would
# leave `composer ci:test:php:cgl` silently green.
#
# The fixture lives in a throwaway directory outside bin/, tests/ and
# php-cs-fixer/, not a tracked file under any of them: .php-cs-fixer.dist.php's
# Finder recurses through all three, so a permanent violating fixture anywhere
# under them would make the real `composer ci:test:php:cgl` fail on every run.
# php-cs-fixer accepts an explicit file path outside its configured Finder and
# still applies the loaded config's rules to it (`fix -- <path>` overrides only
# the path set, not the rule set) — verified against this file's own
# .php-cs-fixer.dist.php before this case was written.
#
# Run from the package root: bash tests/check-php-cs-fixer-cases.sh

set -euo pipefail

# CDPATH= — see check-gitattributes-lockstep-cases.sh's identical guard.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/harness.sh"

report_failure() { harness_fail "$1"; }

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

PHP_CS_FIXER="$ROOT/.build/bin/php-cs-fixer"

harness_require_executable "$PHP_CS_FIXER" ''

harness_workdir
FIXTURE="$work/probe.php"

# The header comment below must match .php-cs-fixer.dist.php's own $header
# heredoc verbatim: header_comment is one of the rules under test, so a
# mismatched header would make even this "clean" fixture reportable — and the
# positive case below would then prove nothing beyond that same header drift.

# --- CONTROL: a fixture already conforming to the shared style must report nothing ---
# If this run reports anything, the positive run below proves nothing: a report
# there could equally have come from a header-comment mismatch alone.
cat > "$FIXTURE" <<'PHP'
<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

function harnessCglControl(string $s): string
{
    return trim($s);
}
PHP

control_out="$("$PHP_CS_FIXER" fix --config "$ROOT/.php-cs-fixer.dist.php" \
    --dry-run --diff -- "$FIXTURE" 2>&1)" \
    && control_rc=0 || control_rc=$?

if [ "$control_rc" -ne 0 ]; then
    report_failure "$(printf 'control: a conforming fixture reports against .php-cs-fixer.dist.php.\n%s' "$control_out")"
else
    printf 'ok (control): a conforming fixture is clean against .php-cs-fixer.dist.php\n'
fi

# --- POSITIVE: a brace/spacing violation must be reported ---
cat > "$FIXTURE" <<'PHP'
<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

function harnessCglPositive( string $s ){
return trim($s);
}
PHP

out="$("$PHP_CS_FIXER" fix --config "$ROOT/.php-cs-fixer.dist.php" \
    --dry-run --diff -- "$FIXTURE" 2>&1)" \
    && rc=0 || rc=$?

if [ "$rc" -eq 0 ]; then
    report_failure "$(printf '.php-cs-fixer.dist.php does not report the malformed fixture — its\n  require of php-cs-fixer/base.php or its Finder has broken.\n%s' "$out")"
elif grep -qF 'Found 1 of 1 files that can be fixed' <<<"$out"; then
    printf 'ok (positive): the malformed fixture is reported through .php-cs-fixer.dist.php\n'
else
    report_failure "$(printf '.php-cs-fixer.dist.php reported something on the positive fixture, but\n  not the expected summary.\n%s' "$out")"
fi

verdict
