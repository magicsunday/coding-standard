<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Lockstep gate for the copy-and-adapt templates.
 *
 * The importable configs (phpstan/base.neon, rector/base.php, php-cs-fixer/base.php)
 * are consumed by reference, so their rule content cannot drift. The copy-and-adapt
 * templates (phpunit.xml, .jscpd.json, .phplint.yml, .editorconfig, deptrac.yaml) have no
 * include-from-vendor mechanism, so every consumer keeps a physical copy — and that
 * copy is where the house standard silently drifts loose (a phpunit.xml that quietly
 * drops `requireCoverageMetadata`, a jscpd config on a stale reporter name).
 *
 * This gate asserts the STABLE region of each copy — the strict flags and the
 * uniform `src`/`tests` layout every module shares — while ignoring the genuinely
 * per-repo parts (the vendor-dir-dependent path prefixes, the per-repo `format`,
 * `path` and `ignore` lists). It is assertion-based, not a byte-diff, so a consumer
 * that legitimately scans an extra JS directory or uses a different vendor-dir is not
 * flagged, but a loosened strictness flag is.
 *
 * Usage (from a consumer repo root, wired as a `ci:test:php:templates` script):
 *
 *     php .build/vendor/magicsunday/coding-standard/bin/check-consumer-config.php .
 *
 * The JS/TS configs (biome.json, tsconfig.json) are checked too, on a narrower
 * contract: they are `extends` stubs rather than copies, so their rule content
 * cannot drift — but the LINK can. What is asserted for THIS pair — not counted
 * here, because a number drifts and a boundary is disputable (does the shared
 * package.json-readability precondition belong to the count or not?) — is every
 * `fail(…)` call in bin/consumer-checks/check-biome-tsconfig.php
 * (`grep -c 'fail(' bin/consumer-checks/check-biome-tsconfig.php`) — restated as
 * a fixed list here it would be the third copy to drift out of step with the code,
 * after this file's own history of that.
 *
 * Exit code 0 = every present config matches the stable canon; 1 = at least one
 * drift. A config file that is absent is skipped (a consumer without JS has no
 * .jscpd.json, biome.json or tsconfig.json); the strict phpunit.xml is REQUIRED.
 *
 * Split per contract under bin/consumer-checks/ (GH-48) once this file crossed
 * 1000 lines — each check-*.php there declares one checkXxx() function and is a
 * shared include, not an entry point, matching bin/support/safe-report-value.php's
 * own boundary; this file stays the thin orchestrator dispatching to them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

// This is a global-namespace entry script, so built-in functions are called
// unqualified (a `use function` import would be a no-op here).

/**
 * The largest JSONC config this gate will read, in bytes.
 *
 * Written once: the number, the two report messages and the comment on
 * check-biome-tsconfig.php's own $stripJsonc were four hand-kept copies. 128 KiB
 * against a measured 2.2 KB for the biggest config this package ships and 5.6 KB
 * for the largest found anywhere on the author's machine — ~23x headroom.
 */
const MAX_JSONC_BYTES = 131072;

/**
 * The largest plain-text config this gate will read, in bytes.
 *
 * Separate from MAX_JSONC_BYTES because it bounds a different cost: the JSONC cap
 * also bounds a string-aware scan, while these files are read and parsed linearly.
 * The bound exists for memory alone — measured, a 196 MB `.editorconfig` at
 * memory_limit=128M ends in `Allowed memory size exhausted`, exit 255, with no gate
 * diagnostic at all. That is the outcome readQuietly()'s scoped handler exists to
 * prevent, and it was reachable at every call site that passed no bound.
 *
 * 1 MiB rather than the JSONC bound: `.editorconfig` and `phpunit.xml` are read
 * whole by tools that impose no limit of their own, and this harness already drives
 * a legitimate 256 KiB `.editorconfig` fixture past the whitespace-run arm. Four
 * times that leaves the bound where only a file no consumer wrote by hand meets it.
 */
const MAX_TEXT_BYTES = 1048576;

$repoRoot = $argv[1] ?? '.';

if (!is_dir($repoRoot)) {
    fwrite(\STDERR, sprintf("Not a directory: %s\n", $repoRoot));
    exit(2);
}

/**
 * This package's OWN installation root — the directory holding `bin/` and
 * `biome/` as siblings, not the consumer repository `$repoRoot` points at.
 * Read-only source for the rule names GH-36's per-rule check derives from
 * `biome/base.json` rather than hand-copying.
 */
$packageRoot = \dirname(__DIR__);

/** @var list<string> $violations */
$violations = [];

// safeReportValue(), readQuietly() and mergeConfigLayer() — shared, see each
// header for the boundary and the requirers. Required rather than duplicated.
require_once __DIR__ . '/support/safe-report-value.php';
require_once __DIR__ . '/support/read-quietly.php';
require_once __DIR__ . '/support/merge-config-layer.php';

require_once __DIR__ . '/consumer-checks/helpers.php';
require_once __DIR__ . '/consumer-checks/check-phpunit-xml.php';
require_once __DIR__ . '/consumer-checks/check-jscpd-json.php';
require_once __DIR__ . '/consumer-checks/check-phplint-yml.php';
require_once __DIR__ . '/consumer-checks/check-editorconfig.php';
require_once __DIR__ . '/consumer-checks/check-deptrac-yaml.php';
require_once __DIR__ . '/consumer-checks/check-biome-tsconfig.php';

checkPhpunitXml($violations, $repoRoot);
checkJscpdJson($violations, $repoRoot);
checkPhplintYml($violations, $repoRoot);
checkEditorconfig($violations, $repoRoot);
checkDeptracYaml($violations, $repoRoot);
checkBiomeTsconfig($violations, $repoRoot, $packageRoot);

// --- Report ---
if (count($violations) === 0) {
    fwrite(\STDOUT, "check-consumer-config: OK — every present template copy matches the stable canon.\n");
    exit(0);
}

fwrite(\STDERR, sprintf("check-consumer-config: %d drift(s) from the shared template canon:\n", count($violations)));

foreach ($violations as $violation) {
    fwrite(\STDERR, sprintf("  - %s\n", $violation));
}

fwrite(\STDERR, "\nAlign the file(s) with the templates/ directory of magicsunday/coding-standard.\n");
exit(1);
