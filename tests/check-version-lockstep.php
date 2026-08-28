<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Keeps package.json's `version` in step with the npm pins documented in the README.
 *
 * The Composer side needs no such check — Packagist derives the version from the git
 * tag, so there is nothing to keep in step. The npm side is a GitHub git dependency,
 * so the tag is written out by hand in the install instructions, and package.json
 * carries the same number a second time. Three hand-maintained copies of one fact
 * drift the moment a release bumps two of them: the README would then tell a consumer
 * to install a tag that predates the fix it documents, and nothing would complain.
 *
 * Run from the package root: php tests/check-version-lockstep.php
 *
 * An optional path argument points it at another directory, which is what lets
 * tests/check-version-lockstep-cases.sh drive it over fixtures instead of over
 * this repository alone — where every run takes the happy path and a green CI
 * would be indistinguishable from a gate that cannot fail.
 */

$root = $argv[1] ?? dirname(__DIR__);

// safeReportValue() — the same guard the shipped gates under bin/ use, for the same
// reason: this gate also runs over pull-request branch content (package.json's
// `version`, and every README pin). Its findings go to STDERR and its summary to
// STDOUT, and the runner scans both for workflow commands. Reproduced before it was wrapped: a
// `version` holding a real newline put a forged `::error::` at column 0, and a pin
// carrying a raw ESC reached the report intact.
require_once __DIR__ . '/../bin/support/safe-report-value.php';
require_once __DIR__ . '/../bin/support/read-quietly.php';
require_once __DIR__ . '/../bin/support/read-package-json-version.php';
require_once __DIR__ . '/../bin/support/version-tag-shape.php';

/**
 * The largest file this gate reads, in bytes.
 *
 * This repository's own README is under 40 KB and its package.json under 2 KB, and
 * the gate runs over pull-request content. Re-derive before raising it:
 * `wc -c README.md package.json`.
 */
const MAX_LOCKSTEP_BYTES = 1048576;

// Exit codes, held the same way the two shipped gates hold them: 0 is a pass, 1 is
// the drift verdict, 2 says the gate could not run at all — an unreadable or
// unparseable package.json, a package.json with no version, an unreadable README.
// Conflating the two let a setup failure count as a caught mismatch, and the case
// harness could not tell them apart either; its own comment described a "usage
// exit" this gate did not have.
//
// "README documents no pin" stays at 1 on purpose: the file is readable and
// well-formed, and losing the documented pin IS the drift this gate reports.
// readCapped()'s null arm is the same "read past the cap, then compare" check
// documented on its own definition — measured before that bound existed: a
// README carrying a matching pin in line 1 and a stale one past the bound
// reported one matching pin and exited 0.
//
// readPackageJsonVersion() holds the four-cause, four-report split this used
// to inline: collapsing "too large", "cannot be read", "does not parse" and
// "parses but carries no version" into one message sends the reader to add a
// key to a file JSON could not read in the first place.
$version = readPackageJsonVersion($root, MAX_LOCKSTEP_BYTES);
$readme  = readCapped($root . '/README.md', MAX_LOCKSTEP_BYTES);

if ($readme === null) {
    fwrite(\STDERR, sprintf("%s/README.md is larger than the %d bytes this gate reads, so a pin past that bound would go unchecked.\n", $root, MAX_LOCKSTEP_BYTES));

    exit(2);
}

if ($readme === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/README.md.\n", $root));
    exit(2);
}

// Every documented OCCURRENCE, matched permissively on purpose.
//
// A shape-only pattern would DROP an unrecognised pin instead of reporting it,
// and dropping is indistinguishable from absence — the vacuity guard below only
// fires when there is no pin at all. So `#1.8.0_hotfix` written beside a correct
// pin left the gate printing OK for a README documenting a tag that does not
// exist: the very outcome the shape check was added to prevent, one step further
// along. The shape is applied per occurrence instead, and a failure is a
// violation rather than a skip.
//
// The terminating class is what a pin written inline in prose ends on — a
// backtick, a paren, a comma, whitespace — none of which may appear in a git ref.
preg_match_all(
    '~github:magicsunday/coding-standard#([^\s`\'"()\[\]{},]+)~',
    $readme,
    $matches,
    \PREG_OFFSET_CAPTURE
);

// The version shape, applied to each occurrence. isVersionTagShaped() (GH-42)
// holds the ambiguity/backtracking rationale this used to carry inline; a git
// ref may not END in a period (git check-ref-format), so a trailing one is
// always prose and is stripped before the comparison — that stripping is this
// gate's own concern, not the shared shape check's.
/** @var list<array{0: string, 1: int, 2: bool}> $pins */
$pins = [];

foreach ($matches[1] as [$raw, $offset]) {
    // Exactly ONE period, because exactly one is what a sentence ends on. Stripping
    // the whole run would read `#1.7.0..` as the tag `1.7.0` and certify lockstep
    // for a pin written wrong — the truncation this gate reports everywhere else,
    // arrived at through the sentence-end allowance. What is left after one strip
    // still may not end in a period, so the shape below reports it.
    $token = str_ends_with($raw, '.') ? substr($raw, 0, -1) : $raw;

    // A documented `#<tag>` placeholder is not a pin and must not be compared as
    // one — nor does it count towards the vacuity guard, since a README carrying
    // only a placeholder documents no pin.
    if ($token === '<tag>') {
        continue;
    }

    $pins[] = [$token, $offset, isVersionTagShaped($token)];
}

// A README that documents no pin at all would make this gate pass vacuously —
// exactly the failure mode a subject-liveness check exists to prevent.
if (count($pins) === 0) {
    fwrite(\STDERR, "README.md documents no `github:magicsunday/coding-standard#<tag>` pin — the version lockstep cannot be checked.\n");
    exit(1);
}

$failed = false;

foreach ($pins as [$pin, $offset, $wellFormed]) {
    $line = substr_count(substr($readme, 0, $offset), "\n") + 1;

    if (!$wellFormed) {
        fwrite(\STDERR, sprintf("UNRECOGNISED  README.md:%d pins #%s, which is not a version tag\n", $line, safeReportValue($pin)));
        $failed = true;

        continue;
    }

    if ($pin !== $version) {
        fwrite(\STDERR, sprintf("MISMATCH  README.md:%d pins #%s, package.json says %s\n", $line, safeReportValue($pin), safeReportValue($version)));
        $failed = true;

        continue;
    }

    printf("OK        README.md:%d pins #%s\n", $line, safeReportValue($pin));
}

if ($failed) {
    fwrite(\STDERR, "\nBump package.json `version` and every README pin in the same commit as the tag.\n");
    exit(1);
}

printf("check-version-lockstep: OK — %d README pin(s) match package.json %s.\n", count($pins), safeReportValue($version));
exit(0);
