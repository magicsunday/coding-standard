<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The deptrac.yaml (optional) contract check, extracted out of
 * bin/check-consumer-config.php (GH-48) once that file crossed 1000 lines. A
 * shared include, not an entry point — see bin/consumer-checks/helpers.php's
 * own docblock for the boundary this file follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts that deptrac.yaml imports the shared layer ruleset.
 *
 * A consumer may set its own `paths`, but dropping the shared `imports` line
 * silently stops enforcing the canonical architecture — the one part of this copy
 * that must not drift. Assert the import is present; the path prefix is free
 * (`vendor/` or a build-dir layout resolve it differently), only the shared file
 * itself is pinned. A full YAML parse is avoided to keep the gate dependency-free;
 * the import path is distinctive enough that a whole-file match is unambiguous.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkDeptracYaml(array &$violations, string $repoRoot): void
{
    $deptracFile = $repoRoot . '/deptrac.yaml';

    if (!is_file($deptracFile)) {
        return;
    }

    $contents = readBounded($violations, $deptracFile, 'deptrac.yaml');

    if ($contents === null) {
        // Reported as oversize by readBounded(); the arms below would run on a
        // truncated read and name causes the file does not have.
        return;
    }

    if ($contents === false) {
        fail($violations, 'deptrac.yaml', 'exists but cannot be read.');

        return;
    }

    // A BOM is reported rather than stripped, because deptrac refuses the file
    // outright: measured against 4.x, a BOM'd config answers `no extension able
    // to load "<BOM>imports"` and the run dies. Same class as the `"//"` key in
    // a Biome config — valid on its own terms, unloadable for the tool — so it
    // is a defect rather than a spelling the gate should tolerate. Stripping it
    // would report OK for a ruleset that never loads. (The sibling `.phplint.yml`
    // read strips instead, because phplint 9.7.2 reads a BOM'd config and runs.)
    //
    // The anchors below cannot stand in for it: the shipped template opens with
    // a comment, so in the common case the BOM displaces nothing and `^imports`
    // still matches on its own line — the file reads as correct and deptrac
    // still refuses it.
    $bomPrefixed = str_starts_with($contents, "\xEF\xBB\xBF");

    if ($bomPrefixed) {
        fail($violations, 'deptrac.yaml', 'starts with a UTF-8 BOM, which deptrac refuses to load — it reports the first key as unknown and the run dies.');

        // Stripped for the checks BELOW, having been reported above. A consumer
        // file that opens directly with `imports:` — the shipped template does
        // not, but a hand-written one may — has that anchor displaced by the
        // BOM, so leaving it in place would add a second report saying the
        // shared ruleset is not imported for a file that imports it. One defect,
        // one report.
        $contents = substr($contents, 3);
    }

    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    // Block-scoped for a load-bearing reason: Deptrac reads the ruleset from
    // `imports` and nowhere else, so the same path under `deptrac.exclude_files`
    // imports nothing while looking like it does.
    $importsBlock = yamlBlock($contents, 'imports');

    // Accept the shared import in any equivalent YAML shape: an optional path
    // prefix that ENDS at a segment boundary (`vendor/` or `.build/vendor/` — so a
    // near-miss `notmagicsunday/…` copy is rejected), an optionally quoted scalar,
    // and an optional trailing inline comment. The `~` delimiter keeps the literal
    // `#` of a YAML comment unescaped.
    // The quote is captured and back-referenced, not written as two independent
    // optional atoms: those accept `- 'vendor/…/layers.yaml"`, a scalar YAML
    // itself cannot parse. An empty capture back-references the empty string, so
    // the unquoted form still matches.
    //
    // The prefix class excludes quotes for the same reason. With `\S*/` the
    // back-reference was asymmetric: an unbalanced OPENING quote was swallowed by
    // the prefix while group 1 matched empty, so `- "vendor/…/layers.yaml` (no
    // closing quote) was accepted while its single-quote mirror was not.
    $importPattern = '~^[ \t]*-[ \t]*([\'"]?)(?:[^\s\'"]*/)?magicsunday/coding-standard/deptrac/layers\.yaml\1[ \t]*(?:#.*)?$~m';

    if ($importsBlock === null) {
        fail($violations, 'deptrac.yaml', sprintf('the `imports:` block could not be scanned (%s), so this gate cannot answer for it.', preg_last_error_msg()));
    } elseif (($importsBlock === '') || (preg_match($importPattern, $importsBlock) !== 1)) {
        fail($violations, 'deptrac.yaml', 'must import the shared `magicsunday/coding-standard/deptrac/layers.yaml` ruleset under the top-level `imports:` key.');
    }
}
