<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The AGENTS.md (optional) contract check — see bin/check-consumer-config.php's
 * own docblock for why this split exists and bin/consumer-checks/helpers.php's
 * for the shared-include boundary it follows.
 *
 * A consumer that has an AGENTS.md carries the shared testing policy in it,
 * exactly once, between the markers
 *
 *     <!-- testing-policy:begin vN -->
 *     <!-- testing-policy:end -->
 *
 * with the text of templates/testing-policy.vN.md between them. Text outside
 * the markers is the repository's own and is not checked. Every released
 * template version stays in templates/, so a consumer on an older version still
 * passes until it bumps the marker.
 *
 * No report line carries text read from the file: the version in the marker is
 * digits only by construction of the marker pattern, and everything else the
 * report says is fixed wording. AGENTS.md is editable in a pull request.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts that an existing AGENTS.md carries the shared testing policy block.
 *
 * @param list<string> $violations  The accumulated report, appended to in place.
 * @param string       $repoRoot    The consumer repository root to inspect.
 * @param string       $packageRoot This package's own root, which holds the templates.
 *
 * @return void
 */
function checkAgentsMd(array &$violations, string $repoRoot, string $packageRoot): void
{
    $agentsFile = $repoRoot . '/AGENTS.md';

    if (!is_file($agentsFile)) {
        return;
    }

    $contents = readBounded($violations, $agentsFile, 'AGENTS.md');

    if ($contents === null) {
        return;
    }

    if ($contents === false) {
        fail($violations, 'AGENTS.md', 'exists but cannot be read.');

        return;
    }

    // Line endings first: the markers are matched per line and the block is
    // compared as text, so a CRLF file must not drift from an LF template.
    $contents = str_replace(["\r\n", "\r"], "\n", stripBom($contents));

    $beginCount = preg_match_all('/^<!-- testing-policy:begin v(\d{1,4}) -->$/m', $contents, $begins, PREG_OFFSET_CAPTURE);
    $endCount   = preg_match_all('/^<!-- testing-policy:end -->$/m', $contents, $ends, PREG_OFFSET_CAPTURE);

    if (($beginCount === false) || ($endCount === false)) {
        fail($violations, 'AGENTS.md', sprintf('the testing policy markers could not be scanned (%s), so this gate cannot answer for it.', preg_last_error_msg()));

        return;
    }

    if ($beginCount === 0) {
        fail($violations, 'AGENTS.md', 'carries no testing policy block. Add the text of templates/testing-policy.vN.md between `<!-- testing-policy:begin vN -->` and `<!-- testing-policy:end -->`.');

        return;
    }

    if (($beginCount > 1) || ($endCount > 1)) {
        fail($violations, 'AGENTS.md', 'carries the testing policy markers more than once. Keep exactly one block.');

        return;
    }

    $beginMarker = $begins[0][0];
    $beginStart  = $beginMarker[1];
    $bodyStart   = $beginStart + strlen($beginMarker[0]) + 1;

    if (($endCount === 0) || ($ends[0][0][1] < $bodyStart)) {
        fail($violations, 'AGENTS.md', 'the testing policy begin marker needs an end marker `<!-- testing-policy:end -->` on a later line.');

        return;
    }

    $version      = $begins[1][0][0];
    $templatePath = $packageRoot . '/templates/testing-policy.v' . $version . '.md';

    if (!is_file($templatePath)) {
        fail($violations, 'AGENTS.md', sprintf('names the unknown testing policy version v%s. This package ships no template for it.', $version));

        return;
    }

    $template = readCapped($templatePath, MAX_TEXT_BYTES);

    if (!is_string($template)) {
        fail($violations, 'AGENTS.md', sprintf('the template for testing policy version v%s could not be read, so this gate cannot answer for it.', $version));

        return;
    }

    $bodyLength = max(0, $ends[0][0][1] - 1 - $bodyStart);
    $body       = substr($contents, $bodyStart, $bodyLength);
    $expected   = rtrim(str_replace(["\r\n", "\r"], "\n", $template), "\n");

    if ($body !== $expected) {
        fail($violations, 'AGENTS.md', sprintf('the testing policy block differs from templates/testing-policy.v%s.md. Replace the text between the markers with the template.', $version));
    }
}
