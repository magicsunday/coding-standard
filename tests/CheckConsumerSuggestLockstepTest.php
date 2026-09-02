<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function dirname;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function str_repeat;

/**
 * Fixture-driven cases for tests/check-consumer-suggest-lockstep.php (#57).
 *
 * Run against this repository alone, the gate only ever takes the happy
 * path — every package tests/consumer/composer.json's `require-dev` hand-copies
 * from composer.json's `suggest` block agrees, so a green CI is
 * indistinguishable from a gate that cannot fail. These cases put it in
 * each failing state on purpose. This gate needs no installed consumer
 * fixture, so GateTestCase's accept/reject/usage-error/report-is-inert
 * exit-code contract applies directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerSuggestLockstepTest extends GateTestCase
{
    /**
     * The largest file the gate under test reads, in bytes. Mirrors
     * MAX_LOCKSTEP_BYTES in tests/check-consumer-suggest-lockstep.php.
     */
    private const int MAX_LOCKSTEP_BYTES = 1048576;

    /**
     * The canon: every package the fixture hand-copies from `suggest`
     * agrees with the constraint documented there.
     */
    #[Test]
    public function acceptsWhenEveryOverlappingPackageAgrees(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'Required for the opt-in tier: ^4.0'],
            ['foo/bar' => '^4.0'],
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The release that bumps `suggest` without bumping the fixture — the
     * case the gate exists for.
     */
    #[Test]
    public function rejectsAMismatchedConstraint(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'Required for the opt-in tier: ^5.0'],
            ['foo/bar' => '^4.0'],
        );

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  tests/consumer/composer.json pins foo/bar to ^4.0, composer.json suggests ^5.0');
    }

    /**
     * Deleting every hand-copy must not make the gate pass vacuously.
     */
    #[Test]
    public function rejectsWhenRequireDevHandCopiesNothingSuggestDocuments(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'Required for the opt-in tier: ^4.0'],
            ['symfony/process' => '^7.2'],
        );

        $this->assertGateRejects(self::gate(), $dir, 'has nothing to compare');
    }

    /**
     * A suggest entry with no trailing constraint at all — the
     * roave/backward-compatibility-check shape, where the prose ends on
     * a parenthetical rather than a version. Must not be silently
     * truncated to whatever text follows the last colon.
     */
    #[Test]
    public function rejectsWhenSuggestDescriptionHasNoTrailingConstraint(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'Install it separately (see the README)'],
            ['foo/bar' => '^4.0'],
        );

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED  composer.json\'s suggest entry for foo/bar');
    }

    /**
     * The extraction takes the LAST colon, not the first — prose with an
     * earlier colon (a parenthetical, a filename) must not truncate the
     * constraint away.
     */
    #[Test]
    public function usesTheLastColonWhenProseContainsMultipleColons(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'See notes.neon: this tier: ^4.0'],
            ['foo/bar' => '^4.0'],
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A require-dev value that is not a string (a malformed manifest) must
     * be reported as unrecognised rather than compared byte-for-byte
     * against a non-string.
     */
    #[Test]
    public function rejectsWhenConsumerConstraintIsNotAString(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => ['foo/bar' => 'Required: ^4.0']]));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => 4]]));

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED  tests/consumer/composer.json pins foo/bar to a non-string constraint');
    }

    /**
     * The counterpart on the suggest side: a description that is not a
     * string at all.
     */
    #[Test]
    public function rejectsWhenSuggestDescriptionIsNotAString(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => ['foo/bar' => 40]]));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateRejects(self::gate(), $dir, 'UNRECOGNISED  composer.json suggests foo/bar with a non-string description');
    }

    /**
     * The set of packages checked is the OVERLAP, derived dynamically —
     * not a hand-kept list of names. An unrelated package present on both
     * sides must be checked exactly like a real suggested package is.
     */
    #[Test]
    public function checksAnyOverlappingPackageNotJustTheKnownThree(): void
    {
        $dir = $this->writeCase(
            ['acme/unrelated-package' => 'Some future opt-in extra: ^2.0'],
            ['acme/unrelated-package' => '^1.0'],
        );

        $this->assertGateRejects(self::gate(), $dir, 'MISMATCH  tests/consumer/composer.json pins acme/unrelated-package to ^1.0, composer.json suggests ^2.0');
    }

    /**
     * A missing root composer.json reports as unreadable, not as an empty
     * suggest block.
     */
    #[Test]
    public function reportsUsageErrorWhenRootComposerJsonIsMissing(): void
    {
        $dir = $this->fixture()->path();

        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
    }

    /**
     * The counterpart on the consumer side.
     */
    #[Test]
    public function reportsUsageErrorWhenConsumerComposerJsonIsMissing(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => ['foo/bar' => 'Required: ^4.0']]));

        $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
    }

    /**
     * A root composer.json that cannot be parsed at all is a usage error,
     * not an empty suggest block.
     */
    #[Test]
    public function reportsUsageErrorWhenRootComposerJsonIsNotValidJson(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', "{\n    \"suggest\":\n");
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateUsageError(self::gate(), $dir, 'composer.json is not valid JSON');
    }

    /**
     * A `suggest` key that is present but not a JSON object (here: a
     * plain string) must not be silently coerced into "nothing to check".
     */
    #[Test]
    public function reportsUsageErrorWhenSuggestIsNotAnObject(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => 'not an object']));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateUsageError(self::gate(), $dir, "`suggest` is not a JSON object");
    }

    /**
     * The counterpart on the consumer side.
     */
    #[Test]
    public function reportsUsageErrorWhenRequireDevIsNotAnObject(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => ['foo/bar' => 'Required: ^4.0']]));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => 'not an object']));

        $this->assertGateUsageError(self::gate(), $dir, "`require-dev` is not a JSON object");
    }

    /**
     * The size cap on the root composer.json read, driven directly:
     * content past the bound is reported as too large before either byte
     * is ever interpreted.
     */
    #[Test]
    public function reportsUsageErrorWhenRootComposerJsonExceedsTheSizeCap(): void
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', str_repeat('x', self::MAX_LOCKSTEP_BYTES + 1));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateUsageError(
            self::gate(),
            $dir,
            'composer.json is larger than the ' . self::MAX_LOCKSTEP_BYTES . ' bytes',
        );
    }

    /**
     * The at-cap counterpart: content that must be read in FULL and
     * compared, so a bound even one byte too small starts rejecting it
     * instead.
     */
    #[Test]
    public function acceptsARootComposerJsonExactlyAtTheSizeCap(): void
    {
        $dir  = $this->fixture()->path();
        $body = (string) json_encode(['suggest' => ['foo/bar' => 'Required: ^4.0']]);

        file_put_contents($dir . '/composer.json', self::padJsonToCap(self::MAX_LOCKSTEP_BYTES, $body));
        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => ['foo/bar' => '^4.0']]));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The package NAME is a JSON key straight out of a pull-request branch
     * and is echoed unconditionally in every report line (OK, MISMATCH,
     * both UNRECOGNISED causes) — unlike the extracted suggest constraint,
     * which isComposerConstraintShaped() already confines to digits, dots,
     * `^`/`~`/`|`/whitespace before it is ever echoed, so it can never
     * itself carry a forged workflow command. The package name carries no
     * such shape constraint.
     */
    #[Test]
    public function reportIsInertWhenAPackageNameAttemptsToForgeAWorkflowCommand(): void
    {
        $package = "foo/bar\n::Error::forged from a pull request";
        $dir     = $this->writeCase(
            [$package => 'Required: ^5.0'],
            [$package => '^4.0'],
        );

        $this->assertGateReportIsInert(self::gate(), $dir, 'foo/bar?::Error::forged from a pull request');
    }

    /**
     * The counterpart trust boundary: a forged consumer constraint. Unlike
     * the suggest side's constraint, this one is compared verbatim with no
     * shape check before being echoed.
     */
    #[Test]
    public function reportIsInertWhenAConsumerConstraintAttemptsToForgeAWorkflowCommand(): void
    {
        $dir = $this->writeCase(
            ['foo/bar' => 'Required: ^5.0'],
            ['foo/bar' => "^4.0\n::Error::forged from a pull request"],
        );

        $this->assertGateReportIsInert(self::gate(), $dir, '^4.0?::Error::forged from a pull request');
    }

    /**
     * Writes a composer.json/tests/consumer/composer.json pair into this
     * test's fixture directory.
     *
     * @param array<string, string> $suggest    The `suggest` block written to composer.json.
     * @param array<string, string> $requireDev The `require-dev` block written to tests/consumer/composer.json.
     *
     * @return string The fixture directory's path.
     */
    private function writeCase(array $suggest, array $requireDev): string
    {
        $dir = $this->fixture()->path();

        file_put_contents($dir . '/composer.json', (string) json_encode(['suggest' => $suggest]));

        mkdir($dir . '/tests/consumer', 0o700, true);
        file_put_contents($dir . '/tests/consumer/composer.json', (string) json_encode(['require-dev' => $requireDev]));

        return $dir;
    }

    /**
     * @return string Absolute path to the repository root.
     */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return list<string> The interpreter and gate script under test.
     */
    private static function gate(): array
    {
        return ['php', self::root() . '/tests/check-consumer-suggest-lockstep.php'];
    }
}
