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
use function microtime;
use function preg_replace;
use function str_repeat;

/**
 * Fixture-driven cases for bin/consumer-checks/check-editorconfig.php — the
 * optional .editorconfig contract: the 4-space house indent plus the
 * Makefile tab override. PHP gate only; bin/check-js-config.mjs has no
 * .editorconfig counterpart. The unreadable-file case lives with the other
 * plain-text readers' in CheckConsumerConfigTest. See
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigEditorconfigTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // .editorconfig
    // -------------------------------------------------------------------

    /**
     * A .editorconfig whose first `=` sits behind a long whitespace run. The
     * pattern this guards against was Theta(W^2) — measured end-to-end at
     * 34.56s for a 256 KiB run and 380s for 1 MiB, on a file with no size
     * cap. Without a TIME assertion this case cannot fail on the defect: the
     * verdict is identical either way, only the wait changes.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigWithLargeWhitespaceRunWithinTimeBound(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n[{Makefile,*.mk}]\nindent_style = tab\na"
            . str_repeat(' ', 262144) . "x=y\n",
        );

        $started = microtime(true);
        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig carrying a 256 KiB whitespace run before its first `=`');
        $elapsed = microtime(true) - $started;

        self::assertLessThanOrEqual(5.0, $elapsed, "the .editorconfig parse took {$elapsed}s on a 256 KiB whitespace run — the quadratic shape is back");
    }

    /**
     * .editorconfig with indent_style = tab in [*].
     */
    #[Test]
    public function rejectsEditorconfigStarIndentStyleTab(): void
    {
        // The fixture is canon in every other respect (indent_size, root,
        // Makefile) so the ONLY violation is the [*] indent_style, and the
        // substring discriminates exactly it.
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = tab\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must set `indent_style = space`', '.editorconfig with indent_style = tab in [*]');
    }

    /**
     * As observed on 2026-08-31, editors honour a BOM'd .editorconfig —
     * editorconfig-core-js (not a dependency of this repository, so there is
     * no local copy to re-check this against) reads one and returns its
     * settings, because JavaScript's `\s` matches U+FEFF. PHP's trim() does
     * not, so without the strip the key parses as
     * "\u{FEFF}root" and a file every editor obeys is reported as drift.
     * Written literally (not copied from templates/, whose header would
     * absorb the BOM on a comment line and leave `root = true` untouched,
     * pinning nothing) so the BOM directly abuts the key the strip protects.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigWithBom(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "\xEF\xBB\xBFroot = true\n\n[*]\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig saved with a UTF-8 BOM directly before its first key');
    }

    /**
     * The Makefile arm no other .editorconfig fixture reaches: every one
     * that writes `[{Makefile,*.mk}]` at all sets `indent_style = tab`, so
     * only the section-MISSING half was driven elsewhere. Reducing the
     * condition to `$makefile === null` would leave this the only red — a
     * repository moving its Makefile to spaces edits the value rather than
     * deleting the header.
     *
     * @return void
     */
    #[Test]
    public function rejectsEditorconfigMakefileSectionSetToSpaces(): void
    {
        $dir      = $this->mkCase();
        $template = (string) file_get_contents(self::root() . '/templates/editorconfig');
        file_put_contents($dir . '/.editorconfig', preg_replace('/^indent_style = tab$/m', 'indent_style = space', $template));

        $this->assertGateRejects(self::phpGate(), $dir, '`[{Makefile,*.mk}]` section with `indent_style = tab`', '.editorconfig whose Makefile section sets spaces instead of tab');
    }

    /**
     * The line splitter, justified by three specific bytes. `\R` matches VT,
     * FF and U+0085 as line breaks; U+0085 is the CONTINUATION byte of a
     * two-byte UTF-8 character, so splitting on it cuts a character in half
     * and re-parses the tail as a config line. The poisoned tail sits AFTER
     * the real settings (the map is last-write-wins) and parses as a key
     * that CHANGES a verdict, or the case does not discriminate.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigCommentCarryingContinuationByte(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n# note \xc4\x85 indent_style = tab\n[{Makefile,*.mk}]\nindent_style = tab\n",
        );

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig whose comment carries a U+0085 continuation byte before a settings-shaped tail');
    }

    /**
     * .editorconfig whose comment carries a form feed before a settings-shaped tail.
     */
    #[Test]
    public function acceptsEditorconfigCommentCarryingFormFeed(): void
    {
        $dir = $this->mkCase();
        file_put_contents(
            $dir . '/.editorconfig',
            "root = true\n[*]\nindent_style = space\nindent_size = 4\n# a form feed \x0c indent_size = 2\n[{Makefile,*.mk}]\nindent_style = tab\n",
        );

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig whose comment carries a form feed before a settings-shaped tail');
    }

    /**
     * Case folding, and the explicit trim charlist beside it. Every other
     * fixture writes lowercase keys separated by plain spaces, so replacing
     * mb_strtolower() with the identity — and the charlist with trim()'s
     * default — would leave the suite green elsewhere. The form feed is the
     * one byte the two charlists disagree on.
     *
     * @return void
     */
    #[Test]
    public function acceptsEditorconfigUppercaseKeysAndFormFeeds(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "ROOT = TRUE\n[*]\n\x0cIndent_Style\x0c = Space\nINDENT_SIZE = 4\n[{Makefile,*.mk}]\nIndent_Style = Tab\n");

        $this->assertGateAccepts(self::phpGate(), $dir, '.editorconfig written with uppercase keys and values, and form feeds around a key');
    }

    /**
     * .editorconfig with root inside a section.
     */
    #[Test]
    public function rejectsEditorconfigRootInsideSection(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "[*]\nroot = true\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'root = true', '.editorconfig with root inside a section');
    }

    /**
     * .editorconfig without the Makefile tab override.
     */
    #[Test]
    public function rejectsEditorconfigWithoutMakefileOverride(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 4\n");

        $this->assertGateRejects(self::phpGate(), $dir, '{Makefile,*.mk}', '.editorconfig without the Makefile tab override');
    }

    /**
     * .editorconfig with indent_size = 2 in [*].
     */
    #[Test]
    public function rejectsEditorconfigStarIndentSize2(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 2\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must set `indent_size = 4`', '.editorconfig with indent_size = 2 in [*]');
    }

    /**
     * .editorconfig without a global [*] section.
     */
    #[Test]
    public function rejectsEditorconfigWithoutGlobalStarSection(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*.md]\nindent_style = space\nindent_size = 4\n\n[{Makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, 'must define a global `[*]` section', '.editorconfig without a global [*] section');
    }

    /**
     * .editorconfig with a lowercase {makefile,*.mk} glob.
     */
    #[Test]
    public function rejectsEditorconfigLowercaseMakefileGlob(): void
    {
        $dir = $this->mkCase();
        file_put_contents($dir . '/.editorconfig', "root = true\n\n[*]\nindent_style = space\nindent_size = 4\n\n[{makefile,*.mk}]\nindent_style = tab\n");

        $this->assertGateRejects(self::phpGate(), $dir, '{Makefile,*.mk}', '.editorconfig with a lowercase {makefile,*.mk} glob');
    }
}
