<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Runner\Parallel\ParallelConfig;

/**
 * Builds the shared magicsunday php-cs-fixer configuration. The consumer supplies
 * its own file header and finder:
 *
 *     $factory = require __DIR__ . '/vendor/magicsunday/coding-standard/php-cs-fixer/base.php';
 *
 *     return $factory(<<<EOF
 *         This file is part of the package magicsunday/<repo>.
 *
 *         For the full copyright and license information, please read the
 *         LICENSE file that was distributed with this source code.
 *         EOF)
 *         ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
 *         ->setFinder(
 *             PhpCsFixer\Finder::create()
 *                 ->in([__DIR__ . '/src/', __DIR__ . '/tests/'])
 *         );
 *
 * A repository that also lints PHTML views appends `->name('*.php')->name('*.phtml')`
 * to its own finder.
 *
 * @param string $header The per-package file-header text (without comment markers).
 *
 * @return Config
 */
return static function (string $header): Config {
    return (new Config())
        ->setRiskyAllowed(true)
        ->setParallelConfig(new ParallelConfig(4, 8))
        ->setRules([
            '@PER-CS2x0'                      => true,
            '@Symfony'                        => true,

            // Additional custom rules
            'declare_strict_types'            => true,
            'concat_space'                    => [
                'spacing' => 'one',
            ],
            'header_comment'                  => [
                'header'       => $header,
                'comment_type' => 'PHPDoc',
                'location'     => 'after_open',
                'separate'     => 'both',
            ],
            'method_argument_space'           => [
                'on_multiline' => 'ensure_fully_multiline',
            ],
            'phpdoc_to_comment'               => false,
            'phpdoc_no_alias_tag'             => false,
            'phpdoc_annotation_without_dot'   => false,
            'no_superfluous_phpdoc_tags'      => false,
            'phpdoc_separation'               => [
                'groups' => [
                    [
                        'author',
                        'license',
                        'link',
                    ],
                ],
            ],
            'no_alias_functions'              => true,
            'whitespace_after_comma_in_array' => [
                'ensure_single_space' => true,
            ],
            'single_line_throw'               => false,
            'self_accessor'                   => false,
            'global_namespace_import'         => [
                'import_classes'   => true,
                'import_constants' => true,
                'import_functions' => true,
            ],
            'function_declaration'            => [
                'closure_function_spacing' => 'one',
                'closure_fn_spacing'       => 'one',
            ],
            'binary_operator_spaces'          => [
                'operators' => [
                    '='   => 'align_single_space_minimal',
                    '=>'  => 'align_single_space_minimal',
                    '+='  => 'align_single_space_minimal',
                    '-='  => 'align_single_space_minimal',
                    '.='  => 'align_single_space_minimal',
                    '??=' => 'align_single_space_minimal',
                ],
            ],
            'yoda_style'                      => [
                'equal'                => false,
                'identical'            => false,
                'less_and_greater'     => false,
                'always_move_variable' => false,
            ],
            'blank_line_before_statement'     => [
                'statements' => [
                    'break',
                    'continue',
                    'for',
                    'foreach',
                    'if',
                    'return',
                    'switch',
                    'throw',
                    'try',
                    'while',
                ],
            ],
        ]);
};
