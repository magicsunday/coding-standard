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

// This package ships php-cs-fixer/base.php itself, so it requires the file
// directly rather than through .build/vendor/magicsunday/coding-standard/ —
// the vendor-nested path a real consumer uses, which does not exist for a
// root package requiring itself (see phpstan.neon's own comment on the same
// constraint).
$factory = require __DIR__ . '/php-cs-fixer/base.php';

return $factory($header)
    ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/bin/', __DIR__ . '/tests/', __DIR__ . '/php-cs-fixer/'])
            ->exclude('consumer')
            ->name('*.php')
    );
