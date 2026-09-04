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

# --- FINDER SCOPE: the real Finder, not an explicit-path override, must
# include bin/consumer and any nested tests/*/consumer, and exclude only the
# top-level tests/consumer fixture ---
#
# The two cases above pass an explicit `-- "$FIXTURE"` path, which bypasses
# php-cs-fixer's configured Finder/exclude()/notPath() entirely — they prove
# the ruleset fires, not that the Finder in .php-cs-fixer.dist.php scopes its
# tests/consumer exclusion correctly. That scoping is this config's own most
# novel piece of logic (see its comments), so it needs its own case with no
# path override, driven against real, uniquely-named throwaway fixtures under
# the tracked tree — the Finder recurses through fixed disk locations
# relative to the config, so there is no workdir-outside-the-tree option here
# the way the two cases above have.
CGL_SCOPE_SUFFIX="$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')"

BIN_CONSUMER_DIR="$ROOT/bin/consumer"
BIN_CONSUMER_PROBE="$BIN_CONSUMER_DIR/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php"

NESTED_CONSUMER_DIR="$ROOT/tests/Support/consumer"
NESTED_CONSUMER_PROBE="$NESTED_CONSUMER_DIR/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php"

TOP_CONSUMER_PROBE="$ROOT/tests/consumer/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php"

# Combined with harness_workdir's own EXIT trap (`rm -rf -- "$harness_workdir_raw"`,
# armed above) rather than layered onto it: `trap ... EXIT` replaces the
# previous handler outright, so a second `trap` call here would silently drop
# the workdir cleanup instead of adding to it.
#
# Ownership (harness_mkdir_owned/harness_rmdir_if_owned, tests/harness.sh) is
# decided by mkdir's OWN exit status at creation time, not by a separate
# existence check taken earlier and acted on later, and not by rmdir's
# non-empty refusal alone: a check-then-act split is a TOCTOU race against a
# second concurrent invocation of this same script (this project's own
# worktree shares across sessions, a documented real hazard here), and an
# unconditional rmdir with no ownership check at all silently deletes a
# maintainer's genuinely pre-existing but EMPTY directory — rmdir only
# refuses a NON-empty one. Both failure modes were live-reproduced against
# earlier revisions of this function.
BIN_CONSUMER_OWNED="$(harness_mkdir_owned "$BIN_CONSUMER_DIR")"
NESTED_CONSUMER_OWNED="$(harness_mkdir_owned "$NESTED_CONSUMER_DIR")"

cleanup_finder_scope_probes() {
    rm -f -- "$BIN_CONSUMER_PROBE" "$NESTED_CONSUMER_PROBE" "$TOP_CONSUMER_PROBE"
    harness_rmdir_if_owned "$BIN_CONSUMER_DIR" "$BIN_CONSUMER_OWNED"
    harness_rmdir_if_owned "$NESTED_CONSUMER_DIR" "$NESTED_CONSUMER_OWNED"
}

# The PRESERVATION section below creates a second mktemp root
# ($preservation_root) after this trap is armed — folded into the SAME
# combined trap rather than cleaned up with a fire-and-forget `rm -rf` at its
# own tail, because any ordinary statement between its creation and that tail
# (a mkdir, a printf redirect) can fail under set -euo pipefail and skip it.
# `${preservation_root:-}` is safe to reference here even though the
# variable does not exist yet: this string is expanded only when the trap
# fires, by which point the script either reached its assignment or aborted
# before it — either way `:-` supplies the empty-string default `set -u`
# would otherwise reject, and `rm -rf -- ""` is a documented no-op.
trap 'cleanup_finder_scope_probes; rm -rf -- "${preservation_root:-}" "$harness_workdir_raw"' EXIT

for probe in "$BIN_CONSUMER_PROBE" "$NESTED_CONSUMER_PROBE" "$TOP_CONSUMER_PROBE"; do
    cat > "$probe" <<PHP
<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

function harnessCglFinderScope${CGL_SCOPE_SUFFIX}( string \$s ){
return trim(\$s);
}
PHP
done

scope_out="$(cd "$ROOT" && "$PHP_CS_FIXER" fix --config "$ROOT/.php-cs-fixer.dist.php" --dry-run --diff 2>&1)" \
    && scope_rc=0 || scope_rc=$?

scope_ok=1

if ! grep -qF "bin/consumer/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php" <<<"$scope_out"; then
    scope_ok=0
    report_failure "$(printf 'the real Finder does not include bin/consumer/ — a slashless\n  exclude(%s) on the tests/-scoped Finder is leaking across in() roots again.\n%s' "'consumer'" "$scope_out")"
fi

if ! grep -qF "tests/Support/consumer/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php" <<<"$scope_out"; then
    scope_ok=0
    report_failure "$(printf 'the real Finder does not include tests/Support/consumer/ — the\n  tests/-scoped exclusion is matching any nested "consumer" directory, not\n  only the top-level tests/consumer fixture.\n%s' "$scope_out")"
fi

if grep -qF "tests/consumer/probe-cgl-selftest-$CGL_SCOPE_SUFFIX.php" <<<"$scope_out"; then
    scope_ok=0
    report_failure "$(printf 'the real Finder reports a file inside tests/consumer/ — the\n  tests/-scoped exclusion no longer excludes its own target fixture.\n%s' "$scope_out")"
fi

if [ "$scope_rc" -eq 0 ]; then
    scope_ok=0
    report_failure "$(printf 'the real Finder reported nothing at all for the three throwaway\n  probes — Finder resolution has broken.\n%s' "$scope_out")"
fi

if [ "$scope_ok" -eq 1 ]; then
    printf 'ok (finder scope): bin/consumer and tests/Support/consumer are linted, tests/consumer alone is excluded\n'
fi

# --- PRESERVATION: cleanup_finder_scope_probes() must never remove a
# directory it did not itself create ---
#
# The FINDER SCOPE case above only ever creates bin/consumer/ and
# tests/Support/consumer/ itself, so it never exercises the "genuinely
# pre-existing, must not be removed" branch of the ownership check above —
# that guarantee was, until this case, asserted only in a comment. Calls the
# SAME harness_mkdir_owned/harness_rmdir_if_owned (tests/harness.sh) the real
# FINDER SCOPE case above uses, against an isolated scratch root rather than
# the tracked tree, so a defect here costs nothing real and this case cannot
# silently drift from what it is meant to prove — the same "prove the
# mechanism in the abstract" shape check-js-configs.sh's own trap-safety
# self-test uses, for the same reason: the real call site's tracked-tree run
# can't safely be the one to fail this way. $preservation_root is cleaned up
# by the combined trap armed above, not here — see that trap's own comment.
preservation_root="$(mktemp -d)"

# Case A: pre-existing and non-empty (the shape a maintainer's own tracked directory has).
preservation_with_content="$preservation_root/with-content"
mkdir -- "$preservation_with_content"
printf '<?php\n' > "$preservation_with_content/real-maintainer-file.php"
owned="$(harness_mkdir_owned "$preservation_with_content")"
harness_rmdir_if_owned "$preservation_with_content" "$owned"

if [ ! -f "$preservation_with_content/real-maintainer-file.php" ]; then
    report_failure 'preservation: a pre-existing, non-empty directory did not survive cleanup'
else
    printf 'ok (preservation): a pre-existing non-empty directory survives cleanup\n'
fi

# Case B: pre-existing but EMPTY (invisible to git, but real on disk — the
# shape an unconditional rmdir, with no ownership check, would have deleted).
preservation_empty="$preservation_root/empty"
mkdir -- "$preservation_empty"
owned="$(harness_mkdir_owned "$preservation_empty")"
harness_rmdir_if_owned "$preservation_empty" "$owned"

if [ ! -d "$preservation_empty" ]; then
    report_failure 'preservation: a pre-existing, empty directory did not survive cleanup'
else
    printf 'ok (preservation): a pre-existing empty directory survives cleanup\n'
fi

verdict
