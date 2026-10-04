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
 * without a marker to wait for.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the jscpd install a `.jscpd.json` requires: jscpd pinned to one exact
 * version in package.json's `devDependencies`, a committed lockfile for
 * `npm ci`, no npm or npx run from a Composer event, and no jscpd run through
 * npx or with a version in a Composer script.
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

    // Both manifests are strict JSON by their tools' own rules; npm and
    // Composer each read a BOM-prefixed file, so the BOM is stripped first.
    // Null means the file was missing, unreadable, oversize or malformed —
    // each already reported, so the caller just stops.
    $readManifest = static function (string $file) use (&$violations, $repoRoot): ?array {
        $contents = readBounded($violations, $repoRoot . '/' . $file, $file);

        if ($contents === null) {
            return null;
        }

        if ($contents === false) {
            fail($violations, $file, 'exists but cannot be read, so the jscpd install contract cannot be checked.');

            return null;
        }

        $json = json_decode(stripBom($contents), true);

        if (!is_array($json)) {
            fail($violations, $file, 'is not valid JSON, so the jscpd install contract cannot be checked.');

            return null;
        }

        return $json;
    };

    // --- package.json: one exact pin, where Dependabot reads it ---

    if (!is_file($repoRoot . '/package.json')) {
        fail($violations, 'package.json', 'is missing. `.jscpd.json` is present, so jscpd must be pinned to one exact version in `devDependencies`, where `npm ci` installs it and Dependabot bumps it.');
    } else {
        $package = $readManifest('package.json');

        if ($package !== null) {
            $devDependencies = $package['devDependencies'] ?? null;

            if (!is_array($devDependencies) || !array_key_exists('jscpd', $devDependencies)) {
                $elsewhere = [];

                foreach (['dependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
                    if (is_array($package[$section] ?? null) && array_key_exists('jscpd', $package[$section])) {
                        $elsewhere[] = $section;
                    }
                }

                fail(
                    $violations,
                    'package.json',
                    '`devDependencies` must declare jscpd, pinned to one exact version, because `.jscpd.json` is present'
                    . ($elsewhere === [] ? '.' : sprintf('; it is declared under `%s`, where a CI tool does not belong.', implode('`, `', $elsewhere)))
                );
            } else {
                $version = $devDependencies['jscpd'];

                // One exact SemVer 2.0.0 version, pre-release and build metadata
                // included, and nothing npm would read as a range or a moving
                // reference. `=5.3.2` and `v5.3.2` are exact to npm too, but the
                // bare form is the one Dependabot writes, and one spelling keeps a
                // pin greppable across repositories.
                $exactVersion = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
                    . '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?'
                    . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';

                if (!is_string($version) || (preg_match($exactVersion, $version) !== 1)) {
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
    // cache on it, so a shrinkwrap alone passes here and fails there.
    if (!is_file($repoRoot . '/package-lock.json')) {
        fail($violations, 'package-lock.json', 'is missing. `npm ci` installs only from a committed lockfile, and the shared cpd workflow requires package-lock.json by name.');
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

    // Every event Composer's scripts documentation names
    // (doc/articles/scripts.md, "Event names": command, installer, package and
    // plugin events). A script under one of these names runs on its own during
    // `composer install`/`update` and friends, which is what the contract
    // keeps npm out of. tests/CheckConsumerConfigJscpdInstallTest.php proves
    // this list against the cases it drives, in both directions.
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
    $commandsOf = static function (mixed $script): array {
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

    // npm or npx as a program: at the start of the command or after a shell
    // separator, an opening parenthesis or a quote, and ended the same way.
    // `pnpm`, `npmish` and `npm-free` are other words, not npm.
    $runsNpm = static fn (string $command): bool => preg_match('/(?:^|[\s;&|(`\'"])np[mx](?=$|[\s;&|)`\'"])/', $command) === 1;

    // Follows `@name` references to other scripts depth-first, so a hook that
    // reaches npm two scripts away is still found. `@php`, `@composer` and
    // `@putenv` are Composer's own commands, not script references. Returns
    // the reference chain and the offending command, or null.
    $findNpm = static function (string $name, array $chain, array &$visited) use (&$findNpm, $scripts, $commandsOf, $runsNpm): ?array {
        $visited[$name] = true;

        foreach ($commandsOf($scripts[$name] ?? null) as $command) {
            if (preg_match('/^@([^\s]+)/', $command, $reference) === 1) {
                $target = $reference[1];

                if (in_array($target, ['php', 'composer', 'putenv'], true)
                    || !array_key_exists($target, $scripts)
                    || isset($visited[$target])
                ) {
                    continue;
                }

                $found = $findNpm($target, [...$chain, $target], $visited);

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if ($runsNpm($command)) {
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
        $found   = $findNpm($event, [], $visited);

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

    // jscpd through npx, or with a version in the command, runs a release the
    // package.json pin does not control: npx resolves its own copy, and
    // `jscpd@<range>` names one in the script itself.
    foreach ($scripts as $name => $script) {
        foreach ($commandsOf($script) as $command) {
            $viaNpx       = preg_match('/(?:^|[\s;&|(`\'"])npx\s[^;&|]*(?<![\w.\/-])jscpd(?![\w-])/', $command) === 1;
            $namesVersion = preg_match('/(?<![\w-])jscpd@/', $command) === 1;

            if (!$viaNpx && !$namesVersion) {
                continue;
            }

            fail(
                $violations,
                'composer.json',
                sprintf(
                    'the script `%s` runs jscpd through npx or names a version (`%s`). Run the binary package.json pins: `node_modules/.bin/jscpd`.',
                    safeReportValue((string) $name),
                    safeReportValue($command)
                )
            );

            break;
        }
    }
}
