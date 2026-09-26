<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractPhpatSubjectsTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function str_repeat;

/**
 * The report shape of the phpat subject-liveness guard,
 * bin/check-phpat-subjects.php (#184), for the values a consumer controls: a
 * subject expression, an unresolvable argument and a rule name, each read out
 * of the consumer's ArchitectureTest and interpolated into the report — see
 * AbstractPhpatSubjectsTestCase for how the ported suite is split. The two
 * poisoned-filename cases of the read arms live in CheckPhpatSubjectsTest,
 * beside the arm they drive.
 *
 * The source is a PHP file rather than XML, so ESC is expressible and the
 * ANSI half of the threat model applies too. Every case asserts the
 * properties GitHub Actions and a terminal key on (assertGateReportIsInert()),
 * not the absence of the payload text: once the bytes cannot start a line the
 * text is inert, and demanding its absence would also pass on a gate that
 * stopped reporting the subject at all.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatSubjectsReportTest extends AbstractPhpatSubjectsTestCase
{
    /**
     * A classname subject carrying ESC, newlines and would-be workflow
     * commands — a raw ESC and raw newlines are legal inside a PHP
     * single-quoted string.
     */
    #[Test]
    public function reportIsInertForAClassnameSubjectCarryingControlCharacters(): void
    {
        $dir = $this->injectedFixture(
            'injected',
            "Selector::classname('Vendor\\Mod\\Nope\e[2K\n"
            . "::error title=Architecture::no vacuous rules found\n"
            . "::add-mask::secret\n"
            . "check-phpat-subjects: OK')",
        );

        $this->assertGateReportIsInert(self::gate(), $dir, 'Nope?[2K?::error title=Architecture');
    }

    /**
     * Length is the ONLY property the wrap adds around a rule name — its
     * capture admits identifier bytes only — so without a case past the cap,
     * removing safeReportValue() there left the whole suite green.
     */
    #[Test]
    public function rejectsAnOverlongRuleNameTruncatedWithAMarker(): void
    {
        $dir = $this->injectedFixture(str_repeat('z', 400), "Selector::inNamespace('Vendor\\Mod\\Nope')", because: 'Long rule name.');

        $this->assertGateRejects(self::gate(), $dir, str_repeat('z', 64) . '…');
    }

    /**
     * The legacy `##[` grammar, with the payload FIRST so it lands inside the
     * 64-byte cap — appended to a longer subject it was cut off and proved
     * nothing.
     */
    #[Test]
    public function reportIsInertForAClassnameSubjectOpeningWithTheLegacyPrefix(): void
    {
        $dir = $this->injectedFixture('injected', "Selector::classname('##[error]forged clean run')");

        $this->assertGateReportIsInert(self::gate(), $dir, 'classname(##?[error]forged clean run)');
    }

    /**
     * The inNamespace() report site, pinned on its own: dropping the guard
     * there left the suite green while only the classname site was pinned.
     */
    #[Test]
    public function reportIsInertForAnInNamespaceSubjectCarryingControlCharacters(): void
    {
        $dir = $this->injectedFixture(
            'injected',
            "Selector::inNamespace('Vendor\\Mod\\Nope\e[2K\n"
            . "::error title=Architecture::no vacuous rules found\n"
            . "check-phpat-subjects: OK')",
        );

        $this->assertGateReportIsInert(self::gate(), $dir, 'Nope?[2K?::error title=Architecture');
    }

    /**
     * The fail-closed arm: an argument the gate cannot resolve is echoed back
     * verbatim, and a concatenation is exactly what reaches it.
     */
    #[Test]
    public function reportIsInertForAnUnresolvableArgumentCarryingControlCharacters(): void
    {
        $dir = $this->injectedFixture(
            'injected',
            "Selector::inNamespace(self::NAMESPACE_ROOT . \"\e[2K\n"
            . "::error title=Architecture::no vacuous rules found\n"
            . 'check-phpat-subjects: OK")',
            "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n",
        );

        $this->assertGateReportIsInert(self::gate(), $dir, '?[2K?::error title=Architecture');
    }

    /**
     * Builds the fixture every case here shares: one live Person class, and
     * an ArchitectureTest whose one rule carries $subject.
     *
     * @param string $ruleName The rule method's name.
     * @param string $subject  The subject selector expression, written verbatim.
     * @param string $constant A class-constant block written before the rule, if any.
     * @param string $because  The because() message.
     *
     * @return string The case directory.
     */
    private function injectedFixture(string $ruleName, string $subject, string $constant = '', string $because = 'Injected subject.'): string
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Person.php', 'Vendor\Mod\Model', 'class', 'Person');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class ArchitectureTest\n{\n"
            . $constant
            . "    #[TestRule]\n    public function {$ruleName}(): Rule\n    {\n"
            . "        return PHPat::rule()\n"
            . "            ->classes({$subject})\n"
            . "            ->shouldNot()->dependOn()\n"
            . "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
            . "            ->because('{$because}');\n"
            . "    }\n}\n",
        );

        return $dir;
    }
}
