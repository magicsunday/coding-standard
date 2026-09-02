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
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function count;
use function file_get_contents;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function substr_count;

/**
 * Proves that phpstan/disallowed-function-calls.neon's case-folding bans both
 * load from an installed vendor layout and actually fire, and that the strict
 * tier delivers them automatically as README and AGENTS document. The banned
 * function list is derived from the shipped config rather than hand-kept, so
 * a function added there is exercised without this test being touched.
 *
 * Migrated off tests/check-disallowed-calls-cases.sh. Requires tests/consumer
 * to be installed (`composer install` inside that directory); every test
 * self-skips via AbstractConsumerPhpstanGateTestCase::setUp() until it is, rather
 * than failing, because this class is also reached by the plain
 * `composer ci:test:phpunit` step that runs BEFORE the consumer fixture is
 * installed in CI — see .github/workflows/ci.yml, where
 * `composer ci:test:disallowed-calls` (this class, filtered) is the step
 * that actually exercises it, positioned after the consumer install step.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckDisallowedCallsTest extends AbstractConsumerPhpstanGateTestCase
{
    /**
     * @var list<non-empty-string>|null Memoized across every test in this class; see bannedFunctions().
     */
    private static ?array $bannedFunctions = null;

    /**
     * Memoized across every test in this class; see controlResult().
     */
    private static ?GateResult $controlResult = null;

    /**
     * Memoized across every test in this class; see positiveResult().
     */
    private static ?GateResult $positiveResult = null;

    /**
     * Memoized across every test in this class; see wiringResult().
     */
    private static ?GateResult $wiringResult = null;

    /**
     * The control run: the same fixture under base.neon alone must be clean,
     * or the positive run below would prove nothing.
     *
     * @return void
     */
    #[Test]
    public function controlRunWithoutTheCaseFoldingConfigIsClean(): void
    {
        $result = self::controlResult();

        self::assertResultIsNotDegraded($result);
        self::assertSame(
            0,
            $result->exitCode,
            "base.neon alone reports on the case-folding fixture, so a report in the positive run would not prove disallowed-calls.neon fired.\n{$result->output}",
        );
    }

    /**
     * The positive run must not pass silently — the case-folding config has to fire.
     *
     * @return void
     */
    #[Test]
    public function positiveRunDoesNotPassSilently(): void
    {
        $result = self::positiveResult();

        self::assertResultIsNotDegraded($result);
        self::assertNotSame(
            0,
            $result->exitCode,
            "the case-folding config reported nothing — it loads but does not fire.\n{$result->output}",
        );
    }

    /**
     * Every banned function must be reported by name, so a future ban that
     * silently stops firing is caught rather than hidden behind a passing count.
     *
     * @return void
     */
    #[Test]
    public function positiveRunReportsEveryBannedFunctionByName(): void
    {
        self::assertResultReportsEveryBannedFunctionByName(
            self::positiveResult(),
            static fn (string $function): string => "{$function}() is not reported by the case-folding config.",
        );
    }

    /**
     * The report count must equal the ban count exactly — a stray extra
     * report, or a fixture that stopped covering one ban while another
     * fires twice, cannot hide behind the per-function check above.
     *
     * @return void
     */
    #[Test]
    public function positiveRunReportsExactlyOneViolationPerBannedFunction(): void
    {
        $result   = self::positiveResult();
        $banned   = self::bannedFunctions();
        $reported = substr_count($result->output, 'is forbidden');

        self::assertSame(
            count($banned),
            $reported,
            sprintf(
                "%d report(s) for %d ban(s) — the fixture and the config have drifted.\n%s",
                $reported,
                count($banned),
                $result->output,
            ),
        );
    }

    /**
     * The strict tier must not pass silently either — README and AGENTS
     * document that it delivers the case-folding bans automatically, and
     * running the tier is the only way to prove that rather than asserting
     * on an include line that could stop resolving at all.
     *
     * @return void
     */
    #[Test]
    public function wiringRunThroughTheStrictTierDoesNotPassSilently(): void
    {
        $result = self::wiringResult();

        self::assertResultIsNotDegraded($result);
        self::assertNotSame(
            0,
            $result->exitCode,
            "the strict tier reported nothing on the case-folding fixture, so the documented automatic inclusion does not hold.\n{$result->output}",
        );
    }

    /**
     * Every banned function must reach the report through the strict tier
     * too. No cardinality check here, unlike the positive run above: the
     * shipmonk/symplify packs the strict tier also loads may legitimately
     * add findings of their own, and pinning their number would turn every
     * upstream rule addition into a false failure in this file.
     *
     * @return void
     */
    #[Test]
    public function wiringRunReportsEveryBannedFunctionByName(): void
    {
        self::assertResultReportsEveryBannedFunctionByName(
            self::wiringResult(),
            static fn (string $function): string => "wiring: {$function}() is not reported through strict.neon.",
        );
    }

    /**
     * Parses phpstan/disallowed-function-calls.neon for every declared
     * `function: '...()'` ban, then cross-checks the parsed count against a
     * plain occurrence count of the same key — so a spelling the pattern
     * cannot see (a class-method ban, an uppercase letter) fails loudly
     * instead of shipping an unexercised ban. Comment lines are stripped
     * first: a sibling file, phpstan/disallowed-calls.neon, documents an
     * `allowIn` override inside a commented example containing `function:
     * '...'`, which would otherwise parse as an extra ban. This file carries
     * no such example today, but the filter costs nothing to keep and guards
     * against one being copied in here later.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the occurrence count and the parsed count disagree, or nothing parsed.
     */
    private static function bannedFunctions(): array
    {
        if (self::$bannedFunctions !== null) {
            return self::$bannedFunctions;
        }

        $config          = (string) file_get_contents(self::root() . '/phpstan/disallowed-function-calls.neon');
        $withoutComments = (string) preg_replace('/^[ \t]*#.*$/m', '', $config);
        $declaredBans    = substr_count($withoutComments, "function: '");

        preg_match_all("/function: '([a-z0-9_]+)\\(\\)'/", $withoutComments, $matches);

        /** @var list<non-empty-string> $banned */
        $banned = $matches[1];

        if ($declaredBans !== count($banned)) {
            throw new RuntimeException(sprintf(
                'the config declares %d ban(s) but the extractor parsed %d'
                . ' — widen the extractor rather than leaving a ban unexercised.',
                $declaredBans,
                count($banned),
            ));
        }

        if ($banned === []) {
            throw new RuntimeException('no banned functions parsed out of the shipped config — the extraction broke.');
        }

        return self::$bannedFunctions = $banned;
    }

    /**
     * @return GateResult The base.neon-alone run against the case-folding fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function controlResult(): GateResult
    {
        return self::$controlResult ??= self::runPhpstan(
            self::consumer() . '/phpstan.neon',
            'case-folding',
        );
    }

    /**
     * @return GateResult The base.neon + disallowed-calls.neon run against the case-folding fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function positiveResult(): GateResult
    {
        return self::$positiveResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-disallowed-calls.neon',
            'case-folding',
        );
    }

    /**
     * @return GateResult The strict.neon run against the case-folding fixture, memoized.
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    private static function wiringResult(): GateResult
    {
        return self::$wiringResult ??= self::runPhpstan(
            self::consumer() . '/phpstan-strict.neon',
            'case-folding',
        );
    }

    /**
     * Shared "every banned function reported by name" loop, used by both the
     * positive and the wiring run — the two differ only in their failure message.
     *
     * @param GateResult               $result         The captured phpstan run to check.
     * @param callable(string): string $failureMessage Builds the failure message for one banned function name.
     *
     * @return void
     */
    private static function assertResultReportsEveryBannedFunctionByName(GateResult $result, callable $failureMessage): void
    {
        foreach (self::bannedFunctions() as $function) {
            self::assertStringContainsString(
                "Calling {$function}() is forbidden",
                $result->output,
                $failureMessage($function),
            );
        }
    }
}
