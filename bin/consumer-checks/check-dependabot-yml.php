<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The .github/dependabot.yml (optional) contract check. See
 * bin/check-consumer-config.php's own docblock for why this split exists and
 * bin/consumer-checks/helpers.php's for the shared-include boundary it follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts that every `updates` entry of .github/dependabot.yml sets a non-empty
 * `commit-message.prefix`.
 *
 * Without a prefix Dependabot copies the style it detects in the history, which can
 * yield conventional-commit subjects the shared commit-convention gate rejects. The
 * file is not a community health file, so no repository inherits it, and a new
 * `updates` entry is the place the setting gets lost.
 *
 * The file is matched line by line rather than parsed, to keep the gate
 * dependency-free like its siblings. `commit-message` must be a direct key of the
 * entry and `prefix` a direct key of `commit-message`, both in block style. Anything
 * else is reported as lacking the setting, which fails closed: a nested decoy, a null
 * or block-scalar value, and a flow-style `commit-message: { prefix: x }`, which is a
 * valid spelling the gate deliberately does not read.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkDependabotYml(array &$violations, string $repoRoot): void
{
    $label = '.github/dependabot.yml';
    $file  = $repoRoot . '/' . $label;

    if (!is_file($file)) {
        return;
    }

    $contents = readBounded($violations, $file, $label);

    if ($contents === null) {
        return;
    }

    if ($contents === false) {
        fail($violations, $label, 'exists but cannot be read.');

        return;
    }

    $contents = str_replace(["\r\n", "\r"], "\n", stripBom($contents));
    $block    = yamlBlock($contents, 'updates');

    if ($block === null) {
        fail(
            $violations,
            $label,
            sprintf(
                'the `updates:` block could not be scanned (%s), so this gate cannot answer for it.',
                preg_last_error_msg()
            )
        );

        return;
    }

    $entries = dependabotUpdateEntries($block);

    if ($entries === []) {
        fail($violations, $label, 'lists no `updates` entry, so there is no `commit-message.prefix` to check.');

        return;
    }

    foreach ($entries as $entry) {
        if (dependabotEntryHasPrefix($entry)) {
            continue;
        }

        $matched = preg_match(
            '/^[ \t]*-?[ \t]*package-ecosystem:[ \t]*["\']?([^"\'\n]*)/m',
            implode("\n", $entry),
            $matches
        );

        $ecosystem = ($matched === 1) ? trim($matches[1]) : '?';

        fail($violations, $label, sprintf('the `%s` entry has no `commit-message.prefix`.', safeReportValue($ecosystem)));
    }
}

/**
 * Splits the body of an `updates:` block into its list entries.
 *
 * An entry starts at a `-` line at the indentation of the block's first one, which
 * is what keeps a nested list (`patterns:`) from starting a new entry.
 *
 * @param string $block The block as yamlBlock() returned it.
 *
 * @return list<list<string>> The lines of each entry.
 */
function dependabotUpdateEntries(string $block): array
{
    $entries = [];
    $current = null;
    $indent  = null;

    foreach (explode("\n", $block) as $line) {
        if (preg_match('/^([ \t]*)-(?:[ \t]|$)/', $line, $matches) === 1) {
            $indent ??= $matches[1];

            if ($matches[1] === $indent) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [$line];

                continue;
            }
        }

        if ($current !== null) {
            $current[] = $line;
        }
    }

    if ($current !== null) {
        $entries[] = $current;
    }

    return $entries;
}

/**
 * Whether a line carries no YAML content, a blank line or a comment.
 *
 * @param string $line The line to inspect.
 *
 * @return bool True when the line is blank or only a comment.
 */
function dependabotLineIsEmpty(string $line): bool
{
    $trimmed = trim($line);

    return ($trimmed === '')
        || str_starts_with($trimmed, '#');
}

/**
 * The column a line's content starts at, with a leading list dash counted as part of
 * the indentation.
 *
 * @param string $line The line to inspect.
 *
 * @return int The width of the leading whitespace and dash.
 */
function dependabotContentColumn(string $line): int
{
    preg_match('/^[ \t]*(?:-(?:[ \t]+|$))?/', $line, $matches);

    return strlen($matches[0]);
}

/**
 * Whether one entry carries a non-empty `commit-message.prefix`.
 *
 * @param list<string> $entry The lines of the entry.
 *
 * @return bool True when `commit-message` is a direct key of the entry and sets a
 *              non-empty `prefix` as its own direct key.
 */
function dependabotEntryHasPrefix(array $entry): bool
{
    $count = count($entry);

    // The entry's own keys start at the column of the first line's content, which is
    // the dash plus its spacing for `- package-ecosystem: x`.
    $keyColumn = dependabotContentColumn($entry[0]);

    if (trim(substr($entry[0], $keyColumn)) === '') {
        for ($index = 1; $index < $count; ++$index) {
            if (!dependabotLineIsEmpty($entry[$index])) {
                $keyColumn = dependabotContentColumn($entry[$index]);

                break;
            }
        }
    }

    for ($index = 0; $index < $count; ++$index) {
        $line = $entry[$index];

        if (
            (preg_match('/^([ \t]*(?:-[ \t]+)?)commit-message:/', $line, $matches) !== 1)
            || (strlen($matches[1]) !== $keyColumn)
        ) {
            continue;
        }

        return dependabotBlockHasPrefix($entry, $index + 1, $keyColumn);
    }

    return false;
}

/**
 * Whether the block-style children of a `commit-message:` line set a non-empty
 * `prefix` as a direct key.
 *
 * @param list<string> $entry     The lines of the entry.
 * @param int          $start     The index of the first line after `commit-message:`.
 * @param int          $keyColumn The column of `commit-message:` itself.
 *
 * @return bool True when the first child level carries a `prefix` with a usable value.
 */
function dependabotBlockHasPrefix(array $entry, int $start, int $keyColumn): bool
{
    $childColumn = null;
    $count       = count($entry);

    for ($index = $start; $index < $count; ++$index) {
        $line = $entry[$index];

        if (dependabotLineIsEmpty($line)) {
            continue;
        }

        $column = strlen($line) - strlen(ltrim($line));

        if ($column <= $keyColumn) {
            return false;
        }

        $childColumn ??= $column;

        if (
            ($column === $childColumn)
            && (preg_match('/^[ \t]+prefix:(.*)$/', $line, $matches) === 1)
        ) {
            return dependabotScalarIsSet($matches[1]);
        }
    }

    return false;
}

/**
 * Whether the text after `prefix:` is a non-empty string scalar.
 *
 * A null, a block scalar, an alias, an anchor, a tag, a flow collection and an
 * unterminated quote are not accepted: a prefix is a plain or quoted string, and
 * anything else is not worth guessing at.
 *
 * @param string $value The text after `prefix:`.
 *
 * @return bool True when the value is a plain or quoted scalar with visible content.
 */
function dependabotScalarIsSet(string $value): bool
{
    $value = trim($value);

    if (($value === '') || str_starts_with($value, '#')) {
        return false;
    }

    if (
        (preg_match('/^"([^"]*)"/', $value, $matches) === 1)
        || (preg_match('/^\'([^\']*)\'/', $value, $matches) === 1)
    ) {
        return trim($matches[1]) !== '';
    }

    if (preg_match('/^(?:~|null)(?:[ \t]+#.*)?$/i', $value) === 1) {
        return false;
    }

    return preg_match('/^[^\s"\'|>*&!{\[%@`]/', $value) === 1;
}
