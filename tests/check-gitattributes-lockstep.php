<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Keeps this repository's own root .gitattributes in step with templates/gitattributes.
 *
 * templates/gitattributes is shipped for consumers to copy; this package applies it
 * to ITSELF too (the README says so), but nothing enforced that. GH-38: when the
 * template gained seven entries (package.json, biome.json, tsconfig.json and four
 * more), this repository's own .gitattributes gained none of them — `git archive`,
 * which is what Packagist serves, shipped npm-only dev tooling into every Composer
 * consumer's dist tarball. Found by a review bot, not by a gate.
 *
 * The qualifier is the whole difficulty: the template lists paths a consumer has
 * that THIS package does not (rector.php, infection.json5 — this package ships the
 * `rector/` and templates it, it has no root rector.php of its own). A naive
 * equality check would report drift for every one of those. So only a template
 * entry naming a path this repository actually HAS is required — everything else is
 * legitimately not applicable and is silently skipped, the same asymmetry
 * bin/check-consumer-config.php uses for its own optional configs.
 *
 * No separate exceptions list is needed for `biome/` and `tsconfig/` staying in the
 * archive (the README documents them as importable from the Composer vendor
 * directory too): the template never lists them as export-ignore candidates in the
 * first place — they are package content, not a copy-and-adapt dev-tooling file —
 * so they never enter this gate's comparison. Likewise the template's OWN
 * commented-out lines (`#/biome.json export-ignore` and its two neighbours, kept
 * inactive on purpose — see templates/gitattributes' header) are skipped exactly
 * like any other comment; a commented-out directive is not a requirement.
 *
 * Run from the package root: php tests/check-gitattributes-lockstep.php
 *
 * An optional path argument points it at another directory, which is what lets
 * tests/check-gitattributes-lockstep-cases.sh drive it over fixtures instead of
 * over this repository alone — where every run takes the happy path and a green CI
 * would be indistinguishable from a gate that cannot fail.
 */

$root = $argv[1] ?? dirname(__DIR__);

// safeReportValue() and readCapped() — the same guards the shipped gates and the
// sibling version-lockstep gate use, for the same reason: a malicious templates/
// or .gitattributes edit is pull-request branch content, not something only the
// maintainer ever writes.
require_once __DIR__ . '/../bin/support/safe-report-value.php';
require_once __DIR__ . '/../bin/support/read-quietly.php';

/**
 * The largest file this gate reads, in bytes.
 *
 * Both files this gate compares are hand-maintained lockstep lists, not generated
 * content — templates/gitattributes is 3127 bytes and this repository's own
 * .gitattributes is 584. Re-derive before raising it: `wc -c templates/gitattributes
 * .gitattributes`.
 */
const MAX_GITATTRIBUTES_BYTES = 1048576;

/**
 * Parses the paths an EXPORT-ignore-style .gitattributes file lists with an active
 * `export-ignore` attribute.
 *
 * Comment lines (leading `#`, which also covers a line that comments OUT a
 * directive — templates/gitattributes keeps three deliberately inactive, see this
 * file's own docblock) and blank lines are skipped. Every remaining line names a
 * path followed by a whitespace-separated attribute list; only the exact token
 * `export-ignore` counts; a negated `-export-ignore` is a DIFFERENT token and is
 * therefore correctly not collected, with no separate negation check needed.
 *
 * A full gitattributes grammar (glob patterns, macros, `**`) is out of scope: every
 * line either file writes anchors an exact `/path`, so an exact-string comparison
 * is what both files actually need.
 *
 * @param string $contents The raw file contents.
 *
 * @return list<string> The paths carrying an active `export-ignore` attribute, in
 *                       the order the file lists them.
 */
$parseExportIgnorePaths = static function (string $contents): array {
    $paths = [];

    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if (($trimmed === '') || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^(\S+)\s+(.+)$/', $trimmed, $matches) !== 1) {
            continue;
        }

        $attributes = preg_split('/\s+/', trim($matches[2])) ?: [];

        if (in_array('export-ignore', $attributes, true)) {
            $paths[] = $matches[1];
        }
    }

    return $paths;
};

$templatePath = $root . '/templates/gitattributes';
$ownPath      = $root . '/.gitattributes';

$templateContents = readCapped($templatePath, MAX_GITATTRIBUTES_BYTES);

if ($templateContents === null) {
    fwrite(\STDERR, sprintf("%s is larger than the %d bytes this gate reads.\n", $templatePath, MAX_GITATTRIBUTES_BYTES));
    exit(2);
}

if ($templateContents === false) {
    fwrite(\STDERR, sprintf("Cannot read %s.\n", $templatePath));
    exit(2);
}

$templatePaths = $parseExportIgnorePaths($templateContents);

// A template carrying no active export-ignore entry at all cannot drive this gate
// — the same vacuity guard tests/check-version-lockstep.php applies to a README
// documenting no pin. Distinct from "none of the entries apply here", which is a
// legitimate pass below.
if (count($templatePaths) === 0) {
    fwrite(\STDERR, sprintf("%s declares no active `export-ignore` entry — the lockstep cannot be checked.\n", $templatePath));
    exit(1);
}

// Absent is a real state to report (this repository shipping no .gitattributes at
// all while the template requires one IS the drift), unreadable is a setup
// failure — the same three-way split bin/check-consumer-config.php's REQUIRED
// phpunit.xml read applies, and for the same reason: is_file() is checked before
// the read so a permissions problem is not misreported as "no file here".
if (is_file($ownPath)) {
    $ownContents = readCapped($ownPath, MAX_GITATTRIBUTES_BYTES);

    if ($ownContents === null) {
        fwrite(\STDERR, sprintf("%s is larger than the %d bytes this gate reads.\n", $ownPath, MAX_GITATTRIBUTES_BYTES));
        exit(2);
    }

    if ($ownContents === false) {
        fwrite(\STDERR, sprintf("Cannot read %s.\n", $ownPath));
        exit(2);
    }
} else {
    $ownContents = '';
}

$ownPaths = array_flip($parseExportIgnorePaths($ownContents));

/** @var list<string> $violations */
$violations = [];

foreach ($templatePaths as $path) {
    $target = $root . '/' . ltrim($path, '/');

    // Not applicable here — the qualifier this gate exists to apply. Silent, the
    // same way an absent .jscpd.json is silent for check-consumer-config.php: the
    // template lists a path a CONSUMER has, and this package legitimately does not
    // have every one of them.
    if (!file_exists($target)) {
        continue;
    }

    if (!isset($ownPaths[$path])) {
        $violations[] = sprintf(
            '%s: missing `export-ignore` — templates/gitattributes lists it and this repository has the path.',
            safeReportValue($path)
        );
    }
}

if (count($violations) === 0) {
    printf("check-gitattributes-lockstep: OK — every applicable templates/gitattributes entry is present in .gitattributes.\n");
    exit(0);
}

fwrite(\STDERR, sprintf(".gitattributes: %d drift(s) from templates/gitattributes:\n", count($violations)));

foreach ($violations as $violation) {
    fwrite(\STDERR, sprintf("  - %s\n", $violation));
}

fwrite(\STDERR, "\nAdd the missing export-ignore line(s) to .gitattributes.\n");
exit(1);
