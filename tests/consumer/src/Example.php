<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture;

use function array_map;
use function trim;

/**
 * A deliberately house-style-conformant class. The CI consumer smoke runs the
 * shared php-cs-fixer, PHPStan and Rector configs against it, so any config that
 * fails to load — or that would rewrite conformant code — turns the build red.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class Example
{
    /**
     * Constructor.
     *
     * @param string $separator The separator placed between the trimmed parts
     */
    public function __construct(
        private string $separator = ', ',
    ) {
    }

    /**
     * Trims every entry and returns them as a list.
     *
     * @param list<string> $parts The raw parts
     *
     * @return list<string>
     */
    public function normalize(array $parts): array
    {
        return array_map(trim(...), $parts);
    }

    /**
     * Returns the configured separator.
     *
     * @return string
     */
    public function separator(): string
    {
        return $this->separator;
    }
}
