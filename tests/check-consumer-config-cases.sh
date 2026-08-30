#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for bin/check-consumer-config.php. Proves the lockstep gate
# ACCEPTS the canon and REJECTS each drift class — including the section-scoping
# edge cases (a `- php` under the wrong YAML list, editorconfig indent set only in a
# narrow section while `[*]` uses tabs) that a naive per-line regex would miss.
#
# Run from the package root: bash tests/check-consumer-config-cases.sh

set -euo pipefail

# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/harness.sh"
harness_workdir

GATE="$ROOT/bin/check-consumer-config.php"
FIXTURE="$ROOT/tests/consumer"

# The node-side front end for the same biome.json/tsconfig.json contract, added
# for #32 — see bin/check-js-config.mjs's own header. Driven only by
# assert_accepts_js/assert_rejects_js/assert_usage_error_js/assert_report_is_inert_js/
# assert_reports_once_js below, alongside their PHP-only counterparts, never
# standalone.
NODE_GATE="$ROOT/bin/check-js-config.mjs"

# `degraded` comes from tests/harness.sh. Why this harness needs it, which the
# shared comment cannot know:
#
# The exit-code tightening closed only half of this hole: a fatal is caught by the
# exit code, but an E_WARNING is not — PHP prints it, carries on, and the gate goes
# on to reach its normal exit. Concretely: drop the `is_array($topLevelRules)`
# guard and `biome-rules-not-an-object` still reports ok, because `foreach ("off"
# as …)` warns, skips the loop, and the case's OTHER violation still produces the
# expected exit 1 and substring. Three guards named in case labels are unprotected
# that way. The repository's own bar is zero notices; a harness that certifies a
# gate has no business accepting a run that did not meet it.
# Thin wrappers over the shared definitions in tests/harness.sh.
assert_accepts() { harness_accepts "$GATE" "$@"; }
assert_rejects() { harness_rejects "$GATE" "$@"; }

# assert_reports_once <dir> <label> <file prefix>
#
# The two assert_* helpers above grep for the PRESENCE of one substring, so they
# cannot express "and nothing further was said about this file" — which is exactly
# the property a read-failure path needs, since the defect it guards against is an
# EXTRA fabricated violation rather than a missing one.
assert_reports_once() {
    local dir="$1" label="$2" prefix="$3" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?

    harness_decide_reports_once "$out" "$rc" "$label" "$prefix"
}

# Membership asked the same way in every direction, so the three set relations
# below read as one property rather than three spellings of it.
contains() { # <needle> <haystack…>
    local needle="$1" candidate
    shift

    for candidate in "$@"; do
        if [ "$candidate" = "$needle" ]; then
            return 0
        fi
    done

    return 1
}

# The derived loops can fail before any gate run, so they report directly.
report_failure() { harness_fail "$1"; }

# count_matches <content> <grep -E pattern>
#
# `|| true`: on zero matches `grep` exits 1 and `pipefail` hands that to this
# assignment, aborting the whole suite under `set -e` before the caller's own
# mismatch guard ever runs — the same trap the pre-existing `language_commas`
# guard further down documents in full. Verified by mutation:
# probe_zero_match_count_survives_pipefail proves the identical pipeline
# WITHOUT `|| true` still aborts on empty input, so this is not a vacuous
# no-op guard.
count_matches() { # <content> <pattern>
    printf '%s' "$1" | grep -oE "$2" | wc -l || true
}

# Self-test for count_matches: an empty block must count as 0 without
# aborting (positive), and the identical pipeline without `|| true` must
# still abort on the same empty input (negative/mutation control) — proving
# the guard is load-bearing. The control runs in a subshell so its abort
# cannot kill this suite.
probe_zero_match_count_survives_pipefail() {
    local count control_out

    count="$(count_matches '' "'[^']*'")"
    if [ "$count" -ne 0 ]; then
        report_failure "bookkeeping self-test — count_matches on an empty block did not read 0"
    fi

    if control_out="$(bash -c 'set -euo pipefail; x="$(printf "" | grep -oE "x" | wc -l)"; printf "reached:%s" "$x"' 2>/dev/null)"; then
        report_failure "bookkeeping self-test — the same pipeline without || true no longer aborts under pipefail on empty input; count_matches's guard may be vacuous"
    elif [ -n "$control_out" ]; then
        report_failure "bookkeeping self-test — the mutation control produced unexpected output: $control_out"
    fi
}
probe_zero_match_count_survives_pipefail

assert_usage_error()     { harness_usage_error     "$GATE" "$@"; }
assert_report_is_inert() { harness_report_is_inert "$GATE" "$@"; }

# assert_js <php-assert-fn> <decide-fn> <dir> <label> [<expected>]
#
# The shared dispatch skeleton behind all five _js wrappers below: run the
# PHP-only assertion first, then the node gate via harness_run_argv, then
# decide with the same harness_decide_* function harness.sh's own
# php-dispatching wrapper uses, labelled "(node)". They call harness_run_argv
# rather than a gate-specific harness.sh helper for the reason stated on
# harness_run_argv itself: a second, near-identical `php`-shaped function
# family for one more interpreter is what this whole file exists to avoid,
# and harness_settle/degraded/harness_decide_* — the actually shared,
# already-probed bookkeeping — are reused unchanged.
#
# "${@:3}" (after the `shift 2`) is what forwards an ABSENT optional argument
# as absent rather than as an empty string — the same presence-preserving
# slice harness_report_is_inert already relies on for
# harness_decide_report_is_inert's own optional 4th argument. That is what
# lets assert_report_is_inert_js's must-carry arm work through this shared
# dispatcher with no special-cased branch of its own.
assert_js() {
    local assert_fn="$1" decide_fn="$2"
    shift 2
    "$assert_fn" "$@"

    local dir="$1" label="$2"
    harness_run_argv "$dir" node "$NODE_GATE"
    "$decide_fn" "$HARNESS_OUT" "$HARNESS_RC" "$label (node)" "${@:3}"
}

assert_accepts_js()         { assert_js assert_accepts         harness_decide_accepts         "$@"; }
assert_rejects_js()         { assert_js assert_rejects         harness_decide_rejects         "$@"; }
assert_usage_error_js()     { assert_js assert_usage_error     harness_decide_usage_error     "$@"; }

# The empty-must-carry arm harness_decide_report_is_inert carries is NOT
# separately re-probed by this file's own probe_report_is_inert_js_shapes —
# search this file for "empty-must-carry arm shared by" for why.
assert_report_is_inert_js() { assert_js assert_report_is_inert harness_decide_report_is_inert "$@"; }
assert_reports_once_js()    { assert_js assert_reports_once    harness_decide_reports_once    "$@"; }

# `php` and `node` are both shadowed, the way harness.sh shadows `php` for its own
# assert_* probes: each _js wrapper calls the PHP-only assertion FIRST, so without
# a clean, FIXED php stub that call would run for real against /nonexistent, and
# its own increment (or lack of one) would be indistinguishable from the node arm
# under test. Every `expected` passed below is chosen so the fixed php stub
# text contains it — keeping the php-side call passing throughout — while the
# node stub is varied per call to drive exactly one node-side arm at a time. The
# empty-must-carry arm shared by assert_rejects_js/harness_rejects (and by
# assert_report_is_inert_js/harness_report_is_inert) is not re-probed here: it is
# the same one-line check already proven in harness.sh's own probes, reused
# verbatim rather than rewritten, so re-proving it here would pin the copy, not
# new logic.
probe_accepts_js_shapes() {
    php() { printf '%s\n' 'check-consumer-config: OK'; return 0; }
    node() { printf '%s\n' "$node_fake_report"; return "$node_fake_rc"; }

    node_fake_report='Warning: the gate emitted a diagnostic'
    node_fake_rc=0
    assert_accepts_js /nonexistent 'probe: accepts_js, the node gate ran degraded'

    node_fake_report='  - x: a drift verdict'
    node_fake_rc=1
    assert_accepts_js /nonexistent 'probe: accepts_js, the node gate did not accept'
}

harness_probe_reporters 2 probe_accepts_js_shapes \
    'assert_accepts_js has a node arm that no longer decides'

probe_usage_error_js_shapes() {
    php() { printf '%s\n' '  - x: refused to run'; return 2; }
    node() { printf '%s\n' "$node_fake_report"; return "$node_fake_rc"; }

    node_fake_report='Warning: the gate emitted a diagnostic'
    node_fake_rc=2
    assert_usage_error_js /nonexistent 'probe: usage_error_js, the node gate ran degraded' 'refused'

    node_fake_report='  - x: refused to run'
    node_fake_rc=1
    assert_usage_error_js /nonexistent 'probe: usage_error_js, not the usage exit' 'refused'

    # The php stub still carries 'refused' (so the php-side call passes), the
    # node stub does not (so only the node-side wrong-reason arm decides).
    node_fake_report='  - x: something else happened'
    node_fake_rc=2
    assert_usage_error_js /nonexistent 'probe: usage_error_js, the wrong reason' 'refused'
}

harness_probe_reporters 3 probe_usage_error_js_shapes \
    'assert_usage_error_js has a node arm that no longer decides'

probe_rejects_js_shapes() {
    php() { printf '%s\n' '  - x: a drift verdict'; return 1; }
    node() { printf '%s\n' "$node_fake_report"; return "$node_fake_rc"; }

    node_fake_report='Warning: the gate emitted a diagnostic'
    node_fake_rc=1
    assert_rejects_js /nonexistent 'probe: rejects_js, the node gate ran degraded' 'x'

    node_fake_report='  - x: refused to run'
    node_fake_rc=2
    assert_rejects_js /nonexistent 'probe: rejects_js, not the drift verdict' 'x'

    # The php stub still carries 'x' (so the php-side call passes), the node stub
    # does not (so only the node-side wrong-reason arm decides).
    node_fake_report='clean report with no match'
    node_fake_rc=1
    assert_rejects_js /nonexistent 'probe: rejects_js, the wrong reason' 'x'
}

harness_probe_reporters 3 probe_rejects_js_shapes \
    'assert_rejects_js has a node arm that no longer decides'

probe_report_is_inert_js_shapes() {
    php() { printf '%s\n' '  - x: a drift verdict'; return 1; }
    node() { printf '%s\n' "$node_fake_report"; return "$node_fake_rc"; }

    # No third argument on these two: harness_report_is_inert's must-carry check
    # is optional (only active with a 4th argument, the wrapper's 3rd), so the
    # php-side call stays clean without one.
    node_fake_report='Warning: the gate emitted a diagnostic'
    node_fake_rc=1
    assert_report_is_inert_js /nonexistent 'probe: report_is_inert_js, the node gate ran degraded'

    node_fake_report='  - x: refused to run'
    node_fake_rc=2
    assert_report_is_inert_js /nonexistent 'probe: report_is_inert_js, not the drift verdict'

    node_fake_report='clean report with no match'
    node_fake_rc=1
    assert_report_is_inert_js /nonexistent 'probe: report_is_inert_js, the wrong reason' 'x'
}

harness_probe_reporters 3 probe_report_is_inert_js_shapes \
    'assert_report_is_inert_js has a node arm that no longer decides'

# assert_reports_once/harness_decide_reports_once are not covered by harness.sh's
# own probe suite (harness_probe_assert_shapes/harness_probe_inert_shapes only drive
# harness_decide_accepts/rejects/usage_error/report_is_inert) — and they became
# load-bearing when the five oversize cases were written against them, six once
# assert_reports_once_js joined for the package.json case. Measured before this:
# replacing assert_reports_once's whole body with `pass "$2"` left the entire suite
# green. Both arms — PHP directly below, node via assert_reports_once_js further
# down — get their own probe here rather than in harness.sh, for the same reason
# harness.sh has none: this file is where the load-bearing use lives.
#
# `php` is shadowed the way harness.sh shadows it, so each arm is reached without a
# gate that produces it. Three calls, three increments; delete an arm and one is lost.
probe_reports_once() {
    php() { printf '%s\n' "$reports_once_fake"; return "$reports_once_rc"; }

    # The degraded fixture satisfies every arm BELOW it — exit 1, exactly one
    # `- phpunit.xml:` line — so only the degraded arm can decide. Without that it
    # violates the count arm too, and deleting the degraded arm falls through to a
    # different failure that increments all the same: the count stays 3 and the probe
    # passes on an arm that no longer decides. Measured, and the same shape the two
    # degraded arms in harness.sh were wrong about.
    reports_once_fake="$(printf 'PHP Warning:  the gate emitted a diagnostic\n  - phpunit.xml: one')"
    reports_once_rc=1
    assert_reports_once /nonexistent 'probe: reports_once, the gate ran degraded' 'phpunit.xml'

    reports_once_fake='  - phpunit.xml: one'
    reports_once_rc=2
    assert_reports_once /nonexistent 'probe: reports_once, not the drift verdict' 'phpunit.xml'

    reports_once_fake="$(printf '  - phpunit.xml: one\n  - phpunit.xml: two')"
    reports_once_rc=1
    assert_reports_once /nonexistent 'probe: reports_once, reported twice' 'phpunit.xml'
}

harness_probe_reporters 3 probe_reports_once \
    'assert_reports_once has an arm that no longer decides'

# The node twin, same shapes as probe_reports_once above — php stays a fixed,
# passing single violation so only the node-side arm under test can decide, the
# same convention probe_rejects_js_shapes and its siblings already use.
probe_reports_once_js_shapes() {
    php() { printf '%s\n' '  - package.json: one'; return 1; }
    node() { printf '%s\n' "$node_fake_report"; return "$node_fake_rc"; }

    # Satisfies every arm below it too — exit 1, exactly one `- package.json:`
    # line — so only the degraded arm can decide, same shape probe_reports_once
    # is measured against.
    node_fake_report="$(printf 'Warning: the gate emitted a diagnostic\n  - package.json: one')"
    node_fake_rc=1
    assert_reports_once_js /nonexistent 'probe: reports_once_js, the node gate ran degraded' 'package.json'

    node_fake_report='  - package.json: one'
    node_fake_rc=2
    assert_reports_once_js /nonexistent 'probe: reports_once_js, not the drift verdict' 'package.json'

    node_fake_report="$(printf '  - package.json: one\n  - package.json: two')"
    node_fake_rc=1
    assert_reports_once_js /nonexistent 'probe: reports_once_js, reported twice' 'package.json'
}

harness_probe_reporters 3 probe_reports_once_js_shapes \
    'assert_reports_once_js has a node arm that no longer decides'

# Every _js wrapper above except the reports_once pair (probed just above, in this
# file, for the reason stated there) is a one-line dispatcher whose increment lives
# in harness.sh and is probed there. What this file must still prove is that it
# grew no report site of its own — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

# The canonical fixture must be accepted.
assert_accepts "$FIXTURE" "canon fixture"

# --- phpunit.xml drift classes ---
mk_case() {
    local name="$1"
    local dir="$work/$name"
    mkdir -p "$dir"
    cp "$FIXTURE/phpunit.xml" "$dir/phpunit.xml"
    printf '%s' "$dir"
}

# The strict attributes the gate requires on phpunit.xml's root element. Held here
# INDEPENDENTLY of the gate's own list, and the cases below are generated from this
# copy — not from the gate's.
#
# The distinction is the whole point, and getting it wrong was measured twice on
# this branch. Generating the cases from `$requiredRootFlags` looks like the
# derive-from-the-source fix the jscpd and extensionMappings tables got, but it is
# the opposite: delete a flag from the gate and no case is generated for it, so the
# suite stays green on a gate that stopped checking. Verified — dropping seven of
# the nine left the run at exit 0 with 164 cases still passing. Two lists that must
# agree is the shape that discriminates; one list read twice is not.
#
# Two of the nine had a hand-written case before this and the other seven had none,
# which is how the gate's oldest and largest table came to be its least proven one.
required_root_flags=(
    requireCoverageMetadata
    beStrictAboutCoverageMetadata
    beStrictAboutOutputDuringTests
    failOnRisky
    failOnWarning
    failOnNotice
    failOnDeprecation
    failOnPhpunitDeprecation
    failOnPhpunitNotice
)

mapfile -t gate_root_flags < <(
    sed -n '/\$requiredRootFlags = \[/,/\];/p' "$ROOT/bin/check-consumer-config.php" \
        | grep -oE "'[A-Za-z]+'" \
        | tr -d "'"
)

if [ "${#gate_root_flags[@]}" -eq 0 ]; then
    report_failure 'read no $requiredRootFlags entries from bin/check-consumer-config.php'
fi

# The structural bar, independent of the extractor's own vocabulary. `'[A-Za-z]+'`
# cannot see a flag carrying a digit or an underscore, and BOTH direction checks
# below iterate over what it extracted — so such a flag generates no case and
# nothing reddens. Counting the quoted entries in the same sed range answers a
# question the name pattern cannot. Occurrences, not lines: two entries on one
# physical line read as one under `grep -c`, which is the silent direction.
# count_matches (defined above) survives a zero-match block; see its own
# docblock for why.
gate_root_flags_block="$(sed -n '/\$requiredRootFlags = \[/,/\];/p' "$ROOT/bin/check-consumer-config.php")"
gate_root_flags_declared="$(count_matches "$gate_root_flags_block" "'[^']*'")"

if [ "$gate_root_flags_declared" -ne "${#gate_root_flags[@]}" ]; then
    report_failure "the \$requiredRootFlags block declares $gate_root_flags_declared entries but this harness parsed ${#gate_root_flags[@]} — widen the extractor rather than leaving one unexercised"
fi

# Both directions, so neither list can quietly outlive the other.
for flag in "${required_root_flags[@]}"; do
    if ! contains "$flag" "${gate_root_flags[@]}"; then
        report_failure "the gate no longer requires the strict phpunit.xml attribute $flag"
    fi
done

for flag in "${gate_root_flags[@]}"; do
    if ! contains "$flag" "${required_root_flags[@]}"; then
        report_failure "the gate requires the phpunit.xml attribute $flag, which these cases do not drive"
    fi
done


# Both failure shapes per flag, because the gate treats them as one requirement
# ("present AND true") and a check for only one of them would not notice the
# other arm being dropped.
for flag in "${required_root_flags[@]}"; do
    d="$(mk_case "phpunit-$flag-false")"
    sed -i "s/$flag=\"true\"/$flag=\"false\"/" "$d/phpunit.xml"
    assert_rejects "$d" "phpunit.xml with $flag set to false" "$flag"

    d="$(mk_case "phpunit-$flag-gone")"
    sed -i "/$flag=\"true\"/d" "$d/phpunit.xml"
    assert_rejects "$d" "phpunit.xml with $flag removed" "$flag"
done

# The other direction: the canon fixture must actually carry every required flag,
# or the `sed` above is a no-op and each case passes on an unmodified copy that
# was already failing for some other reason.
for flag in "${required_root_flags[@]}"; do
    if ! grep -qF "$flag=\"true\"" "$FIXTURE/phpunit.xml"; then
        report_failure "the canon phpunit.xml does not set $flag=\"true\", so its cases modify nothing"
    fi
done

d="$(mk_case source-loose)"
sed -i 's/restrictNotices="true"/restrictNotices="false"/' "$d/phpunit.xml"
assert_rejects "$d" "<source> restrictNotices disabled" "restrictNotices"

# --- .phplint.yml: `- php` present but OUTSIDE the extensions block ---
d="$work/phplint-wrong-block"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.phplint.yml" <<'YML'
path:
    - php
extensions:
    - phtml
YML
assert_rejects "$d" ".phplint.yml with php under path, not extensions" "\`extensions:\` block"

# A .editorconfig whose first `=` sits behind a long whitespace run. The pattern
# this replaced was Θ(W²) — measured end-to-end at 34.56 s for a 256 KiB run, and
# 380 s for 1 MiB, on a file with no size cap. Without a TIME assertion the case
# cannot fail on the defect: the verdict is identical either way, only the wait
# changes. Five seconds is two orders of magnitude above the fixed form (~0.1 s)
# and two below the shape it guards against.
d="$(mk_case editorconfig-whitespace-run)"
php -r '
    file_put_contents(
        $argv[1],
        "root = true\n[*]\nindent_style = space\nindent_size = 4\n[{Makefile,*.mk}]\nindent_style = tab\na"
        . str_repeat(" ", 262144) . "x=y\n"
    );
' "$d/.editorconfig"

editorconfig_started="$(date +%s)"
assert_accepts "$d" ".editorconfig carrying a 256 KiB whitespace run before its first \`=\`"
editorconfig_elapsed="$(( $(date +%s) - editorconfig_started ))"

if [ "$editorconfig_elapsed" -gt 5 ]; then
    report_failure "the .editorconfig parse took ${editorconfig_elapsed}s on a 256 KiB whitespace run — the quadratic shape is back"
fi

# --- deptrac.yaml: present but dropping the shared import (silent arch-drop) ---
d="$work/deptrac-no-import"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
deptrac:
    paths:
        - src
YML
assert_rejects "$d" "deptrac.yaml dropping the shared import" "must import the shared"

# --- deptrac.yaml: valid, imports the shared ruleset (build-dir path prefix) ---
d="$work/deptrac-ok"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml
deptrac:
    paths:
        - src
YML
assert_accepts "$d" "deptrac.yaml importing the shared ruleset"

# Every line shape the block scan must admit, in one fixture each. All are legal
# YAML that Deptrac's and phplint's own parser accepts, and the scan used to stop
# at the first of them — so a consumer who DID carry the required entry was told
# it must. A false reject, the direction this gate's header rules out.
#
# The last shape is the one that regressed while being fixed: the first version of
# the widening required a newline in every alternative, which silently dropped the
# final line of a file that has none. Both fixtures therefore put the SOUGHT entry
# on that last line — with the block first and the sought entry mid-file, dropping
# the final line costs nothing and the case cannot see the regression. Measured:
# it stayed green until the fixtures were reordered.
d="$work/deptrac-ok-shapes"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'deptrac:\n    paths:\n        - src\nimports:\n# why the shared ruleset comes last\n    - some/other.yaml\n\n- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml' > "$d/deptrac.yaml"
assert_accepts "$d" "deptrac.yaml carrying the shared import after a comment, a blank line, at column 0 and with no final newline"

# The quote atoms used to be independently optional, so an opening `'` and a
# closing `"` satisfied the pattern — a scalar YAML itself cannot parse, certified
# as a correct import. The backreference makes the pair a pair; an unquoted entry
# still matches, because an empty capture back-references the empty string.
d="$work/deptrac-mismatched-quotes"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'deptrac:\n    paths:\n        - src\nimports:\n    - %s.build/vendor/magicsunday/coding-standard/deptrac/layers.yaml%s\n' "'" '"' > "$d/deptrac.yaml"
assert_rejects "$d" "deptrac.yaml whose shared import opens on one quote and closes on the other" \
    "must import the shared"

d="$(mk_case phplint-ok-shapes)"
printf 'paths:\n    - ./src\nextensions:\n# only PHP\n\n    - php' > "$d/.phplint.yml"
assert_accepts "$d" ".phplint.yml listing php after a comment and a blank line, with no final newline"

# The BOUNDARY, which nothing pinned. Measured: replacing both block bodies with
# `((?:[^\n]*\n)*)` — read to end of file — left every case green, because the
# existing wrong-key fixtures have no block to run past. These two put the sought
# entry under a LATER top-level key, so a scan that does not stop must accept them
# and a correct one must not.
d="$work/deptrac-shared-under-later-key"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'imports:\n    - some/other.yaml\ndeptrac:\n    paths:\n        - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n' > "$d/deptrac.yaml"
assert_rejects "$d" "deptrac.yaml whose shared path sits under a later top-level key, not in imports" "must import the shared"

d="$(mk_case phplint-php-under-later-key)"
printf 'extensions:\n    - phtml\npaths:\n    - php\n' > "$d/.phplint.yml"
assert_rejects "$d" ".phplint.yml whose \`php\` sits under a later top-level key, not in extensions" "must list"

# The two shapes the column-0 alternative used to swallow, both FALSE ACCEPTS. A
# dash without whitespace after it is not a block sequence entry: `-foreign:` is a
# top-level key, and `---` starts a new document. Before the alternative required
# that whitespace, the scan ran past both and found the sought entry beyond them.
d="$work/deptrac-dash-key"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'imports:\n-foreign:\n    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n' > "$d/deptrac.yaml"
assert_rejects "$d" "deptrac.yaml whose shared import sits under a dash-prefixed key, not in imports" "must import the shared"

d="$work/deptrac-next-document"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'imports:\n---\n- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n' > "$d/deptrac.yaml"
assert_rejects "$d" "deptrac.yaml whose shared import sits in the next YAML document" "must import the shared"


# --- deptrac.yaml: shared path present but under the WRONG key (not imports) ---
d="$work/deptrac-wrong-key"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
deptrac:
    paths:
        - src
    exclude_files:
        - vendor/magicsunday/coding-standard/deptrac/layers.yaml
YML
assert_rejects "$d" "deptrac.yaml with the shared path under the wrong key" "must import the shared"

# --- deptrac.yaml: near-miss vendor namespace must NOT satisfy the gate ---
d="$work/deptrac-near-miss"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - vendor/notmagicsunday/coding-standard/deptrac/layers.yaml
deptrac:
    paths:
        - src
YML
assert_rejects "$d" "deptrac.yaml importing a near-miss (notmagicsunday) path" "must import the shared"

# --- deptrac.yaml: quoted scalar + trailing comment is accepted ---
d="$work/deptrac-quoted"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/deptrac.yaml" <<'YML'
imports:
    - 'vendor/magicsunday/coding-standard/deptrac/layers.yaml' # shared ruleset
deptrac:
    paths:
        - src
YML
assert_accepts "$d" "deptrac.yaml with a quoted import + inline comment"

# --- .editorconfig: [*] indent_style flipped to tab (only that dimension drifts) ---
# The fixture is canon in every other respect (indent_size, root, Makefile) so the
# ONLY violation is the [*] indent_style, and the substring discriminates exactly it.
d="$work/editorconfig-star-tab"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = tab
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with indent_style = tab in [*]" "must set \`indent_style = space\`"

# Editors honour a BOM'd .editorconfig — editorconfig-core-js reads one and
# returns its settings, because JavaScript's `\s` matches U+FEFF. PHP's trim()
# does not, so without the strip the key parses as "\u{FEFF}root" and a file
# every editor obeys is reported as drift.
# Written literally rather than derived from the template, so the BOM ABUTS the
# key the strip protects. With the template's header in place the BOM lands on a
# comment line that both the section and the key regex discard, `root = true` is
# untouched, and the case passes with or without the strip — pinning nothing.
# Filtering the header out of the template restores that vacuity the moment the
# template grows a blank line before its first key, which is an edit nobody would
# connect to this case. A consumer may legitimately write the file without a
# header, and that shape is the one that exercises the strip.
d="$(mk_case editorconfig-bom)"
printf '\xEF\xBB\xBFroot = true\n\n[*]\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n' > "$d/.editorconfig"
assert_accepts "$d" ".editorconfig saved with a UTF-8 BOM directly before its first key"

# The BOM decision is per tool, because the three disagree — each measured
# against the real tool rather than assumed, and pinned here so the asymmetry
# cannot drift back into a uniform guess in either direction.
#
# phplint 9.7.2 reads a BOM'd config and runs normally, so the gate strips: the
# `^extensions` anchor sits at offset 0 and the BOM would displace it, reporting
# drift in a file the tool obeys.
# Written with `extensions:` first rather than copying the template, for the same
# reason as the .editorconfig case above: the template opens with a comment header,
# so its `extensions:` is not on the first line, `/m` matches the anchor on its own
# line and the BOM displaces nothing. YAML key order is free, so a consumer file
# that opens on the key is legitimate — and it is the only shape that exercises the
# strip.
d="$(mk_case phplint-bom)"
printf '\xEF\xBB\xBFextensions:\n    - php\n\npath:\n    - ./src\n' > "$d/.phplint.yml"
assert_accepts "$d" ".phplint.yml saved with a UTF-8 BOM directly before its first key"

# deptrac answers its own BOM'd config with `no extension able to load
# "<BOM>imports"` and dies, so there a BOM IS the defect and stripping it would
# hide one — the gate names that cause rather than reporting a missing import.
# Note the anchors alone would not have caught it: the shipped template opens
# with a comment, so the BOM displaces nothing and `^imports` still matches.
d="$(mk_case deptrac-bom)"
printf '\xEF\xBB\xBF' > "$d/deptrac.yaml"
cat "$ROOT/templates/deptrac.dist.yaml" >> "$d/deptrac.yaml"
assert_rejects "$d" "deptrac.yaml saved with a UTF-8 BOM, which deptrac itself refuses to load" "deptrac.yaml: starts with a UTF-8 BOM"

# And exactly one report: a consumer file that opens ON the `imports:` key has
# that anchor displaced by the BOM too, so leaving the BOM in place for the checks
# below would add a second, false "does not import the shared ruleset" for a file
# that does import it.
d="$(mk_case deptrac-bom-anchored)"
printf '\xEF\xBB\xBFimports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n\ndeptrac:\n    paths:\n        - ./src\n' > "$d/deptrac.yaml"
# Both assertions, because either alone passes for the wrong reason: the count
# alone is satisfied by ONE report of the fabricated kind (lose the BOM report
# while the strip is also gone, and the displaced anchor produces exactly one
# "does not import the shared ruleset" line), and the substring alone cannot see
# a second report beside it.
assert_rejects "$d" "a BOM'd deptrac.yaml that opens on imports: is reported as a BOM" "deptrac.yaml: starts with a UTF-8 BOM"
assert_reports_once "$d" "a BOM'd deptrac.yaml that opens on imports: fabricates no missing-import report" "deptrac.yaml"

# jscpd refuses a BOM'd config outright (`expected value at line 1 column 1`), so
# the gate names that cause instead of reporting a syntax error in a file whose
# syntax is perfect — the conflation this branch resolved at three other reads.
d="$(mk_case jscpd-bom)"
printf '\xEF\xBB\xBF' > "$d/.jscpd.json"
cat "$ROOT/templates/jscpd.json" >> "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json saved with a UTF-8 BOM is reported as such, not as malformed" ".jscpd.json: starts with a UTF-8 BOM"

# The Makefile arm no other .editorconfig fixture reaches: every one that writes
# `[{Makefile,*.mk}]` at all sets `indent_style = tab`, so only the
# section-MISSING half was driven. Reducing the condition to
# `$makefile === null` left the whole suite green — and the surviving half is the
# unrealistic one, since a repository moving its Makefile to spaces edits the
# value rather than deleting the header.
d="$(mk_case editorconfig-makefile-spaces)"
sed 's/^indent_style = tab$/indent_style = space/' "$ROOT/templates/editorconfig" > "$d/.editorconfig"
assert_rejects "$d" ".editorconfig whose Makefile section sets spaces instead of tab" "\`[{Makefile,*.mk}]\` section with \`indent_style = tab\`"

# --- .jscpd.json: stale v4 reporter name ---
d="$work/jscpd-v4"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["consoleFull"]
}
JSON
assert_rejects "$d" ".jscpd.json on the removed v4 reporter name" "console-full"

# --- .jscpd.json: minLines raised to an effectively disabling value ---
d="$work/jscpd-minlines"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 9999,
    "exitCode": 1,
    "reporters": ["console-full"]
}
JSON
assert_rejects "$d" ".jscpd.json with minLines raised to disable detection" "minLines"

# --- POSITIVE: the full canonical template set as a consumer would carry it ---
# The phpunit copy comes from templates/, not from the fixture. It is the file a
# consumer actually copies and it carries this gate's largest table — and it was the
# one shipped template no gate run ever saw. The nine-flag bijection above ties the
# harness list to the GATE's list; nothing tied either to the template, so all three
# agreeing was a coincidence renewed by hand.
d="$work/canon-full"
mkdir -p "$d"
cp "$ROOT/templates/phpunit.xml.dist" "$d/phpunit.xml.dist"
cp "$ROOT/templates/editorconfig" "$d/.editorconfig"
cp "$ROOT/templates/jscpd.json" "$d/.jscpd.json"
cp "$ROOT/templates/phplint.yml" "$d/.phplint.yml"
assert_accepts "$d" "full canonical template set, phpunit included, as templates/ ships it"

# The line splitter, which the comment above it justifies with three specific bytes and
# which nothing drove. `\R` matches VT, FF and U+0085 as line breaks; U+0085 is the
# CONTINUATION byte of a two-byte UTF-8 character, so splitting on it cuts a character
# in half and re-parses the tail as a config line.
#
# The FIRST attempt at this case did not discriminate, and the reason is worth keeping:
# a poisoned comment alone changes nothing, because neither fragment carries an `=` and
# no key is written either way. The tail has to parse as a key that CHANGES a verdict,
# and it has to sit AFTER the real settings — the map is last-write-wins, so a poisoned
# line before them is simply overwritten. Both bytes therefore need their own tail.
d="$work/editorconfig-continuation-byte"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
php -r '
    file_put_contents($argv[1], "root = true\n[*]\nindent_style = space\nindent_size = 4\n# note \xc4\x85 indent_style = tab\n[{Makefile,*.mk}]\nindent_style = tab\n");
' "$d/.editorconfig"
assert_accepts "$d" ".editorconfig whose comment carries a U+0085 continuation byte before a settings-shaped tail"

d="$work/editorconfig-form-feed"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
php -r '
    file_put_contents($argv[1], "root = true\n[*]\nindent_style = space\nindent_size = 4\n# a form feed \x0c indent_size = 2\n[{Makefile,*.mk}]\nindent_style = tab\n");
' "$d/.editorconfig"
assert_accepts "$d" ".editorconfig whose comment carries a form feed before a settings-shaped tail"

# Case folding, and the explicit trim charlist beside it. Every other fixture writes
# lowercase keys separated by plain spaces, so replacing mb_strtolower() with the
# identity — and the charlist with trim()'s default — left the suite green, while
# composer.json carries ext-mbstring for that call. The form feed is the one byte the
# two charlists disagree on.
d="$work/editorconfig-uppercase-keys"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
php -r '
    file_put_contents($argv[1], "ROOT = TRUE\n[*]\n\x0cIndent_Style\x0c = Space\nINDENT_SIZE = 4\n[{Makefile,*.mk}]\nIndent_Style = Tab\n");
' "$d/.editorconfig"
assert_accepts "$d" ".editorconfig written with uppercase keys and values, and form feeds around a key"

# --- .editorconfig: root = true moved below a section header (invalid position) ---
d="$work/editorconfig-root-in-section"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
[*]
root = true
indent_style = space
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with root inside a section" "root = true"

# --- .editorconfig: the Makefile tab override dropped ---
d="$work/editorconfig-no-makefile"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 4
EC
assert_rejects "$d" ".editorconfig without the Makefile tab override" "{Makefile,*.mk}"

# --- phpunit.xml: <source> restrictWarnings loosened (twin of the tested restrictNotices) ---
d="$(mk_case source-warnings)"
sed -i 's/restrictWarnings="true"/restrictWarnings="false"/' "$d/phpunit.xml"
assert_rejects "$d" "<source> restrictWarnings disabled" "restrictWarnings"

# --- .jscpd.json: each zero-tolerance threshold, one per fixture ---
jscpd_fixture() { # <dir> <mutation-jq-free sed on the good json>
    local dir="$1"
    mkdir -p "$dir"
    cp "$FIXTURE/phpunit.xml" "$dir/phpunit.xml"
    cat > "$dir/.jscpd.json" <<'JSON'
{
    "threshold": 0,
    "minTokens": 100,
    "minLines": 5,
    "exitCode": 1,
    "reporters": ["console-full"]
}
JSON
}

d="$work/jscpd-threshold"; jscpd_fixture "$d"
sed -i 's/"threshold": 0/"threshold": 5/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json threshold raised above zero" "threshold"

d="$work/jscpd-exitcode"; jscpd_fixture "$d"
sed -i 's/"exitCode": 1/"exitCode": 0/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json exitCode not 1" "exitCode"

d="$work/jscpd-mintokens"; jscpd_fixture "$d"
sed -i 's/"minTokens": 100/"minTokens": 9999/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json minTokens raised to disable detection" "minTokens"

# The counterpart, so the check cannot be satisfied by rejecting every format:
# the canonical names must pass, as must a config that declares none at all
# (jscpd then applies its own defaults, which still detects).
d="$work/jscpd-format-canonical"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": ["php", "javascript", "typescript", "jsx", "tsx"]/' "$d/.jscpd.json"
assert_accepts "$d" ".jscpd.json using jscpd's own format names"

d="$work/jscpd-format-absent"; jscpd_fixture "$d"
assert_accepts "$d" ".jscpd.json declaring no format at all"

# --- phpunit.xml layout checks: source include, testsuite dir, Architecture exclude ---
d="$(mk_case no-src-include)"
# The only <directory>src</directory> is the <source><include> one (the suite uses
# tests); point it elsewhere so `src` is no longer covered.
sed -i 's#<directory>src</directory>#<directory>lib</directory>#' "$d/phpunit.xml"
assert_rejects "$d" "<source><include> no longer covering src" "must cover the \`src\` directory"

d="$(mk_case no-tests-suite)"
sed -i 's#<directory>tests</directory>#<directory>test</directory>#' "$d/phpunit.xml"
assert_rejects "$d" "test suite not running tests/" "must run the \`tests\` directory"

# The tests/Architecture exclude branch only fires when that dir exists. The canon
# fixture has no <exclude> line, so with the dir present it must be REJECTED...
d="$work/arch-not-excluded"
mkdir -p "$d/tests/Architecture"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
assert_rejects "$d" "tests/Architecture present but not excluded" "must be excluded"

# ...and the paired positive control proves the gate keys on the EXCLUDE line, not
# merely on the directory's existence: same dir present, but the suite now excludes
# it → accepted. Without this pair a gate that rejected on the dir alone would pass.
d="$work/arch-excluded"
mkdir -p "$d/tests/Architecture"
sed 's#<directory>tests</directory>#<directory>tests</directory>\n            <exclude>tests/Architecture</exclude>#' "$FIXTURE/phpunit.xml" > "$d/phpunit.xml"
assert_accepts "$d" "tests/Architecture present and excluded"

# --- phpunit.xml: entirely missing, and not-well-formed ---
d="$work/no-phpunit"
mkdir -p "$d"
assert_rejects "$d" "phpunit.xml missing" "missing"

d="$work/phpunit-malformed"
mkdir -p "$d"
printf '<phpunit><broken' > "$d/phpunit.xml"
assert_rejects "$d" "phpunit.xml not well-formed" "not well-formed"

# --- phpunit.xml.dist fallback: the gate must find the canon under the .dist name ---
d="$work/phpunit-dist"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml.dist"
assert_accepts "$d" "strict config discovered as phpunit.xml.dist"

# --- .phplint.yml with CRLF line endings must still be accepted (the block regex
#     normalises \r first, so `- php\r` under extensions is not false-failed) ---
d="$work/phplint-crlf"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf 'path:\r\n    - src\r\n    - tests\r\nextensions:\r\n    - php\r\n' > "$d/.phplint.yml"
assert_accepts "$d" ".phplint.yml with CRLF line endings"

# --- .editorconfig: [*] indent_size flipped to 2 (the sibling of the indent_style case) ---
d="$work/editorconfig-star-size2"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 2

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with indent_size = 2 in [*]" "must set \`indent_size = 4\`"

# --- phpunit.xml: <source> element absent entirely ---
d="$(mk_case no-source)"
sed -i '/<source/,/<\/source>/d' "$d/phpunit.xml"
assert_rejects "$d" "phpunit.xml without a <source> element" "missing a <source>"

# --- .jscpd.json: not valid JSON ---
d="$work/jscpd-broken"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
printf '{ not json' > "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json not valid JSON" "not valid JSON"

# --- .editorconfig: no global [*] section at all ---
d="$work/editorconfig-no-star"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*.md]
indent_style = space
indent_size = 4

[{Makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig without a global [*] section" "must define a global \`[*]\` section"

# --- .editorconfig: lowercase Makefile glob (case-sensitive, does not match Makefile) ---
d="$work/editorconfig-lowercase-makefile"
mkdir -p "$d"
cp "$FIXTURE/phpunit.xml" "$d/phpunit.xml"
cat > "$d/.editorconfig" <<'EC'
root = true

[*]
indent_style = space
indent_size = 4

[{makefile,*.mk}]
indent_style = tab
EC
assert_rejects "$d" ".editorconfig with a lowercase {makefile,*.mk} glob" "{Makefile,*.mk}"

# --- biome.json / tsconfig.json: the JS/TS extends contract ------------------
#
# mk_js_case gives a case the required phpunit.xml plus the canonical JS configs,
# so each case below can corrupt exactly one of them and be rejected for that
# reason alone.
mk_js_case() {
    local dir
    dir="$(mk_case "$1")"
    cp "$FIXTURE/biome.json" "$dir/biome.json"
    cp "$FIXTURE/tsconfig.json" "$dir/tsconfig.json"
    # The extends contract is asserted only for a repository that actually
    # consumes the npm package, so every case below has to declare it — the
    # non-adopter cases further down leave this out on purpose.
    cat > "$dir/package.json" <<'JSON'
{
    "name": "fixture",
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"
    }
}
JSON
    printf '%s' "$dir"
}

# The canon pair must be accepted — including the JSONC comment tsconfig.json
# legitimately carries, which a plain json_decode would have rejected.
d="$(mk_js_case js-canon)"
assert_accepts_js "$d" "canonical biome.json + tsconfig.json (with a JSONC comment)"

# The bug this package shipped: a "//" note key is valid JSON but makes Biome
# refuse the entire config.
d="$(mk_js_case biome-note-key)"
cat > "$d/biome.json" <<'JSON'
{
    "//": "shared config for this repo",
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_rejects_js "$d" "biome.json with a \"//\" note key" '`"//"` key'

# The same key nested one level down is just as fatal — Biome rejects unknown
# keys at any depth, so a top-level-only check would pass this vacuously.
d="$(mk_js_case biome-note-key-nested)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": {
        "//": "our overrides",
        "enabled": true
    }
}
JSON
assert_rejects_js "$d" "biome.json with a nested \"//\" key" '`"//"` key'

# The same key inside a LOCAL `extends` target, not the document itself — found
# during this change's own audit round by a fresh runtime trace, no fixture
# exercised this combination before. Before GH-36 no local target was ever
# read, so this could not exist; now it can, and it must be caught the same
# way: verified against Biome 2.5.5, the note key makes `./biome.loose.json`
# unloadable regardless of what the document that extends it looks like.
d="$(mk_js_case biome-note-key-in-local-extends)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./biome.loose.json"],
    "files": { "includes": ["src/**"] }
}
JSON
cat > "$d/biome.loose.json" <<'JSON'
{ "//": "note", "linter": { "enabled": true } }
JSON
assert_rejects_js "$d" "biome.json whose local extends target carries a \"//\" key" 'a local `extends` target contains a `"//"` key'

# A local `extends` target past MAX_JSONC_BYTES — found by Codex during PR
# review. Unlike an unreadable or unparseable local target (left to Biome's
# own error, by design), an oversized one is a file Biome loads and applies
# without complaint: the byte cap is this gate's OWN defensive bound against
# the quadratic comment-strip regex, not a real limit either tool enforces.
# Silently treating it as "not resolved" the way an unparseable file is would
# let a deliberately padded local target smuggle a real weakening past this
# gate undetected. Same padding technique as biome-past-the-size-cap below —
# an unterminated string literal is enough to cross the byte cap without
# needing valid JSON, since the cap is checked before parsing.
d="$(mk_js_case biome-local-extends-past-the-size-cap)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./biome.loose.json"],
    "files": { "includes": ["src/**"] }
}
JSON
php -r 'file_put_contents($argv[1], "{\"a\":" . str_repeat("\\\"", 70000));' "$d/biome.loose.json"
assert_rejects_js "$d" "biome.json whose local extends target is past the size cap" "a local \`extends\` target (./biome.loose.json) is larger than the 131072 bytes"

# The oversized-local-target report interpolates the `extends` candidate
# string verbatim — found by security-reviewer during this change's own audit
# round: unlike every other dynamic-interpolation site in this file
# (`overrides[%s]`, `linter.rules.%s`), this one was not routed through
# safeReportValue(). The input is pull-request content in the consumer's CI
# (see $stripJsonc's own trust-model note), and a candidate string doubling as
# a real filename the PR author controls can forge a GitHub Actions workflow
# command mid-line. Verified live against both gates before the fix: the raw
# `##[error]…` sequence reached STDERR byte-for-byte.
d="$(mk_js_case biome-local-extends-past-the-size-cap-forged-annotation)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./##[error]forged"],
    "files": { "includes": ["src/**"] }
}
JSON
php -r 'file_put_contents($argv[1], "{\"a\":" . str_repeat("\\\"", 70000));' "$d/##[error]forged"
assert_report_is_inert_js "$d" "biome.json whose oversized local extends target carries a forged CI annotation" \
    'a local `extends` target (./##?[error]forged) is larger than the 131072 bytes'

# The plain "no extends" case lives with the adoption pair further down, where it
# is the counterpart to the same config in a repository that has not adopted.

# A near-miss package name must NOT satisfy the extends check — the optional path
# prefix has to end at a segment boundary, the same rule the deptrac import uses.
d="$(mk_js_case biome-lookalike-extends)"
printf '{\n    "extends": ["notmagicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending a look-alike package" "biome/base.json"

# Reaching the same file through an explicit node_modules path is legitimate.
d="$(mk_js_case biome-node-modules-path)"
printf '{\n    "extends": ["./node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_accepts_js "$d" "biome.json extending via an explicit node_modules path"

# The pnpm layout reaches the package through a second node_modules segment.
d="$(mk_js_case biome-pnpm-path)"
printf '{\n    "extends": ["./node_modules/.pnpm/@magicsunday+coding-standard@1.7.0/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_accepts_js "$d" "biome.json extending via a pnpm node_modules path"

# An arbitrary local path that merely LOOKS like the package must not count: both
# tools would load that file instead of the installed one, so accepting it would
# report a link to a config nobody shares.
d="$(mk_js_case biome-local-lookalike)"
printf '{\n    "extends": ["./fixtures/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending a local look-alike copy outside node_modules" "biome/base.json"

# A literal `node_modules/` segment somewhere in an arbitrary path is not this
# repository's node_modules — both of these are loaded by the real tools INSTEAD
# of the installed package, which is the whole failure mode.
d="$(mk_js_case biome-nested-lookalike)"
printf '{\n    "extends": ["./fixtures/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending through a node_modules under an unrelated path" "biome/base.json"

d="$(mk_js_case biome-foreign-repo)"
printf '{\n    "extends": ["../../other-repo/node_modules/@magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending another repository's node_modules" "biome/base.json"

# Biome does NOT resolve an extensionless specifier — verified, it answers with
# `module not found` — so the gate must not accept one either.
d="$(mk_js_case biome-extensionless)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending without the .json suffix" "biome/base.json"

# The scope is part of the package name. Neither tool resolves the unscoped
# spelling — Biome answers `module not found`, tsc `TS6053: File … not found` —
# so accepting it would report a link that cannot exist.
d="$(mk_js_case biome-unscoped)"
printf '{\n    "extends": ["magicsunday/coding-standard/biome/base.json"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json extending the unscoped package name" "biome/base.json"

d="$(mk_js_case ts-unscoped)"
printf '{\n    "extends": "magicsunday/coding-standard/tsconfig/base.json"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json extending the unscoped package name" "tsconfig/base.json"

# Neither tool trims the specifier before resolving it: tsc answers a padded one
# with `TS6053: File ' @magicsunday/…' not found`, and Biome already proves it
# does no normalising by refusing the extensionless spelling above. So a padded
# specifier names a module that does not exist, and the gate must report the link
# as missing rather than accept a config the tools cannot load.
d="$(mk_js_case ts-padded-specifier)"
printf '{\n    "extends": " @magicsunday/coding-standard/tsconfig/base.json"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json whose specifier carries leading whitespace" "tsconfig/base.json"

d="$(mk_js_case biome-padded-specifier)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json "]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json whose specifier carries trailing whitespace" "biome/base.json"

# The trailing whitespace character the pattern's ANCHOR lets through rather than
# its body: PCRE's `$` matches before a single trailing newline unless the `D`
# modifier is set, so this spelling passed while the trailing-SPACE case above was
# rejected — the same latitude, decided by which whitespace character it is.
d="$(mk_js_case biome-newline-specifier)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json\\n"]\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json whose specifier ends in a newline" "biome/base.json"

d="$(mk_js_case ts-newline-specifier)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json\\n"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json whose specifier ends in a newline" "tsconfig/base.json"

# Biome accepts only `"//"` or an array for `extends` and answers a bare string
# with `The 'extends' field must be either '//' or an array of paths` — verified
# against 2.5.5. So a scalar specifier is a config Biome refuses to load at all,
# and reporting the link as present would certify exactly the unloadable state
# this gate exists to catch. tsc, by contrast, takes a bare string, which is why
# the two are asserted in opposite directions.
d="$(mk_js_case biome-extends-scalar)"
printf '{\n    "extends": "@magicsunday/coding-standard/biome/base.json"\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json whose extends is a bare string instead of a list" "biome/base.json"

# A specifier that is not a string at all must report as a missing link rather
# than fail the gate on a type error.
d="$(mk_js_case biome-extends-not-a-string)"
printf '{\n    "extends": 5\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json whose extends is not a specifier at all" "biome/base.json"

d="$(mk_js_case biome-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "enabled": false }
}
JSON
assert_rejects_js "$d" "biome.json with the linter disabled" "\`linter.enabled\` must not be false"

d="$(mk_js_case biome-recommended-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "recommended": false } }
}
JSON
assert_rejects_js "$d" "biome.json with the recommended set disabled" "\`linter.rules.recommended\`"

# The formatter is half the shared standard; disabling it is the same class of
# drift as disabling the linter, and shares the loop that reports it.
d="$(mk_js_case biome-formatter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "formatter": { "enabled": false }
}
JSON
assert_rejects_js "$d" "biome.json with the formatter disabled" "\`formatter.enabled\` must not be false"

# The third section Biome lets a consumer switch off wholesale. Verified against
# the pinned schema that `assist.enabled` exists at the root, in an `overrides`
# entry and in each per-language block, so it belongs in the same walk as the
# other two rather than in a check of its own:
#
#     jq -r '.properties | keys[]' node_modules/@biomejs/biome/configuration_schema.json
d="$(mk_js_case biome-assist-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "assist": { "enabled": false }
}
JSON
assert_rejects_js "$d" "biome.json with assist disabled" "\`assist.enabled\` must not be false"

# The same toggle one scope down, so the walk is pinned rather than the root read.
d="$(mk_js_case biome-assist-off-in-override)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["src/**"], "javascript": { "assist": { "enabled": false } } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json disabling assist inside an override's language block" \
    "overrides[0].javascript.assist.enabled"

# The disable route that leaves every `enabled` flag true: narrowed to nothing,
# Biome checks zero files and exits 0, so every other control here passes on a
# config that enforces nothing. Only the shape that can ONLY mean "check nothing"
# is reported — the canon narrows too, and narrowing is legitimate.
d="$(mk_js_case biome-includes-nothing)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "files": { "includes": ["!**/vendor/**", "!**/node_modules/**"] }
}
JSON
assert_rejects_js "$d" "biome.json narrowed to no positive include" "carries no positive pattern"

# The accepting twin, so the arm above cannot be satisfied by rejecting every
# `files.includes`. This is the canonical shape a consumer writes.
d="$(mk_js_case biome-includes-narrowed)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "files": { "includes": ["src/**", "!**/vendor/**"] }
}
JSON
assert_accepts_js "$d" "biome.json narrowed to a real path set"

# `preset: "none"` is the modern spelling of `recommended: false` — Biome
# deprecated the boolean in 2.5 — and silences exactly the same rules. Checking
# only the deprecated spelling would leave the current one unguarded.
d="$(mk_js_case biome-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "preset": "none" } }
}
JSON
assert_rejects_js "$d" "biome.json with the rule preset set to none" "\`linter.rules.preset\`"

# The counterpart: the preset a consumer is SUPPOSED to keep must pass, so the
# check above cannot be satisfied by rejecting the key outright.
d="$(mk_js_case biome-preset-recommended)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "preset": "recommended" } }
}
JSON
assert_accepts_js "$d" "biome.json keeping the recommended rule preset"

# Biome carries `recommended`/`preset` on every rule GROUP as well, so switching
# one group off drops that group's floor while the top-level keys stay untouched.
# Verified: with this, `biome ci` passes a file containing `debugger;`.
d="$(mk_js_case biome-group-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "preset": "none" } } }
}
JSON
assert_rejects_js "$d" "biome.json switching one rule group's preset to none" "linter.rules.suspicious.preset"

d="$(mk_js_case biome-group-recommended-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "correctness": { "recommended": false } } }
}
JSON
assert_rejects_js "$d" "biome.json switching one rule group's recommended off" "linter.rules.correctness.recommended"

# An overrides entry has its own linter/formatter block, so one matching `**`
# disables the shared standard for every file while the top level reads enabled.
d="$(mk_js_case biome-override-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["**"], "linter": { "enabled": false } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json disabling the linter through an overrides entry" "overrides[0].linter.enabled"

d="$(mk_js_case biome-override-preset-none)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["src/**"], "linter": { "rules": { "preset": "none" } } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json dropping the rule floor through an overrides entry" "overrides[0].linter.rules.preset"

# Biome carries linter/formatter a THIRD time, per language — and there it
# silences the shared standard for every file of that language while the
# top-level keys still read as enabled. Verified against 2.5.5: with this config
# a 2-space indent passes. (The `==`-comparison half of that observation belonged
# to the linter case that used to sit here; the derived loop below owns that arm
# now, and with the FORMATTER disabled the linter is still on, so `==` is still
# reported.)
d="$(mk_js_case biome-language-formatter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": { "formatter": { "enabled": false } }
}
JSON
assert_rejects_js "$d" "biome.json disabling the formatter for a whole language" "javascript.formatter.enabled"

# The cross product: a per-language block INSIDE an overrides entry. That is the
# idiomatic place to write one, since an override is how a language setting gets
# scoped to a path set — and walking languages off the document alone left it open.
d="$(mk_js_case biome-override-language-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["**"], "javascript": { "linter": { "enabled": false } } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json disabling a language's linter inside an overrides entry" "overrides[0].javascript.linter.enabled"

# A non-zero index and a non-JS language, so neither the index nor the language
# list is satisfied by the first entry alone.
d="$(mk_js_case biome-override-language-second)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["tests/**"], "javascript": { "formatter": { "quoteStyle": "single" } } },
        { "includes": ["**"], "json": { "formatter": { "enabled": false } } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json disabling a non-JS language's formatter in the SECOND overrides entry" "overrides[1].json.formatter.enabled"

# Every row of the gate's language table, READ from the gate rather than retyped
# here. Two of the six had cases; a typo in any of the other four — `grahpql` for
# `graphql` — left a consumer able to disable that language's linter unreported
# while the suite stayed green.
#
# Derived rather than hand-listed for the reason the derived-flags lockstep
# further down already exists: a copy of the table catches a row the gate LOSES
# and never one it GAINS, so adding `'vue'` there would ship that language's
# escape unproven, which is the same hole one step along. Both directions are
# asserted, so neither list can move without the other.
# The ONE line carrying the array literal, not the block around it: a range
# ending at `as $language` also swallows the loop body, whose `linter` and
# `formatter` keys then read as language names.
# `|| true`: a plain assignment aborts the whole suite under `set -e` when grep
# finds nothing — which is exactly the drift this lockstep exists for (a reordered
# or renamed list). The guard written for that case sits one line below and could
# never run; the ~790 lines of cases after it never ran either, and the exit was
# grep's 1, indistinguishable in the log from "cases failed".
gate_language_literal="$(grep -oE "foreach \(\['javascript'[^]]*\]" "$ROOT/bin/check-consumer-config.php")" || true
mapfile -t gate_languages < <(grep -oE "['\"][a-z0-9_-]+['\"]" <<<"$gate_language_literal" | tr -d "'\"")

# Folded into one if/else, because the count needs a literal to count in. Written
# as two statements, `language_commas="$(grep -o ',' … | wc -l)"` runs on the empty
# string, grep exits 1, pipefail hands that to a plain assignment and `set -e` kills
# the run — 14 lines after the `|| true` added for exactly that. The old form was
# safe by accident: both substitutions sat inside `[ … ]` in an `if` condition,
# where `set -e` does not apply, and the rewrite moved them out.
#
# The mutation that missed it asserted exit 1, which an abort also produces. Assert
# that the run REACHES `verdict`.
if [ "${#gate_languages[@]}" -eq 0 ]; then
    report_failure 'read no language names from the gate — the language lockstep did not run'
else
    # Commas are the structural anchor: one fewer than the entries, whatever an
    # entry is spelled like. Counting quote CHARACTERS was bounded by the very
    # vocabulary this guard un-bounds — measured, a row written `"vue"` left both
    # counts unchanged.
    language_commas="$(count_matches "$gate_language_literal" ',')"

    if [ "$((language_commas + 1))" -ne "${#gate_languages[@]}" ]; then
        report_failure "the language list holds $((language_commas + 1)) entries but this harness parsed ${#gate_languages[@]} — widen the extractor rather than leaving a row unexercised"
    fi
fi

# The languages this harness knows how to drive. A row the gate gains that is not
# here fails below rather than shipping unexercised.
proven_languages=(javascript json css graphql grit html)

# Verified against 2.5.5: with `javascript.linter.enabled: false` a `==`
# comparison passes while the top-level `linter.enabled` still reads true — which
# is why a check that only walked the document would report this config clean.
for language in "${gate_languages[@]}"; do
    d="$(mk_js_case "biome-language-$language-linter-off")"
    printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json"],\n    "%s": { "linter": { "enabled": false } }\n}\n' "$language" > "$d/biome.json"
    assert_rejects_js "$d" "biome.json disabling the linter for $language" "$language.linter.enabled"

    if ! contains "$language" "${proven_languages[@]}"; then
        report_failure "the gate now walks the language \`$language\`, which this harness does not name — add it rather than leaving the row unexercised"
    fi
done

for language in "${proven_languages[@]}"; do
    if ! contains "$language" "${gate_languages[@]}"; then
        report_failure "the gate no longer walks the language \`$language\`, which this harness proves — the row was dropped rather than renamed"
    fi
done

# The counterpart at the same nesting: a per-language style option inside an
# override is exactly what overrides are for and must not be reported.
d="$(mk_js_case biome-override-language-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["tests/**"], "javascript": { "formatter": { "quoteStyle": "single" } } }
    ]
}
JSON
assert_accepts_js "$d" "biome.json setting a per-language style option inside an overrides entry"

# The counterpart: a per-language block that only sets style options is normal
# consumer use and must not be reported.
d="$(mk_js_case biome-language-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": { "formatter": { "quoteStyle": "single" } }
}
JSON
assert_accepts_js "$d" "biome.json setting a per-language style option"

# A legitimate overrides entry — narrowing a single rule for one path — must not
# be reported, or the check would push consumers off a feature they need.
d="$(mk_js_case biome-override-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        {
            "includes": ["tests/**"],
            "linter": { "rules": { "suspicious": { "noExplicitAny": "off" } } }
        }
    ]
}
JSON
assert_accepts_js "$d" "biome.json narrowing a single rule for one path through overrides"

# A malformed config must be reported as such rather than silently skipped —
# json_decode returns null, which an `?? null` read cannot tell from "absent".
d="$(mk_js_case biome-malformed)"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json"\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json that is not valid JSON(C)" "biome.json: not valid JSON(C)"

# biome.jsonc is Biome's own alternative filename; the gate must find it there
# too. Asserted as a REJECT, because that is the only shape that proves discovery:
# an accept case stays green when the .jsonc candidate is dropped from the list —
# the fixture then has no biome file at all and the whole block is skipped.
d="$(mk_js_case biome-jsonc)"
rm "$d/biome.json"
cat > "$d/biome.jsonc" <<'JSON'
{
    // A jsonc file exists precisely so a consumer can comment it.
    "//": "and this note key makes it unloadable",
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_rejects_js "$d" "biome.jsonc is discovered, parsed with comments, and named in the report" "biome.jsonc: "

d="$(mk_js_case biome-jsonc-clean)"
rm "$d/biome.json"
cat > "$d/biome.jsonc" <<'JSON'
{
    // A jsonc file exists precisely so a consumer can comment it.
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
assert_accepts_js "$d" "a clean biome.jsonc is accepted"

# The two cases above reach only the note-key guard and the clean-parse path,
# both of which run before the adoption gate. So nothing drove a .jsonc file
# through the assertions that follow it, and a regression reaching those only via
# the .json filename would have passed. One reject per class closes that.
d="$(mk_js_case biome-jsonc-no-extends)"
rm "$d/biome.json"
printf '{\n    // no shared link\n    "linter": { "enabled": true }\n}\n' > "$d/biome.jsonc"
assert_rejects_js "$d" "biome.jsonc without the shared extends" "biome.jsonc: must \`extends\`"

d="$(mk_js_case biome-jsonc-linter-off)"
rm "$d/biome.json"
printf '{\n    "extends": ["@magicsunday/coding-standard/biome/base.json"],\n    // switched off\n    "linter": { "enabled": false }\n}\n' > "$d/biome.jsonc"
assert_rejects_js "$d" "biome.jsonc with the linter disabled" "biome.jsonc: \`linter.enabled\`"

d="$(mk_js_case ts-no-extends)"
printf '{\n    "compilerOptions": { "strict": true }\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json without the shared extends" "tsconfig/base.json"

d="$(mk_js_case ts-strict-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": false }
}
JSON
assert_rejects_js "$d" "tsconfig.json overriding strict to false" "\`compilerOptions.strict\`"

# The subtler override: `strict` stays on, but the flag the shared base adds ON
# TOP of strict is switched off. This is the realistic drift, and a check that
# only looked at `strict` would miss it.
d="$(mk_js_case ts-unchecked-index-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": true, "noUncheckedIndexedAccess": false }
}
JSON
assert_rejects_js "$d" "tsconfig.json disabling noUncheckedIndexedAccess" "noUncheckedIndexedAccess"

# An extends ARRAY is legal in TypeScript 5+; the shared base may sit anywhere in it.
d="$(mk_js_case ts-extends-array)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": ["./tsconfig.paths.json", "@magicsunday/coding-standard/tsconfig/base.json"],
    "compilerOptions": { "noEmit": true }
}
JSON
assert_accepts_js "$d" "tsconfig.json with the shared base in an extends array"

# Ergonomics flags are deliberately NOT pinned: turning skipLibCheck off is
# stricter, not looser, and must not be reported as drift.
d="$(mk_js_case ts-skiplibcheck-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "skipLibCheck": false }
}
JSON
assert_accepts_js "$d" "tsconfig.json turning skipLibCheck off (stricter, not drift)"

# A trailing comma is legal in tsconfig.json and must not read as malformed.
d="$(mk_js_case ts-trailing-comma)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "noEmit": true,
    },
}
JSON
assert_accepts_js "$d" "tsconfig.json with trailing commas"

# A "//" sequence INSIDE a string is not a comment — stripping it would corrupt
# the document and turn a valid consumer config into a false rejection.
d="$(mk_js_case ts-url-in-string)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "paths": { "@app/*": ["https://example.com/not-a-comment/*"] }
    }
}
JSON
assert_accepts_js "$d" "tsconfig.json with a // inside a string value"

# tsc appends `.json` itself, so this resolves to the very same file — verified
# with 7.0.2. Rejecting it would report drift on a working consumer config.
d="$(mk_js_case ts-extensionless)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base"\n}\n' > "$d/tsconfig.json"
assert_accepts_js "$d" "tsconfig.json extending without the .json suffix"

# --- GH-36: the extends chain resolves to the EFFECTIVE config, not just the
# document's own top level ----------------------------------------------------
#
# Route 1, reproduced from the issue almost verbatim: the shared entry is
# listed FIRST, a LOCAL second entry disables the linter wholesale, and the
# document's own top level never mentions `linter` at all. Verified against
# Biome 2.5.5: with this exact pair, `biome ci` checks zero files, because the
# wholesale disable is folded on top of the shared base.
d="$(mk_js_case biome-local-extends-linter-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./biome.loose.json"],
    "files": { "includes": ["src/**"] }
}
JSON
cat > "$d/biome.loose.json" <<'JSON'
{ "linter": { "enabled": false } }
JSON
assert_rejects_js "$d" "biome.json whose LATER local extends target disables the linter" "\`linter.enabled\` must not be false"

# The order-sensitive half of the same mechanism, in the OTHER direction: the
# shared entry listed AFTER a local override wins the fold and undoes it — the
# shared base sets `linter.enabled: true` explicitly, so it wins back. Verified
# against Biome 2.5.5: with the shared entry last, the same local
# `biome.loose.json` that disabled the linter above no longer does — `biome
# lint` reports the `noDoubleEquals`/`useConst` findings it would report with no
# override at all. A fix that treats the shared entry as a no-op regardless of
# its position — "skip it, it is already asserted separately" — passes route 1
# above but reports drift HERE, on a document this gate must accept.
d="$(mk_js_case biome-shared-extends-after-local-override)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["./biome.loose.json", "@magicsunday/coding-standard/biome/base.json"],
    "files": { "includes": ["src/**"] }
}
JSON
cat > "$d/biome.loose.json" <<'JSON'
{ "linter": { "enabled": false } }
JSON
assert_accepts_js "$d" "biome.json whose shared extends entry follows and undoes a local override"

# A local target that does something ordinary and touches none of the checked
# toggles must not be reported — the negative control the issue itself asks
# for. `lineWidth` is a formatter ergonomic this gate does not pin.
d="$(mk_js_case biome-local-extends-legitimate)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./biome.wide.json"],
    "files": { "includes": ["src/**"] }
}
JSON
cat > "$d/biome.wide.json" <<'JSON'
{ "formatter": { "lineWidth": 120 } }
JSON
assert_accepts_js "$d" "biome.json whose local extends target is a legitimate, non-drifting relaxation"

# A package-scoped entry other than the shared one resolves to no file in this
# repository — "not in the repository", the same answer this gate already
# gives an unmet contract elsewhere — so it must be silently skipped rather
# than reported or crashed on.
d="$(mk_js_case biome-local-extends-package-scoped-out-of-scope)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "@some/other-package"],
    "files": { "includes": ["src/**"] }
}
JSON
assert_accepts_js "$d" "biome.json whose second extends entry is an uninstalled package, not a local file"

# A specifier that escapes the repository must not be followed — the input is
# pull-request content in the consumer's CI, and opening whatever the runner's
# filesystem holds at an arbitrary path is not this gate's job. The escape
# target genuinely disables the linter, so if it were followed this case would
# reject; accepting proves it is not.
d="$(mk_js_case biome-local-extends-path-traversal)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "../escape.json"],
    "files": { "includes": ["src/**"] }
}
JSON
cat > "$(dirname "$d")/escape.json" <<'JSON'
{ "linter": { "enabled": false } }
JSON
assert_accepts_js "$d" "biome.json whose local extends target escapes the repository via ../"

# Route 2: one rule switched off by name survives every check that only looks
# at the group-level `recommended`/`preset` floor. Both value shapes Biome's
# schema allows are driven — a bare string and an options object — since only
# one was verified in the issue.
d="$(mk_js_case biome-rule-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "noDoubleEquals": "off" } } }
}
JSON
assert_rejects_js "$d" "biome.json switching a shared rule off by its bare-string value" "\`linter.rules.suspicious.noDoubleEquals\` must not be \"off\""

d="$(mk_js_case biome-rule-off-object-shape)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "noDoubleEquals": { "level": "off" } } } }
}
JSON
assert_rejects_js "$d" "biome.json switching a shared rule off via its options-object level" "\`linter.rules.suspicious.noDoubleEquals\` must not be \"off\""

# The override half of route 2 — the per-rule check must walk the same
# override scopes the wholesale toggle check already does.
d="$(mk_js_case biome-rule-off-in-override)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["**/*.legacy.js"], "linter": { "rules": { "suspicious": { "noDoubleEquals": "off" } } } }
    ]
}
JSON
assert_rejects_js "$d" "biome.json switching a shared rule off inside an overrides entry" "overrides[0].linter.rules.suspicious.noDoubleEquals\` must not be \"off\""

# Two negative controls, so the per-rule check is proven to look at "off"
# specifically rather than at "any change to a shared rule's value".
d="$(mk_js_case biome-rule-escalated-not-off)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "noDoubleEquals": "error" } } }
}
JSON
assert_accepts_js "$d" "biome.json tightening a shared rule's severity (not drift)"

d="$(mk_js_case biome-rule-off-not-shared)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": { "noExplicitAny": "off" } } }
}
JSON
assert_accepts_js "$d" "biome.json switching off a rule the shared config never turns on itself"

# A rule GROUP literally named after an inherited Object.prototype member
# (found by CodeRabbit during this change's own review): the Node gate's
# `sharedRules[group]` lookup resolves through the prototype chain to the
# inherited member itself rather than `undefined`, so `?? []` never applies —
# `for...of` over a function throws and crashes the whole gate. Biome itself
# refuses this exact shape ("Found an unknown key `toString`"), but the gate
# must never CRASH on a config it is asked to check, whatever Biome does with
# it — a wrong verdict is not the same failure class as an unhandled
# exception. PHP is unaffected: an array key lookup has no prototype chain.
d="$(mk_js_case biome-rule-group-named-tostring)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "toString": { "someRule": "off" } } }
}
JSON
assert_accepts_js "$d" "biome.json with a rule group literally named toString does not crash the gate"

# tsconfig gets the same two mechanisms — order-sensitive extends-chain
# resolution, one hop deep, with the shared entry substituted at its listed
# position. Verified against tsc 7.0.2, mirroring the Biome measurements above.
d="$(mk_js_case ts-local-extends-flag-off)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/tsconfig/base.json", "./tsconfig.loose.json"],
    "include": ["src"]
}
JSON
cat > "$d/tsconfig.loose.json" <<'JSON'
{ "compilerOptions": { "noUncheckedIndexedAccess": false } }
JSON
assert_rejects_js "$d" "tsconfig.json whose LATER local extends target disables noUncheckedIndexedAccess" "noUncheckedIndexedAccess"

d="$(mk_js_case ts-shared-extends-after-local-override)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": ["./tsconfig.loose.json", "@magicsunday/coding-standard/tsconfig/base.json"],
    "include": ["src"]
}
JSON
cat > "$d/tsconfig.loose.json" <<'JSON'
{ "compilerOptions": { "noUncheckedIndexedAccess": false } }
JSON
assert_accepts_js "$d" "tsconfig.json whose shared extends entry follows and undoes a local override"

d="$(mk_js_case ts-local-extends-legitimate)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/tsconfig/base.json", "./tsconfig.paths-only.json"],
    "include": ["src"]
}
JSON
cat > "$d/tsconfig.paths-only.json" <<'JSON'
{ "compilerOptions": { "paths": { "@app/*": ["src/*"] } } }
JSON
assert_accepts_js "$d" "tsconfig.json whose local extends target only adds paths (not drift)"

# An empty JSON object decodes to PHP's `[]`, indistinguishable from an empty
# JSON array once decoded — `array_is_list([])` is `true` — so a naive merge
# treats an empty overlay OBJECT as a list and replaces the accumulated value
# outright instead of leaving it untouched. Found independently by two
# reviewers during this change's own audit round, verified against Biome
# 2.5.5.
#
# Only ONE direction is fixture-tested, not two, and that is deliberate rather
# than an omission — found by test-quality-reviewer in this change's own audit
# round: an earlier version of this file also carried a "leaves a re-enable
# untouched" ACCEPT case, built the same way as the REJECT case below but with
# the layers in the opposite order (shared re-enabling AFTER the local
# disable). It read as the direction-1 counterpart, but no check in this gate
# fires on an ABSENT bad value, only on an explicit one — so wiping the good,
# re-enabled `linter` object down to `{}` and correctly preserving it both
# report the identical verdict (`accept`), and the case could not tell the
# fixed merge from the pre-fix bug apart. The REJECT case immediately below is
# the one direction where the two behaviours actually diverge: wiping a
# *disabled* section removes the very key this gate's check reads, turning an
# expected rejection into a false accept, which is the observable half of this
# bug and the only half a CLI accept/reject fixture can pin.
d="$(mk_js_case biome-empty-object-overlay-does-not-hide-drift)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json", "./biome.loose.json"],
    "files": { "includes": ["src/**"] },
    "linter": {}
}
JSON
cat > "$d/biome.loose.json" <<'JSON'
{ "linter": { "enabled": false } }
JSON
assert_rejects_js "$d" "biome.json whose empty top-level object does not mask an unresolved disable" "\`linter.enabled\` must not be false"

# `extends` shaped as a JSON OBJECT rather than an array or string is not
# accepted by either real tool. Found during this change's own audit round: an
# early implementation iterated an object's VALUES as extends candidates in
# PHP but not in Node (`Array.isArray` is false for a plain object), so the
# same config could reach a different verdict on each side. Both sides must
# now agree — accept, matching neither resolving the local target inside it.
d="$(mk_js_case biome-extends-object-shape)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": { "shared": "@magicsunday/coding-standard/biome/base.json", "local": "./biome.loose.json" }
}
JSON
cat > "$d/biome.loose.json" <<'JSON'
{ "linter": { "enabled": false } }
JSON
assert_accepts_js "$d" "biome.json whose extends is a JSON object rather than an array"

# Prototype pollution via a literal `"__proto__"` key (CWE-1321), found by
# security-reviewer during this change's own audit round: `JSON.parse` creates
# a real OWN `__proto__` property, but `merged[key]` on the read side falls
# through to the inherited accessor once the accumulator carries no own
# `__proto__`, returning the object's actual prototype — which the "both sides
# are objects, recurse" branch then treats as ordinary data and reassigns via
# `[[SetPrototypeOf]]`. Verified against the real gate: without the fix this
# fabricated a `files.includes` violation on a document that never set
# `files` at all. PHP is unaffected — plain arrays have no prototype
# mechanism — so this is the node-only half of the differential pair.
d="$(mk_js_case biome-proto-key-no-fabricated-violation)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "__proto__": { "files": { "includes": ["!**/*"] } }
}
JSON
assert_accepts_js "$d" "biome.json carrying a __proto__ key does not fabricate a files.includes violation"

# The string-protection the trailing-comma pass needs: a comma before a bracket
# INSIDE a string value is part of the value, not punctuation to strip.
#
# Asserted through a value the gate REPORTS BACK, not through a `paths` entry.
# An in-string comma-strip can never break JSON validity — `"a,] b"` becomes
# `"a] b"` and the document still parses — so an accept/reject case placed on a
# key the gate only has to skip is decided identically with and without the
# protection, and pins nothing. A rule GROUP name is interpolated into the
# violation text, so a corrupted one is visible: drop the string guard from the
# comma pass and the report reads `linter.rules.sus]picious`, the expected
# substring is absent, and the case goes red.
d="$(mk_js_case biome-comma-in-string)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "sus,]picious": { "preset": "none" } } }
}
JSON
assert_rejects_js "$d" "biome.json whose reported rule group carries a comma before a bracket inside a string" "linter.rules.sus,]picious"

# A rule-group key is arbitrary bytes chosen by whoever opened the pull request,
# and this gate runs in the CONSUMER's CI over branch content. Why that reaches a
# workflow command, and the source for it, are in bin/support/safe-report-value.php
# — stated once, where the guard lives.
#
# Measured here: unescaped, this key turns a three-line report into six, one of
# which begins `::notice::`.
d="$(mk_js_case biome-control-chars-in-rule-group)"
php -r '
    $esc = chr(27);
    $key = "a" . $esc . "[2K\n::notice::forged\n##[error]forged\nb";
    file_put_contents($argv[1], json_encode([
        "extends" => ["@magicsunday/coding-standard/biome/base.json"],
        "linter"  => ["rules" => [$key => ["recommended" => false]]],
    ]));
' "$d/biome.json"
assert_report_is_inert_js "$d" 'a rule-group key carrying control characters' \
    'a?[2K?::notice::forged?##?[error]forged?b'

# The `overrides` half, which had no case at all. Every other overrides fixture
# writes a JSON ARRAY, so the index is an int and the guard is a no-op on all of
# them — dropping it there left the whole suite green. The gate reaches that site
# through `is_array()`, which is true for a JSON OBJECT too, so a hostile string
# key is reachable.
d="$(mk_js_case biome-control-chars-in-overrides-key)"
php -r '
    $key = "x\n::error::forged\ny";
    file_put_contents($argv[1], json_encode([
        "extends"   => ["@magicsunday/coding-standard/biome/base.json"],
        "overrides" => [$key => ["linter" => ["rules" => ["recommended" => false]]]],
    ]));
' "$d/biome.json"
assert_report_is_inert_js "$d" 'an overrides key carrying a newline' \
    'x?::error::forged?y'

# The phpunit.xml attribute VALUE, the site the round-11 guard did not reach.
# XML attribute-value normalisation folds only LITERAL control characters to a
# space; a character reference survives, so `&#10;` produces a real newline.
# ESC is not expressible in XML 1.0 at all, which is why this payload carries no
# escape sequence and the ANSI arm above cannot fire here.
d="$(mk_case phpunit-control-chars-in-value)"
sed -i 's/failOnRisky="true"/failOnRisky="false\&#10;::error::forged\&#10;  - phpunit.xml: OK"/' "$d/phpunit.xml"
assert_report_is_inert "$d" 'a phpunit.xml attribute value carrying a character reference' \
    'false?::error::forged?'

# The CR half of the same class, which neither shipped gate's suite drove through a
# real fixture before now — only harness.sh's own crafted-report probes did. Why a
# bare CR needs its own arm at all (the runner's line-break semantics) is documented
# once, on harness_decide_report_is_inert in tests/harness.sh — not restated here.
# What this fixture adds is that `&#13;` actually survives XML attribute-value
# normalisation and reaches the gate as a real CR, exactly as `&#10;` does above.
# Verified against this repository's own libxml:
#     php -r 'echo bin2hex((string) simplexml_load_string("<a x=\"false&#13;::error::forged&#13;\"/>")["x"]);'
# prints 66616c73650d3a3a6572726f723a3a666f726765640d — 0x0d preserved at both
# positions, not 0x20, the same way `&#10;` preserves 0x0a rather than being folded.
d="$(mk_case phpunit-control-chars-carriage-return-in-value)"
sed -i 's/failOnRisky="true"/failOnRisky="false\&#13;::error::forged\&#13;  - phpunit.xml: OK"/' "$d/phpunit.xml"
assert_report_is_inert "$d" 'a phpunit.xml attribute value carrying a bare carriage return' \
    'false?::error::forged?'

# The size cap, both sides of the bound. 131072 is read and checked; one byte more
# is reported as unread rather than scanned — the pass is quadratic on an
# unterminated string literal, and the input is pull-request content. The padding
# builder is harness_pad_json_to_cap (tests/harness.sh) — shared with the
# jscpd-at-the-size-cap fixture further down and with
# check-version-lockstep-cases.sh's own at-cap-package-json fixture, since each
# `check-*-cases.sh` file runs as its own process and a locally-defined function
# is not reachable from a sibling file.
d="$(mk_js_case biome-at-the-size-cap)"
harness_pad_json_to_cap 131072 \
    "$(php -r 'echo json_encode(["extends" => ["@magicsunday/coding-standard/biome/base.json"]]);')" \
    "$d/biome.json"
assert_rejects_js "$d" "a biome.json exactly at the size cap is still read and checked" '`"//"` key'

d="$(mk_js_case biome-past-the-size-cap)"
php -r 'file_put_contents($argv[1], "{\"a\":" . str_repeat("\\\"", 70000));' "$d/biome.json"
assert_rejects_js "$d" "a biome.json past the size cap is reported as oversized, not scanned" "larger than the 131072 bytes this gate checks"

# The tsconfig arm is a separate code path. Delete the whole
# `is_int($tsconfigJson)` block and the suite stayed green while the gate printed
# OK for a tsconfig.json it never read — an int is neither null nor an array, so
# no later arm catches it.
d="$(mk_js_case ts-past-the-size-cap)"
php -r 'file_put_contents($argv[1], "{\"a\":" . str_repeat("\\\"", 70000));' "$d/tsconfig.json"
assert_rejects_js "$d" "a tsconfig.json past the size cap is reported as oversized, not scanned" "larger than the 131072 bytes this gate checks"

# The plain-text bound, AT the cap rather than past it — the counterpart of
# biome-at-the-size-cap above, for MAX_TEXT_BYTES rather than MAX_JSONC_BYTES. Every
# case in the oversize loop below writes one byte PAST the bound, where `>` and `>=`
# agree, so a mutation to `>=` survives all of them; this is the one that does not.
# .jscpd.json is picked because an unknown top-level key costs it nothing — the
# shipped template documents that convention with its own "//" key — so the padding
# introduces no second violation, and the fixture's real defect (`threshold: 1`) is
# what the assertion below requires: an "oversized" report would not carry it.
d="$(mk_case jscpd-at-the-size-cap)"
harness_pad_json_to_cap 1048576 \
    "$(php -r 'echo json_encode([
        "threshold" => 1,
        "minTokens" => 100,
        "minLines"  => 5,
        "exitCode"  => 1,
        "reporters" => ["console-full"],
        "format"    => ["php"],
    ]);')" \
    "$d/.jscpd.json"
assert_rejects "$d" "a .jscpd.json exactly at the size cap is still read and checked" '`threshold` must be 0'

# The plain-text bound. Measured before the cap reached these readers: a 196 MB
# .editorconfig at memory_limit=128M ended in `Allowed memory size exhausted`, exit
# 255, with no gate diagnostic — the outcome readQuietly()'s scoped handler exists to
# prevent, reached by every site that passed no bound.
# Every plain-text reader on that bound, not a sample: they were six separate call
# sites before $readBounded, and two cases could not pin the other four.
#
# assert_reports_once, not assert_rejects, and that is the point. A substring
# assertion passes while the gate ALSO fabricates causes: measured before the fix,
# the phpunit fixture produced two violations and the .editorconfig one four, the
# extras naming things the files plainly carry (`not well-formed XML`, `must set
# root = true`). Requiring exactly one report per file is what caught it, and it is
# what the structurally identical unreadable path was already pinned with.
for oversize_file in phpunit.xml .jscpd.json .phplint.yml .editorconfig deptrac.yaml; do
    d="$(mk_case "oversize-${oversize_file#.}")"
    php -r 'file_put_contents($argv[1], str_repeat("x", 1048577));' "$d/$oversize_file"
    assert_reports_once "$d" "an oversized $oversize_file is reported once, as itself" "$oversize_file"
done

# The npm probe's own oversize arm. What it pins is that the manifest problem is
# REPORTED, once and as itself — not that the JS/TS contract keeps running. It does
# not: an unreadable manifest means the gate cannot know whether the repository
# declared the dependency, so it answers "not adopted" and the adoption-gated checks
# are skipped. That is deliberate — reporting them on a guess is the false positive
# the adoption gate exists for — and it is safe only because the read was already
# reported. An earlier label here claimed the opposite, which assert_reports_once
# could never have decided: it counts `- package.json:` lines and nothing else.
#
# A biome.json has to be present or the probe is never called at all — the JS/TS half
# runs only where a JS config exists. It is a CLEAN one, so the single report this
# case counts is the manifest's own.
#
# _js too: bin/check-js-config.mjs's own oversize arm (readBounded/npmDependencyDeclared)
# is otherwise never driven to its violation outcome by any fixture. biome.json's
# and tsconfig.json's own oversize cases (further up) already run both gates, but
# through assert_rejects_js — a plain substring check, not this one's "reported
# EXACTLY once, no fabricated second cause" property. This is the only case that
# drives BOTH gates through assert_reports_once's stricter assertion, so without
# it the parity this property needs would not hold on the node side.
# The plain-text bound, AT the cap rather than past it — package.json's own
# counterpart of biome-at-the-size-cap above, for MAX_TEXT_BYTES rather than
# MAX_JSONC_BYTES. GH-109: only the cap+1 case (below) existed, so a mutation
# narrowing readBounded's `>` to `>=` would reject a package.json that sits
# exactly on the bound, and no fixture caught it. Padding keeps the
# devDependency declaration intact and drops biome.json's own `extends`
# (mirroring js-adopted-no-extends further down): package.json is read in
# full only when the cap comparison is correct, which is what makes `adopted`
# true and the extends check fire at all. Under the `>=` mutant, package.json
# would be (wrongly) treated as oversized — readBounded's own oversize arm
# calls fail() directly, so the gate still rejects, but for the WRONG reason
# (package.json's own too-large report) rather than the intended missing-extends
# one. The assertion below must therefore pin the extends violation's own
# wording ("must `extends`"), not the generic `biome/base.json` mention every
# drift's footer carries regardless of cause — a `biome/base.json` substring
# check cannot tell the two rejections apart, since the footer names it either
# way (verified: Codex's adversarial pass on this diff caught the substring
# being satisfied by the footer under the very mutant it was meant to catch).
d="$(mk_js_case package-json-at-the-size-cap)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
harness_pad_json_to_cap 1048576 \
    "$(php -r 'echo json_encode([
        "name" => "fixture",
        "devDependencies" => ["@magicsunday/coding-standard" => "github:magicsunday/coding-standard#1.7.0"],
    ]);')" \
    "$d/package.json"
assert_rejects_js "$d" "a package.json exactly at the size cap is still read and checked" "must \`extends\`"

d="$(mk_case package-json-past-the-size-cap)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"]
}
JSON
php -r 'file_put_contents($argv[1], str_repeat("x", 1048577));' "$d/package.json"
assert_reports_once_js "$d" "an oversized package.json is reported once, as itself" \
    "package.json"

# The DEL half of the scrub class. `\x00-\x1F` is exercised by the payloads above;
# removing `\x7F` from the class left every one of them green.
d="$(mk_js_case biome-del-in-rule-group)"
php -r '
    file_put_contents($argv[1], json_encode([
        "extends" => ["@magicsunday/coding-standard/biome/base.json"],
        "linter"  => ["rules" => ["a" . chr(127) . "b" => ["recommended" => false]]],
    ]));
' "$d/biome.json"
assert_rejects_js "$d" "a DEL byte in a rule-group key is scrubbed" "linter.rules.a?b"

# The truncation arm, which nothing reached: the payloads above are well under the
# bound, so the ternary took its else branch in every case and deleting the cap
# entirely stayed green. A consumer otherwise controls the report's length —
# measured on the phpunit path, 5000 bytes in produced 5224 bytes out. One helper
# call rather than an open-coded chain: an untruncated report carries 400 `z` and
# no marker, so the expected substring is absent either way it breaks.
d="$(mk_js_case biome-overlong-rule-group)"
php -r '
    file_put_contents($argv[1], json_encode([
        "extends" => ["@magicsunday/coding-standard/biome/base.json"],
        "linter"  => ["rules" => [str_repeat("z", 400) => ["recommended" => false]]],
    ]));
' "$d/biome.json"
assert_rejects_js "$d" "an overlong rule-group key is truncated with a marker" \
    "linter.rules.$(printf 'z%.0s' $(seq 1 64))…"

# The multi-byte-safe half of the same truncation, which the fixture above
# never reaches: every byte in it is ASCII, so the cut always lands on an
# ordinary byte and the continuation-byte backoff loop in
# bin/support/safe-report-value.mjs (mirroring PHP's mb_strcut) never runs.
# This key places a 2-byte UTF-8 character ("ü") so its SECOND byte lands
# exactly on the 64-byte cut point — 63 ASCII "z" bytes, then "ü" (bytes 63-64
# of 0-indexed), then one more character past the cap. A working backoff
# drops the whole "ü" and reports 63 "z"s; a naive cut-with-no-backoff would
# instead keep the lead byte alone (an unpaired 0xC3), which decodes to a
# replacement character rather than this exact substring. Verified against
# the real PHP gate: `mb_strcut(str_repeat("z", 63) . "ü" . "x", 0, 64)`
# returns the identical 63-byte "z" run.
d="$(mk_js_case biome-overlong-rule-group-multibyte-boundary)"
php -r '
    file_put_contents($argv[1], json_encode([
        "extends" => ["@magicsunday/coding-standard/biome/base.json"],
        "linter"  => ["rules" => [str_repeat("z", 63) . "ü" . "x" => ["recommended" => false]]],
    ]));
' "$d/biome.json"
assert_rejects_js "$d" "a rule-group key whose multi-byte character straddles the 64-byte cut is not split" \
    "linter.rules.$(printf 'z%.0s' $(seq 1 63))…"

# Block comments: the accept case, and the discriminating one — a multi-line
# block carrying a quote and a `//` must be closed rather than swallow the rest
# of the document, which would decode to an empty config that passes everything.
d="$(mk_js_case ts-block-comment)"
cat > "$d/tsconfig.json" <<'JSON'
{
    /* A consumer may comment this file. */
    "extends": "@magicsunday/coding-standard/tsconfig/base.json"
}
JSON
assert_accepts_js "$d" "tsconfig.json with a block comment"

d="$(mk_js_case ts-block-comment-swallow)"
cat > "$d/tsconfig.json" <<'JSON'
{
    /* a " and a // inside,
       spread over two lines */
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": { "strict": false }
}
JSON
assert_rejects_js "$d" "tsconfig.json whose block comment must not swallow the rest" "\`compilerOptions.strict\`"

# An UNTERMINATED block comment: PHP's non-greedy `/\*.*?\*/` alternative never
# matches one, so the raw `/*…` text is left in place and fails to parse. A
# scanner that instead treats "no closing */" as "runs to EOF" would silently
# accept a config real JSONC never would.
d="$(mk_js_case ts-block-comment-unterminated)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json"\n}\n/* never closed' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with an unterminated block comment" "tsconfig.json: not valid JSON(C)"

# An invalid UTF-8 byte sitting INSIDE a comment: json_decode() rejects
# invalid UTF-8, but $stripJsonc strips comments byte-safe, BEFORE that
# check ever runs — so a stray byte a comment would have discarded is never
# seen by the parser at all, and the file is accepted. A node port that
# validated UTF-8 before stripping comments (rather than after, on the
# surviving text) would reject this file where PHP does not.
d="$(mk_js_case ts-invalid-utf8-inside-comment)"
php -r '
    file_put_contents(
        $argv[1],
        "{\n    // a stray byte: \xFF end\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\"\n}\n"
    );
' "$d/tsconfig.json"
assert_accepts_js "$d" "tsconfig.json with an invalid UTF-8 byte discarded inside a comment"

# The control: the same invalid byte OUTSIDE any comment must still reject —
# proves the case above passes because the byte was stripped away with the
# comment, not because invalid UTF-8 is tolerated everywhere.
d="$(mk_js_case ts-invalid-utf8-outside-comment)"
php -r '
    file_put_contents(
        $argv[1],
        "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\", \"junk\": \"\xFF\"\n}\n"
    );
' "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with an invalid UTF-8 byte outside any comment" "tsconfig.json: not valid JSON(C)"

# isAsciiWhitespaceByte mirrors $stripJsonc's trailing-comma pattern, which has
# no `/u` modifier and therefore matches only ASCII whitespace — NOT a non-
# breaking space (U+00A0, encoded \xC2\xA0). Pins that classification against
# PHP directly, not against the accept/reject verdict: this fixture is
# rejected either way a comma-before-NBSP-before-`}` is scanned, because the
# leftover NBSP is not part of the JSON grammar's own whitespace set and is
# fatal to JSON.parse/json_decode on its own, whether or not the comma ahead
# of it was stripped. An earlier version of this comment claimed the wider,
# Unicode-aware `\s` the string-based scan (replaced this round) used to
# match would have flipped this specific fixture to an accept — verified
# false by running that retired scan against this exact input: same reject,
# same message. What the fixture actually demonstrates is narrower and still
# worth pinning: isAsciiWhitespaceByte does not itself misclassify NBSP as
# whitespace, which is the property that must not silently drift back toward
# Unicode.
d="$(mk_js_case ts-trailing-comma-nbsp)"
php -r '
    file_put_contents(
        $argv[1],
        "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": true,\xC2\xA0}\n}\n"
    );
' "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with a trailing comma before a non-breaking space, not a real comma-then-close" "tsconfig.json: not valid JSON(C)"

# json_decode() rejects a `\uD800`-style escape with no matching low
# surrogate ("Single unpaired UTF-16 surrogate in unicode escape");
# JSON.parse() accepts it. Verified against the real gate: exit 1,
# "not valid JSON(C)".
d="$(mk_js_case ts-unpaired-surrogate)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "note": "\\uD800"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with an unpaired UTF-16 surrogate escape" "tsconfig.json: not valid JSON(C)"

# The accepting twin: a genuinely PAIRED surrogate (an emoji) must not be
# rejected — otherwise the check above would be satisfied by rejecting every
# astral-plane character rather than only unpaired ones.
d="$(mk_js_case ts-paired-surrogate)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "note": "\\uD83D\\uDE00"\n}\n' > "$d/tsconfig.json"
assert_accepts_js "$d" "tsconfig.json with a properly paired surrogate escape (an emoji)"

# The DUPLICATE-KEY variant of the unpaired-surrogate case above: JSON.parse()
# collapses a repeated key to its LAST occurrence before any check on the
# parsed result ever runs, so an unpaired surrogate sitting only in an
# EARLIER, overwritten occurrence would go unseen by a check that walked the
# parsed value instead of the source text — while json_decode() validates
# every string token as it streams, independent of which occurrence
# survives. Verified against the PHP gate on this exact fixture: PHP rejects
# ("Single unpaired UTF-16 surrogate in unicode escape") even though the
# invalid value is the one the later, valid "note" overwrites.
d="$(mk_js_case ts-unpaired-surrogate-overwritten-by-duplicate-key)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "note": "\\uD800",\n    "note": "valid"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json whose only unpaired surrogate sits in a duplicate key's overwritten first occurrence" "tsconfig.json: not valid JSON(C)"

# json_decode()'s default $depth is 512, and it fails ("Maximum stack depth
# exceeded") once nesting reaches that count — the outermost container counts
# as depth 1. Measured directly against 8.4: 511 levels decode cleanly, 512
# does not; both fixtures below hit that exact boundary (1 wrapper level +
# 511 or 510 nested `{"a": … }` levels = 512 or 511 total) and are verified
# against the real PHP gate. JSON.parse() has no comparable cap at reachable
# depths, and the 128 KiB size cap does nothing to bound this on its own —
# 511 levels of `{"a": … }` costs well under 4 KB.
d="$(mk_js_case ts-depth-512)"
php -r '
    file_put_contents(
        $argv[1],
        "{\"extends\":\"@magicsunday/coding-standard/tsconfig/base.json\",\"deep\":"
            . str_repeat("{\"a\":", 511) . "1" . str_repeat("}", 511) . "}"
    );
' "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json nested to exactly the 512-level depth PHP rejects at" "tsconfig.json: not valid JSON(C)"

d="$(mk_js_case ts-depth-511)"
php -r '
    file_put_contents(
        $argv[1],
        "{\"extends\":\"@magicsunday/coding-standard/tsconfig/base.json\",\"deep\":"
            . str_repeat("{\"a\":", 510) . "1" . str_repeat("}", 510) . "}"
    );
' "$d/tsconfig.json"
assert_accepts_js "$d" "tsconfig.json nested to exactly the 511-level depth PHP still accepts"

# The npm probe's own depth guard. package.json goes through the same
# decodeJsonLikePhp pipeline biome.json/tsconfig.json does (see that
# function's docblock) — verified against the real PHP gate, which needs no
# such guard of its own (json_decode() enforces its depth cap natively at
# every call site).
d="$(mk_case js-package-json-depth-512)"
node -e '
    const fs = require("fs");
    const k = 511;
    const body = "{\"a\":".repeat(k) + "1" + "}".repeat(k);
    fs.writeFileSync(process.argv[1], "{\"devDependencies\":" + body + "}");
' "$d/package.json"
printf '{\n    "linter": { "enabled": false }\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "a package.json nested past the 512-level depth cap is reported, not crashed on" "package.json: is not valid JSON"

# The npm probe's own surrogate guard, same reasoning: package.json shares
# decodeJsonLikePhp with biome.json/tsconfig.json, so a lone surrogate
# ANYWHERE in the manifest — not just in the dependency version string this
# probe reads — must be reported rather than silently accepted and used for
# the adoption check. Verified against the real PHP gate on a byte-identical
# fixture: the JS gate previously accepted this (exit 0), the PHP gate
# rejected it (exit 1, "package.json: is not valid JSON") — a real
# accept/reject divergence, not merely a differing message.
d="$(mk_case js-package-json-unpaired-surrogate)"
printf '{\n    "name": "consumer",\n    "description": "bad \\uD800 escape",\n    "devDependencies": { "@magicsunday/coding-standard": "^3.0.0" }\n}\n' > "$d/package.json"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "a package.json with an unpaired surrogate elsewhere in the manifest is reported" "package.json: is not valid JSON"

# The accepting twin, so the case above cannot be satisfied by rejecting
# every package.json outright.
d="$(mk_case js-package-json-paired-surrogate)"
printf '{\n    "name": "consumer",\n    "description": "an emoji: \\uD83D\\uDE00",\n    "devDependencies": { "@magicsunday/coding-standard": "^3.0.0" }\n}\n' > "$d/package.json"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base"\n}\n' > "$d/tsconfig.json"
assert_accepts_js "$d" "a package.json with a properly paired surrogate elsewhere in the manifest is accepted"

# A comment placed inside a token must not fuse the halves back together.
d="$(mk_js_case ts-comment-in-token)"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "strict": tr/* x */ue }\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with a comment splitting a token" "tsconfig.json: not valid JSON(C)"

# The `\\.` branch of the string pattern, driven by the only input that needs it:
# an ESCAPED QUOTE followed by a comment opener. A backslash pair alone does not
# discriminate — `"src\\vendor\\*"` carries no quote, so a naive `"[^"]*"` consumes
# it exactly as the escape-aware form does and the case is green either way. With
# the escape branch the string is consumed whole and the file parses; without it
# the pass mis-terminates the string at the `\"`, reads ` // b"] }` as a comment,
# strips to end of line and the gate reports the config as unparseable.
d="$(mk_js_case ts-escaped-quote)"
cat > "$d/tsconfig.json" <<'JSON'
{
    "extends": "@magicsunday/coding-standard/tsconfig/base.json",
    "compilerOptions": {
        "paths": { "@app/*": ["a \" // b"] }
    }
}
JSON
assert_accepts_js "$d" "tsconfig.json with an escaped quote before a comment opener inside a string"

# The JSONC tolerance must not extend to genuinely broken input: an unclosed
# object has to be reported, not read as an empty config that passes every
# subsequent `?? null` check.
d="$(mk_js_case ts-malformed)"
printf '{\n    "compilerOptions": { "strict": true\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json that is not valid JSON(C)" "tsconfig.json: not valid JSON(C)"

# --- the adoption gate -------------------------------------------------------
#
# Four existing consumers ship a standalone biome.json today and pull this
# package over Composer. If the extends contract keyed on the file being present,
# their next `composer update` would red a build for a link they never claimed —
# and they could not fix it, because a consumer cannot pin an npm tag that does
# not exist yet. So the assertions key on the npm dependency being declared.
mk_unadopted_case() {
    local dir
    dir="$(mk_case "$1")"
    printf '{\n    "name": "fixture",\n    "devDependencies": { "typescript": "^7.0.2" }\n}\n' > "$dir/package.json"
    printf '%s' "$dir"
}

d="$(mk_unadopted_case js-unadopted-biome)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_accepts_js "$d" "standalone biome.json in a repo that has not adopted the npm package"

d="$(mk_unadopted_case js-unadopted-tsconfig)"
printf '{\n    "compilerOptions": { "strict": false }\n}\n' > "$d/tsconfig.json"
assert_accepts_js "$d" "standalone tsconfig.json in a repo that has not adopted the npm package"

# A repo with no package.json at all is the same case, and is the shape every
# PHP-only consumer has.
d="$(mk_case js-no-package-json)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_accepts_js "$d" "standalone biome.json with no package.json at all"

# A parse failure, unlike the `"//"` key, IS gated on adoption — this reader is
# not Biome's, so it can reject a file the real tool accepts, and reporting that
# to a repository which never claimed the link is the failure the adoption gate
# exists to prevent.
d="$(mk_unadopted_case js-unadopted-malformed)"
printf '{\n    "linter": { "enabled": true\n' > "$d/biome.json"
assert_accepts_js "$d" "malformed biome.json in a repo that has not adopted the npm package"

d="$(mk_js_case js-adopted-malformed)"
printf '{\n    "linter": { "enabled": true\n' > "$d/biome.json"
assert_rejects_js "$d" "malformed biome.json once the npm package is declared" "biome.json: not valid JSON(C)"

# Both tools read a BOM-prefixed config and honour it; json_decode does not. A
# reader stricter than the tools reports a defect in a file that loads fine.
d="$(mk_js_case biome-bom)"
printf '\xEF\xBB\xBF' > "$d/biome.json"
cat "$FIXTURE/biome.json" >> "$d/biome.json"
assert_accepts_js "$d" "biome.json saved with a UTF-8 BOM"

d="$(mk_js_case ts-bom)"
printf '\xEF\xBB\xBF' > "$d/tsconfig.json"
cat "$FIXTURE/tsconfig.json" >> "$d/tsconfig.json"
assert_accepts_js "$d" "tsconfig.json saved with a UTF-8 BOM"

# The reject twin: a SECOND BOM, left over once the strip above already
# consumed the first. json_decode() sees that leftover BOM as unparseable
# JSON syntax and rejects it ("Syntax error", verified against the buildbox)
# — while TextDecoder's default (ignoreBOM: false — the option name reads
# backwards; false is the one that silently CONSUMES a leading BOM from its
# decoded result) would strip the second BOM too, leaving JSON.parse()
# nothing left to reject. Node-only: PHP's own $stripBom only ever strips
# once, so it already rejects this without any change on that side.
d="$(mk_js_case ts-double-bom)"
printf '\xEF\xBB\xBF\xEF\xBB\xBF' > "$d/tsconfig.json"
cat "$FIXTURE/tsconfig.json" >> "$d/tsconfig.json"
assert_rejects_js "$d" "tsconfig.json with a second, leftover BOM once the first is stripped" "tsconfig.json: not valid JSON(C)"

# package.json shares decodeJsonLikePhp with biome.json/tsconfig.json (see
# that function's docblock), so the same leftover-BOM parity gap applies to
# the npm probe's own read.
d="$(mk_case js-package-json-double-bom)"
printf '\xEF\xBB\xBF\xEF\xBB\xBF{\n    "name": "consumer",\n    "devDependencies": { "@magicsunday/coding-standard": "^3.0.0" }\n}\n' > "$d/package.json"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base"\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "a package.json with a second, leftover BOM once the first is stripped is reported" "package.json: is not valid JSON"

# The probe that decides whether any of this runs must not fail open: an
# unreadable package.json would otherwise switch the entire JS/TS contract off
# while the gate still printed OK.
d="$(mk_case js-package-json-malformed)"
printf '{\n    "devDependencies": {\n' > "$d/package.json"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "an unparseable package.json is reported, not treated as non-adoption" "package.json: is not valid JSON"

# A package.json with a BOM is readable by npm, so it must not be reported — and
# the dependency inside it must still be seen.
d="$(mk_case js-package-json-bom)"
printf '\xEF\xBB\xBF{\n    "devDependencies": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' > "$d/package.json"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "a BOM-prefixed package.json is still read for the dependency" "biome/base.json"

# The exception that proves the gate is not simply switched off: a `"//"` key
# makes the config unloadable for Biome whether or not it extends anything, so
# that one check stays unconditional.
# The oversize verdict is UNCONDITIONAL — a file this gate cannot read in full is a
# defect whoever wrote it, so it is not gated on adoption the way a parse failure is.
# Every other unconditional arm has an unadopted twin and these two did not: both
# size-cap cases above adopt, so `elseif ($adopted && is_int($biomeJson))` stayed
# green.
d="$(mk_unadopted_case biome-oversize-unadopted)"
php -r 'file_put_contents($argv[1], "{\"a\":" . str_repeat("\\\"", 70000));' "$d/biome.json"
assert_rejects_js "$d" "an oversized biome.json is reported in a repository that never adopted the package" \
    "larger than the 131072 bytes this gate checks"

d="$(mk_unadopted_case js-unadopted-note-key)"
cat > "$d/biome.json" <<'JSON'
{
    "//": "shared config for this repo",
    "linter": { "enabled": true }
}
JSON
assert_rejects_js "$d" "\"//\" key is reported even without adoption" '`"//"` key'

# And the counterpart: once the dependency IS declared, the full contract is back.
d="$(mk_js_case js-adopted-no-extends)"
printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
assert_rejects_js "$d" "biome.json without extends once the npm package is declared" "biome/base.json"

# --- the pinned strict flags, derived from the shipped base ------------------
#
# The gate pins a hand-written list of compilerOptions; tsconfig/base.json ships
# a set. Today they agree, and nothing holds them there — a strictness flag added
# to the shared base later would go unpinned in silence, which is precisely what
# this gate exists to prevent one layer down. So the cases are DERIVED from the
# base rather than listed here: every flag it ships is either pinned or a named
# ergonomics exception, and neither list may outlive the other.
ergonomics=(esModuleInterop resolveJsonModule skipLibCheck)

# The options `strict: true` switches on as a group. They are NOT written into
# tsconfig/base.json — `strict` implies them — so the base-derived loop below
# cannot generate their cases, and the "pinned but not shipped by the base" check
# would report every one of them as stale. Held here independently of the gate's
# own list so the two must agree in both directions rather than one copying the
# other. Membership is taken from TypeScript's documentation of `strict`, not
# derived — an earlier claim to the contrary was retracted in the gate, whose
# comment names the per-flag compile that could falsify it.
strict_family=(
    alwaysStrict
    noImplicitAny
    noImplicitThis
    strictBindCallApply
    strictBuiltinIteratorReturn
    strictFunctionTypes
    strictNullChecks
    strictPropertyInitialization
    useUnknownInCatchVariables
)



# The decode is guarded rather than indexed straight into. tsconfig is JSONC by
# specification, so the shipped base gaining a comment is a legitimate edit — and
# then `json_decode` returns null, `null["compilerOptions"]` raises a warning, and
# that warning is written to STDOUT by the CLI SAPI, so mapfile reads it as data.
# Measured: the flag list then carries `Warning: Trying to access array offset on
# null in Command line code on line 2` as an entry, and the cases below run with
# that as a flag name. The empty-scan guard underneath never fires, because the
# array is not empty — it is wrong.
#
# `ci:test:json` rejects a commented base first and would stop the CI chain before
# this file runs; someone invoking this script directly gets no such protection.
mapfile -t base_flags < <(php -r '
    $decoded = json_decode(file_get_contents($argv[1]), true);

    if (!is_array($decoded) || !is_array($decoded["compilerOptions"] ?? null)) {
        fwrite(STDERR, "tsconfig/base.json did not decode as JSON carrying compilerOptions\n");
        exit(1);
    }

    foreach ($decoded["compilerOptions"] as $name => $value) {
        if ($value === true) {
            echo $name, "\n";
        }
    }
' "$ROOT/tsconfig/base.json")

# A scan that collected nothing must not read as "everything is classified".
if [ "${#base_flags[@]}" -eq 0 ]; then
    report_failure 'read no compilerOptions flags from tsconfig/base.json'
fi

# The gate's own list, so the lockstep is a real bijection rather than a
# one-directional check. Without it a flag can outlive the base: dropped from
# tsconfig/base.json it generates no case, while the gate keeps rejecting every
# consumer that turns it off — a red for a flag the shared base no longer sets.
mapfile -t pinned_flags < <(
    sed -n '/\$pinnedFlags = \[/,/\];/p' "$ROOT/bin/check-consumer-config.php" \
        | grep -oE "'[A-Za-z]+'" \
        | tr -d "'"
)

if [ "${#pinned_flags[@]}" -eq 0 ]; then
    report_failure 'read no $pinnedFlags entries from bin/check-consumer-config.php'
fi

# The structural bar, independent of the extractor's own vocabulary. `'[A-Za-z]+'`
# cannot see a flag carrying a digit or an underscore, and BOTH direction checks
# below iterate over what it extracted — so such a flag generates no case and
# nothing reddens. Counting the quoted entries in the same sed range answers a
# question the name pattern cannot. Occurrences, not lines: two entries on one
# physical line read as one under `grep -c`, which is the silent direction.
# count_matches (defined above) survives a zero-match block; see its own
# docblock for why.
pinned_flags_block="$(sed -n '/\$pinnedFlags = \[/,/\];/p' "$ROOT/bin/check-consumer-config.php")"
pinned_flags_declared="$(count_matches "$pinned_flags_block" "'[^']*'")"

if [ "$pinned_flags_declared" -ne "${#pinned_flags[@]}" ]; then
    report_failure "the \$pinnedFlags block declares $pinned_flags_declared entries but this harness parsed ${#pinned_flags[@]} — widen the extractor rather than leaving one unexercised"
fi

for flag in "${base_flags[@]}"; do
    d="$(mk_js_case "ts-flag-$flag")"
    printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "%s": false }\n}\n' "$flag" > "$d/tsconfig.json"

    if contains "$flag" "${ergonomics[@]}"; then
        assert_accepts_js "$d" "tsconfig.json turning the ergonomics flag $flag off"
    else
        assert_rejects_js "$d" "tsconfig.json turning the shared strict flag $flag off" "compilerOptions.$flag"
    fi
done

# The other two directions, so neither list can outlive the base it describes.
for flag in "${ergonomics[@]}"; do
    if ! contains "$flag" "${base_flags[@]}"; then
        report_failure "ergonomics exception $flag is no longer shipped by tsconfig/base.json"
    fi
done

for flag in "${pinned_flags[@]}"; do
    if contains "$flag" "${strict_family[@]}"; then
        continue
    fi

    if ! contains "$flag" "${base_flags[@]}"; then
        report_failure "pinned flag $flag is no longer shipped by tsconfig/base.json"
    fi
done

# A consumer may write any family member back individually, and TypeScript treats
# the specific option as an override of the umbrella — so `strict: true` alongside
# `strictNullChecks: false` compiles code that `strict: true` alone rejects.
# Pinning only `strict` therefore pins nothing.
for flag in "${strict_family[@]}"; do
    if ! contains "$flag" "${pinned_flags[@]}"; then
        report_failure "the gate no longer pins the strict-family flag $flag"
    fi

    d="$(mk_js_case "ts-strict-family-$flag")"
    printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "strict": true, "%s": false }\n}\n' "$flag" > "$d/tsconfig.json"
    assert_rejects_js "$d" "tsconfig.json overriding the strict-family flag $flag while keeping strict" "compilerOptions.$flag"
done

# `strict` itself has to remain in the base, or the family above is switched on by
# nothing and pinning its members guards a default rather than a shared decision.
if ! contains strict "${base_flags[@]}"; then
    report_failure 'tsconfig/base.json no longer sets `strict`, so the strict-family pins guard nothing'
fi

# --- the remaining branches --------------------------------------------------

# The adoption probe reads four dependency sections; only devDependencies (used
# throughout the rest of this file) was exercised before this loop existed.
# peerDependencies included: npm >=7 auto-installs an unmet peer with no other
# declaration needed, so a consumer (or an adversarial PR) moving the entry there
# alone still has the package on disk and the shared configs still apply.
for section in dependencies optionalDependencies peerDependencies; do
    d="$(mk_case "js-adopted-via-$section")"
    printf '{\n    "%s": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' "$section" > "$d/package.json"
    printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
    assert_rejects_js "$d" "the npm dependency declared under $section counts as adoption" "biome/base.json"
done

# PHP checks isset($json[$section]['@magicsunday/coding-standard']), which is
# false when the key is present but its value is null — a lookup keyed purely
# on Object.hasOwn() would instead read this as adopted, and then wrongly
# enforce the extends/strict-flag contract on a repository that never
# resolved a real dependency here.
d="$(mk_case js-dependency-value-null)"
printf '{\n    "devDependencies": { "@magicsunday/coding-standard": null }\n}\n' > "$d/package.json"
printf '{\n    "linter": { "enabled": false }\n}\n' > "$d/biome.json"
assert_accepts_js "$d" "an explicit null dependency value does not count as adoption"

# Every overrides case so far put the violation at index 0, so a walk that only
# inspected the first entry would pass them all — and the index in the message
# was never proven to track the real one.
d="$(mk_js_case biome-override-second-entry)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": [
        { "includes": ["tests/**"], "linter": { "rules": { "suspicious": { "noExplicitAny": "off" } } } },
        { "includes": ["**"], "linter": { "enabled": false } }
    ]
}
JSON
assert_rejects_js "$d" "a violation in the SECOND overrides entry is reported with its index" "overrides[1].linter.enabled"

# jscpd's `format` as a bare string rather than a list: the deny-list loop would
# skip it, so the spelling that scans nothing would pass through the escape
# hatch the check exists to close.
d="$work/jscpd-format-scalar"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": "ts"/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a scalar format instead of a list" 'Use "typescript"'

# A repository with NO JS config is never probed, so a broken package.json there
# is not this gate's business — it would be a red for a contract that has nothing
# to check. Deliberately OUTSIDE the root guard below: it needs no permissions
# trick, and it is the only case covering that arm.
d="$(mk_case php-only-broken-package-json)"
printf '{\n    "devDependencies": {\n' > "$d/package.json"
assert_accepts_js "$d" "a PHP-only repo is not probed for the JS/TS contract at all"

# The other arm of the same condition: a TypeScript-only consumer has no
# biome.json, so a probe keyed on that alone would leave the whole tsconfig
# contract — and the derived pinned-flag lockstep — silently switched off.
d="$(mk_case ts-only-adopted)"
printf '{\n    "devDependencies": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' > "$d/package.json"
printf '{\n    "extends": "@magicsunday/coding-standard/tsconfig/base.json",\n    "compilerOptions": { "strict": false }\n}\n' > "$d/tsconfig.json"
assert_rejects_js "$d" "a TypeScript-only consumer is still held to the tsconfig contract" "\`compilerOptions.strict\`"

# A path that is not a directory is a usage error, not drift — a distinct verdict
# with nothing pinning it, so the block could be deleted and every case stayed
# green while a mistyped path reported "phpunit.xml: missing" against a directory
# that does not exist.
assert_usage_error_js "$work/does-not-exist" "a path that is not a directory" "Not a directory"

# An unreadable config is not a syntax error, and reporting it as one sends the
# reader to fix the wrong thing. Every read site gets a case, because two of them
# fail OPEN without one: an unreadable .jscpd.json leaves the gate printing OK for
# a config it never read, and an unreadable package.json switches the ENTIRE JS/TS
# contract off while still printing OK.
#
# Skipped for uid 0: root bypasses DAC, so mode 000 stays readable, the gate
# correctly accepts and every one of these would read as a false regression. CI
# runs non-root, so the branches stay exercised there — the skip line is printed
# rather than silent so the omission is visible when it happens.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-config cases\n'
else
    d="$(mk_unadopted_case js-unreadable-biome)"
    cp "$FIXTURE/biome.json" "$d/biome.json"
    chmod 000 "$d/biome.json"
    assert_rejects_js "$d" "an unreadable biome.json reports as unreadable, not as malformed" "biome.json: exists but cannot be read"
    chmod 644 "$d/biome.json"

    d="$(mk_js_case js-unreadable-tsconfig)"
    chmod 000 "$d/tsconfig.json"
    assert_rejects_js "$d" "an unreadable tsconfig.json reports as unreadable, not as malformed" "tsconfig.json: exists but cannot be read"
    chmod 644 "$d/tsconfig.json"

    # The same file in a NON-adopting repository. The biome case above is written
    # this way already; the tsconfig one was reachable only through the adoption
    # gate, so the identical defect was reported or silent depending on which of
    # the two configs it sat in. An unopenable file is a defect on its own terms —
    # no reader tolerance is in play — so neither of them waits for adoption.
    d="$(mk_unadopted_case js-unreadable-tsconfig-unadopted)"
    cp "$FIXTURE/tsconfig.json" "$d/tsconfig.json"
    chmod 000 "$d/tsconfig.json"
    assert_rejects_js "$d" "an unreadable tsconfig.json is reported even without adoption" "tsconfig.json: exists but cannot be read"
    chmod 644 "$d/tsconfig.json"

    # Fails open without the guard: the gate would print OK for a config it never read.
    d="$work/jscpd-unreadable"; jscpd_fixture "$d"
    chmod 000 "$d/.jscpd.json"
    assert_rejects "$d" "an unreadable .jscpd.json is reported rather than skipped" ".jscpd.json: exists but cannot be read"
    chmod 644 "$d/.jscpd.json"

    # Fails open too, and wider: this one silences the whole JS/TS contract.
    d="$(mk_case js-package-json-unreadable)"
    printf '{\n    "devDependencies": { "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0" }\n}\n' > "$d/package.json"
    printf '{\n    "linter": { "enabled": true }\n}\n' > "$d/biome.json"
    chmod 000 "$d/package.json"
    assert_rejects_js "$d" "an unreadable package.json does not switch the JS/TS contract off" "package.json: exists but cannot be read"
    chmod 644 "$d/package.json"


    # These three degraded differently: the read failure WAS reported, and then the
    # content assertions ran against an empty string and fabricated more.
    for pair in phplint.yml:.phplint.yml editorconfig:.editorconfig; do
        template="${pair%%:*}"
        target="${pair##*:}"
        d="$(mk_case "unreadable-$template")"
        cp "$ROOT/templates/$template" "$d/$target"
        chmod 000 "$d/$target"
        assert_rejects "$d" "an unreadable $target reports only that it cannot be read" "$target: exists but cannot be read"
        assert_reports_once "$d" "an unreadable $target fabricates no content drift" "$target"
        chmod 644 "$d/$target"
    done

    d="$(mk_case unreadable-deptrac)"
    printf 'imports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n' > "$d/deptrac.yaml"
    chmod 000 "$d/deptrac.yaml"
    assert_rejects "$d" "an unreadable deptrac.yaml reports only that it cannot be read" "deptrac.yaml: exists but cannot be read"
    assert_reports_once "$d" "an unreadable deptrac.yaml fabricates no content drift" "deptrac.yaml"
    chmod 644 "$d/deptrac.yaml"

    # phpunit.xml is the one REQUIRED file, and libxml returns the same false for
    # unreadable as for malformed — so this used to read as a syntax error.
    d="$(mk_case unreadable-phpunit)"
    chmod 000 "$d/phpunit.xml"
    assert_rejects "$d" "an unreadable phpunit.xml is not reported as malformed XML" "phpunit.xml: exists but cannot be read"
    chmod 644 "$d/phpunit.xml"
fi

# The silent-skip arms: a malformed sub-node must not quietly drop the checks
# below it in a gate whose whole purpose is not to pass silently.
d="$(mk_js_case biome-override-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "overrides": ["not-an-object", { "includes": ["**"], "linter": { "enabled": false } }]
}
JSON
assert_rejects_js "$d" "a non-object overrides entry does not hide the next one" "overrides[1].linter.enabled"

# A mis-typed per-language block must not stop the walk reporting what else it
# finds. Stated as what it pins, not as what it looks like it pins: removing the
# `is_array($languageScope)` guard above the push does NOT change this verdict,
# because the `?? null` on the read below already absorbs a string subscript. The
# guard is belt-and-braces there; what this case does discriminate is that the walk
# survives the shape at all and still names the real drift.
d="$(mk_js_case biome-language-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "javascript": "off",
    "linter": { "enabled": false }
}
JSON
assert_rejects_js "$d" "biome.json whose per-language block is a string, not an object" \
    "\`linter.enabled\` must not be false"

# Which spelling wins when both exist. Every other `.jsonc` case removes the `.json`
# first, so the discovery ORDER was never driven: the gate reads biome.json and stops.
# The `.jsonc` here carries a defect the report would name if it were the file read.
d="$(mk_js_case biome-both-spellings)"
cat > "$d/biome.jsonc" <<'JSONC'
{
    // this file must not be the one the gate reads
    "linter": { "enabled": false }
}
JSONC
assert_accepts_js "$d" "biome.json is read in preference to a biome.jsonc beside it"

d="$(mk_js_case biome-rules-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "enabled": false, "rules": "off" }
}
JSON
assert_rejects_js "$d" "a scalar linter.rules does not hide the enabled check" "linter.enabled"

d="$(mk_js_case biome-group-not-an-object)"
cat > "$d/biome.json" <<'JSON'
{
    "extends": ["@magicsunday/coding-standard/biome/base.json"],
    "linter": { "rules": { "suspicious": "info", "correctness": { "preset": "none" } } }
}
JSON
assert_rejects_js "$d" "a scalar rule group does not hide the next group" "linter.rules.correctness.preset"

d="$work/jscpd-format-non-string"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": ["console-full"],\n    "format": [5, "ts"]/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a non-string format entry beside a bad one" 'Use "typescript"'

# Every entry of the deny-list table, so a wrong canonical name in any row ships
# pinned rather than unexercised.
# READ from the gate rather than retyped, the same way the language table above
# is: a copy catches an entry the gate loses and never one it gains, so adding
# `'jsonc' => 'json'` there would ship an unexercised message — and an unproven
# canonical name the gate then tells consumers to use — with the suite green.
gate_spelling_block="$(sed -n "/\$extensionSpellings = \[/,/\];/p" "$ROOT/bin/check-consumer-config.php")"
mapfile -t gate_spellings < <(grep -oE "'[a-z0-9_-]+' *=> *'[a-z0-9_-]+'" <<<"$gate_spelling_block" | tr -d "' " | sed 's/=>/:/')

if [ "${#gate_spellings[@]}" -eq 0 ]; then
    report_failure 'read no extension spellings from the gate — the jscpd deny-list lockstep did not run'
fi

# Same guard as the language table, for the same reason: with `[a-z]+` an entry
# such as `'es6' => 'javascript'` was invisible to the extractor, both direction
# checks below passed on the shortened list, and no behavioural fixture was
# generated for it. Counting `=>` in the block against what came out is
# independent of whatever the pattern happens to spell.
# Occurrences, not lines. `grep -c` counts selected LINES while the extractor above
# fills its array from `grep -oE`, i.e. matches — so two table entries written on one
# physical line read as 1 == 1 and the second ships unexercised. That is the silent
# direction, and it is the same choice harness.sh records for its own counter.
# count_matches (defined above) survives a zero-match block; see its own
# docblock for why.
gate_spelling_declared="$(count_matches "$gate_spelling_block" '=>')"

if [ "$gate_spelling_declared" -ne "${#gate_spellings[@]}" ]; then
    report_failure "the spelling table carries $gate_spelling_declared entries but this harness parsed ${#gate_spellings[@]} — widen the extractor rather than leaving a row unexercised"
fi

proven_spellings=(js:javascript mjs:javascript cjs:javascript ts:typescript mts:typescript cts:typescript)

for pair in "${gate_spellings[@]}"; do
    if ! contains "$pair" "${proven_spellings[@]}"; then
        report_failure "the gate now rejects the spelling \`${pair%%:*}\`, which this harness does not name — add it rather than leaving the entry unexercised"
    fi
done

for pair in "${proven_spellings[@]}"; do
    if ! contains "$pair" "${gate_spellings[@]}"; then
        report_failure "the gate no longer rejects the spelling \`${pair%%:*}\` as \`${pair##*:}\`, which this harness proves — the entry was dropped or its canonical name changed"
    fi
done

for pair in "${gate_spellings[@]}"; do
    spelling="${pair%%:*}"
    canonical="${pair##*:}"
    d="$work/jscpd-format-$spelling"; jscpd_fixture "$d"
    sed -i "s/\"reporters\": \[\"console-full\"\]/\"reporters\": [\"console-full\"],\n    \"format\": [\"php\", \"$spelling\"]/" "$d/.jscpd.json"
    assert_rejects "$d" ".jscpd.json using the \"$spelling\" extension as a format name" "Use \"$canonical\""
done

# `reporters` takes the same list-vs-scalar mistake as `format`, and without the
# is_array guard the gate dies on in_array() with a TypeError instead of
# reporting — a crash where a finding belongs.
d="$work/jscpd-reporters-scalar"; jscpd_fixture "$d"
sed -i 's/"reporters": \["console-full"\]/"reporters": "console-full"/' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json with a scalar reporters instead of a list" '`reporters` must contain'

# The "must be present" half of both thresholds: every other fixture always
# carries the key, so only the ">" comparison was exercised.
d="$work/jscpd-no-mintokens"; jscpd_fixture "$d"
sed -i '/"minTokens"/d' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json omitting minTokens entirely" "minTokens"

d="$work/jscpd-no-minlines"; jscpd_fixture "$d"
sed -i '/"minLines"/d' "$d/.jscpd.json"
assert_rejects "$d" ".jscpd.json omitting minLines entirely" "minLines"

# A repo with no JS at all must stay accepted — these configs are optional.
d="$(mk_case no-js)"
assert_accepts_js "$d" "PHP-only repo without biome.json or tsconfig.json"

verdict
