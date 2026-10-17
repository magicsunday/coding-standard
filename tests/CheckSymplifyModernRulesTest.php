<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractConsumerPhpstanGateTestCase;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Proves that the strict tier switches on the `modern` rule group of
 * symplify/phpstan-rules (GH-243): from 14.19 on, `symplify.forbiddenTrait` and
 * `symplify.noReference` live in that group's own rule file, which the strict tier
 * has to include and enable itself. A tier that forgets it loads cleanly and
 * enforces neither rule, so the only proof is a run that must report both.
 *
 * Same shape as CheckPhpatPresetTest; see AbstractConsumerPhpstanGateTestCase for
 * why every test self-skips via setUp() until tests/consumer is installed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckSymplifyModernRulesTest extends AbstractConsumerPhpstanGateTestCase
{
    /**
     * Memoized across every test in this class; see strictResult().
     */
    private static ?GateResult $strictResult = null;

    /**
     * @return void
     */
    #[Test]
    public function strictTierReportsTheTraitUse(): void
    {
        $result = self::strictResult();

        self::assertResultIsNotDegraded($result);
        self::assertOutputContains(
            $result,
            'Trait is forbidden to use',
            'the strict tier did not report the trait use (symplify.forbiddenTrait).',
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function strictTierReportsTheReferenceParameter(): void
    {
        $result = self::strictResult();

        self::assertResultIsNotDegraded($result);
        self::assertOutputContains(
            $result,
            'Use explicit return value over magic &reference',
            'the strict tier did not report the by-reference parameter (symplify.noReference).',
        );
    }

    /**
     * @return GateResult The strict-tier run against the symplify-modern fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function strictResult(): GateResult
    {
        return self::$strictResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-symplify-modern.neon',
            'symplify-modern',
        );
    }
}
