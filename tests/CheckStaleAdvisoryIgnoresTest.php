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

use function dirname;
use function explode;
use function file_put_contents;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Runs bin/check-stale-advisory-ignores.php as a real process against small composer.json and audit files and
 * checks its exit status and its report.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckStaleAdvisoryIgnoresTest extends GateTestCase
{
    /**
     * Counter that gives every input file of a test its own name inside the fixture directory.
     */
    private int $inputFiles = 0;

    /**
     * Every ignored id still matches an advisory of the audit, so nothing is reported.
     */
    #[Test]
    public function passesWhenEveryIgnoredAdvisoryStillApplies(): void
    {
        $result = $this->check(
            $this->manifest(['PKSA-aaaa-0000-0001' => 'reason', 'PKSA-bbbb-0000-0002' => 'reason']),
            $this->audit(['vendor/one' => ['PKSA-aaaa-0000-0001'], 'vendor/two' => ['PKSA-bbbb-0000-0002']]),
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->output);
    }

    /**
     * An id that the audit no longer lists is stale and named, an id that still applies is not.
     */
    #[Test]
    public function failsAndNamesOnlyTheIgnoredAdvisoryThatNoLongerApplies(): void
    {
        $result = $this->check(
            $this->manifest(['PKSA-aaaa-0000-0001' => 'reason', 'PKSA-stale-0000-0009' => 'reason']),
            $this->audit(['vendor/one' => ['PKSA-aaaa-0000-0001']]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-stale-0000-0009', $result->output);
        self::assertStringNotContainsString('PKSA-aaaa-0000-0001', $result->output);
    }

    /**
     * Composer leaves the key out of its report when no installed package matches an ignored id, so every ignored
     * id is stale then.
     */
    #[Test]
    public function failsWhenTheAuditListsNoIgnoredAdvisoryAtAll(): void
    {
        $result = $this->check(
            $this->manifest(['PKSA-aaaa-0000-0001' => 'reason']),
            $this->write(['advisories' => []]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-aaaa-0000-0001', $result->output);
    }

    /**
     * Several stale ids are named one per line in sorted order, whatever order the composer.json lists them in.
     */
    #[Test]
    public function namesSeveralStaleAdvisoriesInSortedOrder(): void
    {
        $result = $this->check(
            $this->manifest([
                'PKSA-zzzz-0000-0003' => 'reason',
                'PKSA-aaaa-0000-0001' => 'reason',
                'PKSA-mmmm-0000-0002' => 'reason',
            ]),
            $this->write(['advisories' => []]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString(
            "  - PKSA-aaaa-0000-0001\n  - PKSA-mmmm-0000-0002\n  - PKSA-zzzz-0000-0003\n",
            $result->output,
        );
    }

    /**
     * An entry keyed by a CVE never matches the advisory id of the report and is stale.
     */
    #[Test]
    public function reportsAnEntryKeyedByACveAsStale(): void
    {
        $result = $this->check(
            $this->manifest(['CVE-2099-0001' => 'reason']),
            $this->audit(['vendor/one' => ['PKSA-aaaa-0000-0001']]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('CVE-2099-0001', $result->output);
    }

    /**
     * An entry of the report that carries no advisory id matches nothing and is skipped without a PHP diagnostic,
     * so the report stays the plain list of stale ids.
     */
    #[Test]
    public function skipsAnAuditEntryWithoutAnAdvisoryId(): void
    {
        $audit = $this->write(
            ['advisories' => [], 'ignored-advisories' => ['vendor/one' => [['packageName' => 'vendor/one']]]],
        );

        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $audit);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-aaaa-0000-0001', $result->output);
        self::assertStringNotContainsString('Warning', $result->output);
    }

    /**
     * Without any ignored id there is nothing that can be stale.
     */
    #[Test]
    public function passesWithoutAnyIgnoredAdvisory(): void
    {
        $result = $this->check(
            $this->write(['name' => 'fixture/module']),
            $this->write(['advisories' => []]),
        );

        self::assertSame(0, $result->exitCode);
    }

    /**
     * Composer also accepts a plain list of ids, where the id is the value and not the key.
     */
    #[Test]
    public function readsAnIgnoreListThatHoldsPlainIds(): void
    {
        $manifest = $this->write(
            ['config' => ['policy' => ['advisories' => ['ignore-id' => ['PKSA-aaaa-0000-0001', 'PKSA-stale-0000-0009']]]]],
        );

        $result = $this->check(
            $manifest,
            $this->audit(['vendor/one' => ['PKSA-aaaa-0000-0001']]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-stale-0000-0009', $result->output);
        self::assertStringNotContainsString('PKSA-aaaa-0000-0001', $result->output);
    }

    /**
     * A path that does not exist is a failure with status 2 for either input, and the report names the path.
     */
    #[Test]
    public function failsWhenAnInputFileCannotBeRead(): void
    {
        $missing = $this->fixture()->path() . '/missing.json';

        $manifestMissing = $this->check($missing, $this->write(['advisories' => []]));
        $auditMissing    = $this->check($this->manifest([]), $missing);

        self::assertSame(2, $manifestMissing->exitCode);
        self::assertSame('Cannot read ' . $missing . "\n", $manifestMissing->output);
        self::assertSame(2, $auditMissing->exitCode);
        self::assertSame('Cannot read ' . $missing . "\n", $auditMissing->output);
    }

    /**
     * An audit report that is not JSON, for example the empty file that a crashed audit leaves, is a failure and
     * never a pass.
     */
    #[Test]
    public function failsWhenTheAuditReportIsNotJson(): void
    {
        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $this->writeRaw(''));

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('not valid JSON', $result->output);
    }

    /**
     * A file that holds valid JSON which is no object or array, for example a bare number, is a failure with status 2
     * and never a pass.
     */
    #[Test]
    public function failsWhenAnInputIsValidJsonButNotAnObject(): void
    {
        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $this->writeRaw('5'));

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('not a JSON object or array', $result->output);
    }

    /**
     * A config value that is no array leaves nothing that is ignored, so nothing can be stale.
     */
    #[Test]
    public function passesWhenTheConfigValueIsNoArray(): void
    {
        $result = $this->check($this->write(['config' => 'none']), $this->write(['advisories' => []]));

        self::assertSame(0, $result->exitCode);
    }

    /**
     * An entry of the ignore list that is neither a string key nor a string value names no advisory and is skipped.
     */
    #[Test]
    public function skipsAnIgnoreEntryThatIsNoString(): void
    {
        $manifest = $this->write(['config' => ['policy' => ['advisories' => ['ignore-id' => [5]]]]]);

        $result = $this->check($manifest, $this->write(['advisories' => []]));

        self::assertSame(0, $result->exitCode);
    }

    /**
     * A package entry of the report that is no list holds no advisory, is skipped without a PHP diagnostic and
     * leaves the ignored id stale.
     */
    #[Test]
    public function skipsAPackageEntryOfTheReportThatIsNoList(): void
    {
        $audit = $this->write(['advisories' => [], 'ignored-advisories' => ['vendor/one' => 'broken']]);

        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $audit);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-aaaa-0000-0001', $result->output);
        self::assertStringNotContainsString('Warning', $result->output);
    }

    /**
     * An ignore-id entry in the object form is refused with status 2 and named, because whether it applies to the
     * audit depends on its on-audit and on-block settings.
     */
    #[Test]
    public function refusesAnIgnoreEntryInTheObjectForm(): void
    {
        $manifest = $this->write(
            ['config' => ['policy' => ['advisories' => ['ignore-id' => ['PKSA-aaaa-0000-0001' => ['reason' => 'x', 'on-audit' => false]]]]]],
        );

        $result = $this->check($manifest, $this->audit(['vendor/one' => ['PKSA-aaaa-0000-0001']]));

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Unsupported ignore-id entry PKSA-aaaa-0000-0001', $result->output);
    }

    /**
     * The id of a refused object-form entry is scrubbed like an id of the stale report, so a line break and a
     * workflow command in the key do not reach the report.
     */
    #[Test]
    public function scrubsTheIdOfARefusedObjectFormEntry(): void
    {
        $manifest = $this->write(
            ['config' => ['policy' => ['advisories' => ['ignore-id' => ["PKSA-aaaa-0000-0001\n##[error]forged" => ['reason' => 'x']]]]]],
        );

        $result = $this->check($manifest, $this->write(['advisories' => []]));

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Unsupported ignore-id entry PKSA-aaaa-0000-0001?##?[error]forged', $result->output);
        self::assertDoesNotMatchRegularExpression('/^[[:space:]]*(::[A-Za-z0-9_-]+|##\\[)/m', $result->output);
    }

    /**
     * An id that starts with a workflow command is printed behind a list marker, so no line of the report starts a
     * command for the CI runner, which reads a command after leading white space.
     */
    #[Test]
    public function printsAnIdThatStartsWithAWorkflowCommandBehindAListMarker(): void
    {
        $result = $this->check(
            $this->manifest(['::error::forged' => 'reason']),
            $this->write(['advisories' => []]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString("  - ::error::forged\n", $result->output);
        self::assertDoesNotMatchRegularExpression('/^[[:space:]]*::[A-Za-z0-9_-]+/m', $result->output);
    }

    /**
     * An id that carries a line break and a workflow command is scrubbed before it is printed, so no line of the
     * report can start a command for the CI runner.
     */
    #[Test]
    public function scrubsAnIdBeforeItIsPrinted(): void
    {
        $result = $this->check(
            $this->manifest(["PKSA-aaaa-0000-0001\n##[error]forged" => 'reason']),
            $this->write(['advisories' => []]),
        );

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('PKSA-aaaa-0000-0001?##?[error]forged', $result->output);

        foreach (explode("\n", $result->output) as $line) {
            self::assertStringStartsNotWith('##[', $line);
        }
    }

    /**
     * A third argument is a usage error like a missing one.
     */
    #[Test]
    public function rejectsAThirdArgument(): void
    {
        $result = (new GateProcess())->runRaw([PHP_BINARY, $this->script(), $this->manifest([]), $this->write(['advisories' => []]), 'extra']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Usage:', $result->output);
    }

    /**
     * An ignore-id value and an ignored-advisories value that are no arrays leave nothing that is ignored or applied.
     */
    #[Test]
    public function readsAScalarAtTheLeafOfEitherInputAsEmpty(): void
    {
        $manifest = $this->write(['config' => ['policy' => ['advisories' => ['ignore-id' => 'PKSA-aaaa-0000-0001']]]]);
        $audit    = $this->write(['advisories' => [], 'ignored-advisories' => 'broken']);

        self::assertSame(0, $this->check($manifest, $this->write(['advisories' => []]))->exitCode);

        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $audit);

        self::assertSame(1, $result->exitCode);
        self::assertStringNotContainsString('Warning', $result->output);
    }

    /**
     * An advisoryId that is no string matches nothing and is skipped without a PHP diagnostic.
     */
    #[Test]
    public function skipsAnAdvisoryIdThatIsNoString(): void
    {
        $audit = $this->write(['advisories' => [], 'ignored-advisories' => ['vendor/one' => [['advisoryId' => ['x']]]]]);

        $result = $this->check($this->manifest(['PKSA-aaaa-0000-0001' => 'reason']), $audit);

        self::assertSame(1, $result->exitCode);
        self::assertStringNotContainsString('Warning', $result->output);
    }

    /**
     * A wrong number of arguments is a usage error with status 2.
     */
    #[Test]
    public function rejectsAWrongNumberOfArguments(): void
    {
        $result = (new GateProcess())->runRaw([PHP_BINARY, $this->script(), $this->manifest([])]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Usage:', $result->output);
    }

    /**
     * Builds a composer.json that ignores the given ids with their reasons.
     *
     * @param array<string, string> $ignoreIds
     */
    private function manifest(array $ignoreIds): string
    {
        return $this->write(['config' => ['policy' => ['advisories' => ['ignore-id' => $ignoreIds]]]]);
    }

    /**
     * Builds an audit report whose "ignored-advisories" lists the given advisory ids per package.
     *
     * @param array<string, list<string>> $advisoryIdsByPackage
     */
    private function audit(array $advisoryIdsByPackage): string
    {
        $ignored = [];

        foreach ($advisoryIdsByPackage as $package => $advisoryIds) {
            foreach ($advisoryIds as $advisoryId) {
                $ignored[$package][] = ['advisoryId' => $advisoryId, 'packageName' => $package];
            }
        }

        return $this->write(['advisories' => [], 'ignored-advisories' => $ignored]);
    }

    /**
     * Writes the data as JSON to a new temporary file and returns its path.
     *
     * @param array<string, mixed> $data
     */
    private function write(array $data): string
    {
        return $this->writeRaw(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Writes the contents to a new temporary file and returns its path.
     */
    private function writeRaw(string $contents): string
    {
        $path = sprintf('%s/input-%d.json', $this->fixture()->path(), ++$this->inputFiles);

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Returns the path of the script under test.
     */
    private function script(): string
    {
        return dirname(__DIR__) . '/bin/check-stale-advisory-ignores.php';
    }

    /**
     * Runs the script with the two input files.
     */
    private function check(string $manifest, string $audit): GateResult
    {
        return (new GateProcess())->runRaw([PHP_BINARY, $this->script(), $manifest, $audit]);
    }
}
