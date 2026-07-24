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
 * supplies its own paths and PHPStan config, and states the PHP floor once through
 * the `$phpVersion` argument. The factory sets that version on the config AND
 * derives the matching version level set, so a repository with a floor above 8.3
 * receives that version's modernizations instead of being silently pinned to 8.3:
 *
 *     use Rector\Config\RectorConfig;
 *
 *     return static function (RectorConfig $config): void {
 *         $config->paths([__DIR__ . '/src/', __DIR__ . '/tests/']);
 *         $config->phpstanConfig(__DIR__ . '/phpstan.neon');
 *
 *         (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($config, 80300);
 *     };
 *
 * The version is the target the transformed code must run on, independent of the
 * PHP interpreter Rector itself runs on — so targeting 80600 from an 8.5 runtime is
 * a valid way to prepare a codebase for PHP 8.6 ahead of its release. 80500 and
 * 80600 require a Rector new enough to ship the matching `UP_TO_PHP_8x` set.
 *
 * @param int $phpVersion The target PHP floor as a PHP_VERSION_ID integer; one of
 *                        80300, 80400, 80500 or 80600.
 *
 * @return callable(RectorConfig, int): void
 */
return static function (RectorConfig $config, int $phpVersion = 80300): void {
    $levelSet = match ($phpVersion) {
        80300 => LevelSetList::UP_TO_PHP_83,
        80400 => LevelSetList::UP_TO_PHP_84,
        80500 => LevelSetList::UP_TO_PHP_85,
        80600 => LevelSetList::UP_TO_PHP_86,
        default => throw new InvalidArgumentException(sprintf(
            'Unsupported PHP version "%d"; the shared Rector base maps 80300, 80400, 80500 or 80600.',
            $phpVersion
        )),
    };

    $config->phpVersion($phpVersion);
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
        $levelSet,
    ]);

    $config->skip([
        CatchExceptionNameMatchingTypeRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
    ]);
};
