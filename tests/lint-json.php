<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Validates that every JSON config this package ships parses. A malformed
 * biome.json or tsconfig.base.json would only surface in a consumer otherwise.
 *
 * Discovers `*.json` files rather than reading a hand-kept list — a shipped file
 * nobody added to the list is simply never validated, which is what happened to
 * tests/consumer/biome.json until it was added by hand.
 *
 * Run from the package root: php tests/lint-json.php
 *
 * An optional path argument points it at another directory, the same shape
 * tests/check-version-lockstep.php uses for its own fixture-driven harness — what
 * lets tests/lint-json-cases.sh drive this gate over fixtures instead of over this
 * repository alone, where every run takes the happy path and a green CI would be
 * indistinguishable from a gate that stopped checking.
 */

// This gate now walks the whole tree instead of reading a fixed list of paths, so
// a file name it reports is no longer necessarily one this repository's own
// authors chose — a pull request can add any `*.json` file anywhere the prune
// list below does not cover. safeReportValue() is the same guard the shipped
// gates under bin/ and tests/check-version-lockstep.php use, for the same reason:
// a file named to carry a `##[error]` sequence or a raw ESC would otherwise reach
// the report verbatim, and the legacy `##[…]` workflow command is matched by the
// runner mid-line, not only at column 0.
require_once __DIR__ . '/../bin/support/safe-report-value.php';
require_once __DIR__ . '/../bin/support/read-quietly.php';

/**
 * Directory names pruned from the scan wherever they occur — vendored or
 * installed trees, not something this package authored, plus `.git`: it is VCS
 * metadata, not a working copy of anything this package ships, and a local
 * clone can carry arbitrary `*.json` files under it depending on what tooling
 * has touched the repository — none of it in scope for this gate. Measured
 * against a real checkout: without this entry the scan also reported dozens of
 * unrelated `*.json` files nested under `.git`.
 */
const PRUNED_DIRECTORY_NAMES = ['.build', 'vendor', 'node_modules', '.git'];

/**
 * The deepest a discovered path may sit below the scan root.
 *
 * Every JSON file this package actually ships sits 0–2 levels down as of this
 * writing (tests/consumer/biome.json is the deepest) — re-derive with
 * `git ls-files '*.json' | awk -F/ '{print NF-1}' | sort -rn | head -1` rather
 * than trusting this count as time passes. 20 is generous headroom for a
 * layout this repository does not have today, while still bounding the number
 * of directory handles `RecursiveDirectoryIterator` holds open at once — a PR
 * that commits a directory nested a thousand levels deep (no symlink needed)
 * exhausts the process's open-file-descriptor table well before that.
 * Reproduced against `ulimit -n 1024`.
 *
 * The bound is enforced by THROWING once a directory this deep is reached
 * (see discoverJsonFiles() below), not by `RecursiveIteratorIterator::
 * setMaxDepth()` — that method stops descending silently, which would let a
 * malformed file nested past the bound go unreported while a well-formed file
 * within it still satisfies the vacuity guard: a partial scan reading as a
 * clean one, exactly what this file's own vacuity guard exists to rule out
 * for an EMPTY scan. Failing loudly instead costs nothing a real shipped
 * config would ever pay — see the constant's own value above.
 */
const MAX_SCAN_DEPTH = 20;

/**
 * The largest JSON file this gate reads whole, in bytes.
 *
 * Every JSON file this package actually ships is a few kilobytes as of this
 * writing — re-derive with `git ls-files '*.json' -z | xargs -0 wc -c` rather
 * than trusting this as time passes. This gate now discovers files rather than
 * reading a hand-picked list, so a file this large is one a pull request
 * added, not one this repository's own authors chose. Bounded at the READ (see
 * readQuietly()'s own `$maxBytes` argument),
 * not measured after it: reading a large file whole and checking `strlen()`
 * afterwards lets `file_get_contents()` materialise the entire file in memory
 * first — reproduced with a 200 MB fixture at `memory_limit=128M`, which
 * raised an uncaught `Allowed memory size exhausted` fatal, with a stack trace
 * naming this repository's own file layout, in place of this gate's own clean
 * `exit(1)` diagnostic.
 */
const MAX_JSON_LINT_BYTES = 1048576;

/**
 * Files this discovery must not report despite matching `*.json`, each with its
 * own reason. This is the one hand-kept list left behind, and it is exclusions
 * rather than inclusions: a shipped file omitted here is still found and
 * validated, only a file named here is deliberately skipped.
 */
const EXCLUDED_JSON_FILES = [
    // JSONC by design — `tsc` accepts comments and trailing commas there, and a
    // strict json_decode() would reject it on that basis alone, not on a real
    // defect.
    'tests/consumer/tsconfig.json',
    // npm's own lockfile. Gitignored (.gitignore) and written only once
    // `npm install` has run, so it is a locally generated artefact rather than a
    // config this package ships — scanning it would make the result depend on
    // whether that install already happened.
    'package-lock.json',
];

/**
 * Discovers every `*.json` file under $root, pruning vendored/installed trees and
 * the documented exceptions above.
 *
 * `RecursiveDirectoryIterator`, not a shell-out to `find` or `git ls-files`: the
 * git form returns EMPTY on a checkout git declines to read — a container whose
 * UID does not own the worktree hits "detected dubious ownership" — and this
 * gate's whole purpose is that an empty run must not read as a clean one; the
 * vacuity guard below cannot tell that apart from "there really are no JSON
 * files" if the listing came back silently empty by a different route.
 *
 * `RecursiveIteratorIterator` is left to throw rather than catching
 * `CATCH_GET_CHILD`: a directory it cannot descend into aborts the whole scan
 * instead of silently reporting a partial one — a partial scan is the same
 * defect as an empty one, one step milder.
 *
 * A SYMLINKED directory is never descended into — not by anything in this
 * callback, but by `RecursiveDirectoryIterator::hasChildren()` itself, which
 * reports false for a symlinked entry when the directory iterator is
 * constructed WITHOUT `FilesystemIterator::FOLLOW_SYMLINKS` — which this code
 * does not pass. Verified against PHP 8.3 and 8.5: passing that flag makes
 * `hasChildren()` return true for the same symlinked directory, so this
 * property depends on the flag staying absent, not on symlinked directories
 * being unrecursable in general — a future edit that adds `FOLLOW_SYMLINKS`
 * for an unrelated reason would reopen exactly the case the fixture below
 * guards. A symlinked LEAF *.json file has no such protection either way — SPL
 * offers it to the callback as an ordinary file — so the caller separately
 * refuses to read through it (see the main loop below): without that check, a
 * leaf `alias.json` symlinked to `.build/vendor/composer/installed.json` —
 * installed by the very `composer install` step that runs before this gate in
 * CI — would be discovered, followed, and reported `OK` despite `.build`/
 * `vendor` being pruned by name one line above; the same route reaches
 * anything else on the runner the process can read. Reproduced against
 * exactly that path.
 *
 * @param string $root Directory to scan, already resolved to a real path.
 *
 * @return list<string> Paths relative to $root, sorted.
 */
function discoverJsonFiles(string $root): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file, string $_key, RecursiveDirectoryIterator $iterator) use ($root): bool {
                if ($iterator->hasChildren()) {
                    if (in_array($file->getFilename(), PRUNED_DIRECTORY_NAMES, true)) {
                        return false;
                    }

                    $depth = substr_count($file->getPathname(), '/') - substr_count($root, '/');

                    if ($depth >= MAX_SCAN_DEPTH) {
                        throw new UnexpectedValueException(sprintf(
                            '%s is nested %d levels below the scan root, past the %d this gate scans',
                            $file->getPathname(),
                            $depth,
                            MAX_SCAN_DEPTH
                        ));
                    }

                    return true;
                }

                return $file->getExtension() === 'json';
            }
        )
    );

    $files = [];

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $relative = substr($file->getPathname(), strlen($root) + 1);

        if (in_array($relative, EXCLUDED_JSON_FILES, true)) {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

$root     = $argv[1] ?? dirname(__DIR__);
$realRoot = realpath($root);

// realpath() alone only rejects a path that does not exist at all — it resolves
// an EXISTING non-directory (a plain file passed as the root argument) just
// fine, and that case must not fall through to the generic "could not scan"
// catch below with a message this branch already has a clearer one for.
if ($realRoot === false || !is_dir($realRoot)) {
    fwrite(\STDERR, sprintf("Not a directory: %s\n", safeReportValue($root)));
    exit(1);
}

try {
    $files = discoverJsonFiles($realRoot);
} catch (UnexpectedValueException $exception) {
    // A directory name reaching this message is no less PR-controlled than a
    // discovered file name — an unreadable subdirectory (a permission-000
    // fixture, or a real one in a broken checkout) surfaces its own name here,
    // and every other report site in this file scrubs the same kind of value.
    fwrite(\STDERR, sprintf(
        "Could not scan %s — the scan is incomplete, so its result says nothing: %s\n",
        safeReportValue($realRoot),
        safeReportValue($exception->getMessage())
    ));
    exit(1);
}

// A scan that matches nothing would make this gate pass vacuously — the same
// failure mode tests/check-version-lockstep.php's own vacuity guard exists to
// prevent, applied to a listing rather than to a pattern.
if ($files === []) {
    fwrite(\STDERR, sprintf("No JSON files found under %s — the gate matched nothing.\n", safeReportValue($realRoot)));
    exit(1);
}

$failed = false;

foreach ($files as $file) {
    $path = $realRoot . '/' . $file;

    // A discovered path is not necessarily a real, in-tree file this package
    // ships. `is_file()` alone would FOLLOW a symlink and validate whatever it
    // points at — reproduced with a leaf `alias.json` pointing outside $realRoot
    // entirely: reading through it turns OK/MISSING/UNREADABLE/INVALID into a
    // filesystem-probing oracle for arbitrary paths on the CI runner, for
    // anything a PR can name a symlink after. `is_link()` is checked first and
    // rejects it either way — dangling (nothing behind it) or live (something
    // behind it this gate must not vouch for) both report the same "this is not
    // a real shipped file" verdict.
    if (is_link($path) || !is_file($path)) {
        fwrite(\STDERR, sprintf("MISSING  %s\n", safeReportValue($file)));
        $failed = true;

        continue;
    }

    $contents = readCapped($path, MAX_JSON_LINT_BYTES);

    if ($contents === null) {
        fwrite(\STDERR, sprintf("TOO LARGE  %s\n", safeReportValue($file)));
        $failed = true;

        continue;
    }

    if ($contents === false) {
        fwrite(\STDERR, sprintf("UNREADABLE  %s\n", safeReportValue($file)));
        $failed = true;

        continue;
    }

    try {
        json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(\STDERR, sprintf("INVALID  %s — %s\n", safeReportValue($file), safeReportValue($exception->getMessage())));
        $failed = true;

        continue;
    }

    printf("OK       %s\n", safeReportValue($file));
}

if ($failed) {
    exit(1);
}

printf("lint-json: OK — %d JSON file(s) parse.\n", count($files));
exit(0);
