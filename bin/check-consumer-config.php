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

        // `format` takes jscpd's FORMAT names, and an unknown one is NOT an
        // error — it silently analyses nothing and reports a clean run. Verified
        // against 5.0.14 on a fixture holding two near-identical TypeScript
        // functions: `["ts"]` prints "No duplicates found" and exits 0, while
        // `["typescript"]` reports 2 clones and exits 1. Same fixture, same
        // threshold. A consumer "fixing" the template to its file extensions
        // therefore keeps a gate that looks active and detects nothing.
        //
        // This is deliberately a deny-list of the extension spellings that look
        // right, not an allow-list of valid names: `jscpd --list` carries ~250
        // formats, and a copy of it here would drift from the tool and start
        // rejecting configs the tool accepts. Each entry below was checked to be
        // absent from that list, so every one of them scans nothing.
        // Scoped to the formats the shipped template actually names, so the table
        // is self-evidently complete: these are the spellings a consumer copying
        // it can plausibly mistype, and each was checked against `jscpd --list`
        // to be absent from it.
        $extensionSpellings = [
            'js'  => 'javascript',
            'mjs' => 'javascript',
            'cjs' => 'javascript',
            'ts'  => 'typescript',
            'mts' => 'typescript',
            'cts' => 'typescript',
        ];

        // Presence is deliberately NOT required: without `format`, jscpd applies
        // its own defaults, which is a working gate rather than a silent one.
        // Only the spellings that disable detection are reported.
        // A bare string is accepted alongside a list, or the very spelling this
        // check exists to reject would slip through by not being in an array.
        $formats = $json['format'] ?? null;

        if (is_string($formats)) {
            $formats = [$formats];
        }

        if (is_array($formats)) {
            foreach ($formats as $format) {
                if (is_string($format) && isset($extensionSpellings[$format])) {
                    $fail($violations, '.jscpd.json', sprintf('`format` entry "%s" is a file extension, not a jscpd format name — jscpd does not error on it, it silently analyses nothing and reports a clean run. Use "%s".', $format, $extensionSpellings[$format]));
                }
            }
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
/**
 * Reduces a JSONC document to strict JSON, leaving string contents untouched.
 *
 * tsconfig.json is JSONC by specification (TypeScript documents comments in it)
 * and Biome accepts a biome.jsonc, so a plain json_decode would report a
 * perfectly legal consumer file as malformed.
 *
 * Both passes match a complete string literal FIRST and then discard it as a
 * candidate, so nothing inside a string is ever rewritten: not a `//` in a URL,
 * not a `"//"` KEY, and not a `,` that happens to sit before a `}` or `]`. That
 * last one is why the trailing-comma pass needs the same protection as the
 * comment pass rather than a plain regex — `{"a": "x,]"}` decodes to `x,]` here
 * and decoded to `x]` before, silently changing a consumer's value.
 *
 * A removed comment leaves one space behind. Without that space, a block comment
 * placed inside a token would fuse the halves back together — a `tr`, a comment,
 * then `ue` would decode as `true` — and the gate would accept a document every
 * real JSONC parser rejects.
 *
 * @param string $json The raw file contents.
 *
 * @return string|null The strict-JSON equivalent, or null if the regex engine failed.
 */
$stripJsonc = static function (string $json): ?string {
    // The string branch matches a COMPLETE literal, so an unterminated one costs
    // a scan to EOF from every quote in the document — quadratic on a file that
    // is nothing but `\"` repeated. Deliberately not guarded: the input is the
    // repository's own biome.json/tsconfig.json, written by whoever runs the
    // gate, so it is a self-inflicted cost rather than an exposure. A size cap
    // would be a guard for an input nobody else controls.
    $string = '"(?:\\\\.|[^"\\\\])*+"';

    $withoutComments = preg_replace(
        '~' . $string . '(*SKIP)(*F)|//[^\n]*|/\*.*?\*/~s',
        ' ',
        $json
    );

    if ($withoutComments === null) {
        return null;
    }

    // No /u: the delimiters are ASCII and the pass is byte-safe, while the
    // modifier would make a config carrying invalid UTF-8 return null and be
    // reported as a comment-stripping failure rather than as the encoding
    // problem it is.
    return preg_replace(
        '~' . $string . '(*SKIP)(*F)|,(?=\s*[}\]])~',
        '',
        $withoutComments
    );
};

/**
 * Loads a JSONC config, or null when it does not parse.
 *
 * The reader has to tolerate what the real tools tolerate, or the gate reports a
 * defect in a file that loads perfectly well. A leading UTF-8 BOM is the case
 * that bites: `json_decode` rejects it, while Biome 2.5.5 and tsc 7.0.2 both read
 * a BOM-prefixed config and honour it — verified against the packed tarball.
 *
 * @param string $path Absolute path to the config file.
 *
 * @return array<array-key, mixed>|null
 */
$loadJsonc = static function (string $path) use ($stripJsonc): ?array {
    $contents = file_get_contents($path);

    if ($contents === false) {
        return null;
    }

    $withoutBom = preg_replace('~^\xEF\xBB\xBF~', '', $contents);
    $stripped   = $stripJsonc($withoutBom ?? $contents);

    if ($stripped === null) {
        return null;
    }

    $decoded = json_decode($stripped, true);

    return is_array($decoded) ? $decoded : null;
};

/**
 * Reports whether an `extends` value references the shared config.
 *
 * The value may be a string (tsconfig) or a list (Biome, and tsconfig since 5.0).
 * Two spelling latitudes are allowed, each because a real tool grants it:
 *
 * - An explicit path is accepted only when it reaches the package through a
 *   `node_modules/` directory of THIS repository — optionally `./`-prefixed, and
 *   optionally through pnpm's `.pnpm/<pkg>/node_modules/` indirection. Anything
 *   looser defeats the purpose: `./fixtures/@magicsunday/…` is an obvious local
 *   look-alike, but so are `./fixtures/node_modules/@magicsunday/…` and
 *   `../../other-repo/node_modules/@magicsunday/…` — both are loaded by the real
 *   tools INSTEAD of the installed package, so accepting them makes the gate
 *   report a shared link that is not the shared config.
 * - The `.json` suffix is optional for tsconfig and required for Biome, because
 *   that is what the tools do: `tsc` resolves
 *   `@magicsunday/coding-standard/tsconfig/base` to the same file, while Biome
 *   answers the equivalent with `Could not resolve … module not found`. Both
 *   checked against the packed tarball with tsc 7.0.2 and Biome 2.5.5.
 *
 * The scope `@` is NOT optional. The npm package is `@magicsunday/coding-standard`,
 * and the unscoped spelling resolves for neither tool — Biome answers `module not
 * found` and tsc `TS6053: File … not found` — so accepting it would report a link
 * that cannot exist. (The Composer-side deptrac import is unscoped and has its own
 * pattern; this one is npm-only.)
 *
 * The decoded config is passed whole rather than its `extends` value, so the
 * caller does not have to narrow a key that may legally hold anything JSON
 * allows: a number or an object is simply not a specifier, and falls out here as
 * a missing link rather than a type error at the call site.
 *
 * @param array<array-key, mixed> $config         The decoded consumer config.
 * @param string                  $sharedStem     Path inside the package, without the `.json` suffix.
 * @param bool                    $suffixOptional Whether the consuming tool resolves the suffix itself.
 *
 * @return bool
 */
$extendsShared = static function (array $config, string $sharedStem, bool $suffixOptional): bool {
    $extends    = $config['extends'] ?? null;
    $candidates = is_array($extends) ? $extends : [$extends];

    $pattern = sprintf(
        '~^(?:\./)?(?:node_modules/(?:\.pnpm/[^/]+/node_modules/)?)?@magicsunday/coding-standard/%s%s$~',
        preg_quote($sharedStem, '~'),
        $suffixOptional ? '(?:\.json)?' : '\.json'
    );

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && (preg_match($pattern, trim($candidate)) === 1)) {
            return true;
        }
    }

    return false;
};

/**
 * Reports whether a decoded config carries a `//` key at any depth.
 *
 * Biome's deserializer rejects unknown keys and refuses the WHOLE file, so a
 * `"//"` note key makes the config unloadable while staying valid JSON — this
 * package shipped exactly that once. The consumer copy can make the same
 * mistake, and `biome ci` then fails with a deserialize error that reads like a
 * tooling problem rather than a config one.
 *
 * @param array<array-key, mixed> $node The decoded config node to walk.
 *
 * @return bool
 */
$hasNoteKey = static function (array $node) use (&$hasNoteKey): bool {
    if (array_key_exists('//', $node)) {
        return true;
    }

    foreach ($node as $child) {
        if (is_array($child) && $hasNoteKey($child)) {
            return true;
        }
    }

    return false;
};

/**
 * Reports whether the repository consumes the npm side of this package.
 *
 * Everything below except the `"//"` guard asserts the LINK to a shared config,
 * which only means something once that config is a dependency. Keying the
 * assertions on the file's mere existence instead would red every repository
 * that has a biome.json and has not adopted the npm package — and it would do so
 * on the update that first delivers this gate, for a link the repository never
 * claimed to have. The ordering makes that unavoidable rather than unlucky: a
 * consumer cannot pin the npm tag before the tag exists, so "align first, then
 * enforce" is the only order available, exactly as the template gate was staged.
 *
 * A `false` from here silences the whole JS/TS half, so it must mean "no link was
 * claimed" and nothing else. An unreadable package.json is NOT that — it is a
 * probe failure, and returning false for it would turn every one of those
 * assertions off while the gate still printed OK. It is reported instead.
 *
 * @param string                $repoRoot   The consumer repository root to inspect.
 * @param list<string>          $violations Collected drift reports, appended to on a probe failure.
 * @param callable              $fail       Reporter shared with the rest of the gate.
 *
 * @return bool
 */
$npmDependencyDeclared = static function (string $repoRoot, array &$violations, callable $fail): bool {
    $packageJsonFile = $repoRoot . '/package.json';

    if (!is_file($packageJsonFile)) {
        return false;
    }

    $contents = file_get_contents($packageJsonFile);

    if ($contents === false) {
        $fail($violations, 'package.json', 'exists but cannot be read, so the JS/TS contract cannot be checked.');

        return false;
    }

    // package.json is strict JSON by npm's own rules, so no JSONC pass here — but
    // npm, Node, Biome and tsc all strip a leading BOM and json_decode does not.
    $withoutBom = preg_replace('~^\xEF\xBB\xBF~', '', $contents);
    $json       = json_decode($withoutBom ?? $contents, true);

    if (!is_array($json)) {
        $fail($violations, 'package.json', 'is not valid JSON, so the JS/TS contract cannot be checked.');

        return false;
    }

    foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
        if (isset($json[$section]['@magicsunday/coding-standard'])) {
            return true;
        }
    }

    return false;
};

$adopted = $npmDependencyDeclared($repoRoot, $violations, $fail);

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
    $json  = $loadJsonc($biomeFile);

    if ($json !== null) {
        // The one check that does NOT depend on adoption: a `"//"` key makes the
        // file unloadable for Biome whether or not it extends anything, so a
        // repository writing its own config is just as broken by it.
        if ($hasNoteKey($json)) {
            $fail($violations, $label, 'contains a `"//"` key — Biome rejects unknown keys and refuses the whole config, so the file is valid JSON but unloadable. Put the note in a comment or in the README.');
        }
    } elseif ($adopted) {
        // A parse failure IS gated on adoption, unlike the `"//"` key, because
        // this reader is not Biome's: it can reject a file the real tool accepts,
        // and reporting that to a repository which never claimed the link is the
        // failure mode the adoption gate exists to prevent. Once the link is
        // claimed, an unreadable config is a real drift — the assertions that
        // follow cannot run at all.
        $fail($violations, $label, 'not valid JSON(C).');
    }

    if ($adopted && ($json !== null)) {
        // Biome requires the `.json` suffix — verified: it answers the bare
        // specifier with `Could not resolve … module not found`.
        if (!$extendsShared($json, 'biome/base', false)) {
            $fail($violations, $label, 'must `extends` the shared `@magicsunday/coding-standard/biome/base.json`.');
        }

        // `linter`/`formatter` can be switched off at the top level and, per
        // Biome's schema, again inside every entry of `overrides` — where an
        // entry matching `**` disables the same thing for every file while the
        // top-level key still reads as enabled.
        $scopes    = [['', $json]];
        $overrides = $json['overrides'] ?? null;

        if (is_array($overrides)) {
            foreach ($overrides as $index => $override) {
                if (is_array($override)) {
                    $scopes[] = [sprintf('overrides[%s].', $index), $override];
                }
            }
        }

        foreach ($scopes as [$prefix, $scope]) {
            foreach (['linter', 'formatter'] as $toggle) {
                if (($scope[$toggle]['enabled'] ?? null) === false) {
                    $fail($violations, $label, sprintf('`%s%s.enabled` must not be false — that disables the shared standard wholesale.', $prefix, $toggle));
                }
            }

            // Turning the recommended set off leaves the shared rule list in
            // place but removes the floor it builds on, so the extends becomes
            // decorative. Biome offers two spellings: the `recommended` boolean,
            // which it deprecated in 2.5 in favour of `preset`, and `preset`.
            // Both are accepted by the current tool and both silence the same
            // rules — verified under 2.5.0 and 2.5.5, where a `debugger`
            // statement (a recommended-set rule the shared config does not list
            // explicitly) goes unreported under either.
            //
            // And both exist on every rule GROUP as well as on `rules` itself,
            // so `rules.suspicious.preset: "none"` drops that group's floor while
            // the top-level keys stay untouched. Verified: with it, `biome ci`
            // passes a file containing `debugger;`. A top-level-only check leaves
            // one spelling per group unguarded, which is the same hole one level
            // down.
            $topLevelRules = $scope['linter']['rules'] ?? null;
            $ruleScopes    = [];

            // Seeded inside the guard so every element is an array by
            // construction, rather than appending one that may not be and
            // skipping it again in the loop below.
            if (is_array($topLevelRules)) {
                $ruleScopes[] = ['linter.rules', $topLevelRules];

                foreach ($topLevelRules as $group => $groupRules) {
                    if (is_array($groupRules)) {
                        $ruleScopes[] = [sprintf('linter.rules.%s', $group), $groupRules];
                    }
                }
            }

            foreach ($ruleScopes as [$path, $rules]) {
                if (($rules['recommended'] ?? null) === false) {
                    $fail($violations, $label, sprintf('`%s%s.recommended` must not be false — that drops the rule floor the shared config builds on.', $prefix, $path));
                }

                if (($rules['preset'] ?? null) === 'none') {
                    $fail($violations, $label, sprintf('`%s%s.preset` must not be `none` — that drops the rule floor the shared config builds on.', $prefix, $path));
                }
            }
        }
    }
}

// tsconfig.json: the strict flags the shared base sets must not be turned back off.
// Gated on adoption for the same reason as the biome block, and more plainly so:
// `strict: false` in a repository that never extended the shared base is that
// repository's own setting, not drift from a standard it does not follow.
$tsconfigFile = $repoRoot . '/tsconfig.json';

if ($adopted && is_file($tsconfigFile)) {
    $json = $loadJsonc($tsconfigFile);

    if ($json === null) {
        $fail($violations, 'tsconfig.json', 'not valid JSON(C).');
    } else {
        // tsc appends `.json` itself, so the bare specifier resolves to the same
        // file and must not be reported as a missing link — verified with 7.0.2.
        if (!$extendsShared($json, 'tsconfig/base', true)) {
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
