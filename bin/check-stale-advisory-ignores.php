<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * Fails when an entry of config.policy.advisories.ignore-id of composer.json is missing from the ignored advisories
 * of the audit report, so that an entry that has become removable is noticed.
 *
 * Usage (from a consumer repo root, wired as a `ci:test:php:advisories` script after the audit):
 *
 *     composer audit --format=json > .build/composer-audit.json
 *     check-stale-advisory-ignores.php composer.json .build/composer-audit.json
 *
 * The audit file is the output of `composer audit --format=json`. Composer lists the advisories that an ignore
 * entry suppresses under "ignored-advisories", grouped by package. The ignored ids, which are the keys of ignore-id
 * or the values of a plain list, are compared with the "advisoryId" values of that list, so an entry has to be an
 * advisory id (PKSA-...). An entry keyed by a CVE or a remote id never matches and is reported as stale, and so
 * is an entry that Composer does not apply to the audit because its on-audit setting is false.
 *
 * Exit status: 0 when every ignored id still applies, 1 when at least one is stale, 2 when an input cannot be read,
 * is not valid JSON, holds JSON that is neither an object nor an array, or the call has the wrong number of arguments.
 */

/**
 * Reads a JSON file into an array, ends the script with status 2 when that is not possible.
 *
 * @return array<array-key, mixed>
 */
function readJsonFile(string $path): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        fwrite(STDERR, "Cannot read $path\n");

        exit(2);
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, "$path is not valid JSON\n");

        exit(2);
    }

    if (!is_array($decoded)) {
        fwrite(STDERR, "$path is not a JSON object or array\n");

        exit(2);
    }

    return $decoded;
}

/**
 * Returns the value below the given keys, or null when a key is missing or the path leaves the arrays.
 *
 * @param array<array-key, mixed> $data
 * @param list<string>            $keys
 *
 * @return array<array-key, mixed>|null
 */
function valueAt(array $data, array $keys): ?array
{
    $current = $data;

    foreach ($keys as $key) {
        if (
            !isset($current[$key])
            || !is_array($current[$key])
        ) {
            return null;
        }

        $current = $current[$key];
    }

    return $current;
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: check-stale-advisory-ignores.php <composer.json> <audit.json>\n");

    exit(2);
}

$manifest = readJsonFile($arguments[1]);
$audit    = readJsonFile($arguments[2]);

// Composer accepts a map of id to reason as well as a plain list of ids.
$ignoredIds = [];

foreach (valueAt($manifest, ['config', 'policy', 'advisories', 'ignore-id']) ?? [] as $key => $value) {
    if (is_string($key)) {
        $ignoredIds[] = $key;
    } elseif (is_string($value)) {
        $ignoredIds[] = $value;
    }
}

$appliedIds = [];

foreach (valueAt($audit, ['ignored-advisories']) ?? [] as $advisories) {
    if (!is_array($advisories)) {
        continue;
    }

    foreach ($advisories as $advisory) {
        if (
            is_array($advisory)
            && is_string($advisory['advisoryId'] ?? null)
        ) {
            $appliedIds[] = $advisory['advisoryId'];
        }
    }
}

$stale = array_values(array_diff($ignoredIds, $appliedIds));

if ($stale === []) {
    exit(0);
}

sort($stale);

fwrite(
    STDERR,
    "Stale advisory ignores, no installed package matches them any more:\n  "
    . implode("\n  ", $stale)
    . "\nRemove these entries from config.policy.advisories.ignore-id in composer.json.\n"
);

exit(1);
