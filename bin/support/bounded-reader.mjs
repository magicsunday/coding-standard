/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * Bounded/BOM-safe file reading, extracted out of bin/check-js-config.mjs (GH-74)
 * once that file crossed 1000 lines. Self-contained: nothing here reports a
 * drift itself (the `fail()`/`violations` report state stays in the gate that
 * owns it, mirroring bin/consumer-checks/helpers.php's own `readBounded()`
 * function, which was never moved into bin/support/read-quietly.php for the
 * same reason) — every caller decides what a `false`/oversize result means for
 * its own report.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

import { closeSync, openSync, readSync, statSync } from 'node:fs';

/**
 * @param {number} bound The cap the reader was held to.
 *
 * @returns {string} The detail line, ready for a caller's own fail().
 */
export function tooLargeDetail(bound) {
    return `is larger than the ${bound} bytes this gate checks, so it was not read in full. A shared-config stub is a few hundred bytes.`;
}

/**
 * @param {string} path
 *
 * @returns {boolean}
 */
export function isFile(path) {
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
export function isDirectory(path) {
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
 * readCapped(), used by the PHP gate's bin/consumer-checks/*.php split.
 *
 * @param {string} path
 * @param {number} maxBytes
 *
 * @returns {Buffer|false} The bytes actually read (up to maxBytes), or false
 *                          when the file cannot be opened.
 */
export function readBoundedBytes(path, maxBytes) {
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
