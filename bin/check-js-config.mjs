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
 * tests/check-consumer-config-cases.sh also runs THIS gate against the identical
 * fixture directory and requires the identical accept/reject verdict (see
 * assert_accepts_js/assert_rejects_js there). A rule change that is not applied
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

import { closeSync, openSync, readSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { safeReportValue } from './support/safe-report-value.mjs';

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
 * @param {number} bound The cap the reader was held to.
 *
 * @returns {string} The detail line, ready for fail().
 */
function tooLargeDetail(bound) {
    return `is larger than the ${bound} bytes this gate checks, so it was not read in full. A shared-config stub is a few hundred bytes.`;
}

/**
 * @param {string} path
 *
 * @returns {boolean}
 */
function isFile(path) {
    try {
        return statSync(path).isFile();
    } catch {
        return false;
    }
}

/**
 * @param {string} path
 *
 * @returns {boolean}
 */
function isDirectory(path) {
    try {
        return statSync(path).isDirectory();
    } catch {
        return false;
    }
}

/**
 * Reads up to maxBytes of a file through a fixed-size buffer, so a config far
 * past the cap is never fully materialised — the node counterpart of the
 * $readFile bounded-length read in bin/check-consumer-config.php.
 *
 * @param {string} path
 * @param {number} maxBytes
 *
 * @returns {Buffer|false} The bytes actually read (up to maxBytes), or false
 *                          when the file cannot be opened.
 */
function readBoundedBytes(path, maxBytes) {
    let fd;

    try {
        fd = openSync(path, 'r');
    } catch {
        return false;
    }

    try {
        const buffer = Buffer.alloc(maxBytes);
        let total = 0;

        while (total < maxBytes) {
            const bytesRead = readSync(fd, buffer, total, maxBytes - total, null);

            if (bytesRead === 0) {
                break;
            }

            total += bytesRead;
        }

        return buffer.subarray(0, total);
    } catch {
        return false;
    } finally {
        closeSync(fd);
    }
}

/**
 * Decodes bytes as UTF-8, rejecting invalid sequences instead of silently
 * substituting U+FFFD the way `Buffer.prototype.toString('utf8')` does.
 *
 * PHP's `json_decode()` unconditionally rejects a document containing invalid
 * UTF-8 (returns null), and $stripJsonc runs byte-safe ahead of it — so a
 * config with one stray invalid byte anywhere is a parse failure on the PHP
 * side, whoever wrote it. `buffer.toString('utf8')` would instead "repair" the
 * byte to U+FFFD before any parsing happens, and `JSON.parse` would then
 * accept the repaired string — silently ACCEPTING a config the PHP gate
 * rejects, on the same byte-identical file. `TextDecoder` with `fatal: true`
 * is the one Node primitive that fails the way `json_decode` does.
 *
 * @param {Buffer} buffer
 *
 * @returns {string|null} The decoded text, or null on invalid UTF-8.
 */
function decodeUtf8Strict(buffer) {
    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
    } catch {
        return null;
    }
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
 * Strips a leading UTF-8 BOM. npm, Node, Biome and tsc all read a BOM-prefixed
 * config and honour it, while JSON.parse does not reject it either in practice
 * — but tsconfig/biome comments are stripped BEFORE parsing, and a leading BOM
 * character would otherwise sit in front of the first token.
 *
 * @param {string} text
 *
 * @returns {string}
 */
function stripBom(text) {
    return text.startsWith('﻿') ? text.slice(1) : text;
}

/**
 * Scans a complete string literal starting at a `"` and returns the index just
 * past its closing quote (or `n`, on an unterminated literal — the caller's own
 * scan then runs out at the same point a real JSON parser would reject it).
 * Shared by stripComments and stripTrailingCommas, which both need to copy a
 * string literal verbatim and skip past it without treating anything inside it
 * as a comment marker or a structural comma.
 *
 * @param {string} json
 * @param {number} start Index of the opening `"`.
 * @param {number} n     `json.length`.
 *
 * @returns {number}
 */
function skipStringLiteral(json, start, n) {
    let j = start + 1;

    while (j < n) {
        if (json[j] === '\\') {
            j += 2;
            continue;
        }

        if (json[j] === '"') {
            j += 1;
            break;
        }

        j += 1;
    }

    return j;
}

/**
 * Reduces a JSONC document to strict JSON, leaving string contents untouched.
 *
 * A single left-to-right scan rather than a regex: it matches a complete string
 * literal first and copies it verbatim, so nothing inside a string is ever
 * rewritten — not a `//` in a URL, not a `"//"` key, not a `,` that happens to
 * sit before a `}` or `]`. Comments are replaced with ONE space (not removed
 * outright), so a comment placed inside a token cannot fuse the halves back
 * together. This is deliberately NOT a port of $stripJsonc's PCRE possessive-
 * quantifier trick in bin/check-consumer-config.php — that trick exists to keep
 * a *regex* linear; a hand-written scan is linear by construction and needs no
 * equivalent.
 *
 * @param {string} json
 *
 * @returns {string}
 */
function stripComments(json) {
    let out = '';
    let i = 0;
    const n = json.length;

    while (i < n) {
        const c = json[i];

        if (c === '"') {
            const j = skipStringLiteral(json, i, n);

            out += json.slice(i, j);
            i = j;
            continue;
        }

        if (c === '/' && json[i + 1] === '/') {
            let j = i + 2;

            while (j < n && json[j] !== '\n') {
                j += 1;
            }

            out += ' ';
            i = j;
            continue;
        }

        if (c === '/' && json[i + 1] === '*') {
            let j = i + 2;

            while (j < n && !(json[j] === '*' && json[j + 1] === '/')) {
                j += 1;
            }

            j = Math.min(j + 2, n);
            out += ' ';
            i = j;
            continue;
        }

        out += c;
        i += 1;
    }

    return out;
}

/**
 * Strips a trailing comma before `}` or `]`, string-aware for the same reason
 * as stripComments — `{"a": "x,]"}` must decode to `x,]`, not `x]`.
 *
 * @param {string} json Comment-free JSON (comments already reduced to spaces).
 *
 * @returns {string}
 */
function stripTrailingCommas(json) {
    let out = '';
    let i = 0;
    const n = json.length;

    while (i < n) {
        const c = json[i];

        if (c === '"') {
            const j = skipStringLiteral(json, i, n);

            out += json.slice(i, j);
            i = j;
            continue;
        }

        if (c === ',') {
            let j = i + 1;

            while (j < n && /\s/.test(json[j])) {
                j += 1;
            }

            if (json[j] === '}' || json[j] === ']') {
                i += 1;
                continue;
            }
        }

        out += c;
        i += 1;
    }

    return out;
}

/**
 * @param {string} json
 *
 * @returns {string}
 */
function stripJsonc(json) {
    return stripTrailingCommas(stripComments(json));
}

/**
 * Loads a JSONC config. Mirrors $loadJsonc's four-way outcome.
 *
 * @param {string} path
 *
 * @returns {{kind: 'unreadable'}|{kind: 'oversize'}|{kind: 'unparseable'}|{kind: 'ok', value: object}}
 */
function loadJsonc(path) {
    const buffer = readBoundedBytes(path, MAX_JSONC_BYTES + 1);

    if (buffer === false) {
        return { kind: 'unreadable' };
    }

    if (buffer.length > MAX_JSONC_BYTES) {
        return { kind: 'oversize' };
    }

    const text = decodeUtf8Strict(buffer);

    if (text === null) {
        return { kind: 'unparseable' };
    }

    const contents = stripBom(text);
    const stripped = stripJsonc(contents);

    let decoded;

    try {
        decoded = JSON.parse(stripped);
    } catch {
        return { kind: 'unparseable' };
    }

    if (decoded === null || typeof decoded !== 'object') {
        return { kind: 'unparseable' };
    }

    return { kind: 'ok', value: decoded };
}

/**
 * True for anything `json_decode(..., true)` would answer `is_array()` true
 * for: a JSON array AND a JSON object alike, since PHP's associative array
 * does not distinguish them. Every `is_array($x)` check ported from
 * bin/check-consumer-config.php needs this, not `Array.isArray` — a JS
 * `Array.isArray` is false for a plain object, which silently narrows a PHP
 * "either shape" check to "array shape only". Pair with `Object.values()`
 * (uniform over an array's elements and an object's values) wherever the PHP
 * side then iterates the checked value with `foreach`.
 *
 * @param {*} value
 *
 * @returns {boolean}
 */
function isArrayLike(value) {
    return value !== null && typeof value === 'object';
}

/**
 * Reports whether an `extends` value references the shared config. See
 * $extendsShared in bin/check-consumer-config.php for the full rationale — the
 * pattern below is that function's regex, translated 1:1 (JS `$` without the
 * `m` flag already matches only the true end of the string, so no equivalent of
 * PCRE's `D` modifier is needed).
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
    const escapedStem = sharedStem.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const suffix = suffixOptional ? '(?:\\.json)?' : '\\.json';
    const pattern = new RegExp(
        `^(?:\\./)?(?:node_modules/(?:\\.pnpm/[^/]+/node_modules/)?)?@magicsunday/coding-standard/${escapedStem}${suffix}$`,
    );

    for (const candidate of candidates) {
        if (typeof candidate === 'string' && pattern.test(candidate)) {
            return true;
        }
    }

    return false;
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

    const text = decodeUtf8Strict(contents);
    let json = null;

    if (text !== null) {
        try {
            json = JSON.parse(stripBom(text));
        } catch {
            json = null;
        }
    }

    if (json === null || typeof json !== 'object') {
        fail('package.json', 'is not valid JSON, so the JS/TS contract cannot be checked.');

        return false;
    }

    for (const section of ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies']) {
        const sectionValue = json[section];

        if (
            sectionValue !== null
            && typeof sectionValue === 'object'
            && Object.hasOwn(sectionValue, '@magicsunday/coding-standard')
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
    const biomeResult = loadJsonc(biomeFile);

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
        const baseScopes = [['', biomeJson]];
        const overrides = biomeJson.overrides ?? null;

        if (overrides !== null && typeof overrides === 'object') {
            for (const [index, override] of Object.entries(overrides)) {
                if (override !== null && typeof override === 'object') {
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

                if (languageScope !== null && typeof languageScope === 'object') {
                    scopes.push([`${prefix}${language}.`, languageScope]);
                }
            }
        }

        // files.includes narrowed to nothing but exclusions is the disable
        // route that leaves every `enabled` flag true.
        const rootIncludes = biomeJson.files?.includes ?? null;

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
                const enabled = toggleScope !== null && typeof toggleScope === 'object' ? toggleScope.enabled : undefined;

                if (enabled === false) {
                    fail(label, `\`${prefix}${toggle}.enabled\` must not be false — that disables the shared standard wholesale.`);
                }
            }

            // `recommended`/`preset` exist on every rule group as well as on
            // `rules` itself.
            const linterScope = scope.linter;
            const topLevelRules = linterScope !== null && typeof linterScope === 'object' ? (linterScope.rules ?? null) : null;
            const ruleScopes = [];

            if (topLevelRules !== null && typeof topLevelRules === 'object') {
                ruleScopes.push(['linter.rules', topLevelRules]);

                for (const [group, groupRules] of Object.entries(topLevelRules)) {
                    if (groupRules !== null && typeof groupRules === 'object') {
                        ruleScopes.push([`linter.rules.${safeReportValue(group)}`, groupRules]);
                    }
                }
            }

            for (const [rulesPath, rules] of ruleScopes) {
                if (rules.recommended === false) {
                    fail(label, `\`${prefix}${rulesPath}.recommended\` must not be false — that drops the rule floor the shared config builds on.`);
                }

                if (rules.preset === 'none') {
                    fail(label, `\`${prefix}${rulesPath}.preset\` must not be \`none\` — that drops the rule floor the shared config builds on.`);
                }
            }
        }
    }
}

// tsconfig.json: the strict flags the shared base sets must not be turned back off.
const tsconfigFileExists = isFile(tsconfigFile);
const tsconfigResult = tsconfigFileExists ? loadJsonc(tsconfigFile) : null;

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
        // see the PHP gate's comment for the derivation and the measured
        // 7.0.2 counter-example this pins.
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

        for (const flag of pinnedFlags) {
            const compilerOptions = tsconfigJson.compilerOptions;
            const value = compilerOptions !== null && typeof compilerOptions === 'object' ? compilerOptions[flag] : undefined;

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
