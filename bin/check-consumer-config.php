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
 * templates (phpunit.xml, .jscpd.json, .phplint.yml, .editorconfig) have no
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
 * Exit code 0 = every present config matches the stable canon; 1 = at least one
 * drift. A config file that is absent is skipped (a consumer without JS has no
 * .jscpd.json); the strict phpunit.xml is REQUIRED.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

// This is a global-namespace entry script, so built-in functions are called
// unqualified (a `use function` import would be a no-op here).

$repoRoot = $argv[1] ?? '.';

if (!is_dir($repoRoot)) {
    fwrite(\STDERR, sprintf("Not a directory: %s\n", $repoRoot));
    exit(2);
}

/** @var list<string> $violations */
$violations = [];

/**
 * Records a drift for the final report.
 *
 * @param list<string> $violations
 */
$fail = static function (array &$violations, string $file, string $detail): void {
    $violations[] = sprintf('%s: %s', $file, $detail);
};

// --- phpunit.xml (REQUIRED): the strict-flag set + the uniform src/tests layout ---
$phpunitPath = $repoRoot . '/phpunit.xml';
$phpunitDist = $repoRoot . '/phpunit.xml.dist';
$phpunitFile = is_file($phpunitPath) ? $phpunitPath : ($phpunitDist !== $phpunitPath && is_file($phpunitDist) ? $phpunitDist : null);

if ($phpunitFile === null) {
    $fail($violations, 'phpunit.xml', 'missing — the strict PHPUnit config is required.');
} else {
    $xml = @simplexml_load_file($phpunitFile);

    if ($xml === false) {
        $fail($violations, 'phpunit.xml', 'not well-formed XML.');
    } else {
        // Every strict attribute must be present AND "true" on the root element.
        $requiredRootFlags = [
            'requireCoverageMetadata',
            'beStrictAboutCoverageMetadata',
            'beStrictAboutOutputDuringTests',
            'failOnRisky',
            'failOnWarning',
            'failOnNotice',
            'failOnDeprecation',
            'failOnPhpunitDeprecation',
            'failOnPhpunitNotice',
        ];

        $rootAttrs = $xml->attributes();

        foreach ($requiredRootFlags as $flag) {
            $value = $rootAttrs[$flag] ?? null;

            if ($value === null) {
                $fail($violations, 'phpunit.xml', sprintf('missing strict flag `%s="true"`.', $flag));

                continue;
            }

            if ((string) $value !== 'true') {
                $fail($violations, 'phpunit.xml', sprintf('strict flag `%s` must be "true", is "%s".', $flag, (string) $value));
            }
        }

        // The <source> element must restrict notices and warnings and include src.
        $source = $xml->source ?? null;

        if ($source === null) {
            $fail($violations, 'phpunit.xml', 'missing a <source> element.');
        } else {
            foreach (['restrictNotices', 'restrictWarnings'] as $flag) {
                $value = $source->attributes()[$flag] ?? null;

                if ($value === null || (string) $value !== 'true') {
                    $fail($violations, 'phpunit.xml', sprintf('<source> must set `%s="true"`.', $flag));
                }
            }

            $includeDirs = [];

            foreach ($source->include->directory ?? [] as $dir) {
                $includeDirs[] = (string) $dir;
            }

            if (!in_array('src', $includeDirs, true)) {
                $fail($violations, 'phpunit.xml', '<source><include> must cover the `src` directory.');
            }
        }

        // The test suite must run `tests` and exclude the phpat Architecture dir
        // when that directory exists (a phpat rule class is not a PHPUnit test).
        $suiteDirs    = [];
        $suiteExcl    = [];

        foreach ($xml->testsuites->testsuite ?? [] as $suite) {
            foreach ($suite->directory as $dir) {
                $suiteDirs[] = (string) $dir;
            }

            foreach ($suite->exclude as $excl) {
                $suiteExcl[] = (string) $excl;
            }
        }

        if (!in_array('tests', $suiteDirs, true)) {
            $fail($violations, 'phpunit.xml', 'a test suite must run the `tests` directory.');
        }

        if (is_dir($repoRoot . '/tests/Architecture') && !in_array('tests/Architecture', $suiteExcl, true)) {
            $fail($violations, 'phpunit.xml', 'the phpat `tests/Architecture` directory must be excluded from the suite.');
        }
    }
}

// --- .jscpd.json (optional): zero-tolerance thresholds + current reporter name ---
$jscpdFile = $repoRoot . '/.jscpd.json';

if (is_file($jscpdFile)) {
    $json = json_decode((string) file_get_contents($jscpdFile), true);

    if (!is_array($json)) {
        $fail($violations, '.jscpd.json', 'not valid JSON.');
    } else {
        if (($json['threshold'] ?? null) !== 0) {
            $fail($violations, '.jscpd.json', '`threshold` must be 0 (zero-tolerance).');
        }

        if (($json['exitCode'] ?? null) !== 1) {
            $fail($violations, '.jscpd.json', '`exitCode` must be 1 so a clone fails the build.');
        }

        $minTokens = $json['minTokens'] ?? null;

        if (!is_int($minTokens) || $minTokens > 100) {
            $fail($violations, '.jscpd.json', '`minTokens` must be present and <= 100.');
        }

        $reporters = $json['reporters'] ?? [];

        if (!is_array($reporters) || !in_array('console-full', $reporters, true)) {
            $fail($violations, '.jscpd.json', '`reporters` must contain "console-full" (the jscpd 5 name; "consoleFull" is the removed v4 spelling).');
        }
    }
}

// --- .phplint.yml (optional): must lint the php extension ---
$phplintFile = $repoRoot . '/.phplint.yml';

if (is_file($phplintFile)) {
    $contents = (string) file_get_contents($phplintFile);

    // A full YAML parse is avoided to keep the gate dependency-free; instead the
    // `extensions:` block is isolated (its indented list items, up to the next
    // top-level key) and `php` is required INSIDE that block — a `- php` sitting
    // under some other list must not satisfy the check.
    $extensionsBlock = '';

    if (preg_match('/^extensions\s*:[^\n]*\n((?:[ \t]+[^\n]*\n?)*)/m', $contents, $m) === 1) {
        $extensionsBlock = $m[1];
    }

    if ($extensionsBlock === '' || preg_match('/^[ \t]*-[ \t]*php[ \t]*$/m', $extensionsBlock) !== 1) {
        $fail($violations, '.phplint.yml', 'the `extensions:` block must list `- php`.');
    }
}

// --- .editorconfig (optional): the 4-space house indent + Makefile tab ---
$editorconfigFile = $repoRoot . '/.editorconfig';

if (is_file($editorconfigFile)) {
    $contents = (string) file_get_contents($editorconfigFile);

    // `root = true` is a preamble key before any section. The indent rules must
    // hold for the GLOBAL `[*]` section specifically — a repo could set `[*]` to
    // tabs and only put spaces in a narrower `[*.md]`-style section, which must
    // not pass. Isolate the `[*]` section (up to the next `[...]` header or EOF).
    if (preg_match('/^\s*root\s*=\s*true\s*$/m', $contents) !== 1) {
        $fail($violations, '.editorconfig', 'must set `root = true`.');
    }

    $globalSection = '';

    if (preg_match('/^\[\*\]\s*$(.*?)(?=^\[|\z)/ms', $contents, $m) === 1) {
        $globalSection = $m[1];
    }

    if ($globalSection === '') {
        $fail($violations, '.editorconfig', 'must define a global `[*]` section.');
    } else {
        if (preg_match('/^\s*indent_style\s*=\s*space\s*$/m', $globalSection) !== 1) {
            $fail($violations, '.editorconfig', 'the `[*]` section must set `indent_style = space`.');
        }

        if (preg_match('/^\s*indent_size\s*=\s*4\s*$/m', $globalSection) !== 1) {
            $fail($violations, '.editorconfig', 'the `[*]` section must set `indent_size = 4`.');
        }
    }
}

// --- Report ---
if (count($violations) === 0) {
    fwrite(\STDOUT, "check-consumer-config: OK — every present template copy matches the stable canon.\n");
    exit(0);
}

fwrite(\STDERR, sprintf("check-consumer-config: %d drift(s) from the shared template canon:\n", count($violations)));

foreach ($violations as $violation) {
    fwrite(\STDERR, sprintf("  - %s\n", $violation));
}

fwrite(\STDERR, "\nAlign the file(s) with vendor/magicsunday/coding-standard/templates/.\n");
exit(1);
