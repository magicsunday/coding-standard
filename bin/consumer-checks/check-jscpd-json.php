<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The .jscpd.json (optional) contract check — see bin/check-consumer-config.php's
 * own docblock for why this split exists and bin/consumer-checks/helpers.php's
 * for the shared-include boundary it follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the zero-tolerance thresholds + the current reporter name on
 * .jscpd.json.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkJscpdJson(array &$violations, string $repoRoot): void
{
    $jscpdFile = $repoRoot . '/.jscpd.json';

    if (!is_file($jscpdFile)) {
        return;
    }

    $jscpdContents = readBounded($violations, $jscpdFile, '.jscpd.json');
    $json          = is_string($jscpdContents) ? json_decode($jscpdContents, true) : null;

    if ($jscpdContents === null) {
        return;
    }

    if ($jscpdContents === false) {
        fail($violations, '.jscpd.json', 'exists but cannot be read.');

        return;
    }

    if (str_starts_with($jscpdContents, "\xEF\xBB\xBF")) {
        // Named rather than folded into "not valid JSON", which is what a bare
        // json_decode failure would report — and it is the cause the reader cannot
        // see, because the file is syntactically perfect. jscpd 5.0.14 answers its
        // own BOM'd config with `expected value at line 1 column 1` and carries on
        // with no config at all, so this is a real defect and not a spelling to
        // tolerate; the BOM is deliberately NOT stripped here for that reason.
        fail($violations, '.jscpd.json', 'starts with a UTF-8 BOM, which jscpd refuses to parse — it reports `expected value at line 1 column 1` and falls back to no config.');

        return;
    }

    if (!is_array($json)) {
        fail($violations, '.jscpd.json', 'not valid JSON.');

        return;
    }

    if (($json['threshold'] ?? null) !== 0) {
        fail($violations, '.jscpd.json', '`threshold` must be 0 (zero-tolerance).');
    }

    if (($json['exitCode'] ?? null) !== 1) {
        fail($violations, '.jscpd.json', '`exitCode` must be 1 so a clone fails the build.');
    }

    $minTokens = $json['minTokens'] ?? null;

    if (!is_int($minTokens) || ($minTokens > 100)) {
        fail($violations, '.jscpd.json', '`minTokens` must be present and <= 100.');
    }

    // minLines is the second detection threshold; raising it (to 9999, say)
    // disables clone detection just as raising minTokens would.
    $minLines = $json['minLines'] ?? null;

    if (!is_int($minLines) || ($minLines > 5)) {
        fail($violations, '.jscpd.json', '`minLines` must be present and <= 5.');
    }

    $reporters = $json['reporters'] ?? [];

    if (!is_array($reporters) || !in_array('console-full', $reporters, true)) {
        fail($violations, '.jscpd.json', '`reporters` must contain "console-full" (the jscpd 5 name; "consoleFull" is the removed v4 spelling).');
    }

    // `format` takes jscpd's FORMAT names, and an unknown one is NOT an
    // error — it silently analyses nothing and reports a clean run. Verified
    // against 5.0.14 on a fixture holding two near-identical TypeScript
    // functions: `["ts"]` prints "No duplicates found" and exits 0, while
    // `["typescript"]` reports 2 clones and exits 1. Same fixture, same
    // threshold. A consumer "fixing" the template to its file extensions
    // therefore keeps a gate that looks active and detects nothing.
    //
    // This is deliberately a deny-list of the extension spellings that look
    // right, not an allow-list of valid names: `jscpd --list` carries ~250
    // formats, and a copy of it here would drift from the tool and start
    // rejecting configs the tool accepts. It is scoped to the formats the
    // shipped template actually names — the spellings a consumer copying it
    // can plausibly mistype — and each entry was checked against
    // `jscpd --list` to be absent from it, so every one of them scans nothing.
    $extensionSpellings = [
        'js'  => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'ts'  => 'typescript',
        'mts' => 'typescript',
        'cts' => 'typescript',
    ];

    // Presence is deliberately NOT required: without `format`, jscpd applies
    // its own defaults, which is a working gate rather than a silent one.
    // Only the spellings that disable detection are reported.
    // A bare string is accepted alongside a list, or the very spelling this
    // check exists to reject would slip through by not being in an array.
    $formats = $json['format'] ?? null;

    if (is_string($formats)) {
        $formats = [$formats];
    }

    if (is_array($formats)) {
        foreach ($formats as $format) {
            if (is_string($format) && isset($extensionSpellings[$format])) {
                fail($violations, '.jscpd.json', sprintf('`format` entry "%s" is a file extension, not a jscpd format name — jscpd does not error on it, it silently analyses nothing and reports a clean run. Use "%s".', $format, $extensionSpellings[$format]));
            }
        }
    }
}
