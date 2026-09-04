<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Fixture\CheckedExceptions\Exception;

use RuntimeException;

/**
 * A first-party exception namespaced under MagicSunday\, so it matches
 * base.neon's `checkedExceptionRegexes` and is treated as a checked
 * exception by CheckCheckedExceptionsTest's fixture. Lives in its own
 * `Exception` sub-namespace per the strict tier's own symplify/phpstan-rules
 * RequireExceptionNamespaceRule (observed 2026-09-02 against
 * symplify/phpstan-rules 14.x; re-derive: `grep -rn
 * RequireExceptionNamespaceRule .build/vendor/symplify/phpstan-rules/config`
 * from an installed consumer if this rule is ever renamed or removed).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/magicsunday/coding-standard/
 */
final class FixtureException extends RuntimeException
{
}
