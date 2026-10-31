<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractConsumerConfigTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function copy;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function preg_replace;
use function str_contains;
use function str_replace;

/**
 * Fixture-driven cases for bin/consumer-checks/check-phpunit-xml.php — the
 * REQUIRED phpunit.xml contract: the strict root-flag set, proven in both
 * directions against the gate's own $requiredRootFlags, and the uniform
 * `src`/`tests` layout. PHP gate only; bin/check-js-config.mjs has no
 * phpunit.xml counterpart. See AbstractConsumerConfigTestCase for the shared
 * scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigPhpunitXmlTest extends AbstractConsumerConfigTestCase
{
    /**
     * The strict attributes the gate requires on phpunit.xml's root element,
     * held here INDEPENDENTLY of the gate's own list — generating the cases
     * below from requiredRootFlagsFromGate() instead would mean deleting a
     * flag from the gate silently drops its case too. Two lists that must
     * agree (proven via requiredRootFlagsBijectionHoldsBothDirections()) is
     * the shape that discriminates a gate that stopped checking.
     *
     * @var list<non-empty-string>
     */
    private const array REQUIRED_ROOT_FLAGS = [
        'beStrictAboutOutputDuringTests',
        'failOnRisky',
        'failOnWarning',
        'failOnNotice',
        'failOnDeprecation',
        'failOnPhpunitDeprecation',
        'failOnPhpunitNotice',
    ];

    /**
     * The two coverage-metadata attributes the gate does NOT require. A
     * repository that keeps a hard coverage percentage may want them, because
     * accidental coverage would inflate the figure, so the gate accepts them
     * on or off. They are held here, apart from REQUIRED_ROOT_FLAGS, so the
     * bijection test below fails if one of them is made mandatory again.
     *
     * @var list<non-empty-string>
     */
    private const array OPTIONAL_COVERAGE_FLAGS = [
        'requireCoverageMetadata',
        'beStrictAboutCoverageMetadata',
    ];

    // -------------------------------------------------------------------
    // Gate-source extraction — this contract's lockstep table, read at
    // runtime from the check-*.php split that declares it through the
    // shared gateSource()/extractQuotedList() primitives (see
    // AbstractConsumerConfigTestCase's class docblock).
    // -------------------------------------------------------------------

    /**
     * @return list<non-empty-string> The gate's own $requiredRootFlags, read from source.
     */
    private static function requiredRootFlagsFromGate(): array
    {
        return self::extractQuotedList('requiredRootFlags', 'bin/consumer-checks/check-phpunit-xml.php');
    }

    // -------------------------------------------------------------------
    // phpunit.xml required root flags
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredRootFlagProvider(): array
    {
        return self::singleArgProviderRows(self::REQUIRED_ROOT_FLAGS);
    }

    /**
     * Phpunit.xml with the given flag set to false.
     */
    #[Test]
    #[DataProvider('requiredRootFlagProvider')]
    public function rejectsRequiredRootFlagSetFalse(string $flag): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace("{$flag}=\"true\"", "{$flag}=\"false\"", $xml));

        $this->assertGateRejects(self::phpGate(), $dir, $flag, "phpunit.xml with {$flag} set to false");
    }

    /**
     * Phpunit.xml with the given flag removed.
     */
    #[Test]
    #[DataProvider('requiredRootFlagProvider')]
    public function rejectsRequiredRootFlagRemoved(string $flag): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents(
            $dir . '/phpunit.xml',
            implode("\n", array_filter(explode("\n", $xml), static fn (string $line): bool => !str_contains($line, "{$flag}=\"true\""))),
        );

        $this->assertGateRejects(self::phpGate(), $dir, $flag, "phpunit.xml with {$flag} removed");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function optionalCoverageFlagProvider(): array
    {
        return self::singleArgProviderRows(self::OPTIONAL_COVERAGE_FLAGS);
    }

    /**
     * A phpunit.xml that does not set an optional coverage-metadata flag at
     * all is accepted, so a repository can drop the per-class bookkeeping.
     */
    #[Test]
    #[DataProvider('optionalCoverageFlagProvider')]
    public function acceptsPhpunitXmlWithoutTheOptionalCoverageFlag(string $flag): void
    {
        $dir  = $this->mkCase();
        $xml  = (string) file_get_contents($dir . '/phpunit.xml');
        $kept = array_filter(explode("\n", $xml), static fn (string $line): bool => !str_contains($line, "{$flag}=\"true\""));

        self::assertNotSame(count(explode("\n", $xml)), count($kept), "the canon phpunit.xml does not carry {$flag}, so this case removes nothing");

        file_put_contents($dir . '/phpunit.xml', implode("\n", $kept));

        $this->assertGateAccepts(self::phpGate(), $dir, "phpunit.xml without the optional {$flag}");
    }

    /**
     * A phpunit.xml that sets an optional coverage-metadata flag to "false"
     * is the PHPUnit default written out, and is accepted as well.
     */
    #[Test]
    #[DataProvider('optionalCoverageFlagProvider')]
    public function acceptsPhpunitXmlWithTheOptionalCoverageFlagSetFalse(string $flag): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');

        self::assertCanonSetsEveryFlag($xml, [$flag]);

        file_put_contents($dir . '/phpunit.xml', str_replace("{$flag}=\"true\"", "{$flag}=\"false\"", $xml));

        $this->assertGateAccepts(self::phpGate(), $dir, "phpunit.xml with the optional {$flag} set to false");
    }

    /**
     * A repository that keeps both optional flags set to "true" still passes.
     * The canon fixture carries them, so this proves the gate does not reject
     * the flags it does not require.
     */
    #[Test]
    public function acceptsPhpunitXmlThatKeepsTheOptionalCoverageFlags(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');

        self::assertCanonSetsEveryFlag($xml, self::OPTIONAL_COVERAGE_FLAGS);

        $this->assertGateAccepts(self::phpGate(), $dir, 'phpunit.xml that keeps both optional coverage flags');
    }

    /**
     * Both directions of the bijection: every flag this suite drives must
     * still be required by the gate, and every flag the gate requires must
     * still be driven by this suite — plus the canon fixture must actually
     * carry every one of them, or the mutation above is a no-op that passes
     * on an unmodified copy already failing for some other reason.
     *
     * @return void
     */
    #[Test]
    public function requiredRootFlagsBijectionHoldsBothDirections(): void
    {
        $gateFlags = self::requiredRootFlagsFromGate();

        self::assertSame([], array_diff(self::REQUIRED_ROOT_FLAGS, $gateFlags), 'the gate no longer requires a phpunit.xml attribute this suite proves');
        self::assertSame([], array_diff($gateFlags, self::REQUIRED_ROOT_FLAGS), 'the gate requires a phpunit.xml attribute this suite does not drive');

        self::assertCanonSetsEveryFlag((string) file_get_contents(self::canon() . '/phpunit.xml'));
    }

    /**
     * Compares the list of flags the canon does not set against an empty
     * list, so the message can only ever name flags from the constant, never
     * $canonXml: assertStringContainsString() would re-embed the whole
     * PR-editable haystack, see the regression test below.
     *
     * @param string       $canonXml The canon phpunit.xml's content.
     * @param list<string> $flags    The flags that must all be set to "true", the required set by default.
     *
     * @return void
     */
    private static function assertCanonSetsEveryFlag(string $canonXml, array $flags = self::REQUIRED_ROOT_FLAGS): void
    {
        $unset = array_values(array_filter(
            $flags,
            static fn (string $flag): bool => !str_contains($canonXml, "{$flag}=\"true\""),
        ));

        self::assertSame(
            [],
            $unset,
            'the canon phpunit.xml does not set these flags to "true", so its cases modify nothing: ' . implode(', ', $unset),
        );
    }

    /**
     * The canon phpunit.xml is PR-editable content, so a failed flag check
     * must not carry it into the failure message: a poisoned copy (a comment
     * planted at the start of a line) would otherwise forge a workflow
     * command in the run of whichever LATER PR first leaves the canon without a required flag.
     * PHPUnit's string-containment constraint re-embeds the whole haystack
     * into the exception message no matter what custom message accompanies it.
     */
    #[Test]
    public function aFailedCanonFlagCheckDoesNotEmbedTheCanonContentInItsMessage(): void
    {
        $thrown = self::assertThrows(
            static fn () => self::assertCanonSetsEveryFlag("<!--\n::error::forged\n##[error]forged\n-->"),
            AssertionFailedError::class,
            'assertCanonSetsEveryFlag() accepted XML that sets no required flag.',
        );

        if (str_contains($thrown->getMessage(), 'forged')) {
            self::fail('The failed canon flag check still embeds the canon content in its message.');
        }
    }

    /**
     * A required flag set to "false" is not set: the check matches the flag
     * together with its "true" value, not the bare attribute name, and reports
     * the flag it found wrong.
     */
    #[Test]
    public function aCanonThatSetsARequiredFlagToFalseIsRejectedByName(): void
    {
        $wrong = self::REQUIRED_ROOT_FLAGS[0];
        $xml   = implode(' ', array_map(
            static fn (string $flag): string => $flag . '="' . ($flag === $wrong ? 'false' : 'true') . '"',
            self::REQUIRED_ROOT_FLAGS,
        ));

        $thrown = self::assertThrows(
            static fn () => self::assertCanonSetsEveryFlag($xml),
            AssertionFailedError::class,
            'assertCanonSetsEveryFlag() accepted a canon that sets a required flag to false.',
        );

        if (!str_contains($thrown->getMessage(), $wrong)) {
            self::fail("The rejection did not name the flag {$wrong}.");
        }
    }

    /**
     * The flag list the helper is given decides what it checks. A canon that
     * sets every required flag but neither optional one is rejected when the
     * optional flags are asked for, and the rejection names them. Without
     * this, a helper that ignored its list would pass every optional-flag
     * guard above whatever the canon carries.
     */
    #[Test]
    public function aCanonCheckHonoursTheFlagListItIsGiven(): void
    {
        $xml = implode(' ', array_map(
            static fn (string $flag): string => $flag . '="true"',
            self::REQUIRED_ROOT_FLAGS,
        ));

        $thrown = self::assertThrows(
            static fn () => self::assertCanonSetsEveryFlag($xml, self::OPTIONAL_COVERAGE_FLAGS),
            AssertionFailedError::class,
            'assertCanonSetsEveryFlag() accepted a canon that sets none of the flags it was asked for.',
        );

        foreach (self::OPTIONAL_COVERAGE_FLAGS as $flag) {
            if (!str_contains($thrown->getMessage(), $flag)) {
                self::fail("The rejection did not name the flag {$flag}.");
            }
        }
    }

    /**
     * <source> restrictNotices disabled.
     */
    #[Test]
    public function rejectsSourceRestrictNoticesDisabled(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('restrictNotices="true"', 'restrictNotices="false"', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'restrictNotices', '<source> restrictNotices disabled');
    }

    /**
     * <source> restrictWarnings disabled.
     */
    #[Test]
    public function rejectsSourceRestrictWarningsDisabled(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('restrictWarnings="true"', 'restrictWarnings="false"', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'restrictWarnings', '<source> restrictWarnings disabled');
    }

    // -------------------------------------------------------------------
    // phpunit.xml layout checks
    // -------------------------------------------------------------------

    /**
     * <source><include> no longer covering src.
     */
    #[Test]
    public function rejectsPhpunitSourceIncludeNoLongerCoveringSrc(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('<directory>src</directory>', '<directory>lib</directory>', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'must cover the `src` directory', '<source><include> no longer covering src');
    }

    /**
     * Test suite not running tests/.
     */
    #[Test]
    public function rejectsPhpunitTestSuiteNotRunningTests(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('<directory>tests</directory>', '<directory>test</directory>', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'must run the `tests` directory', 'test suite not running tests/');
    }

    /**
     * Tests/Architecture present but not excluded.
     */
    #[Test]
    public function rejectsArchitectureDirectoryPresentButNotExcluded(): void
    {
        $dir = $this->mkCase();
        mkdir($dir . '/tests/Architecture', 0o700, true);

        $this->assertGateRejects(self::phpGate(), $dir, 'must be excluded', 'tests/Architecture present but not excluded');
    }

    /**
     * Tests/Architecture present and excluded.
     */
    #[Test]
    public function acceptsArchitectureDirectoryPresentAndExcluded(): void
    {
        $dir = $this->fixture()->path();
        mkdir($dir . '/tests/Architecture', 0o700, true);
        $xml = (string) file_get_contents(self::canon() . '/phpunit.xml');
        file_put_contents(
            $dir . '/phpunit.xml',
            str_replace('<directory>tests</directory>', "<directory>tests</directory>\n            <exclude>tests/Architecture</exclude>", $xml),
        );

        $this->assertGateAccepts(self::phpGate(), $dir, 'tests/Architecture present and excluded');
    }

    /**
     * Phpunit.xml missing.
     */
    #[Test]
    public function rejectsPhpunitMissing(): void
    {
        $this->assertGateRejects(self::phpGate(), $this->fixture()->path(), 'missing', 'phpunit.xml missing');
    }

    /**
     * Phpunit.xml not well-formed.
     */
    #[Test]
    public function rejectsPhpunitNotWellFormed(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents($dir . '/phpunit.xml', '<phpunit><broken');

        $this->assertGateRejects(self::phpGate(), $dir, 'not well-formed', 'phpunit.xml not well-formed');
    }

    /**
     * Strict config discovered as phpunit.xml.dist.
     */
    #[Test]
    public function acceptsPhpunitXmlDistFallback(): void
    {
        $dir = $this->fixture()->path();
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml.dist');

        $this->assertGateAccepts(self::phpGate(), $dir, 'strict config discovered as phpunit.xml.dist');
    }

    /**
     * Phpunit.xml without a <source> element.
     */
    #[Test]
    public function rejectsPhpunitWithoutSourceElement(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', (string) preg_replace('#<source.*?</source>#s', '', $xml));

        $this->assertGateRejects(self::phpGate(), $dir, 'missing a <source>', 'phpunit.xml without a <source> element');
    }

    /**
     * XML attribute-value normalisation folds only LITERAL control
     * characters to a space; a character reference survives, so `&#10;`
     * produces a real newline. ESC is not expressible in XML 1.0 at all,
     * which is why this payload carries no escape sequence and the ANSI
     * arm in assertGateReportIsInert() cannot fire here — it is the phpunit
     * counterpart of the biome control-char cases in
     * CheckConsumerConfigBiomeTsconfigJsoncTest.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenPhpunitAttributeValueCarriesACharacterReference(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('failOnRisky="true"', 'failOnRisky="false&#10;::error::forged&#10;  - phpunit.xml: OK"', $xml));

        $this->assertGateReportIsInert(self::phpGate(), $dir, 'false?::error::forged?', 'a phpunit.xml attribute value carrying a character reference');
    }

    /**
     * The CR half of the same class: `&#13;` survives XML attribute-value
     * normalisation and reaches the gate as a real CR, exactly as `&#10;`
     * does above. Verified against this repository's own libxml:
     * `php -r 'echo bin2hex((string) simplexml_load_string("<a x=\"false&#13;::error::forged&#13;\"/>")["x"]);'`
     * prints `...0d3a3a6572726f723a3a666f726765640d` — 0x0d preserved, not
     * folded to 0x20.
     *
     * @return void
     */
    #[Test]
    public function reportIsInertWhenPhpunitAttributeValueCarriesABareCarriageReturn(): void
    {
        $dir = $this->mkCase();
        $xml = (string) file_get_contents($dir . '/phpunit.xml');
        file_put_contents($dir . '/phpunit.xml', str_replace('failOnRisky="true"', 'failOnRisky="false&#13;::error::forged&#13;  - phpunit.xml: OK"', $xml));

        $this->assertGateReportIsInert(self::phpGate(), $dir, 'false?::error::forged?', 'a phpunit.xml attribute value carrying a bare carriage return');
    }
}
