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

use function chmod;
use function copy;
use function file_get_contents;
use function file_put_contents;
use function str_repeat;

/**
 * The orchestrator-level cases of bin/check-consumer-config.php (and, where
 * the case reaches the shared biome.json/tsconfig.json contract, its Node
 * twin bin/check-js-config.mjs): what belongs to no single
 * bin/consumer-checks/check-*.php contract — the canon fixture and the full
 * shipped template set as a whole, the plain-text size cap and the
 * unreadable-file handling every plain-text reader shares through
 * helpers.php's readBounded(), and the usage error the orchestrator raises
 * before any contract runs. The per-contract cases live in one
 * CheckConsumerConfig*Test class per check-*.php file; see
 * AbstractConsumerConfigTestCase for the map and the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // The canon
    // -------------------------------------------------------------------

    /**
     * Canon fixture.
     */
    #[Test]
    public function canonFixtureIsAccepted(): void
    {
        $this->assertBothAccept(self::canon(), 'canon fixture');
    }

    /**
     * POSITIVE: the full canonical template set as a consumer would carry
     * it. The phpunit copy comes from templates/, not the fixture — it is
     * the file a consumer actually copies, and it carries the largest
     * table (required root flags) no gate run had otherwise exercised via
     * the shipped template.
     *
     * @return void
     */
    #[Test]
    public function acceptsFullCanonicalTemplateSetAsShipped(): void
    {
        $dir = $this->fixture()->path();
        copy(self::root() . '/templates/phpunit.xml.dist', $dir . '/phpunit.xml.dist');
        copy(self::root() . '/templates/editorconfig', $dir . '/.editorconfig');
        copy(self::root() . '/templates/jscpd.json', $dir . '/.jscpd.json');
        copy(self::root() . '/templates/phplint.yml', $dir . '/.phplint.yml');

        $this->assertGateAccepts(self::phpGate(), $dir, 'full canonical template set, phpunit included, as templates/ ships it');
    }

    // -------------------------------------------------------------------
    // Size caps and unreadable files — the plain-text bound
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function oversizeFileProvider(): array
    {
        return self::singleArgProviderRows([
            'phpunit.xml',
            '.jscpd.json',
            '.phplint.yml',
            '.editorconfig',
            'deptrac.yaml',
        ]);
    }

    /**
     * Every plain-text reader on this bound, not a sample: a substring
     * assertion alone passes while the gate ALSO fabricates causes.
     * Requiring exactly one report per file is what an earlier version of
     * this check missed — measured before the fix, the phpunit fixture
     * produced two violations and the .editorconfig one four, the extras
     * naming things the files plainly carry.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('oversizeFileProvider')]
    public function reportsOnceWhenAFileExceedsTheTextSizeCap(string $file): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/' . $file, str_repeat('x', self::MAX_TEXT_BYTES + 1));

        $this->assertGateReportsOnce(self::phpGate(), $dir, $file, "an oversized {$file} is reported once, as itself");
    }

    /**
     * An unreadable .phplint.yml reports only that it cannot be read and
     * fabricates no content-drift finding on top of it.
     */
    #[Test]
    public function rejectsUnreadablePhplintAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/phplint.yml');
        file_put_contents($dir . '/.phplint.yml', $template);
        chmod($dir . '/.phplint.yml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, '.phplint.yml: exists but cannot be read', 'an unreadable .phplint.yml reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, '.phplint.yml', 'an unreadable .phplint.yml fabricates no content drift');
        } finally {
            chmod($dir . '/.phplint.yml', 0o644);
        }
    }

    /**
     * The .editorconfig counterpart of the .phplint.yml case above.
     */
    #[Test]
    public function rejectsUnreadableEditorconfigAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/editorconfig');
        file_put_contents($dir . '/.editorconfig', $template);
        chmod($dir . '/.editorconfig', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, '.editorconfig: exists but cannot be read', 'an unreadable .editorconfig reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, '.editorconfig', 'an unreadable .editorconfig fabricates no content drift');
        } finally {
            chmod($dir . '/.editorconfig', 0o644);
        }
    }

    /**
     * The deptrac.yaml counterpart, with an inline fixture rather than the
     * template (its `.build/vendor/...` import path does not match this
     * suite's own fixture layout).
     */
    #[Test]
    public function rejectsUnreadableDeptracAndFabricatesNoContentDrift(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");
        chmod($dir . '/deptrac.yaml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: exists but cannot be read', 'an unreadable deptrac.yaml reports only that it cannot be read');
            $this->assertGateReportsOnce(self::phpGate(), $dir, 'deptrac.yaml', 'an unreadable deptrac.yaml fabricates no content drift');
        } finally {
            chmod($dir . '/deptrac.yaml', 0o644);
        }
    }

    /**
     * phpunit.xml is the one REQUIRED file, and libxml returns the same
     * false for unreadable as for malformed — so this used to read as a
     * syntax error.
     *
     * @return void
     */
    #[Test]
    public function rejectsUnreadablePhpunitAsUnreadableNotMalformed(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->mkCase();
        chmod($dir . '/phpunit.xml', 0o000);

        try {
            $this->assertGateRejects(self::phpGate(), $dir, 'phpunit.xml: exists but cannot be read', 'an unreadable phpunit.xml is not reported as malformed XML');
        } finally {
            chmod($dir . '/phpunit.xml', 0o644);
        }
    }

    // -------------------------------------------------------------------
    // Usage errors
    // -------------------------------------------------------------------

    /**
     * A path that is not a directory.
     */
    #[Test]
    public function reportsUsageErrorWhenPathIsNotADirectory(): void
    {
        $this->assertBothUsageError($this->fixture()->path() . '/does-not-exist', 'Not a directory', 'a path that is not a directory');
    }
}
