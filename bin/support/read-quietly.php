<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines readQuietly() for the PHP gates that read a file without letting PHP's
 * own warning reach the report before the gate's.
 *
 * `is_file()` passing does not mean the file can be READ — a mode-000 file, or one
 * whose permissions change between the two calls, still fails. PHP raises an
 * unsuppressed E_WARNING on that path, so the raw
 * `Failed to open stream: Permission denied` lands in the output ahead of the
 * gate's own diagnostic and reads like a crash rather than a finding. Captured
 * through a scoped handler rather than the banned `@` prefix.
 *
 * Three near-identical copies of this had accumulated — bin/check-consumer-config.php's
 * `$readFile`, tests/check-version-lockstep.php's `$read`, and a third written for
 * tests/lint-json.php — before this file existed to hold the one that matters. Same
 * duplication threshold bin/support/safe-report-value.php was extracted for.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Reads a file, or returns false without letting PHP print its own warning first.
 *
 * The cap is applied by the READ, not measured after it. Checking `strlen()`
 * afterwards leaves file_get_contents() to materialise the whole file first, so an
 * oversize config ends in `Allowed memory size exhausted` — exit 255, no gate
 * diagnostic — which is the outcome this bound exists to prevent.
 *
 * `$maxBytes` is required, not optional: every caller this file has — both here
 * and in the three copies it replaced — always passed one, so an unbounded
 * default would be a capability with no consumer.
 *
 * @param string $path     Path to the file to read.
 * @param int    $maxBytes The most bytes to read.
 *
 * @return string|false The contents, or false when the file could not be read.
 */
function readQuietly(string $path, int $maxBytes): string|false
{
    set_error_handler(static fn (): bool => true);

    try {
        return file_get_contents($path, false, null, 0, $maxBytes);
    } finally {
        restore_error_handler();
    }
}

/**
 * Reads a file capped at $maxBytes, distinguishing "too large" from every other
 * failure the caller still reports itself.
 *
 * Named readCapped(), not readBounded(): bin/consumer-checks/helpers.php already
 * declares its own readBounded(), with a different three-argument,
 * self-reporting signature — a second, differently-shaped thing sharing that
 * name would read as the same function at every one of that function's call
 * sites. This one takes no `$violations`/`$label` and reports nothing itself;
 * every caller decides its own message for the `null` arm.
 *
 * One byte PAST the cap is read, then compared — reading exactly the cap
 * truncates in silence, which is the failure this bound exists to prevent
 * rather than a smaller version of it. `null` rather than a second `false`:
 * bin/consumer-checks/helpers.php's own oversize reader uses the same
 * true/false/null three-way split, and a caller that conflated "too large"
 * with "unreadable" would tell the reader to check permissions on a file its
 * own size already answered.
 *
 * @param string $path     Path to the file to read.
 * @param int    $maxBytes The most bytes to accept.
 *
 * @return string|false|null The contents, false when the file could not be
 *                           read, or null when it is larger than $maxBytes.
 */
function readCapped(string $path, int $maxBytes): string|false|null
{
    $contents = readQuietly($path, $maxBytes + 1);

    if (is_string($contents) && (strlen($contents) > $maxBytes)) {
        return null;
    }

    return $contents;
}
