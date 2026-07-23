<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$header = <<<EOF
This file is part of the package magicsunday/coding-standard.

For the full copyright and license information, please read the
LICENSE file that was distributed with this source code.
EOF;

$factory = require __DIR__ . '/vendor/magicsunday/coding-standard/php-cs-fixer/base.php';

return $factory($header)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src/'])
    );
