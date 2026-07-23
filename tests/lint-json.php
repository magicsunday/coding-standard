<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Validates that every JSON config this package ships parses. A malformed
 * biome.json or tsconfig.base.json would only surface in a consumer otherwise.
 */

$root = dirname(__DIR__);

$files = [
    'composer.json',
    'package.json',
    'biome.json',
    'tsconfig.base.json',
    'templates/jscpd.json',
    'tests/consumer/composer.json',
];

$failed = false;

foreach ($files as $file) {
    $path = $root . '/' . $file;

    if (!is_file($path)) {
        fwrite(STDERR, sprintf("MISSING  %s\n", $file));
        $failed = true;

        continue;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, sprintf("UNREADABLE  %s\n", $file));
        $failed = true;

        continue;
    }

    try {
        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(STDERR, sprintf("INVALID  %s — %s\n", $file, $exception->getMessage()));
        $failed = true;

        continue;
    }

    printf("OK       %s\n", $file);
}

exit($failed ? 1 : 0);
