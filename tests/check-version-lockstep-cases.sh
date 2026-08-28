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
. "$root/tests/harness.sh"
harness_workdir

gate="$root/tests/check-version-lockstep.php"

# The gate's exit code carries the verdict, so it is captured rather than piped:
# under `set -o pipefail` a `php … | grep` would report the deliberately failing
# run as a harness error.

mk_case() { # <name> <version> <readme body>
    local dir="$work/$1"
    mkdir -p "$dir"
    printf '{\n    "name": "@magicsunday/coding-standard",\n    "version": "%s"\n}\n' "$2" > "$dir/package.json"
    printf '%s\n' "$3" > "$dir/README.md"
    printf '%s' "$dir"
}

# Thin wrappers over the shared definitions in tests/harness.sh.
assert_accepts()         { harness_accepts         "$gate" "$@"; }
assert_rejects()         { harness_rejects         "$gate" "$@"; }
assert_usage_error()     { harness_usage_error     "$gate" "$@"; }
assert_report_is_inert() { harness_report_is_inert "$gate" "$@"; }

# The must-carry (fourth) argument of assert_report_is_inert, driven rather than
# asserted. The driver reports legitimately and is handed a scrubbed payload the
# report does NOT carry, so the arm raises the counter exactly once; delete the arm
# and the count drops to zero.
probe_inert_must_carry() {
    local d
    d="$(mk_case probe-inert-must-carry '1.7.0\n::error::forged' \
        'github:magicsunday/coding-standard#1.7.0')"
    assert_report_is_inert "$d" 'bookkeeping self-test — the must-carry argument' \
        'a scrubbed payload this report never prints'
}

harness_probe_reporters 1 probe_inert_must_carry \
    'harness_report_is_inert ignores its must-carry argument'

# The bar is derived, not remembered — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

# The canon: package.json and both documented pins agree.
d="$(mk_case canon 1.7.0 'Install with

```shell
npm install --save-dev github:magicsunday/coding-standard#1.7.0
```

which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`.')"
assert_accepts "$d" "package.json and every README pin agree"

# The release that bumps two of the three copies — the case the gate exists for.
# A MATCHING pin comes first and the stale one after it, which is the ordering that
# discriminates: a gate stopping after the first match — printing OK and never
# reaching the stale pin — passes every other case in this file. Verified by
# mutation: adding a `break` after the OK branch survives the whole suite without
# this ordering, and is caught with it.
d="$(mk_case stale-pin 1.8.0 'install with

```shell
npm install --save-dev github:magicsunday/coding-standard#1.8.0
```

which records `"@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.7.0"`.')"
assert_rejects "$d" "a README pin left behind by a release" "MISMATCH  README.md:7 pins #1.7.0"

# Two stale pins, so a gate reporting only the first or only the last is caught.
d="$(mk_case two-stale-pins 1.8.0 'github:magicsunday/coding-standard#1.7.0
and also
github:magicsunday/coding-standard#1.6.1')"
assert_rejects "$d" "the first of two stale pins is reported" "MISMATCH  README.md:1 pins #1.7.0"
assert_rejects "$d" "the second of two stale pins is reported as well" "MISMATCH  README.md:3 pins #1.6.1"

# The report has to name the line, or a README with many pins gives the reader
# nothing to go on. Asserted with the MISMATCH prefix, because the gate prints
# the same `README.md:<line>` shape on its OK path too.
d="$(mk_case names-the-line 1.8.0 'line one

github:magicsunday/coding-standard#1.7.0')"
assert_rejects "$d" "the mismatch names the README line" "MISMATCH  README.md:3"

# Deleting the instructions must not make the gate pass vacuously.
d="$(mk_case no-pin 1.7.0 'The install instructions moved elsewhere.')"
assert_rejects "$d" "a README documenting no pin at all" "documents no"

d="$work/no-version"
mkdir -p "$d"
printf '{\n    "name": "@magicsunday/coding-standard"\n}\n' > "$d/package.json"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_usage_error "$d" "package.json without a version" "no string \`version\`"

# The neighbouring cause, which used to land in the same message: a package.json
# JSON cannot read at all was reported as one carrying no `version` key, telling
# the reader to add a key to a file that has no keys.
d="$work/malformed-package-json"
mkdir -p "$d"
printf '{\n    "version":\n' > "$d/package.json"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_usage_error "$d" "a package.json that is not valid JSON is reported as unparseable, not as versionless" "package.json is not valid JSON"

# An IO failure must report as one rather than as a content defect: without the
# distinction, a missing file reads as "the README documents no pin".
d="$work/missing-readme"
mkdir -p "$d"
printf '{\n    "version": "1.7.0"\n}\n' > "$d/package.json"
assert_usage_error "$d" "a missing README reports as unreadable, not as a content defect" "/README.md."

d="$work/missing-package-json"
mkdir -p "$d"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_usage_error "$d" "a missing package.json reports as unreadable" "/package.json."

# The size cap on each of the two files this gate reads, neither driven before now:
# readCapped() is shared with the shipped gates, but this gate's own use of it — one
# call per file, both against MAX_LOCKSTEP_BYTES — had no fixture of its own, so a
# regression scoped to just these two call sites (reading unbounded, or comparing
# against the wrong constant) would have shipped silently. Content past the bound
# need not be valid JSON or a real pin: readCapped() reports the file as too large
# before either byte is ever interpreted.
d="$work/oversize-package-json"
mkdir -p "$d"
php -r 'file_put_contents($argv[1], str_repeat("x", 1048577));' "$d/package.json"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_usage_error "$d" "an oversized package.json is reported as too large, not as unparseable" \
    "package.json is larger than the 1048576 bytes"

d="$work/oversize-readme"
mkdir -p "$d"
printf '{\n    "version": "1.7.0"\n}\n' > "$d/package.json"
php -r 'file_put_contents($argv[1], str_repeat("x", 1048577));' "$d/README.md"
assert_usage_error "$d" "an oversized README.md is reported as too large, not as documenting no pin" \
    "README.md is larger than the 1048576 bytes"

# The two oversize arms above only prove content one byte PAST the bound is
# rejected — a mutation that shrinks the bound actually passed to readCapped()
# (not the MAX_LOCKSTEP_BYTES constant the message text quotes) survives both
# silently, because the printed message always names the untouched constant
# regardless of what bound was enforced: a 1048577-byte fixture still exceeds a
# mutated `MAX_LOCKSTEP_BYTES - 1` bound too, and "larger than the 1048576 bytes"
# is still what gets printed. The counterpart, AT the cap: content that must be
# read in FULL and matched, so a bound even one byte too small starts rejecting
# it instead — mirroring the jscpd-at-the-size-cap fixture in
# check-consumer-config-cases.sh, for this gate's own MAX_LOCKSTEP_BYTES.
d="$work/at-cap-package-json"
mkdir -p "$d"
harness_pad_json_to_cap 1048576 \
    "$(php -r 'echo json_encode(["name" => "@magicsunday/coding-standard", "version" => "1.7.0"]);')" \
    "$d/package.json"
printf 'github:magicsunday/coding-standard#1.7.0\n' > "$d/README.md"
assert_accepts "$d" "a package.json exactly at the size cap is still read in full and its version matched"

d="$work/at-cap-readme"
mkdir -p "$d"
printf '{\n    "version": "1.7.0"\n}\n' > "$d/package.json"
php -r '
    $pin = "github:magicsunday/coding-standard#1.7.0\n";
    $pad = 1048576 - strlen($pin);
    $out = str_repeat("x", $pad) . $pin;

    if (strlen($out) !== 1048576) {
        fwrite(STDERR, sprintf("fixture is %d bytes, not the cap\n", strlen($out)));
        exit(1);
    }

    file_put_contents($argv[1], $out);
' "$d/README.md"
assert_accepts "$d" "a README.md exactly at the size cap is still read in full and the pin matched"

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
for junk in final _hotfix /x; do
    d="$(mk_case "trailing-junk${junk//\//-}" 1.7.0 "npm install --save-dev github:magicsunday/coding-standard#1.7.0${junk}")"
    assert_rejects "$d" "a pin with the trailing characters '${junk}' is reported, not read as a bare version" "UNRECOGNISED"
done

# Only the ONE period a sentence ends on is prose. Stripping the whole run of
# them reads `#1.7.0..` as the tag `1.7.0`, which certifies lockstep for a pin
# that is written wrong — the same truncation the cases above exist to prevent,
# arrived at from the side the sentence-end allowance opened. A git ref may not
# end in a period at all, so what is left after the one strip is junk and is
# reported as such.
d="$(mk_case double-period 1.7.0 'The pin is github:magicsunday/coding-standard#1.7.0..')"
assert_rejects "$d" "a pin followed by more than one period" "UNRECOGNISED"

# The configuration the three cases above structurally cannot reach: a junk pin
# BESIDE a well-formed one. Dropping an unrecognised pin instead of reporting it
# is invisible there, because the vacuity guard only fires when no pin is left.
for junk in final _hotfix /x; do
    d="$(mk_case "junk-beside-good${junk//\//-}" 1.7.0 "github:magicsunday/coding-standard#1.7.0
and also github:magicsunday/coding-standard#1.7.0${junk}")"
    assert_rejects "$d" "a junk pin '${junk}' is reported even beside a matching one" "UNRECOGNISED  README.md:2"
done

# And the discriminator for the shape: a pin that differs only in its last
# segment must still be caught, so the looser match is not simply truncating.
d="$(mk_case near-miss 1.7.0 'npm install --save-dev github:magicsunday/coding-standard#1.7.1')"
assert_rejects "$d" "a pin differing only in the patch segment" "MISMATCH"

# Both values this gate echoes come from the pull-request branch, and its report
# goes to STDERR, which on GitHub Actions doubles as the workflow-command channel.
# The shipped gates carry these cases; this one shipped without them, which is
# why it also shipped without the scrub they pin.
#
# The split detector cannot serve as the discriminator here: this gate's drift
# report is short enough that one forged line stays under its line bound. So each
# payload carries what one of the other arms keys on — the two command grammars and
# an ESC — and each case also asserts the SCRUBBED form, without which a payload
# that never arrived would pass the absence checks identically.
# Leading spaces and a capital in the command name are deliberate: the runner
# TrimStart()s before matching and compares the name case-insensitively, so a
# detector anchored at `^::[a-z-]` would miss this line while the runner honours it.
# Under "drop the scrub" the payload lands on its own line in exactly that shape.
d="$(mk_case inert-version '1.7.0\n  ::Error::forged from a pull request' \
    'github:magicsunday/coding-standard#1.7.0')"
assert_report_is_inert "$d" "a package.json version cannot forge a \`::\` workflow command" \
    '1.7.0?  ::Error::forged from a pull request'

# A bare CR in a consumer value, which the `::` arm cannot see: grep splits on LF, so a
# CR opens a line that arm never examines, while the runner's ReadLine() treats it as a
# line break. Dropping \r from safeReportValue's class left every suite green before
# this case existed.
d="$(mk_case inert-version-carriage-return '1.7.0\r::Error::forged from a pull request' \
    'github:magicsunday/coding-standard#1.7.0')"
assert_report_is_inert "$d" 'a carriage return in a consumer value cannot open a line' \
    '1.7.0?::Error::forged'

# The scrub breaks `#[`, the shorter form, so that a scrubbed value cannot combine
# with the constant text around it into a legacy command. This pins that shorter
# break directly; the full `##[` follows from it by subsumption.
d="$(mk_case inert-version-short-prefix '1.7.0#[error]forged from a pull request' \
    'github:magicsunday/coding-standard#1.7.0')"
assert_report_is_inert "$d" "a value cannot COMPLETE a legacy prefix the report's own '#' starts" \
    '1.7.0#?[error]forged from a pull request'

d="$(mk_case inert-version-legacy '1.7.0##[error]forged from a pull request' \
    'github:magicsunday/coding-standard#1.7.0')"
assert_report_is_inert "$d" "a package.json version cannot forge a legacy \`##[…]\` command" \
    '1.7.0##?[error]forged from a pull request'

d="$(mk_case inert-readme-pin 1.7.0 \
    "$(printf 'github:magicsunday/coding-standard#1.7.0\033cHIDDEN')")"
assert_report_is_inert "$d" "a README pin cannot carry a terminal escape into the report" \
    '1.7.0?cHIDDEN'

verdict
