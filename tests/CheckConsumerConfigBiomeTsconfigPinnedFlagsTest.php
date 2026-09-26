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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function str_contains;

/**
 * Fixture-driven cases for the pinned tsconfig compiler flags in
 * bin/consumer-checks/check-biome-tsconfig.php ($pinnedFlags) and its
 * bin/check-js-config.mjs twin: one case per flag tsconfig/base.json ships
 * as `true`, DERIVED from that file rather than listed by hand, plus the
 * `strict` family it implies, each proven in both directions against the
 * gate's own list. Every case runs BOTH gates against the same fixture
 * through the assertBoth*() helpers; see AbstractConsumerConfigTestCase for
 * the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigBiomeTsconfigPinnedFlagsTest extends AbstractConsumerConfigTestCase
{
    /**
     * Ergonomics flags tsconfig/base.json ships as `true` that are
     * deliberately NOT pinned — turning one off is stricter, not looser, and
     * must not be reported as drift.
     *
     * @var list<non-empty-string>
     */
    private const array ERGONOMICS_FLAGS = ['esModuleInterop', 'resolveJsonModule', 'skipLibCheck'];

    /**
     * The flags `strict: true` switches on as a group. They are not written
     * into tsconfig/base.json themselves — `strict` implies them — so
     * baseFlagsFromTsconfigBase() cannot generate their cases; held here
     * independently so both directions can be checked against the gate's own
     * $pinnedFlags. A consumer may write any family member back individually
     * ("strict": true alongside "strictNullChecks": false compiles code
     * "strict": true alone rejects), so pinning only `strict` pins nothing.
     *
     * @var list<non-empty-string>
     */
    private const array STRICT_FAMILY_FLAGS = [
        'alwaysStrict',
        'noImplicitAny',
        'noImplicitThis',
        'strictBindCallApply',
        'strictBuiltinIteratorReturn',
        'strictFunctionTypes',
        'strictNullChecks',
        'strictPropertyInitialization',
        'useUnknownInCatchVariables',
    ];

    // -------------------------------------------------------------------
    // Gate-source extraction — this contract's lockstep table, read at
    // runtime from the check-*.php split that declares it through the
    // shared gateSource()/extractQuotedList() primitives (see
    // AbstractConsumerConfigTestCase's class docblock).
    // -------------------------------------------------------------------

    /**
     * @return list<non-empty-string> The gate's own $pinnedFlags, read from source.
     */
    private static function pinnedFlagsFromGate(): array
    {
        return self::extractQuotedList('pinnedFlags', 'bin/consumer-checks/check-biome-tsconfig.php');
    }

    // -------------------------------------------------------------------
    // The pinned strict flags, derived from the shipped base — the cases
    // are DERIVED from tsconfig/base.json rather than listed by hand, so
    // a strictness flag added there later cannot go unpinned in silence.
    // -------------------------------------------------------------------

    /**
     * @return list<non-empty-string> Every compilerOptions flag tsconfig/base.json ships as `true`.
     *
     * @throws RuntimeException If the base does not decode as JSON carrying compilerOptions, a flag name is
     *                          not plain letters, or nothing was found.
     */
    private static function baseFlagsFromTsconfigBase(): array
    {
        return self::baseFlagsFromTsconfigJson((string) file_get_contents(self::root() . '/tsconfig/base.json'));
    }

    /**
     * Every compilerOptions flag the given tsconfig JSON ships as `true`.
     *
     * Each name becomes a DataProvider row key (baseFlagProvider()), and
     * PHPUnit prints a failed row's key at the start of a physical line of
     * its failure header — where the runner recognises a workflow command.
     * tsconfig/base.json is PR-editable, so a key is accepted only when it is
     * plain letters, every real TypeScript compiler option's shape, the same
     * posture extractQuotedList() takes for the gate-source tables. `\A`/`\z`
     * rather than `^`/`$`: PCRE's `$` also matches before a trailing newline.
     * The exception names no key, since echoing the rejected one would
     * re-open the channel this closes.
     *
     * @param string $json The tsconfig document to read.
     *
     * @return list<non-empty-string>
     *
     * @throws RuntimeException If the JSON does not carry compilerOptions, a flag name is not plain letters,
     *                          or nothing was found.
     */
    private static function baseFlagsFromTsconfigJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !is_array($decoded['compilerOptions'] ?? null)) {
            throw new RuntimeException('tsconfig/base.json did not decode as JSON carrying compilerOptions');
        }

        $flags = [];

        foreach ($decoded['compilerOptions'] as $name => $value) {
            if ($value !== true) {
                continue;
            }

            if (!is_string($name) || preg_match('/\A[A-Za-z]+\z/', $name) !== 1) {
                throw new RuntimeException(
                    'tsconfig/base.json ships a `true` compilerOptions flag whose name is not plain letters'
                    . ' — widen the check deliberately rather than letting it become a data-set name',
                );
            }

            $flags[] = $name;
        }

        if ($flags === []) {
            throw new RuntimeException('read no compilerOptions flags from tsconfig/base.json');
        }

        /** @var list<non-empty-string> $flags */
        return $flags;
    }

    /**
     * A compilerOptions key carrying a line break must never reach
     * baseFlagProvider() as a row key: PHPUnit would print it at the start of
     * a line in the failure header of whichever run first fails that row,
     * forging a workflow command there. The rejection itself must not carry
     * the key either. Looped here rather than fed through a data provider,
     * whose argument values PHPUnit may itself print on a failure.
     */
    #[Test]
    public function rejectsTsconfigBaseFlagNameThatIsNotPlainLetters(): void
    {
        $unsafeNames = [
            'embedded workflow commands' => "x\n::error::forged\n##[error]forged",
            'trailing newline'           => "forged\n",
            'digit'                      => 'forged2',
            'numeric key'                => '42',
        ];

        foreach ($unsafeNames as $label => $name) {
            $json = (string) json_encode(['compilerOptions' => ['strict' => true, $name => true]]);

            $thrown = self::assertThrows(
                static fn () => self::baseFlagsFromTsconfigJson($json),
                RuntimeException::class,
                "baseFlagsFromTsconfigJson() accepted a flag name that is not plain letters ({$label}).",
            );

            if (str_contains($thrown->getMessage(), 'forged') || str_contains($thrown->getMessage(), '42')) {
                self::fail("The rejection still carries the rejected flag name in its message ({$label}).");
            }
        }
    }

    /**
     * A non-letter key is only a hazard once it becomes a row: a flag the base
     * does not ship as `true` is skipped before the name check, the same set
     * baseFlagProvider() would never have turned into a row.
     */
    #[Test]
    public function ignoresNonTrueTsconfigFlagRegardlessOfItsName(): void
    {
        $json = (string) json_encode(['compilerOptions' => ['strict' => true, "x\n::error::forged" => false, 'target' => 'es2022']]);

        self::assertSame(['strict'], self::baseFlagsFromTsconfigJson($json));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function baseFlagProvider(): array
    {
        return self::singleArgProviderRows(self::baseFlagsFromTsconfigBase());
    }

    /**
     * Every compilerOptions flag tsconfig/base.json ships as `true`, turned
     * off individually: an ERGONOMICS_FLAGS member must still be accepted
     * (turning it off is stricter, not looser), every other flag must be
     * rejected.
     */
    #[Test]
    #[DataProvider('baseFlagProvider')]
    public function baseFlagDrift(string $flag): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"{$flag}\": false }\n}\n");

        if (in_array($flag, self::ERGONOMICS_FLAGS, true)) {
            $this->assertBothAccept($dir, "tsconfig.json turning the ergonomics flag {$flag} off");
        } else {
            $this->assertBothReject($dir, "compilerOptions.{$flag}", "tsconfig.json turning the shared strict flag {$flag} off");
        }
    }

    /**
     * Both directions of the two derived lists against the base, plus the
     * gate's own $pinnedFlags bijection — so neither list can outlive
     * tsconfig/base.json or drift from the other.
     *
     * @return void
     */
    #[Test]
    public function pinnedFlagsBijectionHoldsAgainstBaseAndGate(): void
    {
        $baseFlags   = self::baseFlagsFromTsconfigBase();
        $pinnedFlags = self::pinnedFlagsFromGate();

        foreach (self::ERGONOMICS_FLAGS as $flag) {
            self::assertContains($flag, $baseFlags, "ergonomics exception {$flag} is no longer shipped by tsconfig/base.json");
        }

        foreach ($pinnedFlags as $flag) {
            if (in_array($flag, self::STRICT_FAMILY_FLAGS, true)) {
                continue;
            }

            self::assertContains($flag, $baseFlags, "pinned flag {$flag} is no longer shipped by tsconfig/base.json");
        }

        self::assertContains('strict', $baseFlags, 'tsconfig/base.json no longer sets `strict`, so the strict-family pins guard nothing');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function strictFamilyFlagProvider(): array
    {
        return self::singleArgProviderRows(self::STRICT_FAMILY_FLAGS);
    }

    /**
     * A consumer may write any `strict` family member back individually,
     * and TypeScript treats the specific option as an override of the
     * umbrella — so `strict: true` alongside `strictNullChecks: false`
     * compiles code that `strict: true` alone rejects. Pinning only
     * `strict` therefore pins nothing.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('strictFamilyFlagProvider')]
    public function rejectsTsconfigOverridingStrictFamilyFlagWhileKeepingStrict(string $flag): void
    {
        self::assertContains($flag, self::pinnedFlagsFromGate(), "the gate no longer pins the strict-family flag {$flag}");

        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": \"@magicsunday/coding-standard/tsconfig/base.json\",\n    \"compilerOptions\": { \"strict\": true, \"{$flag}\": false }\n}\n");

        $this->assertBothReject($dir, "compilerOptions.{$flag}", "tsconfig.json overriding the strict-family flag {$flag} while keeping strict");
    }
}
