/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * The byte-level JSONC-to-JSON decode pipeline mirroring PHP's `json_decode`,
 * extracted out of bin/check-js-config.mjs (GH-74) once that file crossed
 * 1000 lines. bin/check-consumer-config.php carries the equivalent logic
 * inline (`$stripJsonc`, `$loadJsonc`, …) rather than in its own
 * bin/support/*.php file — every function below cross-references its PHP
 * counterpart in its own docblock instead of repeating the rationale here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

import { readBoundedBytes } from './bounded-reader.mjs';

/**
 * The deepest JSON nesting decodeJsonLikePhp() will decode, matching PHP's
 * `json_decode()` default `$depth`. Measured directly against 8.4: 511
 * levels of `{"a": … }` nesting (the outermost container counts as depth 1)
 * decodes cleanly, 512 fails with "Maximum stack depth exceeded" — so 511 is
 * the last depth that must still be ACCEPTED, and exceedsMaxJsonDepth's
 * `depth > maxDepth` check rejects starting at 512, not at 513.
 */
const MAX_JSON_DEPTH = 511;

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
export function stripBomBytes(buffer) {
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
 * Scans raw JSON(C) source text — every `"..."` literal, key or value, in
 * document order — for a UTF-16 surrogate code unit with no matching
 * partner: the class json_decode() rejects a `\uXXXX` escape for and
 * JSON.parse() accepts. `String.prototype.isWellFormed()` answers exactly
 * that question for one string — unflagged since Node 20.0.0 (V8 11.3,
 * re-derive with `curl -s
 * https://raw.githubusercontent.com/nodejs/node/main/doc/changelogs/CHANGELOG_V20.md \
 *     | grep -B5 isWellFormed`), which is why bin/check-js-config.mjs is the
 * floor package.json's `engines.node` declares — the supported runtime floor
 * npm evaluates on a consumer's install. The warning-otherwise half is
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
export function decodeJsonLikePhp(buffer) {
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
 * @param {number} maxBytes The largest config this gate will read, in bytes
 *                          (the caller's own size policy — mirrors
 *                          MAX_JSONC_BYTES in bin/check-consumer-config.php).
 *
 * @returns {{kind: 'unreadable'}|{kind: 'oversize'}|{kind: 'unparseable'}|{kind: 'ok', value: object}}
 */
export function loadJsonc(path, maxBytes) {
    const buffer = readBoundedBytes(path, maxBytes + 1);

    if (buffer === false) {
        return { kind: 'unreadable' };
    }

    if (buffer.length > maxBytes) {
        return { kind: 'oversize' };
    }

    const stripped = stripJsonc(stripBomBytes(buffer));
    const decoded = decodeJsonLikePhp(stripped);

    return decoded === null ? { kind: 'unparseable' } : { kind: 'ok', value: decoded };
}
