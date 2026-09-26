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
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;
use function file_put_contents;

/**
 * Fixture-driven cases for bin/consumer-checks/check-deptrac-yaml.php — the
 * optional deptrac.yaml contract: the shared layer ruleset must be imported.
 * PHP gate only; bin/check-js-config.mjs has no deptrac.yaml counterpart.
 * The unreadable-file case lives with the other plain-text readers' in
 * CheckConsumerConfigTest. See AbstractConsumerConfigTestCase for the shared
 * scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigDeptracYamlTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // deptrac.yaml
    // -------------------------------------------------------------------

    /**
     * Deptrac.yaml dropping the shared import.
     */
    #[Test]
    public function rejectsDeptracDroppingTheSharedImport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "deptrac:\n    paths:\n        - src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml dropping the shared import');
    }

    /**
     * Deptrac.yaml importing the shared ruleset.
     */
    #[Test]
    public function acceptsDeptracImportingTheSharedRuleset(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml importing the shared ruleset');
    }

    /**
     * Every line shape the block scan must admit, in one fixture. The last
     * shape is the one that regressed while being fixed: an earlier
     * widening required a newline in every alternative, silently dropping
     * the final line of a file that has none — so the sought entry sits on
     * that last line, with no trailing newline.
     *
     * @return void
     */
    #[Test]
    public function acceptsDeptracSharedImportAfterCommentBlankLineColumnZeroNoFinalNewline(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/deptrac.yaml',
            "deptrac:\n    paths:\n        - src\nimports:\n# why the shared ruleset comes last\n    - some/other.yaml\n\n"
            . '- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml',
        );

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml carrying the shared import after a comment, a blank line, at column 0 and with no final newline');
    }

    /**
     * Deptrac.yaml whose shared import opens on one quote and closes on the other.
     */
    #[Test]
    public function rejectsDeptracMismatchedQuotesAroundSharedImport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - '.build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\"\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared import opens on one quote and closes on the other');
    }

    /**
     * Deptrac.yaml whose shared path sits under a later top-level key, not in imports.
     */
    #[Test]
    public function rejectsDeptracSharedImportUnderLaterTopLevelKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - some/other.yaml\ndeptrac:\n    paths:\n        - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared path sits under a later top-level key, not in imports');
    }

    /**
     * The two shapes the column-0 alternative used to swallow, both FALSE
     * ACCEPTS: a dash without whitespace after it is not a block sequence
     * entry (`-foreign:` is a top-level key), and `---` starts a new
     * document.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracSharedImportUnderDashPrefixedKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n-foreign:\n    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared import sits under a dash-prefixed key, not in imports');
    }

    /**
     * Deptrac.yaml whose shared import sits in the next YAML document.
     */
    #[Test]
    public function rejectsDeptracSharedImportInNextYamlDocument(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n---\n- .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml whose shared import sits in the next YAML document');
    }

    /**
     * Deptrac.yaml with the shared path under the wrong key.
     */
    #[Test]
    public function rejectsDeptracSharedPathUnderWrongKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "deptrac:\n    paths:\n        - src\n    exclude_files:\n        - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml with the shared path under the wrong key');
    }

    /**
     * Deptrac.yaml importing a near-miss (notmagicsunday) path.
     */
    #[Test]
    public function rejectsDeptracNearMissVendorNamespace(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - vendor/notmagicsunday/coding-standard/deptrac/layers.yaml\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must import the shared', 'deptrac.yaml importing a near-miss (notmagicsunday) path');
    }

    /**
     * Deptrac.yaml with a quoted import + inline comment.
     */
    #[Test]
    public function acceptsDeptracQuotedImportWithInlineComment(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "imports:\n    - 'vendor/magicsunday/coding-standard/deptrac/layers.yaml' # shared ruleset\ndeptrac:\n    paths:\n        - src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, 'deptrac.yaml with a quoted import + inline comment');
    }

    /**
     * As observed on 2026-08-31 (deptrac is not a dependency of this
     * repository, so there is no local copy to re-check this against),
     * deptrac answers its own BOM'd config with `no extension able to load
     * "<BOM>imports"` and dies, so there a BOM IS the defect and stripping
     * it would hide one — the gate names that cause rather than reporting a
     * missing import. The template opens with a comment, so the BOM
     * displaces nothing there and `^imports` still matches — the failure is
     * genuinely deptrac's own.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracBom(): void
    {
        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/deptrac.dist.yaml');
        file_put_contents($dir . '/deptrac.yaml', "\xEF\xBB\xBF" . $template);

        $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: starts with a UTF-8 BOM', 'deptrac.yaml saved with a UTF-8 BOM, which deptrac itself refuses to load');
    }

    /**
     * The BOM-anchored counterpart: a consumer file that opens ON the
     * `imports:` key has that anchor displaced by the BOM too, so leaving
     * the BOM in place for the checks below would ALSO fabricate a false
     * "does not import the shared ruleset". Both assertions are required —
     * the count alone is satisfied by one report of the fabricated kind if
     * the strip is dropped along with it.
     *
     * @return void
     */
    #[Test]
    public function rejectsDeptracBomAnchoredOnImportsAsBomOnlyNoFabricatedReport(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/deptrac.yaml', "\xEF\xBB\xBFimports:\n    - vendor/magicsunday/coding-standard/deptrac/layers.yaml\n\ndeptrac:\n    paths:\n        - ./src\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'deptrac.yaml: starts with a UTF-8 BOM', "a BOM'd deptrac.yaml that opens on imports: is reported as a BOM");
        $this->assertGateReportsOnce(self::phpGate(), $dir, 'deptrac.yaml', "a BOM'd deptrac.yaml that opens on imports: fabricates no missing-import report");
    }
}
