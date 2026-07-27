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

if (!is_array($packageJson) || !is_string($packageJson['version'] ?? null)) {
    fwrite(\STDERR, "package.json has no string `version`.\n");
    exit(1);
}

$version = $packageJson['version'];
$readme = $read($root . '/README.md');

if ($readme === false) {
    fwrite(\STDERR, sprintf("Cannot read %s/README.md.\n", $root));
    exit(1);
}

// Every documented pin, with its position, so a mismatch can name the line.
//
// The capture is a version SHAPE, not "everything up to the next quote or
// space". `\S` includes a backtick, a closing paren and a sentence-ending
// period, so the inline forms this repository's own prose uses captured the
// punctuation with the pin and reported a mismatch against a README that was
// perfectly correct.
//
// The shape has to hold for a prerelease and build metadata too, and both edges
// of it matter:
//
// - each `-`/`+` group is dot-separated alphanumerics rather than a class that
//   contains `.`, so `#1.8.0-rc.1.` at the end of a sentence yields `1.8.0-rc.1`
//   and not `1.8.0-rc.1.`; the group repeats, so `#1.2.3-beta.1+build.5` is
//   captured whole instead of truncated at the prerelease.
// - the lookaheads terminate it: nothing that is legal in a git ref may follow
//   (so `#1.7.0final`, `#1.7.0_hotfix` and `#1.7.0/x` are not silently read as
//   the tag `1.7.0`, certifying lockstep for a tag that does not exist), and a
//   following `.` may not begin another segment (so a sentence period is fine
//   and a longer version is not truncated). Punctuation that cannot appear in a
//   ref — a backtick, a paren, a comma, a sentence period — is deliberately NOT
//   in the class, because that is what ends a pin written inline in prose.
//
// A documented `#<tag>` placeholder matches nothing, which is what it is.
preg_match_all(
    '~github:magicsunday/coding-standard#(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)*)(?![0-9A-Za-z_+/-])(?!\.[0-9A-Za-z])~',
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
