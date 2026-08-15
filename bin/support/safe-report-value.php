<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines $safeReportValue for the gate entry scripts under bin/.
 *
 * Both gates run in the CONSUMER's CI over pull-request branch content, so every
 * value they read out of a repository file — a JSON key, an XML attribute value, a
 * phpat subject expression — comes from whoever opened the PR. Their reports go to
 * STDERR, which on GitHub Actions doubles as the workflow-command channel: any line
 * a process writes is scanned for `::notice::`, `::error::` and `::add-mask::` at
 * line start. Interpolated raw, such a value can split one violation line into
 * several, forge annotations and a clean-run verdict, and — where the source format
 * permits ESC — hide preceding lines in a maintainer's terminal with `ESC[2K`.
 *
 * The exit code still carries the real verdict, which is what keeps this log
 * integrity rather than a gate bypass.
 *
 * Shared rather than duplicated because two shipped binaries need it. It is a plain
 * `require` of a closure rather than a class: both entry scripts run in the global
 * namespace by design, and the package autoloads no runtime code.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Reduces a consumer-supplied value to something safe to echo in a report.
 *
 * Byte-wise on purpose, with no `/u`: a value carrying invalid UTF-8 must still be
 * reported rather than collapse. With `/u`, `preg_replace` returns null on such
 * input and the `?? '?'` below would replace the entire value with a single `?`.
 *
 * The 64-byte cap bounds a report the consumer would otherwise control the length
 * of — measured on the phpunit path, a 5000-byte attribute produced a 5224-byte
 * report. `substr` can split a multi-byte character at the boundary, emitting one
 * replacement glyph; `mb_strcut` would avoid that and would also contradict the
 * byte-wise contract above, so the glyph is accepted.
 *
 * @param int|string $value The raw value read out of a consumer file.
 *
 * @return string
 */
$safeReportValue = static function (int|string $value): string {
    $clean = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $value) ?? '?';

    return strlen($clean) > 64 ? substr($clean, 0, 64) . '…' : $clean;
};
