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

use function file_put_contents;

/**
 * Fixture-driven cases for bin/consumer-checks/check-phplint-yml.php — the
 * optional .phplint.yml contract: the `extensions:` block must list `- php`.
 * PHP gate only; bin/check-js-config.mjs has no .phplint.yml counterpart.
 * The unreadable-file case lives with the other plain-text readers' in
 * CheckConsumerConfigTest. See AbstractConsumerConfigTestCase for the shared
 * scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigPhplintYmlTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // .phplint.yml
    // -------------------------------------------------------------------

    /**
     * .phplint.yml with php under path, not extensions.
     */
    #[Test]
    public function rejectsPhplintPhpUnderWrongBlock(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "path:\n    - php\nextensions:\n    - phtml\n");

        $this->assertGateRejects(self::phpGate(), $dir, '`extensions:` block', '.phplint.yml with php under path, not extensions');
    }

    /**
     * .phplint.yml with CRLF line endings.
     */
    #[Test]
    public function acceptsPhplintCrlfLineEndings(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "path:\r\n    - src\r\n    - tests\r\nextensions:\r\n    - php\r\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml with CRLF line endings');
    }

    /**
     * .phplint.yml listing php after a comment and a blank line, with no final newline.
     */
    #[Test]
    public function acceptsPhplintShapesAfterCommentAndBlankLineWithNoFinalNewline(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "paths:\n    - ./src\nextensions:\n# only PHP\n\n    - php");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml listing php after a comment and a blank line, with no final newline');
    }

    /**
     * .phplint.yml whose `php` sits under a later top-level key, not in extensions.
     */
    #[Test]
    public function rejectsPhplintPhpUnderALaterTopLevelKey(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "extensions:\n    - phtml\npaths:\n    - php\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must list', '.phplint.yml whose `php` sits under a later top-level key, not in extensions');
    }

    /**
     * .phplint.yml saved with a UTF-8 BOM directly before its first key.
     */
    #[Test]
    public function acceptsPhplintBom(): void
    {
        // As observed against the overtrue/phplint version installed
        // locally (composer.lock is gitignored, so re-check it against
        // YOUR OWN `composer install` output: `grep -A1 '"name":
        // "overtrue/phplint"' composer.lock`), the tool reads a BOM'd
        // config and runs normally, so the gate
        // strips it — the `^extensions` anchor sits at offset 0 and the BOM
        // would displace it, reporting drift in a file the tool obeys.
        // `extensions:` is written first (not copied from templates/, which
        // opens with a comment) so the BOM actually sits before the anchor.
        $dir = $this->mkCase();
        file_put_contents($dir . '/.phplint.yml', "\xEF\xBB\xBFextensions:\n    - php\n\npath:\n    - ./src\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.phplint.yml saved with a UTF-8 BOM directly before its first key');
    }
}
