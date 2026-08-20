/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * The node counterpart of bin/support/safe-report-value.php — see that file's
 * header for the trust boundary and why the scrub is shaped this way (control
 * characters to `?`, then break the legacy `#[` workflow-command prefix, then a
 * 64-byte cap). Required rather than duplicated inline for the same reason the
 * PHP gate requires its copy instead of retyping it: bin/check-js-config.mjs and
 * any later node-side gate share ONE definition.
 *
 * @param {number|string} value The raw value read out of a consumer file.
 *
 * @returns {string}
 */
export function safeReportValue(value) {
    // eslint-disable-next-line no-control-regex
    let clean = String(value).replace(/[\x00-\x1F\x7F]/g, '?');
    clean = clean.split('#[').join('#?[');

    const bytes = Buffer.from(clean, 'utf8');

    if (bytes.length <= 64) {
        return clean;
    }

    // mb_strcut's counterpart: cut at 64 bytes, then back off while the byte at
    // the cut point is a UTF-8 continuation byte (10xxxxxx), so a multi-byte
    // character is never split.
    let cut = 64;

    while (cut > 0 && (bytes[cut] & 0xc0) === 0x80) {
        cut -= 1;
    }

    return `${bytes.subarray(0, cut).toString('utf8')}…`;
}
