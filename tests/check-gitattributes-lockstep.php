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
 * to ITSELF too (the README says so), but nothing enforced that. GH-38: the one
 * entry this repository's own .gitattributes had never mirrored is `/.build` —
 * present in templates/gitattributes since that file's first commit. `/.build` is
 * also gitignored here, so the gap was never a live leak into an actual archive;
 * the value of a gate is closing it before the next template addition is one that
 * matters. (An earlier version of this comment attributed the gap to a "widened
 * template" incident involving package.json/biome.json/tsconfig.json — that never
 * happened: those three were added to templates/gitattributes and reverted again
 * within the same PR, before merge, per its review-comment thread.)
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
 * .gitattributes is 821. Re-derive before raising it: `wc -c templates/gitattributes
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
 * path followed by a whitespace-separated attribute list.
 *
 * State per path, not a plain append: gitattributes(5) has a later line override an
 * earlier one for the same path, so `/x export-ignore` followed later by
 * `/x -export-ignore` leaves `/x` NOT export-ignored — the file's real,
 * git-effective state. An earlier version of this function only ever appended on
 * the positive token and never removed on the negative one, so a later negation was
 * silently ignored and a path that had genuinely stopped being export-ignored still
 * read as satisfied — a green-while-red gap in the one file half of this gate exists
 * to catch drift in. Tracking `true`/`false` per path and keeping only the paths
 * whose LAST occurrence was positive reproduces gitattributes' own last-line-wins
 * rule; a path mentioned only with `-export-ignore` (no earlier positive) is
 * correctly excluded by the same mechanism, with no separate negation check needed.
 *
 * A full gitattributes grammar (glob patterns, macros, `**`) is out of scope: every
 * line either file writes anchors an exact `/path`, so an exact-string comparison
 * is what both files actually need.
 *
 * @param string $contents The raw file contents.
 *
 * @return list<string> The paths whose LAST occurrence carries an active
 *                       `export-ignore` attribute.
 */
$parseExportIgnorePaths = static function (string $contents): array {
    /** @var array<string, bool> $state */
    $state = [];

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
            $state[$matches[1]] = true;
        } elseif (in_array('-export-ignore', $attributes, true)) {
            $state[$matches[1]] = false;
        }
    }

    return array_keys(array_filter($state));
};

/**
 * Reads a lockstep file capped at MAX_GITATTRIBUTES_BYTES, reporting an oversize or
 * unreadable file and exiting(2) — a setup failure, distinct from the drift verdict
 * (exit 1) this gate otherwise reports. Both this gate's file reads shared this
 * three-branch block verbatim before this closure existed; bin/check-consumer-config.php's
 * own `$readBounded` was extracted at the same 2-copy threshold, for the same reason
 * its docblock gives: a duplicated read block drifts (a message updated at one call
 * site and not the other) exactly the way a duplicated report line does.
 *
 * @param string $path Path to the file to read.
 *
 * @return string The contents. Never returns on failure.
 */
$readOrExit = static function (string $path): string {
    $contents = readCapped($path, MAX_GITATTRIBUTES_BYTES);

    if ($contents === null) {
        fwrite(\STDERR, sprintf("%s is larger than the %d bytes this gate reads.\n", $path, MAX_GITATTRIBUTES_BYTES));
        exit(2);
    }

    if ($contents === false) {
        fwrite(\STDERR, sprintf("Cannot read %s.\n", $path));
        exit(2);
    }

    return $contents;
};

$templatePath = $root . '/templates/gitattributes';
$ownPath      = $root . '/.gitattributes';

$templateContents = $readOrExit($templatePath);
$templatePaths    = $parseExportIgnorePaths($templateContents);

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
$ownContents = is_file($ownPath) ? $readOrExit($ownPath) : '';
$ownPaths    = array_flip($parseExportIgnorePaths($ownContents));

// realpath() proves containment, never string surgery on $path: templates/gitattributes
// is pull-request branch content in this repository's own CI (like every gate this file's
// own header note on safeReportValue()/readCapped() already treats that way), and a
// crafted entry such as `../../../../../../etc/hostname    export-ignore` would otherwise
// walk $target outside this repository via ltrim('/') alone — reproduced: file_exists()
// on the unresolved path answered for an arbitrary path on the CI runner's filesystem,
// turning "is this path applicable" into a boolean existence oracle over the whole
// filesystem. Only the boolean leaked (never content, never a write), but the containment
// is proven here rather than assumed, the same rule this package's own security review
// applies everywhere else. $realRoot is resolved once, outside the loop: it does not
// change per iteration.
$realRoot = realpath($root);

// $templateContents already read successfully above, so $root resolved to a real,
// readable directory at that point — this can only fail on a root removed between
// the two calls, a race this gate reports as a setup failure like every other IO
// problem here, rather than silently treating every template path as inapplicable.
if ($realRoot === false) {
    fwrite(\STDERR, sprintf("Cannot resolve %s to a real path.\n", $root));
    exit(2);
}

/** @var list<string> $violations */
$violations = [];

foreach ($templatePaths as $path) {
    $real = realpath($root . '/' . ltrim($path, '/'));

    // Not applicable here — the qualifier this gate exists to apply. Silent, the
    // same way an absent .jscpd.json is silent for check-consumer-config.php: the
    // template lists a path a CONSUMER has, and this package legitimately does not
    // have every one of them. A path realpath() cannot resolve under $realRoot —
    // absent, or escaping it via `..` — falls in here too: neither is a path this
    // repository "has" for this gate's purpose.
    if (($real === false) || !str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)) {
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
