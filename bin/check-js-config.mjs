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

import { closeSync, openSync, readFileSync, readSync, realpathSync, statSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { safeReportValue } from './support/safe-report-value.mjs';

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

/**
 * The deepest JSON nesting this gate will decode, matching PHP's
 * `json_decode()` default `$depth`. Measured directly against 8.4: 511
 * levels of `{"a": … }` nesting (the outermost container counts as depth 1)
 * decodes cleanly, 512 fails with "Maximum stack depth exceeded" — so 511 is
 * the last depth that must still be ACCEPTED, and exceedsMaxJsonDepth's
 * `depth > maxDepth` check rejects starting at 512, not at 513.
 */
const MAX_JSON_DEPTH = 511;

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
 * bounded-length read in bin/support/read-quietly.php's readQuietly()/
 * readCapped(), used by bin/check-consumer-config.php.
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
 * `ignoreBOM: true` too, for the same reason: every caller of this function
 * already ran stripBomBytes() first, so a leading BOM reaching here is a
 * SECOND one PHP's own $stripBom only strips once — json_decode() then sees
 * that leftover BOM as un-parseable JSON syntax and rejects it ("Syntax
 * error", verified against the buildbox). TextDecoder's default
 * (ignoreBOM: false — the option NAME is misleading, false is the one that
 * silently CONSUMES a leading BOM from the decoded result) would instead
 * strip that second BOM too, leaving JSON.parse() nothing to reject —
 * silently ACCEPTING a double-BOM'd config the PHP gate rejects.
 *
 * @param {Buffer} buffer
 *
 * @returns {string|null} The decoded text, or null on invalid UTF-8.
 */
function decodeUtf8Strict(buffer) {
    try {
        return new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(buffer);
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
 * Strips a leading UTF-8 BOM, at the byte level, before any decoding. npm,
 * Node, Biome and tsc all read a BOM-prefixed config and honour it, while
 * decodeJsonLikePhp's strict decode does not reject one either — but a
 * biome.json/tsconfig.json's comments are stripped BEFORE parsing, and a
 * leading BOM would otherwise sit in front of the first token; operating on
 * bytes rather than a decoded string is required for that path (see
 * stripJsonc's docblock for why decoding must not happen first), and every
 * caller — including package.json, which has no comment-stripping pass of
 * its own — uses this one function rather than a second, string-based copy.
 *
 * @param {Buffer} buffer
 *
 * @returns {Buffer}
 */
function stripBomBytes(buffer) {
    if (buffer.length >= 3 && buffer[0] === 0xef && buffer[1] === 0xbb && buffer[2] === 0xbf) {
        return buffer.subarray(3);
    }

    return buffer;
}

/**
 * @param {number} byte
 *
 * @returns {boolean} True for the PCRE default (byte, non-`/u`) `\s` class —
 *                     space, tab, LF, CR, FF, VT — ASCII whitespace only, not
 *                     the wider set JS's `\s` matches on a decoded string.
 *                     $stripJsonc's trailing-comma pattern has no `/u`
 *                     modifier either, so this mirrors it exactly rather than
 *                     the (wider, Unicode-aware) `\s` this file used before
 *                     the byte-level rewrite.
 */
function isAsciiWhitespaceByte(byte) {
    return byte === 0x20 || byte === 0x09 || byte === 0x0a || byte === 0x0d || byte === 0x0c || byte === 0x0b;
}

/**
 * Scans a complete string literal starting at a `"` and returns the index just
 * past its closing quote (or `n`, on an unterminated literal — the caller's own
 * scan then runs out at the same point a real JSON parser would reject it).
 * Shared by stripCommentsBytes and stripTrailingCommasBytes, which both need
 * to copy a string literal verbatim and skip past it without treating
 * anything inside it as a comment marker or a structural comma.
 *
 * @param {Buffer} buffer
 * @param {number} start Index of the opening `"`.
 * @param {number} n     `buffer.length`.
 *
 * @returns {number}
 */
function skipStringLiteralBytes(buffer, start, n) {
    let j = start + 1;

    while (j < n) {
        if (buffer[j] === 0x5c) {
            j += 2;
            continue;
        }

        if (buffer[j] === 0x22) {
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
 * Operates on raw BYTES, not a decoded string — deliberately, because
 * bin/check-consumer-config.php's $stripJsonc runs byte-safe (no `/u`
 * modifier) over the raw file bytes and only implicitly validates UTF-8
 * AFTERWARDS, via json_decode() on the already-stripped text. An earlier,
 * string-based version of this scan decoded first and validated UTF-8 before
 * stripping — which meant an invalid byte sitting INSIDE a `//`/`/* *\/`
 * comment was rejected here before the comment that would have discarded it
 * ever ran, while the PHP gate strips the comment first and never sees the
 * byte at all. Verified divergence on an otherwise-canonical, adopted
 * biome.json/tsconfig.json carrying one stray non-UTF-8 byte inside a
 * comment: PHP accepted it, the string-based port rejected it. Operating on
 * bytes and deferring decodeUtf8Strict until after stripping (see
 * loadJsonc) closes that gap — every delimiter this scan looks for (`"`,
 * `\`, `/`, `*`, `\n`, whitespace, `,`, `}`, `]`) is single-byte ASCII, and a
 * UTF-8 continuation/invalid byte is always >= 0x80, so it can never be
 * misread as one.
 *
 * A single left-to-right scan rather than a regex: it matches a complete
 * string literal first and copies it verbatim, so nothing inside a string is
 * ever rewritten — not a `//` in a URL, not a `"//"` key, not a `,` that
 * happens to sit before a `}` or `]`. Comments are replaced with ONE space
 * (not removed outright), so a comment placed inside a token cannot fuse the
 * halves back together. This is deliberately NOT a port of $stripJsonc's
 * PCRE possessive-quantifier trick — that trick exists to keep a *regex*
 * linear; a hand-written scan is linear by construction and needs no
 * equivalent.
 *
 * @param {Buffer} buffer
 *
 * @returns {Buffer}
 */
function stripCommentsBytes(buffer) {
    const n = buffer.length;
    const pieces = [];
    let i = 0;
    let segmentStart = 0;

    while (i < n) {
        const c = buffer[i];

        if (c === 0x22) {
            i = skipStringLiteralBytes(buffer, i, n);
            continue;
        }

        if (c === 0x2f && buffer[i + 1] === 0x2f) {
            pieces.push(buffer.subarray(segmentStart, i));

            let j = i + 2;

            while (j < n && buffer[j] !== 0x0a) {
                j += 1;
            }

            pieces.push(Buffer.from([0x20]));
            i = j;
            segmentStart = i;
            continue;
        }

        if (c === 0x2f && buffer[i + 1] === 0x2a) {
            let j = i + 2;
            let closed = false;

            while (j < n) {
                if (buffer[j] === 0x2a && buffer[j + 1] === 0x2f) {
                    closed = true;
                    j += 2;
                    break;
                }

                j += 1;
            }

            if (!closed) {
                // PHP's $stripJsonc alternative for this is the non-greedy
                // `/\*.*?\*/`, which never matches an UNTERMINATED comment —
                // so the raw `/*…` text is left in place there and fails to
                // parse as JSON afterwards. Treating an unterminated comment
                // as extending to EOF (as the closed branch below does) would
                // instead "fix" it silently, accepting a config the PHP gate
                // rejects. Leaving segmentStart where it is and stopping the
                // scan copies the rest of the document verbatim on the flush
                // below, mirroring PHP: whatever comes after is not valid
                // JSON either way.
                i = n;
                break;
            }

            pieces.push(buffer.subarray(segmentStart, i));
            pieces.push(Buffer.from([0x20]));
            i = j;
            segmentStart = i;
            continue;
        }

        i += 1;
    }

    pieces.push(buffer.subarray(segmentStart, n));

    return Buffer.concat(pieces);
}

/**
 * Strips a trailing comma before `}` or `]`, string-aware for the same reason
 * as stripCommentsBytes — `{"a": "x,]"}` must decode to `x,]`, not `x]`.
 * Operates on bytes for the same reason stripCommentsBytes does.
 *
 * @param {Buffer} buffer Comment-free bytes (comments already reduced to spaces).
 *
 * @returns {Buffer}
 */
function stripTrailingCommasBytes(buffer) {
    const n = buffer.length;
    const pieces = [];
    let i = 0;
    let segmentStart = 0;

    while (i < n) {
        const c = buffer[i];

        if (c === 0x22) {
            i = skipStringLiteralBytes(buffer, i, n);
            continue;
        }

        if (c === 0x2c) {
            let j = i + 1;

            while (j < n && isAsciiWhitespaceByte(buffer[j])) {
                j += 1;
            }

            if (buffer[j] === 0x7d || buffer[j] === 0x5d) {
                pieces.push(buffer.subarray(segmentStart, i));
                i += 1;
                segmentStart = i;
                continue;
            }
        }

        i += 1;
    }

    pieces.push(buffer.subarray(segmentStart, n));

    return Buffer.concat(pieces);
}

/**
 * @param {Buffer} buffer
 *
 * @returns {Buffer}
 */
function stripJsonc(buffer) {
    return stripTrailingCommasBytes(stripCommentsBytes(buffer));
}

/**
 * Reports whether a stripped JSONC document nests `{`/`[` past maxDepth
 * levels, checked on raw bytes and iteratively — never by recursing over the
 * decoded structure, which the 128 KiB size cap does nothing to bound (a
 * maximally-nested document costs only a few bytes per level) and which
 * could exhaust JS's own call stack on a file crafted to nest as deep as
 * that cap allows.
 *
 * @param {Buffer} buffer Stripped JSONC bytes (stripJsonc's output).
 * @param {number} maxDepth
 *
 * @returns {boolean}
 */
function exceedsMaxJsonDepth(buffer, maxDepth) {
    const n = buffer.length;
    let depth = 0;
    let i = 0;

    while (i < n) {
        const c = buffer[i];

        if (c === 0x22) {
            i = skipStringLiteralBytes(buffer, i, n);
            continue;
        }

        if (c === 0x7b || c === 0x5b) {
            depth += 1;

            if (depth > maxDepth) {
                return true;
            }
        } else if (c === 0x7d || c === 0x5d) {
            depth -= 1;
        }

        i += 1;
    }

    return false;
}

/**
 * Scans raw JSON(C) source text — every `"..."` literal, key or value, in
 * document order — for a UTF-16 surrogate code unit with no matching
 * partner: the class json_decode() rejects a `\uXXXX` escape for and
 * JSON.parse() accepts. `String.prototype.isWellFormed()` answers exactly
 * that question for one string — unflagged since Node 20.0.0 (V8 11.3,
 * re-derive with `curl -s
 * https://raw.githubusercontent.com/nodejs/node/main/doc/changelogs/CHANGELOG_V20.md \
 *     | grep -B5 isWellFormed`), which is why this file is the floor
 * package.json's `engines.node` declares — the supported runtime floor npm
 * evaluates on a consumer's install. The warning-otherwise half is
 * documented (re-derive with `curl -s
 * https://raw.githubusercontent.com/npm/cli/latest/docs/lib/content/configuring-npm/package-json.md \
 *     | grep -A1 engine-strict`); the hard-failure-under-`engine-strict` half
 * is the runtime behavior itself, not the docs (re-derive with `curl -s
 * https://raw.githubusercontent.com/npm/cli/latest/workspaces/arborist/lib/arborist/build-ideal-tree.js \
 *     | grep -A3 engineStrict` — the `throw err` arm). Unrelated to this
 * repository's own >=24 `devEngines` floor, which governs developing this
 * package, not running it once installed.
 *
 * Runs over the SOURCE, not JSON.parse()'s result: a duplicate object key
 * collapses to its LAST occurrence during parsing, before any check on the
 * parsed value ever runs — so a lone surrogate sitting only in an earlier,
 * overwritten occurrence would go unseen by a check that walked the parsed
 * value instead, while json_decode() validates every string token as it
 * streams, independent of which occurrence survives. Verified with the PHP
 * buildbox: `json_decode('{"a":"\uD800","a":"valid"}', true)` returns NULL
 * ("Single unpaired UTF-16 surrogate in unicode escape") even though the
 * invalid value is the one a later check on the decoded result would never
 * see. Each literal is re-decoded through JSON.parse() in isolation (a
 * single string token has no duplicate-key collapsing of its own) rather
 * than hand-rolling `\uXXXX` unescaping here.
 *
 * @param {string} text Already comment/trailing-comma-stripped source text.
 *
 * @returns {boolean}
 */
function sourceContainsLoneSurrogate(text) {
    const n = text.length;
    let i = 0;

    while (i < n) {
        if (text[i] !== '"') {
            i += 1;
            continue;
        }

        const start = i;
        let j = i + 1;

        while (j < n) {
            if (text[j] === '\\') {
                j += 2;
                continue;
            }

            if (text[j] === '"') {
                j += 1;
                break;
            }

            j += 1;
        }

        let literal;

        try {
            literal = JSON.parse(text.slice(start, j));
        } catch {
            // A malformed literal here fails again, identically, once
            // JSON.parse() runs over the whole document below — no need to
            // duplicate that failure mode here.
            i = j;
            continue;
        }

        if (typeof literal === 'string' && !literal.isWellFormed()) {
            return true;
        }

        i = j;
    }

    return false;
}

/**
 * Decodes a byte buffer the way PHP's `json_decode()` does — applying every
 * guard it enforces natively and this port has to hand-roll: a nesting-depth
 * cap, strict UTF-8 validity, and rejecting an unpaired UTF-16 surrogate
 * escape — returning null on any failure, exactly like json_decode() itself.
 *
 * The ONE place all three guards are wired together, on purpose: they were
 * added to this file one at a time, at different call sites, over several
 * rounds of review — and twice, a guard added at one JSON.parse() call site
 * was found missing at the other (exceedsMaxJsonDepth, then
 * sourceContainsLoneSurrogate). Routing every caller through this single function
 * makes that class of gap structurally impossible to reintroduce at a THIRD
 * call site: there is no longer a second copy of the pipeline for a new
 * guard to land on only one of.
 *
 * @param {Buffer} buffer Already BOM-stripped by every caller; additionally
 *                        comment/trailing-comma-stripped for a JSONC caller
 *                        (biome/tsconfig), passed through unchanged (beyond
 *                        the BOM strip) for the strict-JSON caller
 *                        (package.json).
 *
 * @returns {*|null} The decoded value, or null on any of the failures above.
 */
function decodeJsonLikePhp(buffer) {
    if (exceedsMaxJsonDepth(buffer, MAX_JSON_DEPTH)) {
        // json_decode()'s own default $depth is 512, and it fails past it
        // ("Maximum stack depth exceeded") — measured directly: 511 levels of
        // nesting decodes cleanly, 512 does not. JSON.parse() has no
        // comparable cap at reachable depths, and neither this file's size
        // caps do anything to bound it on their own — 511 levels of
        // `{"a": … }` costs well under 4 KB. Checked ahead of JSON.parse, on
        // the byte-level buffer, so this never recurses over a maximally-
        // nested document itself.
        return null;
    }

    const text = decodeUtf8Strict(buffer);

    if (text === null) {
        return null;
    }

    if (sourceContainsLoneSurrogate(text)) {
        return null;
    }

    let decoded;

    try {
        decoded = JSON.parse(text);
    } catch {
        return null;
    }

    if (decoded === null || typeof decoded !== 'object') {
        return null;
    }

    return decoded;
}

/**
 * Loads a JSONC config. Mirrors $loadJsonc's four-way outcome.
 *
 * UTF-8 validity is checked AFTER stripBomBytes/stripJsonc, not before — see
 * stripCommentsBytes's docblock for why the order is load-bearing.
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

    const stripped = stripJsonc(stripBomBytes(buffer));
    const decoded = decodeJsonLikePhp(stripped);

    return decoded === null ? { kind: 'unparseable' } : { kind: 'ok', value: decoded };
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
 * Reports whether a single `extends` specifier IS the shared package entry.
 * See $isSharedSpecifier in bin/check-consumer-config.php for the full
 * rationale — the pattern below is that function's regex, translated 1:1 (JS
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
 * $extendsShared in bin/check-consumer-config.php for the full rationale.
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
 * Deep-merges two decoded JSON/JSONC documents the way a real tool folds an
 * `extends` chain. Mirrors $mergeConfigLayer in bin/check-consumer-config.php
 * — see that function's docblock for the Biome 2.5.5 measurements behind the
 * `overrides`-concatenates / everything-else-replaces split. PHP's docblock
 * there also explains an `array_is_list([])` ambiguity (an empty JSON object
 * and an empty JSON array both decode to the identical PHP `[]`) that needs a
 * "check whichever side is non-empty" guard — that ambiguity has no JS
 * equivalent, since `JSON.parse('{}')` and `JSON.parse('[]')` decode to
 * distinguishable values here (`Array.isArray` tells them apart), so the
 * plain `isArrayLike(value) && !Array.isArray(value)` check below is already
 * correct on both sides without that guard — with one residual asymmetry
 * against the PHP guard, found during this change's own audit round and left
 * unfixed there for the reason recorded on `$mergeConfigLayer`: a genuinely
 * empty JSON ARRAY on a key whose valid schema shape is always an object
 * (`"linter": []`, never real Biome/tsc config) replaces here (this file's
 * `Array.isArray([])` is unconditionally `true`) while PHP's `array_is_list`
 * ambiguity makes it recurse instead. Not reachable by a config that
 * successfully loads in the real tool — Biome answers that exact shape with
 * `linter has an incorrect type, expected an object, but received an array`
 * before either gate's verdict would matter.
 *
 * `__proto__`/`constructor`/`prototype` are skipped outright — a
 * `JSON.parse`'d document with a literal `"__proto__"` key creates a real OWN
 * property (JSON.parse does not special-case it), but `merged[key]` on the
 * READ side falls through to the inherited accessor once `merged` carries no
 * own `__proto__`, returning the object's actual prototype rather than
 * `undefined` — the "both sides are objects, recurse" branch then treats that
 * prototype as ordinary data and the resulting assignment performs a real
 * `[[SetPrototypeOf]]` on the merged object. None of the three names is a
 * legitimate Biome/tsc config key, so skipping them changes no accepted
 * config's effective result.
 *
 * @param {object} base    The lower-precedence layer.
 * @param {object} overlay The higher-precedence layer.
 *
 * @returns {object}
 */
function mergeConfigLayer(base, overlay) {
    const merged = { ...base };

    for (const [key, value] of Object.entries(overlay)) {
        if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
            continue;
        }

        const baseValue = merged[key];

        if (key === 'overrides' && Array.isArray(value) && Array.isArray(baseValue)) {
            merged[key] = [...baseValue, ...value];

            continue;
        }

        if (
            isArrayLike(value) && !Array.isArray(value)
            && isArrayLike(baseValue) && !Array.isArray(baseValue)
        ) {
            merged[key] = mergeConfigLayer(baseValue, value);

            continue;
        }

        merged[key] = value;
    }

    return merged;
}

/**
 * Folds a resolved `extends` chain into the effective document. Mirrors
 * $foldExtendsChain in bin/check-consumer-config.php — the one step shared
 * verbatim by the biome.json and tsconfig.json blocks below.
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
 * bin/check-consumer-config.php — trusted content, no size bound, no JSONC
 * handling.
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
 * $resolveExtendsLayers in bin/check-consumer-config.php — see that
 * function's docblock for why the shared entry is substituted with this
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
 *
 * @returns {object[]} The layers, in `extends` order.
 */
function resolveExtendsLayers(repoRoot, extendsValue, sharedStem, suffixOptional, sharedLayer) {
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

            const decoded = loadJsonc(path);

            if (decoded.kind === 'ok') {
                layers.push(decoded.value);
            }

            break;
        }
    }

    return layers;
}

/**
 * The individual Biome rules this package's own biome/base.json turns on
 * explicitly, keyed by group. Mirrors $sharedBiomeRules in
 * bin/check-consumer-config.php.
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
        //
        // Every assertion below runs against the EFFECTIVE document (GH-36) —
        // see $biomeEffective's comment in bin/check-consumer-config.php.
        const biomeBaseConfig = loadOwnConfig(join(packageRoot, 'biome', 'base.json'));
        const biomeLayers = resolveExtendsLayers(repoRoot, biomeJson.extends ?? null, 'biome/base', false, biomeBaseConfig);
        const biomeEffective = foldExtendsChain(biomeLayers, biomeJson);

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

                for (const ruleName of sharedRules[group] ?? []) {
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
        // bin/check-consumer-config.php.
        const tsconfigLayers = resolveExtendsLayers(
            repoRoot,
            tsconfigJson.extends ?? null,
            'tsconfig/base',
            true,
            loadOwnConfig(join(packageRoot, 'tsconfig', 'base.json')),
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
