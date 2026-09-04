<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The phpunit.xml (REQUIRED) contract check — see bin/check-consumer-config.php's
 * own docblock for why this split exists and bin/consumer-checks/helpers.php's
 * for the shared-include boundary it follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the strict-flag set + the uniform src/tests layout on phpunit.xml —
 * REQUIRED, unlike every other contract this gate checks.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkPhpunitXml(array &$violations, string $repoRoot): void
{
    $phpunitPath = $repoRoot . '/phpunit.xml';
    $phpunitDist = $repoRoot . '/phpunit.xml.dist';
    $phpunitFile = null;

    if (is_file($phpunitPath)) {
        $phpunitFile = $phpunitPath;
    } elseif (is_file($phpunitDist)) {
        $phpunitFile = $phpunitDist;
    }

    if ($phpunitFile === null) {
        fail($violations, 'phpunit.xml', 'missing — the strict PHPUnit config is required.');

        return;
    }

    // Read it first: simplexml returns the same `false` for an unreadable file as
    // for a malformed one, so without this a permissions problem is reported as a
    // syntax error — on the one file this gate declares REQUIRED, which is the
    // worst place to send the reader to the wrong fix.
    $phpunitContents = readBounded($violations, $phpunitFile, 'phpunit.xml');

    // A malformed file makes simplexml emit an E_WARNING per libxml error and
    // return false; capture those warnings through a scoped handler rather than
    // the banned `@` prefix, then branch on the return value.
    set_error_handler(static fn (): bool => true);

    try {
        $xml = is_string($phpunitContents) ? simplexml_load_string($phpunitContents) : false;
    } finally {
        restore_error_handler();
    }

    if ($phpunitContents === null) {
        // Reported as oversize by readBounded(). This is the file the gate declares
        // REQUIRED, so the report is a violation either way — but `not well-formed
        // XML` on a truncated read names a cause the file does not have.
        return;
    }

    if ($phpunitContents === false) {
        fail($violations, 'phpunit.xml', 'exists but cannot be read.');

        return;
    }

    if ($xml === false) {
        fail($violations, 'phpunit.xml', 'not well-formed XML.');

        return;
    }

    // Every strict attribute must be present AND "true" on the root element.
    $requiredRootFlags = [
        'requireCoverageMetadata',
        'beStrictAboutCoverageMetadata',
        'beStrictAboutOutputDuringTests',
        'failOnRisky',
        'failOnWarning',
        'failOnNotice',
        'failOnDeprecation',
        'failOnPhpunitDeprecation',
        'failOnPhpunitNotice',
    ];

    $rootAttrs = $xml->attributes();

    foreach ($requiredRootFlags as $flag) {
        $value = $rootAttrs[$flag] ?? null;

        if ($value === null) {
            fail($violations, 'phpunit.xml', sprintf('missing strict flag `%s="true"`.', $flag));

            continue;
        }

        if ((string) $value !== 'true') {
            fail($violations, 'phpunit.xml', sprintf('strict flag `%s` must be "true", is "%s".', $flag, safeReportValue((string) $value)));
        }
    }

    // The <source> element must restrict notices and warnings and include src.
    $source = $xml->source ?? null;

    if ($source === null) {
        fail($violations, 'phpunit.xml', 'missing a <source> element.');
    } else {
        foreach (['restrictNotices', 'restrictWarnings'] as $flag) {
            $value = $source->attributes()[$flag] ?? null;

            if (($value === null) || ((string) $value !== 'true')) {
                fail($violations, 'phpunit.xml', sprintf('<source> must set `%s="true"`.', $flag));
            }
        }

        $includeDirs = [];

        foreach ($source->include->directory ?? [] as $dir) {
            $includeDirs[] = (string) $dir;
        }

        if (!in_array('src', $includeDirs, true)) {
            fail($violations, 'phpunit.xml', '<source><include> must cover the `src` directory.');
        }
    }

    // The test suite must run `tests` and exclude `tests/Architecture` when
    // that directory exists — everything there is a rule class (PHPStan or
    // otherwise), never a PHPUnit test, unconditionally.
    $suiteDirs = [];
    $suiteExcl = [];

    foreach ($xml->testsuites->testsuite ?? [] as $suite) {
        foreach ($suite->directory as $dir) {
            $suiteDirs[] = (string) $dir;
        }

        foreach ($suite->exclude as $excl) {
            $suiteExcl[] = (string) $excl;
        }
    }

    if (!in_array('tests', $suiteDirs, true)) {
        fail($violations, 'phpunit.xml', 'a test suite must run the `tests` directory.');
    }

    if (is_dir($repoRoot . '/tests/Architecture') && !in_array('tests/Architecture', $suiteExcl, true)) {
        fail($violations, 'phpunit.xml', 'the `tests/Architecture` directory must be excluded from the suite.');
    }
}
