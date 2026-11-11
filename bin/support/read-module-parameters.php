<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines readModuleParameters() for bin/sync-webtrees-module.php.
 *
 * The parameter file Make/module.mk of a module is read by make, by this function and by
 * ModuleParameters.php of the release tests. All three follow the same grammar, which is the
 * subset of make that the file uses. A line is an assignment when it holds a name of upper case
 * letters, digits and underscores, optionally indented with spaces, then `=`, `:=`, `::=` or `?=`,
 * then the value. Whitespace around the value and a carriage return at the end of the line are
 * dropped. The last `=`, `:=` or `::=` of a name wins. A `?=` sets a name only when no earlier
 * assignment set it. Every other line is ignored, among them comments, conditionals and `+=`, which
 * the parameter file must not use. A value carries no comment after it.
 * tests/ModuleParameterGrammarTest.php runs one fixture through all three readers and real make.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Reads the assignments of a parameter file.
 *
 * @param string $contents The parameter file
 *
 * @return array<string, string> The value of every parameter that the file assigns
 */
function readModuleParameters(string $contents): array
{
    $parameters = [];

    foreach (explode("\n", $contents) as $line) {
        if (preg_match('/^ *([A-Z][A-Z0-9_]*)[ \t]*(:?:?=|\?=)[ \t]*(.*?)[ \t\r]*$/', $line, $match) !== 1) {
            continue;
        }

        if ($match[2] === '?=' && array_key_exists($match[1], $parameters)) {
            continue;
        }

        $parameters[$match[1]] = $match[3];
    }

    return $parameters;
}
