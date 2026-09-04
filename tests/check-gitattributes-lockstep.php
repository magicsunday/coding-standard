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
 * matters.
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
 * commented-out lines (e.g. `#/biome.json export-ignore` — see templates/gitattributes'
 * header for the full set, kept inactive on purpose) are skipped exactly like any
 * other comment; a commented-out directive is not a requirement.
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
 * directive — templates/gitattributes keeps some deliberately inactive, see this
 * file's own docblock) and blank lines are skipped. Every remaining line names a
 * path followed by a whitespace-separated attribute list.
 *
 * State per path, not a plain append: gitattributes(5) has a later TOKEN override an
 * earlier one for the same path, both across lines (`/x export-ignore` followed later
 * by `/x -export-ignore` leaves `/x` NOT export-ignored) and within one line (`/x
 * export-ignore -export-ignore` is ALSO not export-ignored — the trailing token wins
 * there too) — the file's real, git-effective state either way. An earlier version of
 * this function only ever appended on the positive token and never removed on the
 * negative one, so a later negation was silently ignored and a path that had genuinely
 * stopped being export-ignored still read as satisfied — a green-while-red gap in the
 * one file half of this gate exists to catch drift in. Iterating $attributes in order
 * and letting each token overwrite $state as it is encountered reproduces
 * gitattributes' own last-token-wins rule at both granularities; a path mentioned only
 * with `-export-ignore`/`!export-ignore` (no earlier positive) is correctly excluded
 * by the same mechanism, with no separate negation check needed.
 *
 * A full gitattributes grammar (glob patterns, macros, `**`) is out of scope: every
 * line either file writes anchors an exact `/path`, so an exact-string comparison
 * is what both files actually need.
 *
 * A leading UTF-8 BOM is stripped before the first line is split off: without it,
 * the three BOM bytes attach to the file's very first token — a comment marker
 * (silently defeating the `#`-prefix skip, since the trimmed line no longer STARTS
 * with `#`) or a path (corrupting `$matches[1]` so it can never realpath()-resolve
 * below, and a required entry that can never resolve reads as "not applicable" —
 * reproduced: a repository that genuinely has the corrupted entry's path, with
 * .gitattributes genuinely missing the mirroring line, was still reported OK).
 * Editors that default to "UTF-8 with BOM" on save produce exactly this file; the
 * artifact does not change either file's declared intent, so tolerating it here
 * matches how bin/check-consumer-config.php already tolerates one for .editorconfig.
 * Looped, not a single strip: reproduced the same false-accept one BOM stack
 * deeper (two concatenated UTF-8 BOMs) against a single `if`-shaped strip.
 *
 * UTF-16/UTF-32 BOMs are deliberately NOT handled: a file genuinely saved in one
 * of those encodings has every byte doubled or quadrupled throughout, not just a
 * marker prefix on otherwise-UTF-8 content — this gate's byte-oriented line
 * splitting and regex would not usefully parse the rest of such a file either, so
 * stripping only the marker would not make the file readable. Out of scope, the
 * same way a full gitattributes grammar is out of scope two paragraphs up.
 *
 * @param string $contents The raw file contents.
 *
 * @return list<string> The paths whose LAST token carries an active
 *                       `export-ignore` attribute.
 */
$parseExportIgnorePaths = static function (string $contents): array {
    /** @var array<array-key, bool> $state */
    $state = [];

    while (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }

    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if (($trimmed === '') || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^(\S+)\s+(.+)$/', $trimmed, $matches) !== 1) {
            continue;
        }

        $attributes = preg_split('/\s+/', trim($matches[2])) ?: [];

        // Iterated in order, not two independent in_array() presence checks: a
        // presence check cannot tell `export-ignore -export-ignore` (unset) from
        // `-export-ignore export-ignore` (set) on the SAME line, because both
        // tokens are simply present either way. Assigning as each token is reached
        // makes the textually last one win, matching git's own within-line rule —
        // reproduced against a real checkout: `git check-attr export-ignore` on a
        // line ending in `-export-ignore` reports `unset` regardless of what an
        // earlier token on that line said.
        //
        // Three tokens, not two: gitattributes(5) also has `!attr` ("unspecified" —
        // resets to unset, as if no rule had matched at all), which this simplified
        // two-file model treats the same as `-attr` since there is no lower-priority
        // rule underneath for it to fall back to. Reproduced: a real `git archive`
        // of a commit whose .gitattributes reads `/x export-ignore` then
        // `/x !export-ignore` still includes `/x`, and `git check-attr` reports
        // `unspecified` for it — an earlier version of this loop recognised only
        // `export-ignore`/`-export-ignore` and left `$state` unchanged for `!attr`,
        // so that second, real "turn it back off" line was silently ignored.
        foreach ($attributes as $attribute) {
            if ($attribute === 'export-ignore') {
                $state[$matches[1]] = true;
            } elseif (($attribute === '-export-ignore') || ($attribute === '!export-ignore')) {
                $state[$matches[1]] = false;
            }
        }
    }

    // array_keys() on a $state whose key happens to be a canonical-integer string
    // (a template line naming a bare numeric path with no leading slash — content
    // this gate treats as pull-request-controlled) comes back as PHP's own int key,
    // not the string $matches[1] captured it as; strval() restores the list<string>
    // this function's own signature promises, so a numeric path cannot reach
    // ltrim()'s string-typed parameter below as an int and throw under
    // declare(strict_types=1) instead of this gate's own graceful exit paths.
    return array_map(strval(...), array_keys(array_filter($state)));
};

/**
 * Reports a path as unreadable and exits(2) — a setup failure, distinct from the
 * drift verdict (exit 1) this gate otherwise reports. Shared by $readOrExit's own
 * unreadable branch and the templates/gitattributes is_link() guard below, which
 * reaches this same verdict without ever calling readCapped() — extracted at this
 * repository's own 2+-duplicate threshold once that guard added the second call
 * site. Do NOT route the .gitattributes is_link() check further down through this
 * closure: that one is deliberately NOT a setup failure (see its own comment) —
 * routing it here would replace the real, checkable drift report with an early
 * setup-failure exit, before the violation list this gate builds is ever reached.
 *
 * @param string $path Path that could not be read.
 *
 * @return never
 */
$reportUnreadable = static function (string $path): never {
    fwrite(\STDERR, sprintf("Cannot read %s.\n", $path));
    exit(2);
};

/**
 * Reads a lockstep file capped at MAX_GITATTRIBUTES_BYTES, reporting an oversize or
 * unreadable file and exiting(2). Both this gate's file reads shared this
 * three-branch block verbatim before this closure existed — extracted at this
 * repository's own 2+-duplicate threshold. bin/consumer-checks/helpers.php's own
 * readBounded() consolidated a duplicated read block too, for a related but
 * distinct reason (see that file's own docblock: it replaced SIX copies that used
 * to substitute an empty string for an oversize read, which fabricated violations
 * on the truncated content — not this closure's motivation).
 *
 * @param string $path Path to the file to read.
 *
 * @return string The contents. Never returns on failure.
 */
$readOrExit = static function (string $path) use ($reportUnreadable): string {
    $contents = readCapped($path, MAX_GITATTRIBUTES_BYTES);

    if ($contents === null) {
        fwrite(\STDERR, sprintf("%s is larger than the %d bytes this gate reads.\n", $path, MAX_GITATTRIBUTES_BYTES));
        exit(2);
    }

    if ($contents === false) {
        $reportUnreadable($path);
    }

    return $contents;
};

$templatePath = $root . '/templates/gitattributes';
$ownPath      = $root . '/.gitattributes';

// is_link() is checked FIRST, same as the $ownPath guard below, but treated as a
// setup failure rather than as absent: unlike a symlinked .gitattributes (a
// state a real checkout can legitimately end up in), this repository's own
// release process never produces a symlinked templates/gitattributes. Without
// this guard, $readOrExit follows the link via file_get_contents() and parses
// the target's content as if it were templates/gitattributes, echoing
// fragments of an arbitrary file the gate's author did not intend it to read.
if (is_link($templatePath)) {
    $reportUnreadable($templatePath);
}

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
//
// is_link() is checked FIRST and, if true, forces the same empty-content path as
// absent — is_file() alone follows a symlink and would read whatever regular
// file it points to. Git itself does not: a symlinked .gitattributes is not
// read for attribute purposes at all (reproduced with git check-attr and git
// archive), so a symlink here is exactly as ineffective as no file, and
// treating it as such is what makes this gate certify the same archive git
// itself will produce rather than the target file's content. Intentionally NOT
// $reportUnreadable(): unlike the template guard above, this is real,
// checkable drift, not a setup failure — routing it there would exit(2) before
// the violation list is ever built.
$ownContents = (!is_link($ownPath) && is_file($ownPath)) ? $readOrExit($ownPath) : '';
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
//
// Containment is proven on the PARENT directory below, not on the full target path:
// git tracks a symlink as a blob holding the literal target string, independent of
// whether that target resolves, and `git archive` ships a DANGLING symlink (one whose
// target does not exist) unconditionally (verified 2026-08-31; see the commit history
// for the reproduction recipe). realpath() on the full target FOLLOWS the link and
// fails once it cannot resolve that missing target — exactly the same false result as
// "this repository does not have that path", so a genuinely tracked, genuinely shipped
// dangling symlink silently read as not applicable (GH-112). The parent directory is a
// real, non-symlinked directory in every applicable case here (a symlinked ANCESTOR
// directory is the separate, narrower gap GH-119 tracks), so resolving it alone still
// proves containment without needing the leaf itself to resolve.
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
    // A NUL byte in a captured path (`\S` does not exclude it) throws a ValueError
    // out of realpath() regardless of this file's strict_types setting — PHP 8+
    // rejects any NUL-byte path unconditionally, not a strict-mode-only behavior
    // — reproduced identically on PHP 8.3/8.4/8.5 with and without strict_types.
    // Treated the same as the numeric-key case above: not a path this repository
    // "has", reported via this gate's own graceful exit paths rather than an
    // uncaught crash and a stack trace naming this file's own filesystem path.
    if (str_contains($path, "\0")) {
        continue;
    }

    $target     = $root . '/' . ltrim($path, '/');
    $parentReal = realpath(dirname($target));

    // Not applicable here — the qualifier this gate exists to apply. Silent, the
    // same way an absent .jscpd.json is silent for check-consumer-config.php: the
    // template lists a path a CONSUMER has, and this package legitimately does not
    // have every one of them. A parent directory realpath() cannot resolve under
    // $realRoot — absent, or escaping it via `..` — falls in here too: neither is
    // a path this repository "has" for this gate's purpose.
    if (($parentReal === false)
        || (($parentReal !== $realRoot) && !str_starts_with($parentReal, $realRoot . \DIRECTORY_SEPARATOR))
    ) {
        continue;
    }

    $base = basename($target);

    // A raw "." or ".." leaf component is not a real filename git can ever track —
    // reported by security-reviewer during this fix's own review: dirname() folds a
    // trailing ".." away textually BEFORE realpath() ever runs, so a path such as
    // ".." alone or "subdir/../.." resolves $parentReal to $realRoot itself and passes
    // the containment check above, then re-joining $base onto $parentReal rebuilds
    // "$realRoot/.." — one directory OUTSIDE the very root containment just proved
    // safe. Reproduced: templates/gitattributes containing `.. export-ignore`
    // reported `..` as a real, applicable, missing-export-ignore violation before
    // this guard existed. Rejecting the unresolved leaf here, rather than trusting
    // basename() of attacker-controlled $target, closes it without weakening the
    // parent-directory containment check itself.
    if (($base === '.') || ($base === '..')) {
        continue;
    }

    $leaf = $parentReal . \DIRECTORY_SEPARATOR . $base;

    // is_link() catches a git-tracked DANGLING symlink: file_exists() alone follows
    // a symlink before testing existence, so it reports false for a symlink whose
    // target does not exist even though git genuinely tracks and ships that symlink
    // (see the comment above $realRoot). A resolving symlink satisfies either check;
    // a genuinely absent leaf satisfies neither and is skipped as not applicable.
    if (!is_link($leaf) && !file_exists($leaf)) {
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
