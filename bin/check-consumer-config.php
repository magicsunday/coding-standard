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
 * cannot drift — but the LINK can. The gate asserts the extends is present and
 * that the strict flags underneath it are not overridden back to false.
 *
 * Exit code 0 = every present config matches the stable canon; 1 = at least one
 * drift. A config file that is absent is skipped (a consumer without JS has no
 * .jscpd.json, biome.json or tsconfig.json); the strict phpunit.xml is REQUIRED.
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
$phpunitFile = null;

if (is_file($phpunitPath)) {
    $phpunitFile = $phpunitPath;
} elseif (is_file($phpunitDist)) {
    $phpunitFile = $phpunitDist;
}

if ($phpunitFile === null) {
    $fail($violations, 'phpunit.xml', 'missing — the strict PHPUnit config is required.');
} else {
    // A malformed file makes simplexml_load_file emit an E_WARNING per libxml
    // error and return false; capture those warnings through a scoped handler
    // rather than the banned `@` prefix, then branch on the return value.
    set_error_handler(static fn (): bool => true);

    try {
        $xml = simplexml_load_file($phpunitFile);
    } finally {
        restore_error_handler();
    }

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

                if (($value === null) || ((string) $value !== 'true')) {
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

        if (!is_int($minTokens) || ($minTokens > 100)) {
            $fail($violations, '.jscpd.json', '`minTokens` must be present and <= 100.');
        }

        // minLines is the second detection threshold; raising it (to 9999, say)
        // disables clone detection just as raising minTokens would.
        $minLines = $json['minLines'] ?? null;

        if (!is_int($minLines) || ($minLines > 5)) {
            $fail($violations, '.jscpd.json', '`minLines` must be present and <= 5.');
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
    // Normalise line endings first: the block-isolation regex uses `\n`, so a CRLF
    // file would leave a trailing `\r` on each list item and false-fail the `- php`
    // match (the .editorconfig parser normalises the same way via preg_split('/\R/')).
    $contents = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($phplintFile));

    // A full YAML parse is avoided to keep the gate dependency-free; instead the
    // `extensions:` block is isolated (its indented list items, up to the next
    // top-level key) and `php` is required INSIDE that block — a `- php` sitting
    // under some other list must not satisfy the check.
    $extensionsBlock = '';

    if (preg_match('/^extensions\s*:[^\n]*\n((?:[ \t]+[^\n]*\n?)*)/m', $contents, $m) === 1) {
        $extensionsBlock = $m[1];
    }

    if (($extensionsBlock === '') || (preg_match('/^[ \t]*-[ \t]*php[ \t]*$/m', $extensionsBlock) !== 1)) {
        $fail($violations, '.phplint.yml', 'the `extensions:` block must list `- php`.');
    }
}

// --- .editorconfig (optional): the 4-space house indent + Makefile tab ---
$editorconfigFile = $repoRoot . '/.editorconfig';

if (is_file($editorconfigFile)) {
    $contents = (string) file_get_contents($editorconfigFile);

    // EditorConfig is section-scoped INI: `root` is a preamble key valid only
    // BEFORE the first `[section]`, and each key belongs to the section it sits
    // under. A per-line whole-file regex accepts drift (a `root` moved into a
    // section, `indent_style` set only in a narrow `[*.md]` while `[*]` uses tabs,
    // the Makefile override deleted), so parse the file into a preamble map plus a
    // per-section key map and assert each value in the section it must hold in.
    /** @var array<string, string> $preamble */
    $preamble = [];
    /** @var array<string, array<string, string>> $sections */
    $sections = [];
    $current  = null;

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if (($trimmed === '') || ($trimmed[0] === '#') || ($trimmed[0] === ';')) {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $trimmed, $m) === 1) {
            $current            = $m[1];
            $sections[$current] = $sections[$current] ?? [];

            continue;
        }

        if (preg_match('/^([^=]+?)\s*=\s*(.*)$/', $trimmed, $m) === 1) {
            $key   = strtolower(trim($m[1]));
            $value = strtolower(trim($m[2]));

            if ($current === null) {
                $preamble[$key] = $value;
            } else {
                $sections[$current][$key] = $value;
            }
        }
    }

    if (($preamble['root'] ?? null) !== 'true') {
        $fail($violations, '.editorconfig', 'must set `root = true` in the preamble (before any section).');
    }

    $global = $sections['*'] ?? null;

    if ($global === null) {
        $fail($violations, '.editorconfig', 'must define a global `[*]` section.');
    } else {
        if (($global['indent_style'] ?? null) !== 'space') {
            $fail($violations, '.editorconfig', 'the `[*]` section must set `indent_style = space`.');
        }

        if (($global['indent_size'] ?? null) !== '4') {
            $fail($violations, '.editorconfig', 'the `[*]` section must set `indent_size = 4`.');
        }
    }

    // Makefiles keep hard tabs; the canonical override is `[{Makefile,*.mk}]`. The
    // glob is case-sensitive, so the section name must match exactly — a lowercase
    // `{makefile,*.mk}` would not match the real `Makefile` and silently apply no
    // tab rule, so it is NOT accepted as an equivalent.
    $makefile = $sections['{Makefile,*.mk}'] ?? null;

    if (($makefile === null) || (($makefile['indent_style'] ?? null) !== 'tab')) {
        $fail($violations, '.editorconfig', 'must keep the `[{Makefile,*.mk}]` section with `indent_style = tab`.');
    }
}

// --- deptrac.yaml (optional): must import the shared layer ruleset ---
// A consumer may set its own `paths`, but dropping the shared `imports` line
// silently stops enforcing the canonical architecture — the one part of this copy
// that must not drift. Assert the import is present; the path prefix is free
// (`vendor/` or a build-dir layout resolve it differently), only the shared file
// itself is pinned. A full YAML parse is avoided to keep the gate dependency-free;
// the import path is distinctive enough that a whole-file match is unambiguous.
$deptracFile = $repoRoot . '/deptrac.yaml';

if (is_file($deptracFile)) {
    $contents = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($deptracFile));

    // Isolate the TOP-LEVEL `imports:` block (its indented items, up to the next
    // top-level key) and require the shared file INSIDE it — the same block-scoping
    // the `.phplint.yml` check uses. A path sitting under some other list (e.g.
    // `deptrac.exclude_files`) must not satisfy the check: Deptrac only loads the
    // ruleset from `imports`.
    $importsBlock = '';

    if (preg_match('/^imports\s*:[^\n]*\n((?:[ \t]+[^\n]*\n?)*)/m', $contents, $m) === 1) {
        $importsBlock = $m[1];
    }

    // Accept the shared import in any equivalent YAML shape: an optional path
    // prefix that ENDS at a segment boundary (`vendor/` or `.build/vendor/` — so a
    // near-miss `notmagicsunday/…` copy is rejected), an optionally quoted scalar,
    // and an optional trailing inline comment. The `~` delimiter keeps the literal
    // `#` of a YAML comment unescaped.
    $importPattern = '~^[ \t]*-[ \t]*[\'"]?(?:\S*/)?magicsunday/coding-standard/deptrac/layers\.yaml[\'"]?[ \t]*(?:#.*)?$~m';

    if (($importsBlock === '') || (preg_match($importPattern, $importsBlock) !== 1)) {
        $fail($violations, 'deptrac.yaml', 'must import the shared `magicsunday/coding-standard/deptrac/layers.yaml` ruleset under the top-level `imports:` key.');
    }
}

// --- biome.json / tsconfig.json (optional): the JS/TS counterpart ------------
//
// These are NOT copy-and-adapt templates — they are one-line `extends` stubs, so
// unlike the PHP templates their rule content genuinely cannot drift. What CAN
// drift is the link itself: a consumer that drops the `extends`, or overrides a
// strict flag back to false underneath it, keeps a green build while enforcing
// its own weaker standard. That is the same failure class the template gate
// exists for, so it is checked the same way.
//
// tsconfig.json is JSONC by specification (TypeScript documents comments in it),
// and Biome accepts a biome.jsonc, so a plain json_decode would report a
// perfectly legal consumer file as "not valid JSON". Strip comments and trailing
// commas first — string-aware, so a `"//"` KEY and any `//` inside a string
// value survive; only real comments are removed.
$stripJsonc = static function (string $json): string {
    $out       = '';
    $length    = strlen($json);
    $inString  = false;
    $inLine    = false;
    $inBlock   = false;
    $escaped   = false;

    for ($i = 0; $i < $length; ++$i) {
        $char = $json[$i];
        $next = $json[$i + 1] ?? '';

        if ($inLine) {
            if ($char === "\n") {
                $inLine = false;
                $out   .= $char;
            }

            continue;
        }

        if ($inBlock) {
            if (($char === '*') && ($next === '/')) {
                $inBlock = false;
                ++$i;
            }

            continue;
        }

        if ($inString) {
            $out .= $char;

            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = false;
            }

            continue;
        }

        if ($char === '"') {
            $inString = true;
            $out     .= $char;

            continue;
        }

        if ($char === '/') {
            if ($next === '/') {
                $inLine = true;

                continue;
            }

            if ($next === '*') {
                $inBlock = true;

                continue;
            }
        }

        $out .= $char;
    }

    // Trailing commas are legal in tsconfig.json but not in strict JSON.
    return (string) preg_replace('/,(\s*[}\]])/', '$1', $out);
};

/**
 * Reports whether an `extends` value references the shared config.
 *
 * The value may be a string (tsconfig) or a list (Biome, and tsconfig since 5.0).
 * The specifier is normally the bare package path, but a consumer may reach the
 * same file through an explicit node_modules path, so an optional prefix is
 * accepted — one that must END at a segment boundary, so a near-miss package
 * such as `notmagicsunday/coding-standard/...` is still rejected.
 */
$extendsShared = static function (mixed $extends, string $sharedPath): bool {
    $candidates = is_array($extends) ? $extends : [$extends];

    $pattern = sprintf(
        '~^(?:\S*/)?@?magicsunday/coding-standard/%s$~',
        preg_quote($sharedPath, '~')
    );

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && (preg_match($pattern, trim($candidate)) === 1)) {
            return true;
        }
    }

    return false;
};

/**
 * Collects every `//` key in a decoded config, at any depth.
 *
 * Biome's deserializer rejects unknown keys and refuses the WHOLE file, so a
 * `"//"` note key makes the config unloadable while staying valid JSON — this
 * package shipped exactly that once. The consumer copy can make the same
 * mistake, and `biome ci` then fails with a deserialize error that reads like a
 * tooling problem rather than a config one.
 *
 * @param mixed $node
 *
 * @return bool
 */
$hasNoteKey = static function (mixed $node) use (&$hasNoteKey): bool {
    if (!is_array($node)) {
        return false;
    }

    if (array_key_exists('//', $node)) {
        return true;
    }

    foreach ($node as $child) {
        if ($hasNoteKey($child)) {
            return true;
        }
    }

    return false;
};

// biome.json / biome.jsonc: the linter must stay wired to the shared ruleset.
$biomeFile = null;

foreach (['biome.json', 'biome.jsonc'] as $candidate) {
    if (is_file($repoRoot . '/' . $candidate)) {
        $biomeFile = $repoRoot . '/' . $candidate;

        break;
    }
}

if ($biomeFile !== null) {
    $label = basename($biomeFile);
    $json  = json_decode($stripJsonc((string) file_get_contents($biomeFile)), true);

    if (!is_array($json)) {
        $fail($violations, $label, 'not valid JSON(C).');
    } else {
        if ($hasNoteKey($json)) {
            $fail($violations, $label, 'contains a `"//"` key — Biome rejects unknown keys and refuses the whole config, so the file is valid JSON but unloadable. Put the note in a comment or in the README.');
        }

        if (!$extendsShared($json['extends'] ?? null, 'biome/base.json')) {
            $fail($violations, $label, 'must `extends` the shared `@magicsunday/coding-standard/biome/base.json`.');
        }

        foreach (['linter', 'formatter'] as $section) {
            if (($json[$section]['enabled'] ?? null) === false) {
                $fail($violations, $label, sprintf('`%s.enabled` must not be false — that disables the shared standard wholesale.', $section));
            }
        }

        // Turning the recommended set off leaves the shared rule list in place
        // but removes the floor it builds on, so the extends becomes decorative.
        // Biome offers two spellings for it: the `recommended` boolean, which it
        // deprecated in 2.5 in favour of `preset`, and `preset: "none"`. Both are
        // accepted by the current tool and both silence the same rules — verified
        // under 2.5.0 and 2.5.5, where a `debugger` statement (a recommended-set
        // rule the shared config does not list explicitly) goes unreported under
        // either. Checking only the deprecated spelling would leave the modern
        // one as an unguarded way out.
        if (($json['linter']['rules']['recommended'] ?? null) === false) {
            $fail($violations, $label, '`linter.rules.recommended` must not be false.');
        }

        if (($json['linter']['rules']['preset'] ?? null) === 'none') {
            $fail($violations, $label, '`linter.rules.preset` must not be `none` — that drops the recommended floor the shared config builds on.');
        }
    }
}

// tsconfig.json: the strict flags the shared base sets must not be turned back off.
$tsconfigFile = $repoRoot . '/tsconfig.json';

if (is_file($tsconfigFile)) {
    $json = json_decode($stripJsonc((string) file_get_contents($tsconfigFile)), true);

    if (!is_array($json)) {
        $fail($violations, 'tsconfig.json', 'not valid JSON(C).');
    } else {
        if (!$extendsShared($json['extends'] ?? null, 'tsconfig/base.json')) {
            $fail($violations, 'tsconfig.json', 'must `extends` the shared `@magicsunday/coding-standard/tsconfig/base.json`.');
        }

        // Only the strictness flags are pinned. `esModuleInterop`,
        // `resolveJsonModule` and `skipLibCheck` are ergonomics, not strictness —
        // a consumer turning skipLibCheck off is stricter, not looser — so they
        // are deliberately left free, as are module/target/lib/jsx and paths.
        $pinnedFlags = [
            'strict',
            'noUncheckedIndexedAccess',
            'exactOptionalPropertyTypes',
            'noImplicitOverride',
            'forceConsistentCasingInFileNames',
            'isolatedModules',
        ];

        foreach ($pinnedFlags as $flag) {
            if (($json['compilerOptions'][$flag] ?? null) === false) {
                $fail($violations, 'tsconfig.json', sprintf('`compilerOptions.%s` must not be false — it overrides the shared strict base.', $flag));
            }
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

fwrite(\STDERR, "\nAlign the file(s) with the templates/ directory of magicsunday/coding-standard.\n");
exit(1);
