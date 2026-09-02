<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines readCappedJsonObject() for the PHP gates that need a JSON file's
 * decoded top-level object and nothing else — no extraction, no per-key
 * validation, both of which stay the caller's own concern.
 *
 * Extracted out of bin/support/read-package-json-version.php's own read-decode-
 * validate sequence once tests/check-consumer-suggest-lockstep.php (#57) needed
 * the identical three steps a second time, for a different top-level shape
 * (`suggest`/`require-dev` sections rather than a single `version` string) —
 * the same 2+-duplicate threshold every other bin/support/ extraction in this
 * repository was made at.
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
 * Reads $path and json_decode()s it into an associative array, or exits(2)
 * with one of three distinct diagnoses a caller cannot usefully recover from
 * itself: the file is larger than $maxBytes, the file cannot be read, or the
 * file does not parse as a JSON object.
 *
 * @param string $path     Path to the JSON file to read.
 * @param int    $maxBytes The most bytes this function reads.
 *
 * @return array<string, mixed> The decoded JSON object. Never returns on failure.
 */
function readCappedJsonObject(string $path, int $maxBytes): array
{
    $contents = readCapped($path, $maxBytes);

    if ($contents === null) {
        fwrite(\STDERR, sprintf("%s is larger than the %d bytes this gate reads.\n", $path, $maxBytes));
        exit(2);
    }

    if ($contents === false) {
        fwrite(\STDERR, sprintf("Cannot read %s.\n", $path));
        exit(2);
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        fwrite(\STDERR, sprintf("%s is not valid JSON.\n", $path));
        exit(2);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}
