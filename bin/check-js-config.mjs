#!/usr/bin/env node

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * Node-side front end for the JS/TS half of the lockstep gate.
 *
 * bin/check-consumer-config.php checks biome.json/tsconfig.json too, but it is a
 * Composer-installed entry point — a repository with no composer.json (the shared
 * Biome/TypeScript setup IS its whole toolchain, magicsunday/webtrees-chart-lib
 * being the case that surfaced this) can never reach it. This file runs the SAME
 * contract against a path argument, wired as an npm script, so a pure-JS
 * repository gets it too.
 *
 * "Same contract" is not an assertion this file makes about itself — it is
 * enforced from the other side: every biome.json/tsconfig.json case in
 * tests/CheckConsumerConfigTest.php also runs THIS gate against the identical
 * fixture directory and requires the identical accept/reject verdict (see
 * that class's assertBoth*() helpers). A rule change that is not applied
 * to both files fails that differential check, not a hand-kept comment.
 *
 * Usage (from a consumer repo root, wired as an npm script):
 *
 *     node node_modules/@magicsunday/coding-standard/bin/check-js-config.mjs .
 *
 * Exit code 0 = every present JS/TS config matches the shared standard; 1 = at
 * least one drift; 2 = the path argument is not a directory. A repository with
 * neither biome.json/biome.jsonc nor tsconfig.json is not probed at all — these
 * configs are optional, exactly as on the PHP side.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { safeReportValue } from './support/safe-report-value.mjs';
import { isArrayLike, mergeConfigLayer } from './support/merge-config-layer.mjs';
import { isDirectory, isFile, readBoundedBytes, tooLargeDetail } from './support/bounded-reader.mjs';
import { decodeJsonLikePhp, loadJsonc, stripBomBytes } from './support/jsonc.mjs';

/**
 * This package's OWN installation root — the directory holding `bin/` and
 * `biome/` as siblings, not the consumer repository `repoRoot` points at.
 * Read-only source for the rule names GH-36's per-rule check derives from
 * `biome/base.json` rather than hand-copying. Mirrors `$packageRoot` in
 * bin/check-consumer-config.php.
 */
const packageRoot = dirname(dirname(fileURLToPath(import.meta.url)));

/**
 * The largest JSONC config this gate will read, in bytes. Mirrors
 * MAX_JSONC_BYTES in bin/check-consumer-config.php — see that constant's
 * docblock for the derivation.
 */
const MAX_JSONC_BYTES = 131072;

/**
 * The largest plain-text config (package.json) this gate will read, in bytes.
 * Mirrors MAX_TEXT_BYTES in bin/check-consumer-config.php.
 */
const MAX_TEXT_BYTES = 1048576;

const repoRoot = process.argv[2] ?? '.';

if (!isDirectory(repoRoot)) {
    process.stderr.write(`Not a directory: ${repoRoot}\n`);
    process.exit(2);
}

/** @type {string[]} */
const violations = [];

/**
 * Records a drift for the final report.
 *
 * @param {string} file   The config file the drift was found in.
 * @param {string} detail What is wrong with it, as the report will read.
 *
 * @returns {void}
 */
function fail(file, detail) {
    violations.push(`${file}: ${detail}`);
}

/**
 * Reads a plain-text config under MAX_TEXT_BYTES, reporting an oversize file
 * itself. Mirrors $readBounded: false means unreadable (not yet reported), null
 * means oversize (already reported), a Buffer is the contents.
 *
 * Returned as bytes, not a decoded string: PHP's own $readBounded is
 * encoding-agnostic too — it is the caller's json_decode() that rejects
 * invalid UTF-8, so decoding (and deciding what an invalid-UTF-8 read means
 * for the caller's own contract) belongs at the call site, via
 * decodeUtf8Strict.
 *
 * @param {string} path
 * @param {string} label How the file is named in the report.
 *
 * @returns {Buffer|false|null}
 */
function readBounded(path, label) {
    const buffer = readBoundedBytes(path, MAX_TEXT_BYTES + 1);

    if (buffer === false) {
        return false;
    }

    if (buffer.length > MAX_TEXT_BYTES) {
        fail(label, tooLargeDetail(MAX_TEXT_BYTES));

        return null;
    }

    return buffer;
}

/**
 * Reports whether a single `extends` specifier IS the shared package entry.
 * See $isSharedSpecifier in bin/consumer-checks/check-biome-tsconfig.php for
 * the full rationale — the pattern below is that closure's regex, translated 1:1 (JS
 * `$` without the `m` flag already matches only the true end of the string, so
 * no equivalent of PCRE's `D` modifier is needed).
 *
 * @param {string} candidate       One `extends` list entry.
 * @param {string} sharedStem      Path inside the package, without the `.json` suffix.
 * @param {boolean} suffixOptional Whether the consuming tool resolves the suffix itself.
 *
 * @returns {boolean}
 */
function isSharedSpecifier(candidate, sharedStem, suffixOptional) {
    const escapedStem = sharedStem.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const suffix = suffixOptional ? '(?:\\.json)?' : '\\.json';
    const pattern = new RegExp(
        `^(?:\\./)?(?:node_modules/(?:\\.pnpm/[^/]+/node_modules/)?)?@magicsunday/coding-standard/${escapedStem}${suffix}$`,
    );

    return pattern.test(candidate);
}

/**
 * Reports whether an `extends` value references the shared config. See
 * $extendsShared in bin/consumer-checks/check-biome-tsconfig.php for the full rationale.
 *
 * @param {object} config         The decoded consumer config.
 * @param {string} sharedStem     Path inside the package, without the `.json` suffix.
 * @param {boolean} suffixOptional Whether the consuming tool resolves the suffix itself.
 * @param {boolean} [listRequired] Whether the consuming tool accepts only a list, never a bare string.
 *
 * @returns {boolean}
 */
function extendsShared(config, sharedStem, suffixOptional, listRequired = false) {
    const extendsValue = Object.hasOwn(config, 'extends') ? config.extends : null;

    if (listRequired && !isArrayLike(extendsValue)) {
        return false;
    }

    const candidates = isArrayLike(extendsValue) ? Object.values(extendsValue) : [extendsValue];

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && isSharedSpecifier(candidate, sharedStem, suffixOptional)) {
            return true;
        }
    }

    return false;
}

/**
 * Folds a resolved `extends` chain into the effective document. Mirrors
 * $foldExtendsChain in bin/consumer-checks/check-biome-tsconfig.php — the one
 * step shared verbatim by the biome.json and tsconfig.json blocks below.
 *
 * @param {object[]} layers   From resolveExtendsLayers, in `extends` order.
 * @param {object} document   The document itself, merged last.
 *
 * @returns {object}
 */
function foldExtendsChain(layers, document) {
    let effective = {};

    for (const layer of layers) {
        effective = mergeConfigLayer(effective, layer);
    }

    return mergeConfigLayer(effective, document);
}

/**
 * Loads a config this package itself ships. Mirrors $loadOwnConfig in
 * bin/consumer-checks/check-biome-tsconfig.php — trusted content, no size
 * bound, no JSONC handling.
 *
 * @param {string} path Absolute path to the package's own config file.
 *
 * @returns {object}
 */
function loadOwnConfig(path) {
    if (!isFile(path)) {
        return {};
    }

    try {
        const decoded = JSON.parse(readFileSync(path, 'utf8'));

        return isArrayLike(decoded) ? decoded : {};
    } catch {
        return {};
    }
}

/**
 * Resolves an `extends` chain to the config layers a real tool would also
 * apply, IN THE ORDER the document names them. Mirrors
 * $resolveExtendsLayers in bin/consumer-checks/check-biome-tsconfig.php — see
 * that closure's docblock for why the shared entry is substituted with this
 * package's own bundled content rather than skipped (order-sensitive: a
 * shared entry listed AFTER a local override wins the fold and undoes it,
 * verified against Biome 2.5.5 and tsc 7.0.2), why an unresolvable
 * package-scoped entry contributes nothing silently, why a path escaping
 * repoRoot is not followed, and why the resolution is one hop deep.
 *
 * @param {string} repoRoot        The consumer repository root.
 * @param {*} extendsValue         The document's raw `extends` value.
 * @param {string} sharedStem      Passed to isSharedSpecifier.
 * @param {boolean} suffixOptional Whether a bare specifier may omit `.json`.
 * @param {object} sharedLayer     This package's own bundled config (from
 *                                  loadOwnConfig), substituted wherever the
 *                                  shared entry sits in the chain.
 * @param {string} label           How the checked config file is named in
 *                                  the report — used when a local target is
 *                                  oversized, the one local-resolution
 *                                  failure this gate reports rather than
 *                                  silently skipping (see below).
 *
 * One residual PHP/JS asymmetry, found and verified during this change's own
 * audit round: a JSON object whose keys are the sequential strings "0", "1",
 * … resolves as a local `extends` candidate list in PHP (its `array_is_list`
 * check cannot tell such an object from a genuine array, because
 * `json_decode` there re-indexes numeric string keys to integers — a
 * long-standing PHP behaviour, not specific to this file) while `Array.isArray`
 * here correctly rejects the same input. Not fixed: Biome itself hard-rejects
 * that `extends` shape outright (`extends has an incorrect type, expected an
 * array, but received an object`, verified against 2.5.5), so no real,
 * loadable consumer config reaches this divergence. See
 * $resolveExtendsLayers in bin/consumer-checks/check-biome-tsconfig.php for
 * the full note.
 *
 * @returns {object[]} The layers, in `extends` order.
 */
function resolveExtendsLayers(repoRoot, extendsValue, sharedStem, suffixOptional, sharedLayer, label) {
    const candidates = Array.isArray(extendsValue)
        ? extendsValue
        : (typeof extendsValue === 'string' ? [extendsValue] : []);

    let realRoot;

    try {
        realRoot = realpathSync(repoRoot);
    } catch {
        realRoot = null;
    }

    const layers = [];

    for (const candidate of candidates) {
        if (typeof candidate !== 'string') {
            continue;
        }

        if (isSharedSpecifier(candidate, sharedStem, suffixOptional)) {
            layers.push(sharedLayer);

            continue;
        }

        if (realRoot === null) {
            continue;
        }

        for (const attempt of suffixOptional ? [candidate, `${candidate}.json`] : [candidate]) {
            const path = join(repoRoot, attempt);

            if (!isFile(path)) {
                continue;
            }

            let real;

            try {
                real = realpathSync(path);
            } catch {
                break;
            }

            if (!(real === realRoot || real.startsWith(realRoot + sep))) {
                break;
            }

            const decoded = loadJsonc(path, MAX_JSONC_BYTES);

            if (decoded.kind === 'ok') {
                layers.push(decoded.value);
            } else if (decoded.kind === 'oversize') {
                // Unlike an unreadable or unparseable local target — left to
                // Biome's own error, per this function's docblock — an
                // oversized one is a file Biome loads and applies without
                // any complaint of its own: MAX_JSONC_BYTES is this gate's
                // OWN defensive cap against stripJsonc's quadratic comment
                // scan, not a real limit either tool enforces. Silently
                // treating it as "not resolved" the way an unparseable file
                // is would let a deliberately padded local target smuggle a
                // real weakening past this gate undetected — found by Codex
                // during PR review.
                fail(label, `a local \`extends\` target (${safeReportValue(attempt)}) ` + tooLargeDetail(MAX_JSONC_BYTES));
            }

            break;
        }
    }

    return layers;
}

/**
 * The individual Biome rules this package's own biome/base.json turns on
 * explicitly, keyed by group. Mirrors $sharedBiomeRules in
 * bin/consumer-checks/check-biome-tsconfig.php.
 *
 * @param {object} biomeBaseConfig This package's own bundled biome/base.json,
 *                                  decoded once by loadOwnConfig and shared
 *                                  with resolveExtendsLayers rather than
 *                                  re-read here.
 *
 * @returns {Record<string, string[]>}
 */
function sharedBiomeRules(biomeBaseConfig) {
    const rules = biomeBaseConfig?.linter?.rules;

    if (!isArrayLike(rules)) {
        return {};
    }

    const byGroup = {};

    for (const [group, groupRules] of Object.entries(rules)) {
        if (group === 'preset' || !isArrayLike(groupRules) || Array.isArray(groupRules)) {
            continue;
        }

        byGroup[group] = Object.keys(groupRules);
    }

    return byGroup;
}

/**
 * Extracts a Biome rule's severity, accepting both value shapes the schema
 * allows: a bare string and an options object. Mirrors $biomeRuleSeverity.
 *
 * @param {*} ruleValue
 *
 * @returns {string|null}
 */
function biomeRuleSeverity(ruleValue) {
    if (typeof ruleValue === 'string') {
        return ruleValue;
    }

    if (isArrayLike(ruleValue) && !Array.isArray(ruleValue) && typeof ruleValue.level === 'string') {
        return ruleValue.level;
    }

    return null;
}

/**
 * Reports whether a decoded config carries a `//` key at any depth. Mirrors
 * $hasNoteKey; recurses into both object values and array elements, since a
 * JSON array decodes to a JS array here just as it decodes to a PHP list-array
 * there.
 *
 * @param {*} node
 *
 * @returns {boolean}
 */
function hasNoteKey(node) {
    if (node === null || typeof node !== 'object') {
        return false;
    }

    if (!Array.isArray(node) && Object.hasOwn(node, '//')) {
        return true;
    }

    const children = Array.isArray(node) ? node : Object.values(node);

    for (const child of children) {
        if (hasNoteKey(child)) {
            return true;
        }
    }

    return false;
}

/**
 * Reports whether the repository consumes the npm side of this package. See
 * $npmDependencyDeclared for the full rationale, in particular why
 * peerDependencies counts and why an unreadable/unparseable manifest is
 * reported rather than silently answered "not adopted".
 *
 * @param {string} repoRoot
 *
 * @returns {boolean}
 */
function npmDependencyDeclared(repoRoot) {
    const packageJsonFile = join(repoRoot, 'package.json');

    if (!isFile(packageJsonFile)) {
        return false;
    }

    const contents = readBounded(packageJsonFile, 'package.json');

    if (contents === null) {
        // Oversize, and already reported by readBounded.
        return false;
    }

    if (contents === false) {
        fail('package.json', 'exists but cannot be read, so the JS/TS contract cannot be checked.');

        return false;
    }

    // package.json has no comment-stripping pass, so it goes through
    // decodeJsonLikePhp directly after a byte-level BOM strip — the same
    // pipeline loadJsonc uses for biome.json/tsconfig.json, which is the
    // point: routing every JSON.parse() call site through the one shared
    // function is what keeps a guard from landing on one of them and not
    // the other, which has already happened twice (see decodeJsonLikePhp's
    // own docblock).
    const json = decodeJsonLikePhp(stripBomBytes(contents));

    if (json === null || typeof json !== 'object') {
        fail('package.json', 'is not valid JSON, so the JS/TS contract cannot be checked.');

        return false;
    }

    for (const section of ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies']) {
        const sectionValue = json[section];

        // PHP checks isset($json[$section]['@magicsunday/coding-standard']),
        // which is false when the key is present but its value is null —
        // Object.hasOwn alone is true there, so the null check is required
        // to keep the two in lockstep on an explicit `"…": null` entry.
        if (
            isArrayLike(sectionValue)
            && Object.hasOwn(sectionValue, '@magicsunday/coding-standard')
            && sectionValue['@magicsunday/coding-standard'] !== null
        ) {
            return true;
        }
    }

    return false;
}

// biome.json / biome.jsonc: the linter must stay wired to the shared ruleset.
let biomeFile = null;
let biomeLabel = null;

for (const candidate of ['biome.json', 'biome.jsonc']) {
    const candidatePath = join(repoRoot, candidate);

    if (isFile(candidatePath)) {
        biomeFile = candidatePath;
        biomeLabel = candidate;
        break;
    }
}

const tsconfigFile = join(repoRoot, 'tsconfig.json');

// Probed only when there is a JS/TS config to hold to the contract — see
// $hasJsConfig for why.
const hasJsConfig = biomeFile !== null || isFile(tsconfigFile);
const adopted = hasJsConfig && npmDependencyDeclared(repoRoot);

if (biomeFile !== null) {
    const label = biomeLabel;
    const biomeResult = loadJsonc(biomeFile, MAX_JSONC_BYTES);

    if (biomeResult.kind === 'ok') {
        // The one check that does NOT depend on adoption — see $hasNoteKey's
        // call site in the PHP gate.
        if (hasNoteKey(biomeResult.value)) {
            fail(
                label,
                'contains a `"//"` key — Biome rejects unknown keys and refuses the whole config, so the file is valid JSON but unloadable. Put the note in a comment or in the README.',
            );
        }
    } else if (biomeResult.kind === 'unreadable') {
        fail(label, 'exists but cannot be read.');
    } else if (biomeResult.kind === 'oversize') {
        fail(label, tooLargeDetail(MAX_JSONC_BYTES));
    } else if (adopted) {
        // A parse failure is gated on adoption; see the PHP gate's comment at
        // the equivalent branch for why.
        fail(label, 'not valid JSON(C).');
    }

    if (adopted && biomeResult.kind === 'ok') {
        const biomeJson = biomeResult.value;

        // Biome requires the `.json` suffix — verified against 2.5.5.
        if (!extendsShared(biomeJson, 'biome/base', false, true)) {
            fail(label, 'must `extends` the shared `@magicsunday/coding-standard/biome/base.json`.');
        }

        // linter/formatter/assist carry three nested places, which combine —
        // the document, each overrides entry, and a per-language block inside
        // either. See the PHP gate's comment for the measured 2.5.5 behaviour
        // this walk exists to catch.
        //
        // Every assertion below runs against the EFFECTIVE document (GH-36) —
        // see $biomeEffective's comment in bin/consumer-checks/check-biome-tsconfig.php.
        const biomeBaseConfig = loadOwnConfig(join(packageRoot, 'biome', 'base.json'));
        const biomeLayers = resolveExtendsLayers(repoRoot, biomeJson.extends ?? null, 'biome/base', false, biomeBaseConfig, label);
        const biomeEffective = foldExtendsChain(biomeLayers, biomeJson);

        // The "//" check above only ever saw the document itself — before
        // GH-36, that was the whole story, since no local `extends` target
        // was ever read. It now is: a local target Biome loads as part of the
        // same chain is refused by Biome on exactly the same grounds, so it
        // needs the same check. See the PHP gate's comment at the equivalent
        // branch.
        if (biomeLayers.some((layer) => hasNoteKey(layer))) {
            fail(label, 'a local `extends` target contains a `"//"` key — Biome rejects unknown keys and refuses the whole config it belongs to, so the chain is valid JSON but unloadable. Put the note in a comment or in the README.');
        }

        // The rule names GH-36's per-rule check below must not find "off" —
        // derived from this package's own biome/base.json, not hand-copied.
        const sharedRules = sharedBiomeRules(biomeBaseConfig);

        const baseScopes = [['', biomeEffective]];
        const overrides = biomeEffective.overrides ?? null;

        if (isArrayLike(overrides)) {
            for (const [index, override] of Object.entries(overrides)) {
                if (isArrayLike(override)) {
                    baseScopes.push([`overrides[${safeReportValue(index)}].`, override]);
                }
            }
        }

        const scopes = [];
        const languages = ['javascript', 'json', 'css', 'graphql', 'grit', 'html'];

        for (const [prefix, baseScope] of baseScopes) {
            scopes.push([prefix, baseScope]);

            for (const language of languages) {
                const languageScope = baseScope[language] ?? null;

                if (isArrayLike(languageScope)) {
                    scopes.push([`${prefix}${language}.`, languageScope]);
                }
            }
        }

        // files.includes narrowed to nothing but exclusions is the disable
        // route that leaves every `enabled` flag true.
        const rootIncludes = biomeEffective.files?.includes ?? null;

        if (isArrayLike(rootIncludes)) {
            const positive = Object.values(rootIncludes).filter(
                (pattern) => typeof pattern === 'string' && !pattern.startsWith('!'),
            );

            if (positive.length === 0) {
                fail(label, '`files.includes` carries no positive pattern, so Biome checks nothing and every other setting here is decorative.');
            }
        }

        for (const [prefix, scope] of scopes) {
            for (const toggle of ['linter', 'formatter', 'assist']) {
                const toggleScope = scope[toggle];
                const enabled = isArrayLike(toggleScope) ? toggleScope.enabled : undefined;

                if (enabled === false) {
                    fail(label, `\`${prefix}${toggle}.enabled\` must not be false — that disables the shared standard wholesale.`);
                }
            }

            // `recommended`/`preset` exist on every rule group as well as on
            // `rules` itself.
            const linterScope = scope.linter;
            const topLevelRules = isArrayLike(linterScope) ? (linterScope.rules ?? null) : null;
            const ruleScopes = [];

            if (isArrayLike(topLevelRules)) {
                ruleScopes.push(['linter.rules', topLevelRules, null]);

                for (const [group, groupRules] of Object.entries(topLevelRules)) {
                    if (isArrayLike(groupRules)) {
                        ruleScopes.push([`linter.rules.${safeReportValue(group)}`, groupRules, group]);
                    }
                }
            }

            for (const [rulesPath, rules, group] of ruleScopes) {
                if (rules.recommended === false) {
                    fail(label, `\`${prefix}${rulesPath}.recommended\` must not be false — that drops the rule floor the shared config builds on.`);
                }

                if (rules.preset === 'none') {
                    fail(label, `\`${prefix}${rulesPath}.preset\` must not be \`none\` — that drops the rule floor the shared config builds on.`);
                }

                // GH-36's second measured route: switching one rule off by name.
                // See the PHP gate's comment at the equivalent branch.
                if (group === null) {
                    continue;
                }

                // `Object.hasOwn`, not a bare `sharedRules[group] ?? []` — `group`
                // is a consumer-controlled rule-group NAME (`Object.entries` over a
                // consumer's own `linter.rules`), and `sharedRules` is a plain
                // object. A group literally named `toString` (or `constructor`,
                // `valueOf`, …) resolves through the prototype chain to the
                // inherited `Object.prototype` member — a function, which is
                // neither `null` nor `undefined`, so `?? []` never applies — and
                // `for...of` over a function throws `TypeError: … is not
                // iterable`, crashing the whole gate on a config Biome would
                // merely answer `Found an unknown key` for. Found by CodeRabbit,
                // verified live: `for (const x of ({})['toString'] ?? []) {}`
                // throws exactly that.
                for (const ruleName of Object.hasOwn(sharedRules, group) ? sharedRules[group] : []) {
                    if (biomeRuleSeverity(rules[ruleName]) === 'off') {
                        fail(label, `\`${prefix}${rulesPath}.${ruleName}\` must not be "off" — that drops a rule the shared config enables explicitly.`);
                    }
                }
            }
        }
    }
}

// tsconfig.json: the strict flags the shared base sets must not be turned back off.
const tsconfigFileExists = isFile(tsconfigFile);
const tsconfigResult = tsconfigFileExists ? loadJsonc(tsconfigFile, MAX_JSONC_BYTES) : null;

if (tsconfigFileExists && tsconfigResult?.kind === 'unreadable') {
    fail('tsconfig.json', 'exists but cannot be read.');
}

if (tsconfigResult?.kind === 'oversize') {
    fail('tsconfig.json', tooLargeDetail(MAX_JSONC_BYTES));
}

if (adopted && tsconfigFileExists) {
    if (tsconfigResult.kind === 'unparseable') {
        fail('tsconfig.json', 'not valid JSON(C).');
    } else if (tsconfigResult.kind === 'ok') {
        const tsconfigJson = tsconfigResult.value;

        // tsc appends `.json` itself, so the bare specifier resolves to the
        // same file — verified with 7.0.2.
        if (!extendsShared(tsconfigJson, 'tsconfig/base', true)) {
            fail('tsconfig.json', 'must `extends` the shared `@magicsunday/coding-standard/tsconfig/base.json`.');
        }

        // The nine after `strict` are the family `strict` switches on as a
        // group; the five after those are not implied by `strict` at all —
        // see the PHP gate's comment for the derivation and the tsc 7.0.2
        // counter-example that measured it.
        const pinnedFlags = [
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

        // GH-36: fold every `extends` entry onto the document itself before
        // asserting, the shared entry's own bundled content included at its
        // listed position — see $tsconfigEffective's comment in
        // bin/consumer-checks/check-biome-tsconfig.php.
        const tsconfigLayers = resolveExtendsLayers(
            repoRoot,
            tsconfigJson.extends ?? null,
            'tsconfig/base',
            true,
            loadOwnConfig(join(packageRoot, 'tsconfig', 'base.json')),
            'tsconfig.json',
        );
        const tsconfigEffective = foldExtendsChain(tsconfigLayers, tsconfigJson);

        for (const flag of pinnedFlags) {
            const compilerOptions = tsconfigEffective.compilerOptions;
            const value = isArrayLike(compilerOptions) ? compilerOptions[flag] : undefined;

            if (value === false) {
                fail('tsconfig.json', `\`compilerOptions.${flag}\` must not be false — it overrides the shared strict base.`);
            }
        }
    }
}

// --- Report ---
if (violations.length === 0) {
    process.stdout.write('check-js-config: OK — every present biome.json/tsconfig.json matches the shared standard.\n');
    process.exit(0);
}

process.stderr.write(`check-js-config: ${violations.length} drift(s) from the shared JS/TS configuration:\n`);

for (const violation of violations) {
    process.stderr.write(`  - ${violation}\n`);
}

process.stderr.write('\nAlign biome.json/tsconfig.json with the @magicsunday/coding-standard biome/base.json and tsconfig/base.json.\n');
process.exit(1);
