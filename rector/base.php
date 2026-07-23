<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Applies the shared magicsunday Rector rule sets to a RectorConfig. The consumer
 * supplies its own paths, PHP version and PHPStan config:
 *
 *     use Rector\Config\RectorConfig;
 *
 *     return static function (RectorConfig $config): void {
 *         $config->paths([__DIR__ . '/src/', __DIR__ . '/tests/']);
 *         $config->phpVersion(80300);
 *         $config->phpstanConfig(__DIR__ . '/phpstan.neon');
 *
 *         (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($config);
 *     };
 *
 * The consumer additionally pins the desired `LevelSetList::UP_TO_PHP_8x` if it
 * wants a floor above 8.3 (this base applies UP_TO_PHP_83).
 *
 * @return callable(RectorConfig): void
 */
return static function (RectorConfig $config): void {
    $config->importNames();
    $config->removeUnusedImports();
    $config->disableParallel();

    $config->sets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
        SetList::TYPE_DECLARATION_DOCBLOCKS,
        LevelSetList::UP_TO_PHP_83,
    ]);

    $config->skip([
        CatchExceptionNameMatchingTypeRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
    ]);
};
