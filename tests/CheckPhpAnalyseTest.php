<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateProcess;
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function is_executable;

/**
 * Proves the ROOT phpstan.neon — this package's own self-analysis config,
 * run by `composer ci:test:php:analyse` — actually wires in
 * phpstan/disallowed-function-calls.neon, rather than merely appearing to via
 * its `includes:` line. Migrated off tests/check-php-analyse-cases.sh (#71).
 *
 * CheckDisallowedCallsTest already proves the ban LIST's own content
 * exhaustively, but only through tests/consumer's installed, vendor-nested
 * copy of this package — it never runs phpstan.neon itself. A broken or
 * missing include there would leave `composer ci:test:php:analyse` silently
 * green: bin/ and tests/ call none of the banned functions, so nothing else
 * would notice. One representative ban is enough here — the list's content
 * is not this class's concern, only phpstan.neon's own resolution of it.
 *
 * The probe lives in this test's own throwaway fixture directory, not a
 * tracked file: phpstan.neon's `paths` recurse through all of tests/ except
 * tests/consumer, so a permanent violating fixture there would make the real
 * `composer ci:test:php:analyse` fail on every run. PHPStan accepts an
 * explicit file path outside its configured `paths` and still applies the
 * loaded config's rules to it.
 *
 * Unlike CheckDisallowedCallsTest, this needs no installed consumer fixture —
 * only this repository's own root install, which the plain
 * `composer ci:test:phpunit` step already has — so it runs there, with no
 * setUp() self-skip. A missing root phpstan binary is a failure, not a skip:
 * the plain step runs after `composer install`, where its absence is a
 * broken install rather than an expected state. The bash original's
 * bookkeeping self-test (harness_assert_no_stray_increments) is not ported,
 * the same way the earlier migrations reasoned: GateTestCase's own meta-suite
 * already proves its decisions generically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpAnalyseTest extends GateTestCase
{
    /**
     * The message PHPStan reports for the one representative ban the
     * positive case below calls.
     */
    private const string BANNED_CALL_MESSAGE = 'Calling strtolower() is forbidden';

    /**
     * CONTROL: a clean probe must report nothing. If it did, a report on the
     * positive probe below could equally have come from the level-6 rule
     * packs alone, and would prove nothing about the ban list's include.
     */
    #[Test]
    public function aCleanProbeIsCleanAgainstTheRootConfig(): void
    {
        $result = $this->analyse(<<<'PHP'
            <?php

            declare(strict_types=1);

            function caseFoldControl(string $s): string
            {
                return mb_strtolower($s, 'UTF-8');
            }

            PHP);

        self::assertSame(0, $result->exitCode, self::diagnosticMessage('A clean probe reports against phpstan.neon.', $result->output));
    }

    /**
     * POSITIVE: a banned call must be reported through phpstan.neon, by the
     * ban's own message — not merely as some finding.
     */
    #[Test]
    public function aBannedCallIsReportedThroughTheRootConfig(): void
    {
        $result = $this->analyse(<<<'PHP'
            <?php

            declare(strict_types=1);

            function caseFoldPositive(string $s): string
            {
                return strtolower($s);
            }

            PHP);

        if ($result->exitCode === 0) {
            self::fail(self::diagnosticMessage(
                'phpstan.neon does not report strtolower() — its own include of phpstan/disallowed-function-calls.neon has broken.',
                $result->output,
            ));
        }

        self::assertOutputContains(
            $result,
            self::BANNED_CALL_MESSAGE,
            'phpstan.neon reported something on the positive probe, but not the expected ban.',
        );
    }

    /**
     * Writes $source as a probe file into this test's own fixture directory
     * and analyses it with this repository's own phpstan binary against the
     * root phpstan.neon.
     *
     * @param string $source The probe's PHP source.
     *
     * @return GateResult The analysis run's combined output and exit code.
     */
    private function analyse(string $source): GateResult
    {
        $phpstan = self::root() . '/.build/bin/phpstan';

        if (!is_executable($phpstan)) {
            self::fail("The root phpstan binary is missing or not executable ({$phpstan}) — run `composer install` first.");
        }

        $probe = $this->fixture()->path() . '/probe.php';
        file_put_contents($probe, $source);

        return (new GateProcess())->runRaw(
            [$phpstan, 'analyse', '--configuration', self::root() . '/phpstan.neon', '--error-format=raw', '--no-progress', '--memory-limit=-1', $probe],
            null,
            [],
            300.0,
        );
    }
}
