#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture cases for tests/lint-shell.sh.
#
# The gate it drives was added because an aborted harness prints no failure
# marker and reads as a clean run. Shipped without cases it had that property
# itself: run against this repository alone every harness parses, the FAILED
# branch never executes, and `if bash -n "$script" || true; then` produces
# byte-identical output and exit 0 — a gate that had stopped checking would look
# exactly like a gate finding nothing wrong.
#
# So the gate gets what it asks of everything else: a run in which it MUST report.

set -euo pipefail

# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GATE="$ROOT/tests/lint-shell.sh"

work="$(mktemp -d)"
work="$(CDPATH= cd -- "$work" && pwd)"
# The mode-000 fixture below is a DIRECTORY, and `rm -rf` cannot traverse one —
# it fails, the whole tree survives as an undeletable /tmp leftover, and under
# `set -e` a failing EXIT trap additionally rewrites an all-passed run into a red
# one. Restoring the mode in the trap rather than only in the straight line makes
# the cleanup independent of where the run stops.
trap 'chmod -R u+rwX "$work" 2>/dev/null || true; rm -rf "$work"' EXIT

fails=0

# The gate's exit code carries the verdict, so it is captured rather than piped:
# under `set -o pipefail` a `bash … | grep` would report the deliberately failing
# run as a harness error.

assert_accepts() { # <dir> <name>
    local out rc
    out="$(bash "$GATE" "$1" 2>&1)" && rc=0 || rc=$?

    if [ "$rc" -eq 0 ]; then
        printf 'ok (accepted): %s\n' "$2"
    else
        printf 'FAILED (should have been accepted): %s\n%s\n' "$2" "$out" >&2
        fails=$((fails + 1))
    fi
}

assert_rejects() { # <dir> <name> <substring the report must carry>
    local out rc
    out="$(bash "$GATE" "$1" 2>&1)" && rc=0 || rc=$?

    # Exactly 1, the gate's own verdict — 2 is its usage error, and anything
    # else is a crash. A gate that dies on its way to reporting has not reported.
    if [ "$rc" -ne 1 ]; then
        printf 'FAILED (expected the syntax verdict, got exit %s): %s\n%s\n' "$rc" "$2" "$out" >&2
        fails=$((fails + 1))
    elif grep -qF "$3" <<<"$out"; then
        printf 'ok (rejected on the tested violation): %s\n' "$2"
    else
        printf 'FAILED (rejected for the wrong reason): %s\nexpected to find: %s\n%s\n' "$2" "$3" "$out" >&2
        fails=$((fails + 1))
    fi
}

# A script that parses is accepted. Without this, a gate that rejected
# everything would satisfy every case below.
d="$work/valid"
mkdir -p "$d"
printf '#!/usr/bin/env bash\nset -euo pipefail\n\nmain() {\n    printf "hello\\n"\n}\n\nmain\n' > "$d/good.sh"
assert_accepts "$d" "a script that parses"

# The case the gate exists for, in the exact shape that bit this branch: an
# apostrophe inside a comment within an embedded `node -e '…'` block closes the
# surrounding single quote, and bash dies at that line having run nothing.
d="$work/apostrophe"
mkdir -p "$d"
cat > "$d/broken.sh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail

node -e '
// This comment carries an apostrophe: the range's first version.
process.stdout.write("never reached");
'
SCRIPT
assert_rejects "$d" "an apostrophe closing an embedded node -e block is reported" "FAILED   broken.sh"

# The plainer shape, so the case above is not the only thing standing between
# this gate and a syntax error.
d="$work/unclosed"
mkdir -p "$d"
printf '#!/usr/bin/env bash\nmain() {\n    printf "no closing brace\\n"\n' > "$d/unclosed.sh"
assert_rejects "$d" "an unclosed function body is reported" "FAILED   unclosed.sh"

# The report names the FILE, or a run over a dozen harnesses leaves the reader
# to find which one died.
d="$work/named"
mkdir -p "$d"
printf '#!/usr/bin/env bash\nset -euo pipefail\nprintf "fine\\n"\n' > "$d/fine.sh"
printf '#!/usr/bin/env bash\nif [ 1 -eq 1 ]; then\n' > "$d/incomplete.sh"
# The gate's OWN line, verbatim — `bash -n` writes its diagnostic as an absolute
# path followed by `: line N:`, so it can never produce the `FAILED` marker with
# the root-relative name. Asserting the bare filename pinned nothing: it appears
# in bash's message too, so deleting the gate's report line left this case green
# while the run no longer named which script died. Measured.
assert_rejects "$d" "the report names the offending script rather than only the error" "FAILED   incomplete.sh"

# The vacuity guard. A root with no scripts at all must report rather than
# congratulate itself — this is the arm that caught a `git ls-files` returning
# empty inside a container, which was indistinguishable from success.
d="$work/empty"
mkdir -p "$d"
assert_rejects "$d" "a root carrying no shell scripts is reported, not passed vacuously" "matched nothing"

# The prune list: a script inside a vendor tree is not this repository's to lint,
# and a syntax error in one must not be reported as ours. Paired with a valid
# script beside it, so the run has something legitimate to find and the accept
# cannot come from the vacuity guard instead.
# One directory per prune arm, at its OWN depth: a broken script under
# `.build/vendor/` is pruned at `.build` and says nothing about the `vendor`
# arm, which is how that arm came to be unreachable — deleting `-o -name vendor`
# left every case green. A repository using Composer's default vendor-dir has
# `vendor/` at the root, so the arm is not decorative.
d="$work/pruned"
mkdir -p "$d/.build/nested" "$d/vendor/foreign" "$d/node_modules/other"
printf '#!/usr/bin/env bash\nprintf "ours\\n"\n' > "$d/ours.sh"
printf '#!/usr/bin/env bash\nfunction broken( {\n' > "$d/.build/nested/theirs.sh"
printf '#!/usr/bin/env bash\nfunction broken( {\n' > "$d/vendor/foreign/theirs.sh"
printf '#!/usr/bin/env bash\ncase x in\n' > "$d/node_modules/other/theirs.sh"
assert_accepts "$d" "a broken script inside .build, vendor or node_modules is not this repository's to report"

# A scan that could not see the whole tree. `find` prints `Permission denied` for
# a directory it cannot descend into and carries on, so without this the gate
# checked whatever it did reach and reported OK — "did not look" reading as
# "found nothing", which is the failure this gate exists to rule out one step
# milder than an empty listing. Skipped as root, where mode 000 denies nothing.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny traversal): the incomplete-scan case\n'
else
    d="$work/unreadable-subdir"
    mkdir -p "$d/private"
    printf '#!/usr/bin/env bash\nprintf "fine\\n"\n' > "$d/visible.sh"
    printf '#!/usr/bin/env bash\nfunction broken( {\n' > "$d/private/hidden.sh"
    chmod 000 "$d/private"
    assert_rejects "$d" "a directory the scan cannot descend into is reported, not silently skipped" "scan is incomplete"
    chmod 755 "$d/private" || true
fi

# The usage verdict, distinct from the syntax one, so a mistyped path cannot read
# as a repository full of broken scripts.
out="$(bash "$GATE" "$work/does-not-exist" 2>&1)" && rc=0 || rc=$?

if [ "${rc:-0}" -ne 2 ]; then
    printf 'FAILED (expected the usage verdict, got exit %s): a nonexistent root\n%s\n' "${rc:-0}" "$out" >&2
    fails=$((fails + 1))
elif grep -qF 'Not a directory' <<<"$out"; then
    printf 'ok (usage error on the tested condition): a nonexistent root is a usage error, not a syntax verdict\n'
else
    printf 'FAILED (exit 2, but not for the tested reason): a nonexistent root\n%s\n' "$out" >&2
    fails=$((fails + 1))
fi

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails" >&2
    exit 1
fi

printf '\nAll cases passed.\n'
