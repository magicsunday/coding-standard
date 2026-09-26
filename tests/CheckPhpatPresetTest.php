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

use function array_filter;
use function count;
use function explode;
use function sprintf;
use function str_contains;

/**
 * Proves the opt-in phpat preset phpstan/phpat.neon (GH-183): included next to
 * base.neon exactly as a consumer does, it loads phpat from an installed vendor
 * layout, a registered rule fires on a violating class and stays quiet on a
 * compliant one, and `phpat.show_rule_names` prefixes the finding with the rule
 * method's name. The contrasting run through base.neon alone, with the same rule
 * class registered, proves the preset is opt-in: without it, phpat never runs.
 *
 * Same shape as CheckCheckedExceptionsTest; see AbstractConsumerPhpstanGateTestCase
 * for why every test self-skips via setUp() until tests/consumer is installed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatPresetTest extends AbstractConsumerPhpstanGateTestCase
{
    /**
     * The fixture's violating class, as phpat names it in its finding.
     */
    private const string OPEN_LEAF = 'MagicSunday\CodingStandard\Fixture\Phpat\Leaf\OpenLeaf';

    /**
     * Memoized across every test in this class; see presetResult().
     */
    private static ?GateResult $presetResult = null;

    /**
     * Memoized across every test in this class; see baseResult().
     */
    private static ?GateResult $baseResult = null;

    /**
     * The preset must report the non-final leaf, prefixed with the rule
     * method's name — the prefix is what `phpat.show_rule_names: true` adds, so
     * matching on it proves the preset's parameter reached phpat, not only its
     * extension include.
     *
     * @return void
     */
    #[Test]
    public function presetReportsTheNonFinalLeafUnderItsRuleName(): void
    {
        $result = self::presetResult();

        self::assertResultIsNotDegraded($result);
        self::assertOutputContains(
            $result,
            'leafClassesAreFinal: ' . self::OPEN_LEAF . ' should be final',
            'the phpat preset did not report the non-final OpenLeaf under its rule name.',
        );
    }

    /**
     * The compliant sibling must NOT be reported — proves the rule
     * discriminates rather than flagging every class in its subject.
     *
     * @return void
     */
    #[Test]
    public function presetDoesNotReportTheFinalLeaf(): void
    {
        $result = self::presetResult();

        self::assertResultIsNotDegraded($result);
        self::assertOutputDoesNotContain(
            $result,
            'Leaf\FinalLeaf',
            'the final FinalLeaf was reported anyway.',
        );
    }

    /**
     * The OpenLeaf finding must be the run's only one. A second finding in the
     * fixture — a deprecated phpat builder call, a base.neon finding on the rule
     * class itself — would otherwise pass unnoticed next to the expected one.
     *
     * @return void
     */
    #[Test]
    public function presetReportsNothingButTheNonFinalLeaf(): void
    {
        $result = self::presetResult();

        self::assertResultIsNotDegraded($result);

        $findings = array_filter(
            explode("\n", $result->output),
            static fn (string $line): bool => str_contains($line, '/phpat/'),
        );

        if (count($findings) !== 1) {
            self::fail(self::diagnosticMessage(
                sprintf('expected exactly one finding under phpat/, got %d.', count($findings)),
                $result->output,
            ));
        }
    }

    /**
     * base.neon alone must NOT load phpat: the same fixture with the same rule
     * class registered, analysed without the preset, reports no phpat finding.
     * Proves the preset is opt-in rather than phpat having crept back into the
     * base (GH-45 removed it from there). Matched without the rule-name prefix,
     * which only the preset's `show_rule_names` adds.
     *
     * @return void
     */
    #[Test]
    public function baseAloneDoesNotLoadPhpat(): void
    {
        $result = self::baseResult();

        self::assertResultIsNotDegraded($result);
        self::assertOutputDoesNotContain(
            $result,
            self::OPEN_LEAF . ' should be final',
            'base.neon alone reported a phpat finding — phpat is no longer opt-in.',
        );
    }

    /**
     * @return GateResult The base.neon + phpat.neon run against the phpat fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function presetResult(): GateResult
    {
        return self::$presetResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-phpat.neon',
            'phpat',
        );
    }

    /**
     * @return GateResult The base.neon-only run (rule class registered, no preset)
     *                    against the phpat fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function baseResult(): GateResult
    {
        return self::$baseResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-phpat-base-only.neon',
            'phpat',
        );
    }
}
