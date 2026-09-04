<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The biome.json / tsconfig.json (optional) contract check, extracted out of
 * bin/check-consumer-config.php (GH-48) once that file crossed 1000 lines. A
 * shared include, not an entry point — see bin/consumer-checks/helpers.php's
 * own docblock for the boundary this file follows.
 *
 * These are NOT copy-and-adapt templates — they are one-line `extends` stubs, so
 * unlike the PHP templates their rule content genuinely cannot drift. What CAN
 * drift is the link itself: a consumer that drops the `extends`, or overrides a
 * strict flag back to false underneath it, keeps a green build while enforcing
 * its own weaker standard. That is the same failure class the template gate
 * exists for, so it is checked the same way.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the JS/TS `extends` link + the strict flags the shared base sets.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 * @param string       $packageRoot This package's own installation root — the
 *                                  directory holding `bin/` and `biome/` as
 *                                  siblings, not $repoRoot. Read-only source
 *                                  for the rule names GH-36's per-rule check
 *                                  derives from `biome/base.json` rather than
 *                                  hand-copying.
 *
 * @return void
 */
function checkBiomeTsconfig(array &$violations, string $repoRoot, string $packageRoot): void
{
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
        // is nothing but `\"` repeated.
        //
        // This IS attacker-reachable, and an earlier version of this comment said the
        // opposite: it called the input "the repository's own biome.json, written by
        // whoever runs the gate". The trust model is the one stated in
        // bin/support/safe-report-value.php — these gates run in the CONSUMER's CI over
        // pull-request branch content. Nor does `pcre.backtrack_limit` bound it: the
        // possessive `*+` means each failed attempt is one linear non-backtracking
        // scan, so there is no backtracking for the limit to catch.
        //
        // Measured on php 8.5 through the shipped binary, biome.json only:
        //
        //     500 KB of `\"`      34.6 s
        //     1 MB of `\"`       259   s   (4 m 20 s end-to-end)
        //     valid 1 MB JSON      0.003 s
        //
        // The cap in $loadJsonc is what bounds it. Kept here rather than rewriting the
        // regex: the quadratic shape is inherent to "scan to EOF from every quote",
        // and a size limit is both smaller and easier to reason about.
        $stringLiteral = '"(?:\\\\.|[^"\\\\])*+"';

        $withoutComments = preg_replace(
            '~' . $stringLiteral . '(*SKIP)(*F)|//[^\n]*|/\*.*?\*/~s',
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
            '~' . $stringLiteral . '(*SKIP)(*F)|,(?=\s*[}\]])~',
            '',
            $withoutComments
        );
    };

    /**
     * Loads a JSONC config.
     *
     * Four outcomes, kept apart because they send the reader to different places:
     * the decoded config, `null` when it does not parse, `false` when it could not be
     * read at all, and `MAX_JSONC_BYTES + 1` as an int — a sentinel, not the file's
     * size, since the read stops at the cap — when the file is past the size this
     * gate reads. Collapsing any two of them reports one problem as another, which is
     * the wrong file to go and fix.
     *
     * @param string $path Path to the config file.
     *
     * @return array<array-key, mixed>|false|int|null
     */
    $loadJsonc = static function (string $path) use ($stripJsonc): array|int|null|false {
        // One byte past the cap is read, so `> MAX_JSONC_BYTES` can tell "at the
        // bound" from "past it" without a second stat.
        $contents = readQuietly($path, MAX_JSONC_BYTES + 1);

        if ($contents === false) {
            return false;
        }

        // 128 KiB. See $stripJsonc: an unterminated string literal makes the comment
        // pass quadratic, and the input is pull-request content in the consumer's CI.
        // Above it a real shared-config stub does not exist: measured, the largest
        // `biome.json`/`tsconfig.json` anywhere on the author's machine is 5649 bytes
        // and the ones this package ships are 2203 and 626 — roughly 23x headroom. The
        // worst case UNDER the bound is not restated here; an earlier version put a
        // number on it that neither carried a derivation nor squared with the 34 s the
        // mutation of the neighbouring case measures at 140005 bytes.
        // Returned as its own state rather than `false`, which means "cannot be read"
        // and would send the reader looking at file permissions.
        if (strlen($contents) > MAX_JSONC_BYTES) {
            return strlen($contents);
        }

        // Null here means the PCRE engine failed rather than the document being
        // malformed — not constructible from a config file, so it has no case; it is
        // reported as unparseable because there is nothing more specific to say.
        $stripped = $stripJsonc(stripBom($contents));

        if ($stripped === null) {
            return null;
        }

        $decoded = json_decode($stripped, true);

        return is_array($decoded) ? $decoded : null;
    };

    /**
     * Reports whether a single `extends` specifier IS the shared package entry.
     *
     * Extracted from $extendsShared so the same recognition serves a second
     * purpose (GH-36): telling a LOCAL `extends` target — one this gate now reads
     * and folds into the effective config — apart from the shared entry, which is
     * asserted for separately and must not be re-read here as if it were a
     * consumer-authored file.
     *
     * @param string $candidate      One `extends` list entry.
     * @param string $sharedStem     Path inside the package, without the `.json` suffix.
     * @param bool   $suffixOptional Whether the consuming tool resolves the suffix itself.
     *
     * @return bool
     */
    $isSharedSpecifier = static function (string $candidate, string $sharedStem, bool $suffixOptional): bool {
        // `$~D` rather than `$~`: without the D modifier PCRE lets `$` match before a
        // single trailing newline, so `"…/base.json\n"` — valid JSON, and a string
        // neither tool trims before resolving — would be read as the shared link. That
        // is the whitespace latitude the sibling $extendsShared docblock rules out,
        // reintroduced by the anchor rather than by the pattern body.
        $pattern = sprintf(
            '~^(?:\./)?(?:node_modules/(?:\.pnpm/[^/]+/node_modules/)?)?@magicsunday/coding-standard/%s%s$~D',
            preg_quote($sharedStem, '~'),
            $suffixOptional ? '(?:\.json)?' : '\.json'
        );

        return preg_match($pattern, $candidate) === 1;
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
     *   that is what the tools do: `tsc`
     *   resolves `@magicsunday/coding-standard/tsconfig/base` to the same file, while Biome
     *   answers the equivalent with `Could not resolve … module not found`. Both
     *   checked against the packed tarball with tsc 7.0.2 and Biome 2.5.5.
     *
     * Surrounding whitespace is NOT a latitude, for the same reason the two above are:
     * neither tool trims the specifier before resolving it, so ` @magicsunday/…` names
     * a module that does not exist and tsc answers it with `TS6053: File ' @magicsunday/…'
     * not found`. Accepting it would report a link the consumer's own tools cannot follow.
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
     * A bare string is a specifier for tsconfig and NOT for Biome, which accepts only
     * `"//"` or an array of paths and answers anything else with `The 'extends' field
     * must be either '//' or an array of paths` — a deserialize error that kills the
     * whole run. Verified against Biome 2.5.5. So `$listRequired` is what keeps the
     * gate from reporting a link inside a config the consumer's own tool refuses to
     * load, which is this gate's central failure mode rather than a spelling nicety.
     *
     * @param array<array-key, mixed> $config         The decoded consumer config.
     * @param string                  $sharedStem     Path inside the package, without the `.json` suffix.
     * @param bool                    $suffixOptional Whether the consuming tool resolves the suffix itself.
     * @param bool                    $listRequired   Whether the consuming tool accepts only a list, never a bare string.
     *
     * @return bool
     */
    $extendsShared = static function (array $config, string $sharedStem, bool $suffixOptional, bool $listRequired = false) use ($isSharedSpecifier): bool {
        $extends = $config['extends'] ?? null;

        if ($listRequired && !is_array($extends)) {
            return false;
        }

        $candidates = is_array($extends) ? $extends : [$extends];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $isSharedSpecifier($candidate, $sharedStem, $suffixOptional)) {
                return true;
            }
        }

        return false;
    };

    /**
     * Loads a config this package itself ships (`biome/base.json`,
     * `tsconfig/base.json`), so its own content can take part in a resolved
     * `extends` chain exactly where the consumer lists it (GH-36) — including
     * placed AFTER a local override, where the shared entry's own explicit values
     * win back and undo it, exactly as the real tool folds the chain. Trusted
     * content: no size bound and no JSONC handling, because this package does not
     * ship comments in its own configs and the input is not pull-request content.
     *
     * A missing or unparseable bundled file is a packaging defect this gate
     * cannot repair; folding an empty layer in that case leaves the consumer's own
     * values as the only ones checked, which is the safer failure than treating a
     * read error as a decoded document.
     *
     * @param string $path Absolute path to the package's own config file.
     *
     * @return array<array-key, mixed>
     */
    $loadOwnConfig = static function (string $path): array {
        $contents = is_file($path) ? file_get_contents($path) : false;
        $decoded  = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : [];
    };

    /**
     * Resolves an `extends` chain to the config layers a real tool would also
     * apply, IN THE ORDER the document names them (GH-36).
     *
     * A later `extends` entry silences the shared standard just as effectively as
     * the document's own top level does — Biome and tsc both apply the chain in
     * order, each entry overriding the ones before it — so a gate that reads only
     * the document itself misses it. Order matters in BOTH directions, which is
     * why the shared entry is a layer here rather than being skipped: a consumer
     * listing it AFTER a local override gets the shared entry's own explicit
     * values back on top, undoing the override, exactly as Biome 2.5.5 and tsc
     * 7.0.2 both fold it — verified, not assumed: with the shared entry listed
     * last, a local `linter.enabled: false` (Biome) and a local
     * `noUncheckedIndexedAccess: false` (tsc) were both silently overridden back
     * on, because the shared base sets each of those explicitly. Treating the
     * shared entry as a no-op regardless of position would have reported drift on
     * a consumer config that was not actually drifted.
     *
     * A package-scoped entry other than the shared one is excluded by
     * construction, not by pattern matching — it resolves to no file inside
     * $repoRoot, so the loop below silently contributes nothing for it. That is
     * the same answer this gate already gives an unmet contract elsewhere: not in
     * the repository, nothing to read.
     *
     * A specifier that escapes the repository (a `../` chain reaching outside it)
     * is not followed either. The input is pull-request content in the consumer's
     * CI — see $stripJsonc's own trust-model note — and opening whatever the CI
     * runner's filesystem happens to hold at an arbitrary path is not this gate's
     * job. Both exclusions are silent, not reported: a target this gate cannot
     * read is a target it never claimed to check.
     *
     * A local target that itself fails to read or parse contributes nothing rather
     * than a fabricated drift report — the real tool's own error for that file, not
     * a guess by a reader that is not the real tool, is the correct diagnostic for
     * it.
     *
     * Only entries the document's OWN `extends` list names are resolved — a local
     * target's own `extends` chain is not followed transitively. The exploit this
     * closes needs exactly one hop (the issue's reproduction and both fixture
     * routes below use one), and a second hop is unbounded recursion over
     * consumer-controlled file names with no cycle guard yet in place.
     *
     * @param string                  $repoRoot       The consumer repository root
     *                                                  — where the checked config
     *                                                  file itself sits, so a
     *                                                  local specifier resolves
     *                                                  relative to it exactly as
     *                                                  the real tool does.
     * @param array<array-key, mixed> $config         The decoded consumer config —
     *                                                  the whole document, not just
     *                                                  its `extends` key, matching
     *                                                  $extendsShared's own call
     *                                                  convention. `extends` is
     *                                                  read out as an UNTYPED local
     *                                                  below rather than a typed
     *                                                  parameter, deliberately: the
     *                                                  value is pull-request
     *                                                  content and can legally be
     *                                                  ANY JSON type (a number, a
     *                                                  bool, an object) — a
     *                                                  parameter typed narrower
     *                                                  than that throws a
     *                                                  TypeError on exactly the
     *                                                  malformed input this
     *                                                  function exists to answer
     *                                                  "no candidates" for, rather
     *                                                  than crashing the whole gate
     *                                                  (verified: `{"extends": 5}`
     *                                                  raised `TypeError: Argument
     *                                                  #2 ($extends) must be of
     *                                                  type array|string|null, int
     *                                                  given` before this fix).
     * @param string                  $sharedStem     Passed to
     *                                                  $isSharedSpecifier.
     * @param bool                    $suffixOptional Whether a bare specifier may
     *                                                  omit `.json` — true for
     *                                                  tsconfig (tsc appends it),
     *                                                  false for Biome (verified:
     *                                                  it does not — a bare
     *                                                  specifier with no matching
     *                                                  file on disk is reported
     *                                                  `module not found`, never
     *                                                  resolved with the suffix
     *                                                  appended).
     * @param array<array-key, mixed> $sharedLayer    This package's own bundled
     *                                                  config (from
     *                                                  $loadOwnConfig), substituted
     *                                                  wherever the shared entry
     *                                                  sits in the chain.
     * @param list<string>            $violations     The accumulated report,
     *                                                  appended to when a local
     *                                                  target is oversized —
     *                                                  the one local-resolution
     *                                                  failure this gate reports
     *                                                  rather than silently
     *                                                  skipping (see below).
     * @param string                  $label          How the checked config file
     *                                                  is named in the report.
     *
     * @return list<array<array-key, mixed>> The layers, in `extends` order — the
     *                                        caller folds them left-to-right and
     *                                        merges the document on top, so later
     *                                        entries and the document itself win
     *                                        exactly as the tool resolves them.
     */
    $resolveExtendsLayers = static function (string $repoRoot, array $config, string $sharedStem, bool $suffixOptional, array $sharedLayer, array &$violations, string $label) use ($isSharedSpecifier, $loadJsonc): array {
        $extends = $config['extends'] ?? null;

        // `array_is_list`, not a bare `is_array`: neither Biome nor tsc accepts an
        // `extends` value shaped as a JSON OBJECT — a decoded PHP array that is not
        // a list — and the JS mirror already rejects that shape (`Array.isArray`
        // is false for a plain object). Accepting it here too would iterate an
        // object's VALUES as candidates and could read a local file for one of
        // them, a verdict the JS side would never reach for the same input.
        //
        // One residual PHP/JS asymmetry, found and verified during this change's
        // own audit round, deliberately left unfixed for the same reason the
        // `mergeConfigLayer()` empty-array ambiguity is: a JSON OBJECT whose keys
        // happen to be the sequential strings `"0"`, `"1"`, … decodes in PHP to an
        // array indexed by the equivalent INTEGERS — a long-standing PHP behaviour
        // for every array, not specific to `json_decode` — and `array_is_list`
        // then reports `true` for it, same as a genuine JSON array. JS has no
        // equivalent coercion (`JSON.parse('{"0":"a"}')` stays a plain object,
        // `Array.isArray` on it is `false`), so PHP resolves such an `extends`
        // value's entries as local candidates while JS resolves none. Not fixed:
        // Biome itself hard-rejects this exact shape — verified against 2.5.5,
        // `extends has an incorrect type, expected an array, but received an
        // object` kills the whole config load — so a config that reaches this
        // divergence never successfully loads for a real consumer, the same
        // manifestation argument as the `"linter": []` case above.
        $candidates = match (true) {
            is_array($extends) && array_is_list($extends) => $extends,
            is_string($extends)                            => [$extends],
            default                                         => [],
        };

        $realRoot = realpath($repoRoot);
        $layers   = [];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            if ($isSharedSpecifier($candidate, $sharedStem, $suffixOptional)) {
                $layers[] = $sharedLayer;

                continue;
            }

            if ($realRoot === false) {
                continue;
            }

            foreach ($suffixOptional ? [$candidate, $candidate . '.json'] : [$candidate] as $attempt) {
                $path = $repoRoot . '/' . $attempt;

                if (!is_file($path)) {
                    continue;
                }

                $real = realpath($path);

                if (($real === false) || !str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)) {
                    break;
                }

                $decoded = $loadJsonc($path);

                if (is_array($decoded)) {
                    $layers[] = $decoded;
                } elseif (is_int($decoded)) {
                    // Unlike an unreadable or unparseable local target — left to
                    // Biome's own error, per this function's docblock — an
                    // oversized one is a file Biome loads and applies without any
                    // complaint of its own: MAX_JSONC_BYTES is this gate's OWN
                    // defensive cap against $stripJsonc's quadratic comment scan,
                    // not a real limit either tool enforces. Silently treating it
                    // as "not resolved" the way an unparseable file is would let a
                    // deliberately padded local target smuggle a real weakening
                    // past this gate undetected — found by Codex during PR
                    // review.
                    fail($violations, $label, sprintf('a local `extends` target (%s) ', safeReportValue($attempt)) . tooLargeDetail(MAX_JSONC_BYTES));
                }

                break;
            }
        }

        return $layers;
    };

    /**
     * Folds a resolved `extends` chain into the effective document: each layer in
     * `extends` order, the document itself merged last so it wins over all of them
     * (GH-36). The one caller-visible step shared verbatim by the biome.json and
     * tsconfig.json blocks below — kept as its own function rather than the same
     * three-line loop written twice, since `$biomeLayers`/`$tsconfigLayers` are
     * never read again once folded.
     *
     * @param list<array<array-key, mixed>> $layers   From $resolveExtendsLayers, in `extends` order.
     * @param array<array-key, mixed>       $document The document itself, merged last.
     *
     * @return array<array-key, mixed>
     */
    $foldExtendsChain = static function (array $layers, array $document): array {
        $effective = [];

        foreach ($layers as $layer) {
            $effective = mergeConfigLayer($effective, $layer);
        }

        return mergeConfigLayer($effective, $document);
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
     * @param string $repoRoot The consumer repository root to inspect.
     *
     * @return bool
     */
    $npmDependencyDeclared = static function (string $repoRoot) use (&$violations): bool {
        $packageJsonFile = $repoRoot . '/package.json';

        if (!is_file($packageJsonFile)) {
            return false;
        }

        $contents = readBounded($violations, $packageJsonFile, 'package.json');

        if ($contents === null) {
            // Oversize, and already reported by readBounded() — which is what makes
            // answering "not adopted" safe here. The gate cannot know whether a manifest
            // it could not read declares the dependency, and reporting the adoption-gated
            // contract on a guess is the false positive that gate exists to prevent. What
            // would be a fail-open is answering false SILENTLY; the run is already red.
            return false;
        }

        if ($contents === false) {
            fail($violations, 'package.json', 'exists but cannot be read, so the JS/TS contract cannot be checked.');

            return false;
        }

        // package.json is strict JSON by npm's own rules, so no JSONC pass here.
        $json = json_decode(stripBom($contents), true);

        if (!is_array($json)) {
            fail($violations, 'package.json', 'is not valid JSON, so the JS/TS contract cannot be checked.');

            return false;
        }

        // peerDependencies included: npm >=7 auto-installs an unmet peer with no other
        // declaration needed — measured, `npm install` on a package.json naming a package
        // ONLY under peerDependencies adds it to node_modules. A consumer moving the entry
        // there (or a PR that does it to switch this whole contract off) still has the
        // package on disk and the shared configs still apply; a section list that excluded
        // it would report "not adopted" for a repository that plainly is. Not proof of
        // resolution either way — a future npm dependency section this list does not name,
        // or a non-standard install path (a workspace hoist, a resolutions/overrides shim),
        // is not covered.
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            if (isset($json[$section]['@magicsunday/coding-standard'])) {
                return true;
            }
        }

        return false;
    };

    /**
     * The individual Biome rules this package's own biome/base.json turns on
     * explicitly, keyed by group (GH-36).
     *
     * Derived from the shipped file rather than hand-copied here, so a rule added
     * to or dropped from it needs no matching edit in this gate — the same
     * reasoning as the `$pinnedFlags` ↔ tsconfig/base.json bijection a few
     * hundred lines below. `preset` is excluded: it selects a whole rule-set floor,
     * not one rule, and is already asserted separately.
     *
     * @param array<array-key, mixed> $biomeBaseConfig This package's own bundled
     *                                                   biome/base.json, decoded
     *                                                   once by $loadOwnConfig and
     *                                                   shared with
     *                                                   $resolveExtendsLayers
     *                                                   rather than re-read here.
     *
     * @return array<string, list<string>> Rule names per group, e.g. `['suspicious' => ['noDoubleEquals', …]]`.
     */
    $sharedBiomeRules = static function (array $biomeBaseConfig): array {
        $rules = $biomeBaseConfig['linter']['rules'] ?? null;

        if (!is_array($rules)) {
            return [];
        }

        $byGroup = [];

        foreach ($rules as $group => $groupRules) {
            if (($group === 'preset') || !is_string($group) || !is_array($groupRules)) {
                continue;
            }

            foreach (array_keys($groupRules) as $ruleName) {
                if (is_string($ruleName)) {
                    $byGroup[$group][] = $ruleName;
                }
            }
        }

        return $byGroup;
    };

    /**
     * Extracts a Biome rule's severity, accepting both value shapes the schema
     * allows: a bare string (`"off"`) and an options object (`{"level": "off"}`).
     *
     * Takes the group's decoded rules array plus the rule name, rather than the
     * already-indexed value, for the same reason $resolveExtendsLayers takes the
     * whole config rather than its already-indexed `extends` value: the value at
     * an arbitrary consumer-controlled key is pull-request content and can
     * legally be ANY JSON type — a parameter typed narrower than that would throw
     * a TypeError on exactly the malformed rule value this function exists to
     * answer "no severity" for.
     *
     * @param array<array-key, mixed> $rules    The decoded `linter.rules.<group>` object.
     * @param int|string              $ruleName The rule name to read out of it.
     *
     * @return string|null The severity, lower-case as written; null when the shape carries none.
     */
    $biomeRuleSeverity = static function (array $rules, int|string $ruleName): ?string {
        $ruleValue = $rules[$ruleName] ?? null;

        if (is_string($ruleValue)) {
            return $ruleValue;
        }

        if (is_array($ruleValue) && is_string($ruleValue['level'] ?? null)) {
            return $ruleValue['level'];
        }

        return null;
    };

    // biome.json / biome.jsonc: the linter must stay wired to the shared ruleset.
    $biomeFile = null;

    foreach (['biome.json', 'biome.jsonc'] as $candidate) {
        if (is_file($repoRoot . '/' . $candidate)) {
            $biomeFile = $repoRoot . '/' . $candidate;

            break;
        }
    }

    // Probed only when there is a JS/TS config to hold to the contract. Otherwise a
    // PHP-only consumer with a malformed package.json would be reported for a
    // contract that has nothing to check — the same "red for something you never
    // claimed" the adoption keying itself exists to avoid.
    $hasJsConfig = ($biomeFile !== null) || is_file($repoRoot . '/tsconfig.json');
    $adopted     = $hasJsConfig && $npmDependencyDeclared($repoRoot);

    if ($biomeFile !== null) {
        $label     = basename($biomeFile);
        $biomeJson = $loadJsonc($biomeFile);

        if (is_array($biomeJson)) {
            // The one check that does NOT depend on adoption: a `"//"` key makes the
            // file unloadable for Biome whether or not it extends anything, so a
            // repository writing its own config is just as broken by it.
            if ($hasNoteKey($biomeJson)) {
                fail($violations, $label, 'contains a `"//"` key — Biome rejects unknown keys and refuses the whole config, so the file is valid JSON but unloadable. Put the note in a comment or in the README.');
            }
        } elseif ($biomeJson === false) {
            // Unreadable is unconditional: no reader tolerance is in play, the file
            // simply cannot be opened, and that is true whoever wrote it.
            fail($violations, $label, 'exists but cannot be read.');
        } elseif (is_int($biomeJson)) {
            // Also unconditional, and for the same reason: the file is past the size
            // this gate reads, which is true whoever wrote it.
            fail($violations, $label, tooLargeDetail(MAX_JSONC_BYTES));
        } elseif ($adopted) {
            // A PARSE failure is gated on adoption, unlike the `"//"` key and unlike
            // an unreadable file, because this reader is not Biome's: it can reject a
            // file the real tool accepts, and reporting that to a repository which
            // never claimed the link is the failure mode the adoption gate exists to
            // prevent. Once the link is claimed, an unparseable config is a real
            // drift — the assertions that follow cannot run at all.
            fail($violations, $label, 'not valid JSON(C).');
        }

        if ($adopted && is_array($biomeJson)) {
            // Biome requires the `.json` suffix — verified: it answers the bare
            // specifier with `Could not resolve … module not found`.
            if (!$extendsShared($biomeJson, 'biome/base', false, true)) {
                fail($violations, $label, 'must `extends` the shared `@magicsunday/coding-standard/biome/base.json`.');
            }

            // `linter`/`formatter` can be switched off at the top level and, per
            // Biome's schema, again inside every entry of `overrides` — where an
            // entry matching `**` disables the same thing for every file while the
            // top-level key still reads as enabled.
            // Biome carries `linter`/`formatter` in three nested places, and they
            // COMBINE: the document, each `overrides` entry, and a per-language block
            // inside either of those. `javascript.linter.enabled: false` silences the
            // shared standard for every JS/TS file while the top-level key still reads
            // as enabled — verified under 2.5.5, where such a config lets `a == b` and
            // a 2-space indent through.
            //
            // So the languages are expanded per BASE scope rather than per document.
            // Walking them off the document alone leaves the cross product open, and
            // an override is the idiomatic place to write a language block, since that
            // is how a language setting gets scoped to a path set.
            //
            // Every assertion below runs against the EFFECTIVE document (GH-36): the
            // document's own values folded on top of every `extends` entry it names,
            // in the order the tool itself would resolve them — the shared entry
            // included, substituted with this package's own bundled content (see
            // $resolveExtendsLayers for why it must be, not merely skipped). A
            // repository with no `extends` array at all gets back exactly
            // $biomeJson, because folding nothing onto it changes nothing.
            $biomeBaseConfig = $loadOwnConfig($packageRoot . '/biome/base.json');
            $biomeLayers     = $resolveExtendsLayers($repoRoot, $biomeJson, 'biome/base', false, $biomeBaseConfig, $violations, $label);
            $biomeEffective  = $foldExtendsChain($biomeLayers, $biomeJson);

            // The "//" check above only ever saw the document itself — before
            // GH-36, that was the whole story, since no local `extends` target was
            // ever read. It now is: a local target Biome loads as part of the same
            // chain is refused by Biome on exactly the same grounds, so it needs
            // the same check. Found during this change's own audit round (a
            // fresh runtime trace, not a hand-picked case): earlier fixtures
            // already exercised a local target's OTHER content (a disabled
            // linter, an off rule), but no fixture put a `"//"` key inside one
            // until then.
            foreach ($biomeLayers as $layer) {
                if ($hasNoteKey($layer)) {
                    fail($violations, $label, 'a local `extends` target contains a `"//"` key — Biome rejects unknown keys and refuses the whole config it belongs to, so the chain is valid JSON but unloadable. Put the note in a comment or in the README.');

                    break;
                }
            }

            // The rule names GH-36's per-rule check below must not find "off" —
            // derived from this package's own biome/base.json, not hand-copied.
            $sharedRules = $sharedBiomeRules($biomeBaseConfig);

            $baseScopes = [['', $biomeEffective]];
            $overrides  = $biomeEffective['overrides'] ?? null;

            if (is_array($overrides)) {
                foreach ($overrides as $index => $override) {
                    if (is_array($override)) {
                        $baseScopes[] = [sprintf('overrides[%s].', safeReportValue($index)), $override];
                    }
                }
            }

            $scopes = [];

            foreach ($baseScopes as [$prefix, $baseScope]) {
                $scopes[] = [$prefix, $baseScope];

                foreach (['javascript', 'json', 'css', 'graphql', 'grit', 'html'] as $language) {
                    $languageScope = $baseScope[$language] ?? null;

                    if (is_array($languageScope)) {
                        $scopes[] = [sprintf('%s%s.', $prefix, $language), $languageScope];
                    }
                }
            }

            // `files.includes` is the disable route that leaves every `enabled` flag
            // true: a consumer whose sources moved out from under a narrowing pattern
            // gets `biome ci` checking zero files at exit 0, and every check above
            // passes. It cannot simply be banned — the canonical config in this very
            // package narrows — so only the shape that can ONLY mean "check nothing"
            // is reported: a list with no positive pattern in it. A `!`-prefixed entry
            // is an exclusion; a list of nothing but exclusions includes nothing.
            //
            // `files` exists at the document root and in each `overrides` entry (an
            // override's own key is `includes`), but the wholesale case is the root:
            // an override that matches nothing narrows that override, not the run.
            $rootIncludes = $biomeEffective['files']['includes'] ?? null;

            if (is_array($rootIncludes)) {
                $positive = array_filter(
                    array_filter($rootIncludes, 'is_string'),
                    static fn (string $pattern): bool => !str_starts_with($pattern, '!')
                );

                if (count($positive) === 0) {
                    fail($violations, $label, '`files.includes` carries no positive pattern, so Biome checks nothing and every other setting here is decorative.');
                }
            }

            foreach ($scopes as [$prefix, $scope]) {
                // `assist` is the third section Biome lets a consumer switch off
                // wholesale, alongside the linter and the formatter, and it was the one
                // this walk did not read. Re-derive the set rather than trusting this
                // list — the root object of the version this package pins:
                //
                //     jq -r '.properties | keys[]' node_modules/@biomejs/biome/configuration_schema.json
                //
                // The CLI's `--help` was recorded here too and answers nothing: it prints
                // the command list, in which none of `assist`, `linter`, `formatter` or
                // `enabled` occurs.
                foreach (['linter', 'formatter', 'assist'] as $toggle) {
                    if (($scope[$toggle]['enabled'] ?? null) === false) {
                        fail($violations, $label, sprintf('`%s%s.enabled` must not be false — that disables the shared standard wholesale.', $prefix, $toggle));
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
                // skipping it again in the loop below. $group is null for the
                // `linter.rules` scope itself, which holds no single rule GROUP's
                // rules directly and so has nothing for the per-rule check below to
                // walk.
                if (is_array($topLevelRules)) {
                    $ruleScopes[] = ['linter.rules', $topLevelRules, null];

                    foreach ($topLevelRules as $group => $groupRules) {
                        if (is_array($groupRules) && is_string($group)) {
                            $ruleScopes[] = [sprintf('linter.rules.%s', safeReportValue($group)), $groupRules, $group];
                        }
                    }
                }

                foreach ($ruleScopes as [$path, $rules, $group]) {
                    if (($rules['recommended'] ?? null) === false) {
                        fail($violations, $label, sprintf('`%s%s.recommended` must not be false — that drops the rule floor the shared config builds on.', $prefix, $path));
                    }

                    if (($rules['preset'] ?? null) === 'none') {
                        fail($violations, $label, sprintf('`%s%s.preset` must not be `none` — that drops the rule floor the shared config builds on.', $prefix, $path));
                    }

                    // A second route the same GH-36 fix closes: switching one rule
                    // off by name survives every check above, because none of them
                    // look past the group-level `recommended`/`preset` floor at the
                    // individual rules this package enables explicitly. $sharedRules
                    // is keyed by group, so only a genuine rule-group scope (not the
                    // bare `linter.rules` scope, where $group is null) has anything
                    // to check here.
                    if ($group === null) {
                        continue;
                    }

                    foreach ($sharedRules[$group] ?? [] as $ruleName) {
                        if ($biomeRuleSeverity($rules, $ruleName) === 'off') {
                            fail($violations, $label, sprintf('`%s%s.%s` must not be "off" — that drops a rule the shared config enables explicitly.', $prefix, $path, $ruleName));
                        }
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
    $tsconfigJson = is_file($tsconfigFile) ? $loadJsonc($tsconfigFile) : null;

    // Unreadable is unconditional, exactly as it is for the Biome config: no reader
    // tolerance is in play, the file simply cannot be opened, and that is true whoever
    // wrote it. Gating this one on adoption while the sibling reports it either way
    // would make the same defect visible or invisible depending on which config it is
    // in — the asymmetry is a bug in the reporting, not a difference between the files.
    if (is_file($tsconfigFile) && ($tsconfigJson === false)) {
        fail($violations, 'tsconfig.json', 'exists but cannot be read.');
    }

    if (is_int($tsconfigJson)) {
        fail($violations, 'tsconfig.json', tooLargeDetail(MAX_JSONC_BYTES));
    }

    if ($adopted && is_file($tsconfigFile)) {
        if ($tsconfigJson === null) {
            fail($violations, 'tsconfig.json', 'not valid JSON(C).');
        } elseif (is_array($tsconfigJson)) {
            // tsc appends `.json` itself, so the bare specifier resolves to the same
            // file and must not be reported as a missing link — verified with 7.0.2.
            if (!$extendsShared($tsconfigJson, 'tsconfig/base', true)) {
                fail($violations, 'tsconfig.json', 'must `extends` the shared `@magicsunday/coding-standard/tsconfig/base.json`.');
            }

            // Only the strictness flags are pinned. `esModuleInterop`,
            // `resolveJsonModule` and `skipLibCheck` are ergonomics, not strictness —
            // a consumer turning skipLibCheck off is stricter, not looser — so they
            // are deliberately left free, as are module/target/lib/jsx and paths.
            //
            // Two groups. The nine after `strict` are the family `strict` switches on
            // as a group; the five after those — noUncheckedIndexedAccess through
            // isolatedModules — are not implied by `strict` at all, they are what
            // tsconfig/base.json sets explicitly.
            //
            // The family matters because each member may be written back
            // individually: TypeScript treats the specific option as an override of
            // the umbrella, so pinning only `strict` pins nothing. Measured with tsc
            // 7.0.2:
            //
            //     printf 'export function len(s: string|null): number { return s.length; }' > src/index.ts
            //     # {"strict":true}                            -> error TS18047: 's' is possibly 'null'
            //     # {"strict":true,"strictNullChecks":false}   -> exit 0
            //
            // MEMBERSHIP is taken from TypeScript's documentation of `strict`, not
            // derived. An earlier version of this comment claimed otherwise, on the
            // strength of a probe that only asked whether tsc accepts each name as a
            // compiler option — which the five non-members above pass just as well, so
            // that probe cannot produce a counterexample and proves nothing about
            // membership. Falsifying it needs the shape three lines up, per flag: a
            // snippet the option governs, compiled under `{"strict":true}` and under
            // `{"strict":true,"<flag>":false}`, with the diagnostic required to
            // disappear.
            $pinnedFlags = [
                'strict',
                'alwaysStrict',
                'noImplicitAny',
                'noImplicitThis',
                'strictBindCallApply',
                'strictBuiltinIteratorReturn',
                'strictFunctionTypes',
                'strictNullChecks',
                'strictPropertyInitialization',
                'useUnknownInCatchVariables',
                'noUncheckedIndexedAccess',
                'exactOptionalPropertyTypes',
                'noImplicitOverride',
                'forceConsistentCasingInFileNames',
                'isolatedModules',
            ];

            // A pinned flag switched back to false in a LATER `extends` entry
            // survives untouched if only $tsconfigJson itself is checked — tsc
            // applies the chain in order, each entry overriding the ones before it, so
            // the effective document is what must be asserted against, the shared
            // entry's own bundled content included at its listed position (see
            // $resolveExtendsLayers). A repository with no `extends` array at all
            // gets back exactly $tsconfigJson — the same GH-36 divergence fixed for
            // biome.json above.
            $tsconfigLayers    = $resolveExtendsLayers($repoRoot, $tsconfigJson, 'tsconfig/base', true, $loadOwnConfig($packageRoot . '/tsconfig/base.json'), $violations, 'tsconfig.json');
            $tsconfigEffective = $foldExtendsChain($tsconfigLayers, $tsconfigJson);

            foreach ($pinnedFlags as $flag) {
                if (($tsconfigEffective['compilerOptions'][$flag] ?? null) === false) {
                    fail($violations, 'tsconfig.json', sprintf('`compilerOptions.%s` must not be false — it overrides the shared strict base.', $flag));
                }
            }
        }
    }
}
