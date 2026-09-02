<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CheckedExceptions\Exception;

use LogicException;

/**
 * A first-party exception namespaced under MagicSunday\ (so it matches
 * strict.neon's checkedExceptionRegexes) that ALSO extends LogicException
 * (so it matches uncheckedExceptionClasses by inheritance) — the one shape
 * that actually discriminates whether uncheckedExceptionClasses's
 * inheritance matching is in effect. See CheckedExceptionsFixture's
 * uncheckedByInheritanceOnly() for why this differs from a plain SPL
 * InvalidArgumentException.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
final class FixtureLogicException extends LogicException
{
}
