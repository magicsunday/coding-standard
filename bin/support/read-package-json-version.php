<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines readPackageJsonVersion() for the PHP gates that need package.json's
 * `version` string and nothing else from it.
 *
 * Extracted out of tests/check-version-lockstep.php once tests/check-release-tag-lockstep.php
 * (GH-42) needed the identical read-parse-validate sequence a second time — the
 * same 2+-duplicate threshold bin/support/read-quietly.php and
 * bin/support/safe-report-value.php were both extracted at. The four exit(2)
 * messages below are unchanged from the gate that originated them, since
 * tests/check-version-lockstep-cases.sh greps for their exact text.
 *
 * Depends on readCapped() from bin/support/read-quietly.php — a caller must
 * `require_once` that file first, the same convention every other bin/support/
 * file already leaves to its callers rather than requiring itself and risking a
 * double include guard mismatch.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Reads $root/package.json and returns its `version` string, or exits(2) with
 * one of four distinct diagnoses a caller cannot usefully recover from itself:
 * the file is larger than $maxBytes, the file cannot be read, the file does not
 * parse as JSON, or it parses but carries no string `version`. Collapsing these
 * into one message would send the reader to add a key to a file JSON could not
 * even read in the first place.
 *
 * @param string $root     The directory containing package.json.
 * @param int    $maxBytes The most bytes this function reads.
 *
 * @return string The `version` string. Never returns on failure.
 */
function readPackageJsonVersion(string $root, int $maxBytes): string
{
    $contents = readCapped($root . '/package.json', $maxBytes);

    if ($contents === null) {
        fwrite(\STDERR, sprintf("%s/package.json is larger than the %d bytes this gate reads.\n", $root, $maxBytes));

        exit(2);
    }

    if ($contents === false) {
        fwrite(\STDERR, sprintf("Cannot read %s/package.json.\n", $root));
        exit(2);
    }

    $packageJson = json_decode($contents, true);

    if (!is_array($packageJson)) {
        fwrite(\STDERR, sprintf("%s/package.json is not valid JSON.\n", $root));
        exit(2);
    }

    if (!is_string($packageJson['version'] ?? null)) {
        fwrite(\STDERR, "package.json has no string `version`.\n");
        exit(2);
    }

    return $packageJson['version'];
}
