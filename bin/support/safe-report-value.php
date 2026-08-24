<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines safeReportValue() for the PHP gates that echo a value read out of a
 * repository file. Re-derive which those are rather than trusting a list here:
 * `grep -rln "^require_once .*safe-report-value" bin tests`. Anchored, and naming
 * the statement rather than the path: the bare path also matches files that only
 * MENTION it, including shell files that cannot require a PHP file at all, and
 * including this one, whose own docblock carries the pattern. Node cannot require
 * a PHP file either, and has its own ES module sibling instead
 * (bin/support/safe-report-value.mjs, re-derive its importers with
 * `grep -rl "from '\./support/safe-report-value.mjs'" bin`) — the consumer-facing
 * node gate (bin/check-js-config.mjs) imports it directly, same as this file's PHP
 * requirers import this one. tests/check-js-configs.sh's OWN embedded self-check
 * (its `manifest_check`/peer-range JS, not the shipped gate) is on the same trust
 * boundary as both and carries its own local `encodeValue()` instead, for the
 * same reason neither the PHP nor the .mjs helper is reachable from a bash
 * heredoc.
 *
 * The `bin/` gates run in the CONSUMER's CI over pull-request branch content, and
 * tests/check-version-lockstep.php runs in this repository's own; either way every
 * value they read out of a repository file — a JSON key, an XML attribute value, a
 * phpat subject expression — comes from whoever opened the PR. Their findings go to
 * STDERR and their summaries to STDOUT, and the runner scans BOTH for workflow
 * commands (src/Runner.Worker/Handlers/ScriptHandler.cs wires each stream to its own
 * OutputManager; read 2026-08-19).
 *
 * TWO parser generations read that channel, and they need different defences. The
 * current `::cmd::` form must start the line — but the runner calls TrimStart()
 * first, so leading whitespace does not save it, and the command name is matched
 * case-insensitively. The LEGACY `##[cmd]` form is found with IndexOf and needs no
 * line start at all, which is why scrubbing control characters is not sufficient on
 * its own: a value carrying `##[error]` forges an annotation from mid-line, and the
 * runner then suppresses the real line entirely. Re-derive both against
 * actions/runner: `ActionCommand.TryParse` / `TryParseV2` in
 * src/Runner.Common/ActionCommand.cs and the unconditional v1 fallback in
 * src/Runner.Worker/ActionCommandManager.cs — read 2026-08-19.
 *
 * So this function does two things: it removes the control characters that let a
 * value reach column 0, and it breaks the legacy prefix. Interpolated raw, such a
 * value can split one violation line into several, forge annotations and a
 * clean-run verdict, and — where the source format permits ESC — hide preceding
 * lines in a maintainer's terminal with `ESC[2K`.
 *
 * The exit code still carries the real verdict, which is what keeps this log
 * integrity rather than a gate bypass.
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
 * report.
 *
 * `mb_strcut`, not `substr`: it budgets in BYTES too, so the bound above still holds,
 * and it does not split a multi-byte character at the boundary. The byte budget is
 * the load-bearing half, so the recipe prints the LENGTH as well as the validity — a
 * character-budgeting cut would answer 65 here and the two validity checks alone
 * could not tell it apart:
 *
 *     php -r '$v = str_repeat("a", 63) . "\u{00fc}";
 *         var_dump(strlen(mb_strcut($v, 0, 64, "UTF-8")),
 *                  mb_check_encoding(mb_strcut($v, 0, 64, "UTF-8"), "UTF-8"),
 *                  mb_check_encoding(substr($v, 0, 64), "UTF-8"));'
 *
 * 63 / true / false today. On the invalid UTF-8 this function must survive the two
 * agree byte for byte, with no throw and no warning — measured over a lone lead byte
 * and a run of 0xff.
 *
 * @param int|string $value The raw value read out of a consumer file.
 *
 * @return string
 */
function safeReportValue(int|string $value): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $value) ?? '?';

    // `#[`, not `##[`, so the scrubbed value is safe INDEPENDENTLY of the constant
    // text it gets interpolated into: a report ending in `#` would otherwise supply
    // the first character of a legacy command the scrub had left intact. Breaking the
    // shorter form costs nothing, subsumes the longer one, and removes the need to
    // check whether any constant text ends in `#` as the reports change — the scrub
    // applies unconditionally, so no such count is load-bearing here.
    //
    // `::` is deliberately left alone: the v2 parser needs it at line start (it
    // TrimStart()s first, so leading whitespace does not protect a line), while
    // `Selector::classname` is legitimate report content that scrubbing would
    // mangle on every run. The property that makes this safe is that no report line
    // puts a consumer value where the runner's TrimStart() leaves `::` at the front.
    //
    // That property is ASSERTED, not argued: harness_report_is_inert in
    // tests/harness.sh greps every gate's real output for `^[[:space:]]*::` and for
    // the legacy form, over fixtures that poison each report site. A written
    // enumeration of the call sites stood here instead and was wrong three times in
    // three rounds — it called `  - ` non-whitespace, it went stale the moment a new
    // report prefix landed, and the grep it handed the reader returned a hit it did
    // not account for. The test can contradict the code; a paragraph cannot.
    $clean = str_replace('#[', '#?[', $clean);

    return strlen($clean) > 64 ? mb_strcut($clean, 0, 64, 'UTF-8') . '…' : $clean;
}
