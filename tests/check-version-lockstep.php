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

/**
 * Reads a file, or returns false without letting PHP print its own warning first.
 *
 * A scoped handler rather than the `@` prefix: the sibling gate does it this way
 * for the same reason, and `@` would also swallow an error worth seeing.
 *
 * @param string $path Path to the file to read.
 *
 * @return string|false
 */
$read = static function (string $path): string|false {
    set_error_handler(static fn (): bool => true);

    try {
        return file_get_contents($path);
    } finally {
        restore_error_handler();
    }
};

$packageJsonContents = $read($root . '/package.json');

if ($packageJsonContents === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/package.json.\n", $root));
    exit(1);
}

$packageJson = json_decode($packageJsonContents, true);

// Three causes, three reports. Collapsing "cannot be read", "does not parse" and
// "parses but carries no version" into one message sends the reader to add a key
// to a file JSON could not read in the first place — the same conflation the
// sibling gate keeps apart on purpose, and for the same reason: the message is
// the only instruction the reader gets.
if (!is_array($packageJson)) {
    fwrite(\STDERR, sprintf("%s/package.json is not valid JSON.\n", $root));
    exit(1);
}

if (!is_string($packageJson['version'] ?? null)) {
    fwrite(\STDERR, "package.json has no string `version`.\n");
    exit(1);
}

$version = $packageJson['version'];
$readme  = $read($root . '/README.md');

if ($readme === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/README.md.\n", $root));
    exit(1);
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

// The version shape, applied to each occurrence. It has to hold for a prerelease
// and build metadata too: each `-`/`+` group is dot-separated alphanumerics
// rather than a class containing `.`, and the group repeats, so
// `#1.2.3-beta.1+build.5` is taken whole instead of truncated at the prerelease.
// A git ref may not END in a period (git check-ref-format), so a trailing one is
// always prose and is stripped before the comparison.
$shape = '~^\d+(?:\.\d+)*(?:[-+][0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)*$~D';

$pins = [];

foreach ($matches[1] ?? [] as [$raw, $offset]) {
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

    $pins[] = [$token, $offset, preg_match($shape, $token) === 1];
}

// A README that documents no pin at all would make this gate pass vacuously —
// exactly the failure mode the phpat subject-liveness guard exists to prevent.
if (count($pins) === 0) {
    fwrite(\STDERR, "README.md documents no `github:magicsunday/coding-standard#<tag>` pin — the version lockstep cannot be checked.\n");
    exit(1);
}

$failed = false;

foreach ($pins as [$pin, $offset, $wellFormed]) {
    $line = substr_count(substr($readme, 0, $offset), "\n") + 1;

    if (!$wellFormed) {
        fwrite(\STDERR, sprintf("UNRECOGNISED  README.md:%d pins #%s, which is not a version tag\n", $line, $pin));
        $failed = true;

        continue;
    }

    if ($pin !== $version) {
        fwrite(\STDERR, sprintf("MISMATCH  README.md:%d pins #%s, package.json says %s\n", $line, $pin, $version));
        $failed = true;

        continue;
    }

    printf("OK        README.md:%d pins #%s\n", $line, $pin);
}

if ($failed) {
    fwrite(\STDERR, "\nBump package.json `version` and every README pin in the same commit as the tag.\n");
    exit(1);
}

printf("check-version-lockstep: OK — %d README pin(s) match package.json %s.\n", count($pins), $version);
exit(0);
