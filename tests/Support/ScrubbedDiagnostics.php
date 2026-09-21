<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function str_contains;
use function str_replace;

// scrubReportControlBytes() — the control-byte-strip + legacy-`##[`-break core
// scrubbedForDiagnostic() below shares rather than duplicating. It is required
// here so GateTestCase and AbstractConsumerPhpstanGateTestCase need no require
// of their own (`grep -rn "^    use ScrubbedDiagnostics;" tests/` lists the
// users); CheckJsConfigsTest requires it again because it calls
// scrubReportControlBytes() directly.
require_once __DIR__ . '/../../bin/support/safe-report-value.php';

/**
 * The failure-message half of a gate suite's PR-content hygiene, shared by the
 * two test-case lineages that assert on a subprocess report: GateTestCase
 * (this package's own gate scripts) and AbstractConsumerPhpstanGateTestCase
 * (the real PHPStan binary). A report — or a repository file a test reads — is
 * PR-editable content, so an assertion over it must not let that content reach
 * a failure message unscrubbed: PHPUnit's own string-containment/regex
 * constraints unconditionally re-embed the FULL, RAW haystack into the
 * exception message (dated and detailed in
 * tests/CheckJsConfigsTest.php's own assertMessageDoesNotForgeWorkflowCommand()
 * docblock, not repeated here), which would forge, in PHPUnit's own failure
 * output, the very workflow-command annotation this trait exists to keep out
 * of a CI log.
 *
 * Both assertion helpers below are therefore a manual str_contains() +
 * self::fail(), never assertStringContainsString()/
 * assertStringNotContainsString(); the four message helpers are the scrub they
 * fail through. tests/ScrubbedDiagnosticGuardTest.php scans a fixed list of
 * suites for a raw report reaching an assertion and recognises the four
 * message helpers by their bare names.
 *
 * @phpstan-require-extends TestCase
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
trait ScrubbedDiagnostics
{
    /**
     * Asserts the report carries $needle, failing with $message followed by
     * the scrubbed report. Guards against an empty $needle first: it is
     * contained in every report, so the containment check would assert
     * nothing.
     *
     * @param GateResult $result  The captured run to check.
     * @param string     $needle  The substring the report must carry.
     * @param string     $message The failure label, followed by the scrubbed report content.
     *
     * @return void
     *
     * @throws AssertionFailedError If $needle is empty, or the report does not carry it.
     */
    protected static function assertOutputContains(GateResult $result, string $needle, string $message): void
    {
        self::assertNotSame('', $needle, 'The must-carry argument is empty, so it would assert nothing.');

        if (str_contains($result->output, $needle)) {
            return;
        }

        self::fail(self::diagnosticMessage($message, $result->output));
    }

    /**
     * The opposite direction of assertOutputContains(): the report must NOT
     * carry $needle. The failure message embeds the report that did carry it,
     * scrubbed. No empty-needle precondition here: an empty needle is
     * contained in every report, so it fails loudly rather than passing
     * vacuously.
     *
     * @param GateResult $result  The captured run to check.
     * @param string     $needle  The substring the report must not carry.
     * @param string     $message The failure label, followed by the scrubbed report content.
     *
     * @return void
     *
     * @throws AssertionFailedError If the report carries $needle.
     */
    protected static function assertOutputDoesNotContain(GateResult $result, string $needle, string $message): void
    {
        if (!str_contains($result->output, $needle)) {
            return;
        }

        self::fail(self::diagnosticMessage($message, $result->output));
    }

    /**
     * Reduces $value to something safe to embed in a self::fail() diagnostic
     * when $value may itself be exactly the forged CI annotation the calling
     * assertion exists to catch, rather than a PHPUnit string-containment/
     * regex constraint. Shares scrubReportControlBytes()'s control-byte strip
     * and legacy `##[` break (bin/support/safe-report-value.php, required near
     * the top of this file), then additionally breaks every `::` occurrence
     * for the same reason: scrubReportControlBytes() deliberately leaves `::`
     * alone (a namespaced identifier is legitimate report content). Embedded
     * newlines are already folded away by that strip, so a `::cmd::` command
     * can only open a line where a diagnostic places $value directly after a
     * newline, as diagnosticMessage() does; breaking every occurrence is the
     * simple form that covers that placement.
     *
     * @param string $value The raw value to scrub before embedding in a self::fail() message.
     *
     * @return string The value scrubbed per scrubReportControlBytes(), with every `::` occurrence broken.
     */
    protected static function scrubbedForDiagnostic(string $value): string
    {
        return str_replace('::', ':?:', scrubReportControlBytes($value));
    }

    /**
     * Composes a self::fail()-ready diagnostic message: $label followed by a
     * newline and $output scrubbed through self::scrubbedForDiagnostic().
     *
     * $label is used verbatim, not scrubbed like $output: a label is a
     * developer-authored literal, and scrubbing it would mangle one that
     * legitimately contains "::" as prose. A call site that ever builds a label
     * from fixture content must scrub it there. The structural guard does not
     * look inside a call to this method: its whole span counts as scrubbed.
     *
     * @param string $label  The failure label, used verbatim.
     * @param string $output The raw value to scrub before appending.
     *
     * @return string The label, a newline, then the output scrubbed.
     */
    protected static function diagnosticMessage(string $label, string $output): string
    {
        return $label . "\n" . self::scrubbedForDiagnostic($output);
    }

    /**
     * Resolves an optional caller-supplied assertion $message against a
     * scrubbed default: $message verbatim when non-empty, otherwise
     * diagnosticMessage()'s $default label followed by $output scrubbed. So it
     * appends the scrubbed $output only when $message is empty; where the
     * output must be appended either way, use self::messageWithOutput().
     *
     * @param string $message The caller-supplied message, used verbatim when non-empty.
     * @param string $default The failure label used when $message is empty.
     * @param string $output  The raw value to scrub before appending, when $message is empty.
     *
     * @return string The message verbatim, or the default plus the output scrubbed when the message is empty.
     */
    protected static function messageOrDefault(string $message, string $default, string $output): string
    {
        return $message !== '' ? $message : self::diagnosticMessage($default, $output);
    }

    /**
     * Composes a self::fail()-ready diagnostic message that always appends
     * $output scrubbed, regardless of whether $message is empty: $message
     * verbatim when non-empty, otherwise $default, either way followed by a
     * newline and $output scrubbed through self::scrubbedForDiagnostic().
     * Unlike messageOrDefault(), which does not append $output when $message is
     * non-empty.
     *
     * @param string $message The caller-supplied message, used verbatim when non-empty.
     * @param string $default The failure label used when $message is empty.
     * @param string $output  The raw value to scrub before appending.
     *
     * @return string The message or the default, followed by the output scrubbed.
     */
    protected static function messageWithOutput(string $message, string $default, string $output): string
    {
        return self::diagnosticMessage($message !== '' ? $message : $default, $output);
    }
}
