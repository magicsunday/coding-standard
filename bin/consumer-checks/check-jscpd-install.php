<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The jscpd install contract check (GH-219) — what a `.jscpd.json` brings with
 * it, beyond the config itself (bin/consumer-checks/check-jscpd-json.php). See
 * bin/check-consumer-config.php's own docblock for why this split exists and
 * bin/consumer-checks/helpers.php's for the shared-include boundary it follows.
 *
 * Keyed on `.jscpd.json` being present, not on an adoption marker: unlike the
 * npm link to this package, every part of this contract is something a
 * consumer can put in place BEFORE the release that ships the check — an exact
 * pin, a lockfile, hooks without npm — so "align first, enforce second" holds
 * without a marker to wait for. The command text of the cpd script (GH-223)
 * follows the same rule: a consumer carries it before the release that ships
 * the check.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the jscpd install a `.jscpd.json` requires: jscpd pinned to one exact
 * version in package.json's `devDependencies`, a lockfile for
 * `npm ci`, no npm or npx run from a Composer event, no jscpd run through
 * npx or with a version in a Composer script, and a Composer command that runs
 * jscpd carries exactly the documented command line.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkJscpdInstall(array &$violations, string $repoRoot): void
{
    if (!is_file($repoRoot . '/.jscpd.json')) {
        return;
    }

    // Null means the file was missing, unreadable, oversize or malformed, each
    // already reported, so the caller just stops.
    $readManifest = static function (string $file) use (&$violations, $repoRoot): ?array {
        return readJsonManifest($violations, $repoRoot . '/' . $file, $file, 'jscpd install contract');
    };

    // --- package.json: one exact pin, where Dependabot reads it ---

    if (!is_file($repoRoot . '/package.json')) {
        fail($violations, 'package.json', 'is missing. `.jscpd.json` is present, so jscpd must be pinned to one exact version in `devDependencies`, where `npm ci` installs it and Dependabot bumps it.');
    } else {
        $package = $readManifest('package.json');

        if ($package !== null) {
            $devDependencies = $package['devDependencies'] ?? null;

            $elsewhere = [];

            foreach (['dependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
                if (
                    is_array($package[$section] ?? null)
                    && array_key_exists('jscpd', $package[$section])
                ) {
                    $elsewhere[] = $section;
                }
            }

            if (
                !is_array($devDependencies)
                || !array_key_exists('jscpd', $devDependencies)
            ) {
                fail(
                    $violations,
                    'package.json',
                    '`devDependencies` must declare jscpd, pinned to one exact version, because `.jscpd.json` is present'
                    . ($elsewhere === [] ? '.' : sprintf('; it is declared under `%s`, where a CI tool does not belong.', implode('`, `', $elsewhere)))
                );
            } else {
                if ($elsewhere !== []) {
                    fail(
                        $violations,
                        'package.json',
                        sprintf('jscpd is also declared under `%s`, so its version lives in more than one place. Keep the one exact pin in `devDependencies`.', implode('`, `', $elsewhere))
                    );
                }

                $version = $devDependencies['jscpd'];

                // One exact SemVer 2.0.0 version, pre-release and build metadata
                // included, and nothing npm would read as a range or a moving
                // reference. `=5.3.2` and `v5.3.2` are exact to npm too, but the
                // bare form is the one Dependabot writes, and one spelling keeps a
                // pin greppable across repositories.
                $exactVersion = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
                    . '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?'
                    . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';

                if (
                    !is_string($version)
                    || (preg_match($exactVersion, $version) !== 1)
                ) {
                    fail(
                        $violations,
                        'package.json',
                        sprintf(
                            '`devDependencies.jscpd` must be one exact version such as "5.3.2", is %s. A range or tag installs whatever release is newest, not the version Dependabot proposed.',
                            is_string($version) ? '"' . safeReportValue($version) . '"' : 'not a string'
                        )
                    );
                }
            }
        }
    }

    // --- lockfile: what `npm ci` installs from ---

    // package-lock.json by name: `npm ci` would also install from an
    // npm-shrinkwrap.json, but the shared cpd workflow (magicsunday/.github,
    // .github/workflows/cpd.yml) requires package-lock.json and keys its npm
    // cache on it, so a shrinkwrap alone passes here and fails there. Re-check:
    // gh api repos/magicsunday/.github/contents/.github/workflows/cpd.yml
    //     --jq .content | base64 -d | grep -n package-lock
    if (!is_file($repoRoot . '/package-lock.json')) {
        fail($violations, 'package-lock.json', 'is missing. `npm ci` installs only from a lockfile, and the shared cpd workflow requires package-lock.json by name.');
    }

    // --- composer.json: no npm from a Composer event, no jscpd around the pin ---

    if (!is_file($repoRoot . '/composer.json')) {
        return;
    }

    $composer = $readManifest('composer.json');
    $scripts  = $composer['scripts'] ?? null;

    if (!is_array($scripts)) {
        return;
    }

    // The Composer events a script can hook (command, installer, package and
    // plugin events). A script under one of these names runs on its own during
    // `composer install`/`update` and friends, which is what the contract
    // keeps npm out of. tests/CheckConsumerConfigJscpdInstallHooksTest.php
    // proves this list against the cases it drives, in both directions.
    $composerEvents = [
        'pre-install-cmd',
        'post-install-cmd',
        'pre-update-cmd',
        'post-update-cmd',
        'pre-status-cmd',
        'post-status-cmd',
        'pre-archive-cmd',
        'post-archive-cmd',
        'pre-autoload-dump',
        'post-autoload-dump',
        'post-root-package-install',
        'post-create-project-cmd',
        'pre-operations-exec',
        'pre-package-install',
        'post-package-install',
        'pre-package-update',
        'post-package-update',
        'pre-package-uninstall',
        'post-package-uninstall',
        'init',
        'command',
        'pre-file-download',
        'post-file-download',
        'pre-command-run',
        'pre-pool-create',
    ];

    // A script is a command string or a list of them; anything else (a
    // callback map, a number, null) is Composer's own error to report.
    $commandsOf = static function (string|int|float|bool|array|null $script): array {
        if (is_string($script)) {
            return [$script];
        }

        if (!is_array($script)) {
            return [];
        }

        $commands = [];

        foreach ($script as $command) {
            if (is_string($command)) {
                $commands[] = $command;
            }
        }

        return $commands;
    };

    // Where a program name can start: at the start of the command or after a
    // shell separator, an opening parenthesis, a quote, a slash or a backslash
    // (so an absolute path to the binary counts).
    $programStart = '(?:^|[\s;&|(`\'"\/\\\\])';

    // npm or npx as a program. The word after it must not continue the name,
    // so `npm>/dev/null` and `npm${IFS}ci` still count while `pnpm`, `npmish`
    // and `npm-free` stay other words. The Windows launchers `npm.cmd`, `npm.exe`
    // and `npm.bat` are npm, in any letter case, and so are the npx ones.
    $runsNpm = static fn (string $command): bool => preg_match('/' . $programStart . '(?i:np[mx](?:\.(?:cmd|exe|bat))?)(?![\w.@\/-])/', $command) === 1;

    // jscpd run through npx: an npx word appears in a segment and a jscpd word
    // follows it in the same segment. Each segment is scanned once, from its first
    // npx, so a command made of many npx words costs one linear scan and not one
    // scan per npx. The split ignores quotes on purpose: an npx inside a quoted
    // `bash -c` string still installs, so over-detecting here is the safe side.
    $runsJscpdViaNpx = static function (string $command) use ($programStart): bool {
        // A line continuation joins the lines before the shell splits them.
        foreach (preg_split('/[;&|\n]/', str_replace("\\\n", '', $command)) ?: [] as $segment) {
            if (preg_match('/' . $programStart . '(?i:npx(?:\.(?:cmd|exe|bat))?)\s/', $segment, $found, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $rest = substr($segment, $found[0][1] + strlen($found[0][0]));

            if (preg_match('/(?<![\w.\/-])jscpd(?![\w-])/', $rest) === 1) {
                return true;
            }
        }

        return false;
    };

    // Follows `@name` references to other scripts depth-first, so a hook that
    // reaches npm two scripts away is still found. `@php` and `@putenv` are
    // Composer's own commands, not script references. `@composer` can run a
    // script by name in several spellings (`run-script name`, a bare `name`,
    // options before either), so every word of its arguments that names a
    // script is followed instead of parsing Composer's command line. Whatever
    // follows the `@name` word reaches a shell as arguments, so it is checked
    // like any other command string. A chain deeper than the limit is not
    // followed any further and is reported once, so the walk stays small and
    // shallow however long a manifest within the size cap makes a chain.
    // Returns the reference chain and the offending command, or null.
    $maxReferenceDepth = 64;
    $chainTooDeep      = false;
    $findNpm           = static function (string $name, array &$chain, array &$visited) use (&$findNpm, &$chainTooDeep, $maxReferenceDepth, $scripts, $commandsOf, $runsNpm): ?array {
        $visited[$name] = true;

        foreach ($commandsOf($scripts[$name] ?? null) as $command) {
            $targets   = [];
            $arguments = $command;

            if (preg_match('/^@(\S+)\s*(.*)$/s', $command, $reference) === 1) {
                $arguments = $reference[2];

                if ($reference[1] === 'composer') {
                    $targets = preg_split('/\s+/', $arguments, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                } elseif (
                    ($reference[1] !== 'php')
                    && ($reference[1] !== 'putenv')
                ) {
                    $targets = [$reference[1]];
                }
            }

            foreach ($targets as $target) {
                if (
                    !array_key_exists($target, $scripts)
                    || isset($visited[$target])
                ) {
                    continue;
                }

                if (count($chain) >= $maxReferenceDepth) {
                    $chainTooDeep = true;

                    continue;
                }

                $chain[] = $target;
                $found   = $findNpm($target, $chain, $visited);

                if ($found !== null) {
                    return $found;
                }

                array_pop($chain);
            }

            if ($runsNpm($arguments)) {
                return [$chain, $command];
            }
        }

        return null;
    };

    foreach ($composerEvents as $event) {
        if (!array_key_exists($event, $scripts)) {
            continue;
        }

        $visited = [];
        $chain   = [];
        $found   = $findNpm($event, $chain, $visited);

        if ($found === null) {
            continue;
        }

        [$chain, $command] = $found;

        $through = '';

        if ($chain !== []) {
            $through = ' through ' . implode(' -> ', array_map(
                static fn (string $target): string => '`@' . safeReportValue($target) . '`',
                $chain
            ));
        }

        fail(
            $violations,
            'composer.json',
            sprintf(
                'the Composer event `%s` runs npm or npx%s (`%s`), so the Composer run reaches the network outside the step that installs the Node tooling. Run `npm ci` explicitly instead, in CI and in the local install target.',
                $event,
                $through,
                safeReportValue($command)
            )
        );
    }

    if ($chainTooDeep) {
        fail(
            $violations,
            'composer.json',
            sprintf('a chain of script references is deeper than %d, so the gate does not follow it and cannot tell whether a Composer event reaches npm.', $maxReferenceDepth)
        );
    }

    // The command line the shared cpd workflow runs (magicsunday/.github,
    // .github/workflows/cpd.yml): the installed binary, the config pair and
    // the flags listed below, in any order, and nothing else. No scan path, because
    // `.jscpd.json` carries the paths, and no other flag, because a flag the
    // workflow does not pass makes the local scan a different scan than CI.
    // Re-check: gh api repos/magicsunday/.github/contents/.github/workflows/cpd.yml
    //     --jq .content | base64 -d | grep -n 'jscpd'
    // and compare the whole line, not only the program path.
    $documentedProgram = 'node_modules/.bin/jscpd';
    $documentedFlags   = ['--skip-comments', '--no-tips', '--fail-on-empty'];

    // Where a command segment runs jscpd, its first word is the program, so a
    // path argument that merely ends in jscpd (`npx foo node_modules/.bin/jscpd`)
    // is not a run. Returns what differs from the documented command, or null.
    // A segment is what an unquoted shell separator (`;`, `&`, `|` or a newline)
    // leaves. One pass tracks quotes, backslash escapes and comments, so a quote
    // inside the other kind of quote or a comment does not open one. The README
    // lists what the check does not see.
    $commandLineDrift = static function (string $command) use ($documentedProgram, $documentedFlags): ?string {
        $segments = [];
        $current  = '';
        $quote    = '';
        $length   = strlen($command);

        for ($position = 0; $position < $length; ++$position) {
            $character = $command[$position];

            if ($quote === "'") {
                $quote = ($character === "'") ? '' : $quote;
            } elseif (
                ($character === '\\')
                && ($position + 1 < $length)
            ) {
                $escaped = $command[++$position];

                // A backslash before a newline is a line continuation, which the
                // shell removes together with the newline.
                $current .= ($escaped === "\n") ? '' : $character . $escaped;

                continue;
            } elseif ($quote === '"') {
                $quote = ($character === '"') ? '' : $quote;
            } elseif (
                ($character === '"')
                || ($character === "'")
            ) {
                $quote = $character;
            } elseif (
                ($character === '#')
                && (trim(substr($current, -1)) === '')
            ) {
                $newline  = strpos($command, "\n", $position);
                $position = ($newline === false) ? $length : ($newline - 1);

                continue;
            } elseif (strpbrk($character, ";&|\n") !== false) {
                $segments[] = $current;
                $current    = '';

                continue;
            }

            $current .= $character;
        }

        $segments[] = $current;

        foreach ($segments as $segment) {
            $words = preg_split('/\s+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (
                ($words === [])
                || (preg_match('#(?:^|/)jscpd$#', $words[0]) !== 1)
            ) {
                continue;
            }

            if ($words[0] !== $documentedProgram) {
                return sprintf(
                    'the program is `%s`, not `%s`',
                    safeReportValue($words[0]),
                    $documentedProgram
                );
            }

            $seen  = [];
            $count = count($words);

            for ($index = 1; $index < $count; ++$index) {
                $word = $words[$index];

                if ($word === '--config') {
                    if (($words[$index + 1] ?? null) !== '.jscpd.json') {
                        return '`--config` must be followed by `.jscpd.json`';
                    }

                    if (isset($seen[$word])) {
                        return '`--config .jscpd.json` is given twice';
                    }

                    $seen[$word] = true;
                    ++$index;

                    continue;
                }

                if (!in_array($word, $documentedFlags, true)) {
                    return sprintf('`%s` is not part of the documented command', safeReportValue($word));
                }

                if (isset($seen[$word])) {
                    return sprintf('`%s` is given twice', $word);
                }

                $seen[$word] = true;
            }

            foreach (['--config', ...$documentedFlags] as $required) {
                if (!isset($seen[$required])) {
                    return sprintf(
                        '`%s` is missing',
                        $required === '--config' ? '--config .jscpd.json' : $required
                    );
                }
            }
        }

        return null;
    };

    // The contract runs the binary the package.json pin installs, so jscpd
    // through npx, or with a version in the command, is reported. A command
    // that runs the installed binary is held to the documented command line.
    foreach ($scripts as $name => $script) {
        foreach ($commandsOf($script) as $command) {
            $viaNpx       = $runsJscpdViaNpx($command);
            $namesVersion = preg_match('/(?<![\w-])jscpd@/', $command) === 1;

            if (
                !$viaNpx
                && !$namesVersion
            ) {
                $drift = $commandLineDrift($command);

                if ($drift === null) {
                    continue;
                }

                fail(
                    $violations,
                    'composer.json',
                    sprintf(
                        'the script `%s` runs jscpd with a command line that differs from the documented one: %s (`%s`). The shared cpd workflow runs `%s %s %s`, so the script runs exactly that, with the scan paths in `.jscpd.json`.',
                        safeReportValue($name),
                        $drift,
                        safeReportValue($command),
                        $documentedProgram,
                        '--config .jscpd.json',
                        implode(' ', $documentedFlags)
                    )
                );

                break;
            }

            fail(
                $violations,
                'composer.json',
                sprintf(
                    'the script `%s` runs jscpd through npx or names a version (`%s`). Run the binary package.json pins: `node_modules/.bin/jscpd`.',
                    safeReportValue($name),
                    safeReportValue($command)
                )
            );

            break;
        }
    }
}
