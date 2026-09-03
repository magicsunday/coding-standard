<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CheckedExceptions;

/**
 * The base declaration CheckedExceptionsOverrideFixture overrides, so that
 * override is what checkTooWideThrowTypesInProtectedAndPublicMethods needs to
 * find checkable — see that fixture's own docblock.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class AbstractCheckedExceptionsOverrideFixture
{
    /**
     * The declaration CheckedExceptionsOverrideFixture overrides with a
     * deliberately stale throws annotation.
     *
     * @return void
     */
    abstract public function overriddenStaleThrows(): void;
}
