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
 */

$root = dirname(__DIR__);

$packageJson = json_decode((string) file_get_contents($root . '/package.json'), true);

if (!is_array($packageJson) || !is_string($packageJson['version'] ?? null)) {
    fwrite(STDERR, "package.json has no string `version`.\n");
    exit(1);
}

$version = $packageJson['version'];
$readme  = (string) file_get_contents($root . '/README.md');

// Every documented pin, with its position, so a mismatch can name the line.
preg_match_all(
    '~github:magicsunday/coding-standard#(\S+?)(?=["\s]|$)~',
    $readme,
    $matches,
    PREG_OFFSET_CAPTURE
);

$pins = $matches[1] ?? [];

// A README that documents no pin at all would make this gate pass vacuously —
// exactly the failure mode the phpat subject-liveness guard exists to prevent.
if (count($pins) === 0) {
    fwrite(STDERR, "README.md documents no `github:magicsunday/coding-standard#<tag>` pin — the version lockstep cannot be checked.\n");
    exit(1);
}

$failed = false;

foreach ($pins as [$pin, $offset]) {
    $line = substr_count(substr($readme, 0, $offset), "\n") + 1;

    if ($pin !== $version) {
        fwrite(STDERR, sprintf("MISMATCH  README.md:%d pins #%s, package.json says %s\n", $line, $pin, $version));
        $failed = true;

        continue;
    }

    printf("OK        README.md:%d pins #%s\n", $line, $pin);
}

if ($failed) {
    fwrite(STDERR, "\nBump package.json `version` and every README pin in the same commit as the tag.\n");
    exit(1);
}

printf("check-version-lockstep: OK — %d README pin(s) match package.json %s.\n", count($pins), $version);
exit(0);
