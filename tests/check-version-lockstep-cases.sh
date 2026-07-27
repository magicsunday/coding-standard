#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for the version lockstep gate.
#
# Run against this repository alone, the gate only ever takes the happy path —
# package.json and both README pins agree, so a green CI is indistinguishable
# from a gate that cannot fail. Turning `exit(1)` into `exit(0)`, dropping the
# `$failed` assignment or inverting the comparison would all stay green forever.
# These cases put it in each failing state on purpose.

set -euo pipefail

# CDPATH= because the target starts with neither /, ./ nor ../ and would
# otherwise be searched in CDPATH, resolving to a foreign tree.
root="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
gate="$root/tests/check-version-lockstep.php"

work="$(mktemp -d)"
work="$(CDPATH= cd -- "$work" && pwd)"
trap 'rm -rf "$work"' EXIT

fails=0

# The gate's exit code carries the verdict, so it is captured rather than piped:
# under `set -o pipefail` a `php … | grep` would report the deliberately failing
# run as a harness error.
assert_accepts() { # <dir> <name>
    local out rc
    out="$(php "$gate" "$1" 2>&1)" && rc=0 || rc=$?

    if [ "$rc" -eq 0 ]; then
        printf 'ok (accepted): %s\n' "$2"
    else
        printf 'FAILED (should have been accepted): %s\n%s\n' "$2" "$out" >&2
        fails=$((fails + 1))
    fi
}

assert_rejects() { # <dir> <name> <substring the report must carry>
    local out rc
    out="$(php "$gate" "$1" 2>&1)" && rc=0 || rc=$?

    if [ "$rc" -eq 0 ]; then
        printf 'FAILED (should have been rejected): %s\n' "$2" >&2
        fails=$((fails + 1))
    elif grep -qF "$3" <<<"$out"; then
        printf 'ok (rejected on the tested violation): %s\n' "$2"
    else
        printf 'FAILED (rejected for the wrong reason): %s\nexpected to find: %s\n%s\n' "$2" "$3" "$out" >&2
        fails=$((fails + 1))
    fi
}

mk_case() { # <name> <version> <readme body>
    local dir="$work/$1"
    mkdir -p "$dir"
    printf '{\n    "name": "@magicsunday/coding-standard",\n    "version": "%s"\n}\n' "$2" > "$dir/package.json"
    printf '%s\n' "$3" > "$dir/README.md"
    printf '%s' "$dir"
}

# The canon: package.json and both documented pins agree.
d="$(mk_case canon 1.7.0 'Install with

```shell
npm install --save-dev github:magicsunday/coding-standard#1.7.0
```

which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`.')"
assert_accepts "$d" "package.json and every README pin agree"

# The release that bumps two of the three copies — the case the gate exists for.
# The stale pin comes FIRST and a matching one after it, so the loop has to carry
# on past a mismatch: a gate that stopped at the first one would pass otherwise.
d="$(mk_case stale-pin 1.8.0 'which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`, install with

```shell
npm install --save-dev github:magicsunday/coding-standard#1.8.0
```')"
assert_rejects "$d" "a README pin left behind by a release" "MISMATCH"

# Two stale pins, so a gate reporting only the first or only the last is caught.
d="$(mk_case two-stale-pins 1.8.0 'github:magicsunday/coding-standard#1.7.0
and also
github:magicsunday/coding-standard#1.6.1')"
assert_rejects "$d" "the first of two stale pins is reported" "README.md:1 pins #1.7.0"
assert_rejects "$d" "the second of two stale pins is reported as well" "README.md:3 pins #1.6.1"

# The report has to name the line, or a README with many pins gives the reader
# nothing to go on. Asserted with the MISMATCH prefix, because the gate prints
# the same `README.md:<line>` shape on its OK path too.
d="$(mk_case names-the-line 1.8.0 'line one

github:magicsunday/coding-standard#1.7.0')"
assert_rejects "$d" "the mismatch names the README line" "MISMATCH  README.md:3"

# Deleting the instructions must not make the gate pass vacuously.
d="$(mk_case no-pin 1.7.0 'The install instructions moved elsewhere.')"
assert_rejects "$d" "a README documenting no pin at all" "documents no"

d="$(mk_case no-version '' 'github:magicsunday/coding-standard#1.7.0')"
printf '{\n    "name": "@magicsunday/coding-standard"\n}\n' > "$d/package.json"
assert_rejects "$d" "package.json without a version" "no string \`version\`"

# An IO failure must report as one rather than as a content defect: without the
# distinction, a missing file reads as "the README documents no pin".
d="$work/missing-readme"
mkdir -p "$d"
printf '{\n    "version": "1.7.0"\n}\n' > "$d/package.json"
assert_rejects "$d" "a missing README reports as unreadable, not as a content defect" "Cannot read"

d="$work/missing-package-json"
mkdir -p "$d"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_rejects "$d" "a missing package.json reports as unreadable" "Cannot read"

# The inline forms this repository's own prose uses. `\S` swallowed the trailing
# backtick and the closing paren, so a correct README reported a mismatch
# against a pin that only differed by punctuation.
d="$(mk_case backticked 1.7.0 'The pin is `github:magicsunday/coding-standard#1.7.0` today.')"
assert_accepts "$d" "a pin written inline in backticks"

d="$(mk_case parenthesised 1.7.0 'See the install section (github:magicsunday/coding-standard#1.7.0).')"
assert_accepts "$d" "a pin written inside parentheses"

# A documented placeholder is not a pin, and must not be compared as one — but
# a README carrying ONLY a placeholder still has no pin to check, so the
# vacuity guard is what reports it.
d="$(mk_case placeholder-only 1.7.0 'Install `github:magicsunday/coding-standard#<tag>` with the tag you want.')"
assert_rejects "$d" "a README carrying only a placeholder" "documents no"

d="$(mk_case placeholder-beside-pin 1.7.0 'Install `github:magicsunday/coding-standard#<tag>`, currently
github:magicsunday/coding-standard#1.7.0.')"
assert_accepts "$d" "a placeholder documented beside a real pin"

# A prerelease tag is still a version and must compare as one.
d="$(mk_case prerelease 1.8.0-rc.1 'npm install --save-dev github:magicsunday/coding-standard#1.8.0-rc.1')"
assert_accepts "$d" "a prerelease pin"

# The combination the two separate cases never exercised: a prerelease pin at the
# end of a sentence. The suffix class used to swallow the period, so a correct
# README reported a mismatch against itself.
d="$(mk_case prerelease-sentence 1.8.0-rc.1 'The current prerelease is github:magicsunday/coding-standard#1.8.0-rc.1.')"
assert_accepts "$d" "a prerelease pin at the end of a sentence"

d="$(mk_case build-metadata 1.7.0+build.5 'see github:magicsunday/coding-standard#1.7.0+build.5.')"
assert_accepts "$d" "a build-metadata pin at the end of a sentence"

d="$(mk_case prerelease-and-build 1.2.3-beta.1+build.5 'github:magicsunday/coding-standard#1.2.3-beta.1+build.5')"
assert_accepts "$d" "a pin carrying both a prerelease and build metadata"

# The other direction: a pin with trailing junk must not be truncated to a
# version that happens to match, certifying lockstep for a tag that does not
# exist. It is not a version, so the vacuity guard is what reports it.
d="$(mk_case trailing-junk 1.7.0 'npm install --save-dev github:magicsunday/coding-standard#1.7.0final')"
assert_rejects "$d" "a pin with trailing characters is not read as a bare version" "documents no"

# And the discriminator for the shape: a pin that differs only in its last
# segment must still be caught, so the looser match is not simply truncating.
d="$(mk_case near-miss 1.7.0 'npm install --save-dev github:magicsunday/coding-standard#1.7.1')"
assert_rejects "$d" "a pin differing only in the patch segment" "MISMATCH"

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
