<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CaseFolding;

use function lcfirst;
use function strtolower;
use function strtoupper;
use function ucfirst;
use function ucwords;

/**
 * A deliberately non-conformant class: every method below calls one of the five
 * byte-wise case-folding functions banned by `phpstan/disallowed-calls.neon`.
 * The self-test asserts that each one is actually reported, which is what proves
 * the shipped config both loads and fires — a config that loads but matches
 * nothing would pass a plain "PHPStan is green" check while enforcing nothing.
 *
 * It lives outside `src/` on purpose: the php-cs-fixer and Rector consumer
 * smoke runs are scoped to `src/`, so this fixture cannot disturb them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class CaseFoldingFixture
{
    /**
     * Folds a tag to upper case — leaves any multi-byte character unfolded.
     *
     * @param string $tag The raw tag
     *
     * @return string
     */
    public function upper(string $tag): string
    {
        return strtoupper($tag);
    }

    /**
     * Folds a tag to lower case — leaves any multi-byte character unfolded.
     *
     * @param string $tag The raw tag
     *
     * @return string
     */
    public function lower(string $tag): string
    {
        return strtolower($tag);
    }

    /**
     * Uppercases the first byte — a silent no-op on a multi-byte first character.
     *
     * @param string $name The raw name
     *
     * @return string
     */
    public function firstUpper(string $name): string
    {
        return ucfirst($name);
    }

    /**
     * Lowercases the first byte — the exact mirror of ucfirst().
     *
     * @param string $name The raw name
     *
     * @return string
     */
    public function firstLower(string $name): string
    {
        return lcfirst($name);
    }

    /**
     * Uppercases each word's first byte — splits on ASCII whitespace only.
     *
     * @param string $name The raw name
     *
     * @return string
     */
    public function wordsUpper(string $name): string
    {
        return ucwords($name);
    }
}
