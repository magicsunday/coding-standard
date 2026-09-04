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

// A single Finder with three `in()` roots and one `exclude('consumer')` would
// NOT scope the exclusion to tests/consumer alone: Symfony Finder computes a
// slashless exclude name's match against each file's basename regardless of
// WHICH `in()` root it was found under (verified against the vendored
// ExcludeDirectoryFilterIterator — a slashless exclude() name is checked via
// isset($excludedDirs[$file->getFilename()]), with no per-root distinction),
// so a future directory literally named `consumer` under bin/ or
// php-cs-fixer/ would be silently skipped too. A dedicated Finder whose ONLY
// `in()` root is tests/ makes the same exclude() call unambiguous.
$testsFinder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/tests/'])
    ->exclude('consumer')
    ->name('*.php');

return $factory($header)
    ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/bin/', __DIR__ . '/php-cs-fixer/'])
            ->name('*.php')
            ->append($testsFinder)
    );
