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
// WHICH `in()` root it was found under (re-derive: `grep -n 'excludedDirs\['
// .build/vendor/symfony/finder/Iterator/ExcludeDirectoryFilterIterator.php`
// — a slashless exclude() name is checked via
// isset($excludedDirs[$file->getFilename()]), with no per-root distinction),
// so a future directory literally named `consumer` under bin/ or
// php-cs-fixer/ would be silently skipped too. A dedicated Finder whose ONLY
// `in()` root is tests/ removes the cross-root ambiguity, but exclude()'s
// basename match is STILL unanchored within that one root: it would also
// skip a hypothetical tests/Support/consumer/ or any other nested directory
// literally named `consumer`, not only the top-level tests/consumer fixture.
// notPath() with an anchored regex matches PathFilterIterator's
// getRelativePathname() (relative to this Finder's own `in()` root, i.e.
// relative to tests/) instead of a bare basename, so `^consumer(/|$)`
// excludes exactly the top-level tests/consumer entry and nothing else.
$testsFinder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/tests/'])
    ->notPath('#^consumer(/|$)#')
    ->name('*.php');

return $factory($header)
    ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/bin/', __DIR__ . '/php-cs-fixer/'])
            ->name('*.php')
            ->append($testsFinder)
    );
