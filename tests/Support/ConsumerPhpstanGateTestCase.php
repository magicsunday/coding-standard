<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function dirname;
use function is_executable;
use function sprintf;

/**
 * Shared scaffolding for a self-test that proves a shared PHPStan config
 * fires by running the real, installed `tests/consumer` PHPStan binary
 * against a fixture — the shape `CheckDisallowedCallsTest` and
 * `CheckCheckedExceptionsTest` both need and previously each reimplemented
 * (root()/consumer()/phpstanBinary()/runPhpstan()/the self-skip setUp() /
 * assertResultIsNotDegraded()). Not `GateTestCase`: that class's
 * accept/reject/usage-error exit-code contract is for this package's OWN
 * `bin/check-*.php` gate scripts, not a third-party binary's PHPStan
 * findings, which is why both consumers already extended `TestCase`
 * directly instead.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class ConsumerPhpstanGateTestCase extends TestCase
{
    /**
     * Skips every test in this class until tests/consumer is installed,
     * instead of failing it — this class is also reached by the plain
     * `composer ci:test:phpunit` step that runs before the consumer
     * fixture is installed in CI.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!is_executable(self::phpstanBinary())) {
            self::markTestSkipped(sprintf(
                '%s is missing — run `composer install` in tests/consumer first.',
                self::phpstanBinary(),
            ));
        }
    }

    /**
     * @return string Absolute path to the repository root.
     */
    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return string Absolute path to tests/consumer, an installed consumer layout.
     */
    protected static function consumer(): string
    {
        return self::root() . '/tests/consumer';
    }

    /**
     * @return string Absolute path to the phpstan binary tests/consumer installs.
     */
    protected static function phpstanBinary(): string
    {
        return self::consumer() . '/.build/bin/phpstan';
    }

    /**
     * Runs `phpstan analyse --configuration <config> <fixturePath>` from
     * tests/consumer via GateProcess (combined stdout+stderr, matching the
     * bash original's `2>&1`). `$fixturePath` is passed unconditionally, not
     * conditional on which config is passed: without it, `$config` alone
     * would fall back to that neon file's OWN `paths:` entry — which for
     * phpstan.neon (the usual control config) is `src`, a different fixture
     * entirely, silently scoping a control run away from the fixture a
     * positive/wiring run actually analyses. A config that already declares
     * its own matching `paths:` tolerates the explicit argument as
     * redundant but harmless.
     *
     * @param string $config      Absolute path to the configuration file to analyse with.
     * @param string $fixturePath The sole positional path argument to pass to `phpstan analyse`.
     *
     * @return GateResult
     *
     * @throws ProcessStartFailedException If the phpstan process could not be started.
     * @throws ProcessTimedOutException    If the phpstan process exceeds its timeout.
     * @throws ProcessSignaledException    If the phpstan process was killed by a signal.
     */
    protected static function runPhpstan(string $config, string $fixturePath): GateResult
    {
        $command = [
            self::phpstanBinary(),
            'analyse',
            '--configuration',
            $config,
            '--error-format=raw',
            '--no-progress',
            '--memory-limit=-1',
        ];

        return (new GateProcess())->run(
            $command,
            $fixturePath,
            self::consumer(),
        );
    }

    /**
     * @param GateResult $result The captured phpstan run to check.
     *
     * @return void
     */
    protected static function assertResultIsNotDegraded(GateResult $result): void
    {
        self::assertFalse(
            $result->isDegraded(),
            'phpstan emitted a diagnostic of its own.',
        );
    }
}
