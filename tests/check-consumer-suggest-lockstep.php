<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Keeps tests/consumer/composer.json's `require-dev` pins for the opt-in
 * strict-tier packages in step with the constraint composer.json's own
 * `suggest` block documents for the same package.
 *
 * The fixture's `require-dev` hand-copies each suggested package's version
 * constraint a second time, so the Case-folding self-test step, and the
 * Checked-exceptions self-test's one strict-tier-inheritance assertion (see
 * .github/workflows/ci.yml's own comments on those steps), exercise the
 * strict tier (phpstan/strict.neon, phpstan/disallowed-calls.neon) against
 * the same versions the `suggest` text tells a real consumer to install
 * (#57). Nothing ties the two copies together on its own — a bump to one
 * `suggest` entry without the matching fixture bump keeps that step green
 * on the OLD major while the install text already promises the new one.
 *
 * The packages checked are derived from the OVERLAP between `suggest` and
 * the fixture's `require-dev`, never a hand-kept list of names — re-derive
 * the current set rather than trusting a count here: `php -r '$c =
 * json_decode(file_get_contents("composer.json"), true); $f =
 * json_decode(file_get_contents("tests/consumer/composer.json"), true);
 * var_export(array_keys(array_intersect_key($c["suggest"],
 * $f["require-dev"])));'`. A future package added to (or dropped from)
 * either side is picked up without editing this gate.
 *
 * Run from the package root: php tests/check-consumer-suggest-lockstep.php
 *
 * An optional path argument points it at another directory, which is what
 * lets tests/CheckConsumerSuggestLockstepTest.php drive it over fixtures
 * instead of over this repository alone — where every run takes the happy
 * path and a green CI would be indistinguishable from a gate that cannot
 * fail.
 */

$root = $argv[1] ?? dirname(__DIR__);

// safeReportValue(), readCapped() and readCappedJsonObject() — the same
// guards the shipped gates under bin/ use, for the same reason: this gate
// also runs over pull-request branch content (both composer.json files can
// be edited by a PR). Its findings go to STDERR and its summary to STDOUT,
// and the runner scans both for workflow commands.
require_once __DIR__ . '/../bin/support/safe-report-value.php';
require_once __DIR__ . '/../bin/support/read-quietly.php';
require_once __DIR__ . '/../bin/support/read-capped-json.php';

/**
 * The largest file this gate reads, in bytes.
 *
 * Both files this gate reads sit far below the cap — re-derive rather than
 * trusting a number here: `wc -c composer.json tests/consumer/composer.json`.
 * The gate runs over pull-request content, so the cap stays generous rather
 * than tracking either file's current size.
 */
const MAX_LOCKSTEP_BYTES = 1048576;

/**
 * Whether $token is shaped like a composer version constraint this gate can
 * compare (`^4.0`, `~1.2.3`, `^4.0 || ^5.0`, ...).
 *
 * Deliberately narrower than the full composer constraint grammar: this
 * shape only needs to tell apart "a real constraint" from "the extraction
 * landed on trailing prose" (a suggest entry with no version at the end,
 * like roave/backward-compatibility-check's) — re-derive whether every
 * CHECKED entry still fits it rather than trusting that it does:
 * `grep -A6 '"suggest"' composer.json`. A suggest entry this shape rejects
 * is reported as UNRECOGNISED, not silently accepted or silently dropped.
 *
 * @param string $token The candidate version constraint.
 *
 * @return bool Whether $token is shaped like a composer version constraint.
 */
function isComposerConstraintShaped(string $token): bool
{
    return preg_match('#^[\^~]?\d+(?:\.\d+)*(?:\s*\|\|\s*[\^~]?\d+(?:\.\d+)*)*$#D', $token) === 1;
}

/**
 * Reads $path via readCappedJsonObject() and returns its $section as an
 * array (empty when the key is absent but the file otherwise parses), or
 * exits(2) when $section is present but not itself a JSON object — on top
 * of that function's own three read/decode diagnoses.
 *
 * @param string $path     Path to the composer.json to read.
 * @param string $section  The top-level key to return (`suggest`, `require-dev`).
 * @param int    $maxBytes The most bytes this function reads.
 *
 * @return array<string, mixed> The section's value. Never returns on failure.
 */
function readComposerSection(string $path, string $section, int $maxBytes): array
{
    $decoded      = readCappedJsonObject($path, $maxBytes);
    $sectionValue = $decoded[$section] ?? [];

    if (!is_array($sectionValue)) {
        fwrite(\STDERR, sprintf("%s's `%s` is not a JSON object.\n", $path, $section));
        exit(2);
    }

    /** @var array<string, mixed> $sectionValue */
    return $sectionValue;
}

$suggest    = readComposerSection($root . '/composer.json', 'suggest', MAX_LOCKSTEP_BYTES);
$requireDev = readComposerSection($root . '/tests/consumer/composer.json', 'require-dev', MAX_LOCKSTEP_BYTES);

/** @var array<string, mixed> $packages */
$packages = array_intersect_key($suggest, $requireDev);
ksort($packages);

// A require-dev hand-copying nothing composer.json suggests would make this
// gate pass vacuously — exactly the failure mode a subject-liveness check
// exists to prevent.
if (count($packages) === 0) {
    fwrite(\STDERR, "tests/consumer/composer.json's require-dev hand-copies no package composer.json also suggests — the lockstep check has nothing to compare.\n");
    exit(1);
}

$failed = false;

foreach ($packages as $package => $description) {
    if (!is_string($description)) {
        fwrite(\STDERR, sprintf("UNRECOGNISED  composer.json suggests %s with a non-string description\n", safeReportValue($package)));
        $failed = true;

        continue;
    }

    $consumerConstraint = $requireDev[$package];

    if (!is_string($consumerConstraint)) {
        fwrite(\STDERR, sprintf("UNRECOGNISED  tests/consumer/composer.json pins %s to a non-string constraint\n", safeReportValue($package)));
        $failed = true;

        continue;
    }

    // The suggest text is prose ending in `: <constraint>` — the LAST colon,
    // since the prose itself may contain earlier ones (a parenthetical, a
    // file name).
    $colonPosition       = strrpos($description, ':');
    $suggestedConstraint = $colonPosition === false ? '' : trim(substr($description, $colonPosition + 1));

    if (!isComposerConstraintShaped($suggestedConstraint)) {
        fwrite(\STDERR, sprintf(
            "UNRECOGNISED  composer.json's suggest entry for %s does not end in a recognisable version constraint\n",
            safeReportValue($package),
        ));
        $failed = true;

        continue;
    }

    if ($consumerConstraint !== $suggestedConstraint) {
        fwrite(\STDERR, sprintf(
            "MISMATCH  tests/consumer/composer.json pins %s to %s, composer.json suggests %s\n",
            safeReportValue($package),
            safeReportValue($consumerConstraint),
            safeReportValue($suggestedConstraint),
        ));
        $failed = true;

        continue;
    }

    printf("OK        %s pins %s\n", safeReportValue($package), safeReportValue($consumerConstraint));
}

if ($failed) {
    fwrite(\STDERR, "\nKeep tests/consumer/composer.json's require-dev pin and composer.json's suggest constraint for the same package in step.\n");
    exit(1);
}

printf("check-consumer-suggest-lockstep: OK — %d package(s) in step.\n", count($packages));
exit(0);
