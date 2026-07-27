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

$packageJsonContents = @file_get_contents($root . '/package.json');

if ($packageJsonContents === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/package.json.\n", $root));
    exit(1);
}

$packageJson = json_decode($packageJsonContents, true);

if (!is_array($packageJson) || !is_string($packageJson['version'] ?? null)) {
    fwrite(\STDERR, "package.json has no string `version`.\n");
    exit(1);
}

$version = $packageJson['version'];
$readme  = @file_get_contents($root . '/README.md');

if ($readme === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/README.md.\n", $root));
    exit(1);
}

// Every documented pin, with its position, so a mismatch can name the line.
//
// The capture is a version SHAPE, not "everything up to the next quote or
// space". `\S` includes a backtick, a closing paren and a sentence-ending
// period, so the inline forms this repository's own prose uses — a pin in
// backticks, in parentheses, or at the end of a sentence — captured the
// punctuation with the pin and reported a mismatch against a README that was
// perfectly correct. Matching digits and dot-separated groups stops exactly
// where the version does, and a documented `#<tag>` placeholder is not captured
// at all, which is what it is: a placeholder, not a pin.
preg_match_all(
    '~github:magicsunday/coding-standard#(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?)~',
    $readme,
    $matches,
    \PREG_OFFSET_CAPTURE
);

$pins = $matches[1] ?? [];

// A README that documents no pin at all would make this gate pass vacuously —
// exactly the failure mode the phpat subject-liveness guard exists to prevent.
if (count($pins) === 0) {
    fwrite(\STDERR, "README.md documents no `github:magicsunday/coding-standard#<tag>` pin — the version lockstep cannot be checked.\n");
    exit(1);
}

$failed = false;

foreach ($pins as [$pin, $offset]) {
    $line = substr_count(substr($readme, 0, $offset), "\n") + 1;

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
