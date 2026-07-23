<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src/',
    ]);

    $rectorConfig->phpVersion(80300);
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($rectorConfig);
};
