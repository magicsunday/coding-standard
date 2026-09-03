<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CheckedExceptions;

use MagicSunday\CodingStandard\Fixture\CheckedExceptions\Exception\FixtureException;

/**
 * Deliberately NOT final, and overriddenStaleThrows() deliberately overrides
 * AbstractCheckedExceptionsOverrideFixture — the one shape that exercises
 * checkTooWideThrowTypesInProtectedAndPublicMethods's genuine effect: a stale
 * throws annotation on a non-final public method's override is checkable,
 * unlike a non-final class's own first-declared method (see README's
 * "Checked exceptions" section on that gap; staleThrows() in the sibling
 * fixture proves the unconditional case via a final class instead).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
class CheckedExceptionsOverrideFixture extends AbstractCheckedExceptionsOverrideFixture
{
    /**
     * @throws FixtureException Never thrown — deliberately stale.
     *
     * @return void
     */
    public function overriddenStaleThrows(): void
    {
        // Deliberately empty — proves checkTooWideThrowTypesInProtectedAndPublicMethods
        // catches a stale throws annotation on a non-final method's override.
    }
}
