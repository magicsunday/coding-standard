<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Shared helpers for the bin/consumer-checks/check-*.php contract checks
 * bin/check-consumer-config.php dispatches to (GH-48) — a shared include, not
 * an entry point, matching bin/support/safe-report-value.php's own boundary.
 *
 * Scoped to this gate rather than bin/support/, which holds primitives shared
 * with OTHER scripts (tests/check-version-lockstep.php, tests/lint-json.php):
 * fail()/tooLargeDetail()/stripBom()/yamlBlock()/readBounded() are all
 * specific to the copy-and-adapt-template contract these checks enforce, with
 * no consumer outside them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Records a drift for the final report.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $file       The config file the drift was found in.
 * @param string       $detail     What is wrong with it, as the report will read.
 *
 * @return void
 */
function fail(array &$violations, string $file, string $detail): void
{
    $violations[] = sprintf('%s: %s', $file, $detail);
}

/**
 * The oversize verdict, held once — the wording was edited at each reader separately
 * before this binding existed. Takes the bound as an argument, because the JSONC and
 * plain-text readers do not share one.
 *
 * @param int $bound The cap the reader was held to.
 *
 * @return string The detail line, ready for fail().
 */
function tooLargeDetail(int $bound): string
{
    return sprintf(
        'is larger than the %d bytes this gate checks, so it was not read in full. A shared-config stub is a few hundred bytes.',
        $bound
    );
}

/**
 * Strips a leading UTF-8 BOM.
 *
 * npm, Node, Biome and tsc all read a BOM-prefixed config and honour it, while
 * `json_decode` rejects it — so without this the gate reports a defect in a file
 * that loads perfectly well for every tool that matters.
 *
 * @param string $contents The raw file contents.
 *
 * @return string
 */
function stripBom(string $contents): string
{
    return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
}

/**
 * Isolates the indented block that follows a top-level YAML key.
 *
 * A full YAML parse is avoided to keep the gate dependency-free, so the block is
 * matched: every line that belongs to `<key>:` up to the next top-level key. Three
 * line shapes belong to it, and all three are legal YAML that a real parser
 * accepts — an indented entry, a blank line, and a comment or list item written at
 * column 0. The list-item alternative requires whitespace after the dash OR the end
 * of the line — what a block sequence entry has, including an empty one. Without
 * that it also swallowed `---` (a document separator, so the scan ran into the next
 * document) and a top-level key whose name begins with a dash, such as `-foreign:`
 * — both FALSE ACCEPTS, the worse direction. Enforcing the shorter rule and dropping
 * the `?` would break the capture at a bare `-` line, which is the truncation class
 * two paragraphs up. Requiring `[ \t]+` on every line truncated the capture at the first of
 * the other two, so a consumer whose file DID carry the required entry after a
 * blank line was told it did not.
 *
 * The input is normalised to end with a newline first. Every alternative consumes
 * one, which is what keeps the repeat from matching empty — but it also means a
 * file with no final newline would silently lose its last line, which is how the
 * first version of this fix traded one false reject for another.
 *
 * Shared by the `.phplint.yml` and `deptrac.yaml` checks: one defect on two paths
 * was fixed twice before this existed.
 *
 * The boundary that makes the column-0 list-item alternative safe: a top-level key
 * stops the scan before it, so an entry written under a LATER key is not captured.
 * Both directions are driven in tests/CheckConsumerConfigTest.php; list them
 * rather than copying their verdicts here, since a before/after table describes a
 * version of the code that no longer exists:
 *
 *     grep -n 'function.*Deptrac\|function.*Phplint' tests/CheckConsumerConfigTest.php
 *
 * @param string $contents The file contents, line endings already normalised.
 * @param string $key      The top-level key whose block to isolate.
 *
 * @return string|null The block, an empty string when the key is absent, or null when
 *                     the scan itself failed.
 */
function yamlBlock(string $contents, string $key): ?string
{
    $normalised = str_ends_with($contents, "\n") ? $contents : $contents . "\n";

    $pattern = sprintf(
        '/^%s\s*:[^\n]*\n((?:[ \t]+[^\n]*\n|[ \t]*(?:#[^\n]*)?\n|-(?:[ \t][^\n]*)?\n)*)/m',
        preg_quote($key, '/')
    );

    $matched = preg_match($pattern, $normalised, $matches);

    // A PCRE failure returns 0, not false, which the caller cannot tell from "the
    // key is not there" — and both callers then report a drift the file does not
    // have. Measured: this pattern exhausts the JIT stack at roughly 8000 block
    // lines, so a large but perfectly ordinary `.phplint.yml` was reported as
    // missing its `extensions:` block. The size cap at the read bounds it; this
    // arm makes the remaining case say what happened.
    if (($matched === false) || (preg_last_error() !== \PREG_NO_ERROR)) {
        return null;
    }

    return $matched === 1 ? $matches[1] : '';
}

/**
 * Reads a plain-text config under MAX_TEXT_BYTES, reporting an oversize file itself.
 *
 * Three outcomes, and the caller must keep them apart: a string is the contents,
 * `false` means the file could not be read, and `null` means it was read past the
 * bound and has ALREADY been reported.
 *
 * Null rather than an empty string, which is what six copies of this block used to
 * substitute. Measured: with `''` the content arms ran on the truncated read and
 * fabricated causes — five oversize configs produced twelve violations, of which
 * seven named things the files plainly carry (`not well-formed XML`, `must list
 * `- php``, three `.editorconfig` section arms). Reporting once here and letting
 * every caller return early on `null` is what avoids that.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $path       The file to read.
 * @param string       $label      How the file is named in the report.
 *
 * @return string|false|null The contents, false when unreadable, null when oversize.
 */
function readBounded(array &$violations, string $path, string $label): string|false|null
{
    $contents = readCapped($path, MAX_TEXT_BYTES);

    if ($contents === null) {
        fail($violations, $label, tooLargeDetail(MAX_TEXT_BYTES));
    }

    return $contents;
}
