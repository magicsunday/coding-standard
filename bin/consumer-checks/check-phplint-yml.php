<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The .phplint.yml (optional) contract check — see bin/check-consumer-config.php's
 * own docblock for why this split exists and bin/consumer-checks/helpers.php's
 * for the shared-include boundary it follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts that .phplint.yml lints the php extension.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkPhplintYml(array &$violations, string $repoRoot): void
{
    $phplintFile = $repoRoot . '/.phplint.yml';

    if (!is_file($phplintFile)) {
        return;
    }

    // Normalise line endings first: the block-isolation regex uses `\n`, so a CRLF
    // file would leave a trailing `\r` on each list item and false-fail the `- php`
    // match. The .editorconfig parser splits on the same three terminators, by
    // regex rather than str_replace because it needs the lines anyway.
    $contents = readBounded($violations, $phplintFile, '.phplint.yml');

    if ($contents === null) {
        // Reported as oversize by readBounded(); the arms below would run on a
        // truncated read and name causes the file does not have.
        return;
    }

    if ($contents === false) {
        fail($violations, '.phplint.yml', 'exists but cannot be read.');

        return;
    }

    // The BOM is stripped here and NOT at the jscpd or deptrac read, because
    // the three tools disagree and the gate follows each one. Measured:
    // phplint 9.7.2 reads a BOM-prefixed .phplint.yml and runs normally, so
    // not stripping would false-reject a file the tool accepts — the
    // `^extensions` anchor below sits at offset 0 and the BOM displaces it.
    // jscpd 5.0.14 answers its own BOM'd config with `expected value at line
    // 1 column 1`, and deptrac with `no extension able to load "<BOM>imports"`,
    // so for those two a BOM IS the defect and stripping it would hide one. The
    // deptrac half is the one of the three whose version is recorded in the
    // sibling check-deptrac-yaml.php rather than here; both statements are
    // about the same run. A probe that can CONTRADICT the behaviour, which
    // `composer show` cannot:
    //
    //     printf '\xEF\xBB\xBFimports: []\n' > /tmp/d.yaml \
    //         && .build/bin/deptrac analyse --config-file=/tmp/d.yaml; echo "exit $?"
    $contents = stripBom($contents);
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    // Block-scoped, so a `- php` sitting under some other list (a `paths:`
    // entry naming a php directory, say) does not satisfy the check.
    $extensionsBlock = yamlBlock($contents, 'extensions');

    if ($extensionsBlock === null) {
        fail($violations, '.phplint.yml', sprintf('the `extensions:` block could not be scanned (%s), so this gate cannot answer for it.', preg_last_error_msg()));
    } elseif (($extensionsBlock === '') || (preg_match('/^[ \t]*-[ \t]*php[ \t]*$/m', $extensionsBlock) !== 1)) {
        fail($violations, '.phplint.yml', 'the `extensions:` block must list `- php`.');
    }
}
