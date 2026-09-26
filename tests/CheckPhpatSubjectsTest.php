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

use function copy;
use function rename;
use function str_repeat;

/**
 * The liveness arms, fail-closed reports, read arms and usage errors of the
 * phpat subject-liveness guard, bin/check-phpat-subjects.php (#184) — see
 * AbstractPhpatSubjectsTestCase for how the ported suite is split.
 *
 * Proves the guard ACCEPTS an ArchitectureTest whose rule subjects all match
 * a real class, and REJECTS the vacuous cases — the trait-only namespace
 * subject (the manifested bug), an empty namespace, a missing classname
 * target, and an unparseable subject (fail-closed) — while treating an
 * isAbstract() subject with no abstract class as a legitimate conditional
 * guard.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatSubjectsTest extends AbstractPhpatSubjectsTestCase
{
    /**
     * Every subject matches a real class; isAbstract() with no abstract
     * class is a conditional guard, not a vacuous rule.
     */
    #[Test]
    public function acceptsWhenEverySubjectIsLive(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE, self::ABSTRACT_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * modelIsALeaf's subject repointed at a trait-only namespace — the bug
     * this gate exists for: phpat never visits a trait, so the rule is a no-op.
     */
    #[Test]
    public function rejectsAnInNamespaceSubjectOnATraitOnlyNamespace(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE_ON_TRAITS, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * inNamespace(Model) with no class in Model at all.
     */
    #[Test]
    public function rejectsAnInNamespaceSubjectOnAnEmptyNamespace(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Model)');
    }

    /**
     * classname(Configuration) with no Configuration class.
     */
    #[Test]
    public function rejectsAClassnameSubjectWithNoSuchClass(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'classname(Vendor\Mod\Configuration)');
    }

    /**
     * A #[TestRule] method whose subject cannot be parsed fails closed.
     */
    #[Test]
    public function rejectsAnUnparseableSubject(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::BROKEN_RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * A classname subject targeting an ABSTRACT class discriminates the
     * abstract-class accepting branch of the inventory.
     */
    #[Test]
    public function acceptsAClassnameSubjectTargetingAnAbstractClass(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'AbstractNode.php', 'Vendor\Mod', 'abstract class', 'AbstractNode');
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::ABSTRACT_TARGET_RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A subject class written as `final readonly class` (value-object form).
     */
    #[Test]
    public function acceptsAClassnameSubjectOnAFinalReadonlyClass(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final readonly class', 'Configuration');
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A classname subject targeting an existing TRAIT — the wrong kind, not
     * an absent name.
     */
    #[Test]
    public function rejectsAClassnameSubjectOnATrait(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'trait', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'classname(Vendor\Mod\Configuration)');
    }

    /**
     * An interface is a live subject: PHPStan emits InClassNode for it, so phpat
     * enforces an inNamespace() rule on an interface-only namespace.
     */
    #[Test]
    public function acceptsAnInNamespaceSubjectOnAnInterfaceOnlyNamespace(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/NodeInterface.php', 'Vendor\Mod\Model', 'interface', 'NodeInterface');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * An enum is a live subject too — InClassNode fires for it as for a class.
     */
    #[Test]
    public function acceptsAnInNamespaceSubjectOnAnEnumOnlyNamespace(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/NodeKind.php', 'Vendor\Mod\Model', 'enum', 'NodeKind');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * classname() on an interface names a live subject; only a trait is vacuous.
     */
    #[Test]
    public function acceptsAClassnameSubjectOnAnInterface(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'interface', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * phpat's classes() is variadic. A live first selector must not carry an
     * unchecked, trait-only second one through, so a multi-selector call fails
     * closed rather than being read as its first argument alone.
     */
    #[Test]
    public function rejectsAMultiSelectorClassesCall(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(<<<'RULE'
                #[TestRule]
                public function modelAndTraitsAreLeaves(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'), Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('Model and Traits are leaves.');
                }
            RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'modelAndTraitsAreLeaves: could not identify a subject selector');
    }

    /**
     * An unhandled selector (Selector::implement) fails closed, distinctly.
     */
    #[Test]
    public function rejectsAnUnhandledSelector(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::UNKNOWN_SELECTOR_RULE);

        $this->assertGateRejects(self::gate(), $dir, 'unhandled subject selector Selector::implement');
    }

    /**
     * An unresolvable subject argument fails closed, distinctly.
     */
    #[Test]
    public function rejectsAnUnresolvableSubjectArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::UNRESOLVABLE_ARG_RULE);

        $this->assertGateRejects(self::gate(), $dir, 'could not resolve');
    }

    /**
     * `self::NAMESPACE_ROOT . self::CONST` is not silently resolved to the root.
     */
    #[Test]
    public function rejectsASubjectComposedWithAnotherConstant(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::COMPOSED_ARG_RULE);

        $this->assertGateRejects(self::gate(), $dir, 'could not resolve');
    }

    /**
     * A src/ file past the size cap says so. The arm once existed and could
     * not report: a later `$violations = []` discarded every entry the
     * inventory loop appended, so this fixture printed `OK`. The oversized
     * file sits behind the inNamespace() arm here.
     *
     * The must-not-carry half: $inventoryIncomplete has two sites, this one
     * and the unreadable arm, and dropping this site's flag alone made the
     * gate go on to print "matches no class" for a class the fixture defines.
     */
    #[Test]
    public function rejectsASrcFilePastTheSizeCapWithoutFabricatingAVacuousRule(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeFile(
            "{$dir}/src/Model/Node.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod\\Model;\n\n// " . str_repeat('x', 262145) . "\nfinal class Node\n{\n}\n",
        );
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, self::PAST_THE_CAP);
        self::assertOutputDoesNotContain(
            self::runGate($dir),
            'matches no class',
            'An oversized src/ file made the gate fabricate a vacuous-rule verdict.',
        );
    }

    /**
     * The companion in the OTHER direction: the oversized file IS the
     * classname() target, so classname()'s guard is exercised without the
     * chmod-based unreadable case, which is skipped under root.
     */
    #[Test]
    public function rejectsAnOversizedClassnameTargetWithoutFabricatingAVacuousRule(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeFile(
            "{$dir}/src/Configuration.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod;\n\n// " . str_repeat('x', 262145) . "\nfinal class Configuration\n{\n}\n",
        );
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, self::PAST_THE_CAP);
        self::assertOutputDoesNotContain(
            self::runGate($dir),
            'matches no class',
            'An oversized classname() target made the gate fabricate a vacuous-rule verdict.',
        );
    }

    /**
     * The safeReportValue() half of the size-cap arm. Oversize, not
     * unreadable — the one a pull request can actually produce: git carries
     * no mode bits, but a >256 KB file is one `git add`. `a?::error` is the
     * whole proof: the `?` is the newline this site has to have translated.
     */
    #[Test]
    public function reportIsInertForAnOversizedSrcFileNamedToCarryControlCharacters(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeFile("{$dir}/src/a\n::error title=x::forged.php", "<?php\n// " . str_repeat('x', 262145));
        self::writeArchTest($dir, self::CONFIG_RULE);

        $this->assertGateReportIsInert(self::gateFromInside(), $dir, 'a?::error');
    }

    /**
     * An ArchitectureTest past the size cap: the gate did not run, exit 2.
     */
    #[Test]
    public function refusesAnArchitectureTestPastTheSizeCap(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeFile(self::archTestPath($dir), "<?php\n\n// " . str_repeat('x', 262145) . "\n");

        $this->assertGateUsageError(self::gate(), $dir, self::PAST_THE_CAP);
    }

    /**
     * An unreadable src/ file leaves the inventory SHORT, and the liveness
     * arms can then only answer "not found". Before the guard this reported
     * the read failure AND a vacuous rule for a class that plainly exists.
     * Both targets are unreadable, so both liveness arms are driven.
     */
    #[Test]
    public function rejectsAnUnreadableSrcFileWithoutFabricatingAVacuousRule(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));
        self::makeUnreadable("{$dir}/src/Model/Node.php");
        self::makeUnreadable("{$dir}/src/Configuration.php");

        $this->assertGateRejects(self::gate(), $dir, 'cannot be read, so its classes are not in the inventory');
        self::assertOutputDoesNotContain(
            self::runGate($dir),
            'matches no class',
            'An unreadable src/ file made the gate fabricate a vacuous-rule verdict.',
        );
    }

    /**
     * A filename is the widest byte domain the gate reports, and a pull
     * request chooses it.
     */
    #[Test]
    public function reportIsInertForAnUnreadableSrcFileNamedToCarryControlCharacters(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::CONFIG_RULE);
        $poisoned = "{$dir}/src/a\n::error title=x::forged ##[error]legacy \e[2K\rcr.php";
        self::writeFile($poisoned, "<?php\n");
        self::makeUnreadable($poisoned);

        $this->assertGateReportIsInert(self::gateFromInside(), $dir, 'a?::error');
    }

    /**
     * An unreadable ArchitectureTest is the exit-2 half, and the report must
     * say which of the two (unreadable, oversized) happened.
     */
    #[Test]
    public function refusesAnUnreadableArchitectureTest(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeFile(self::archTestPath($dir), "<?php\n");
        self::makeUnreadable(self::archTestPath($dir));

        $this->assertGateUsageError(self::gate(), $dir, 'cannot be read');
    }

    /**
     * An ArchitectureTest with no rule method under either discovery path.
     */
    #[Test]
    public function rejectsAnArchitectureTestWithNoRuleMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::NON_RULE_METHOD);

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * An ArchitectureTest but no src/ directory: exit 2.
     */
    #[Test]
    public function refusesAnArchitectureTestWithoutASrcDirectory(): void
    {
        $dir = $this->fixture()->path();
        self::writeArchTest($dir, self::MODEL_RULE);

        $this->assertGateUsageError(self::gate(), $dir, 'no src/ directory');
    }

    /**
     * Not ported from the bash original, which never drove it: a root
     * argument that is not a directory at all is the other exit-2 path.
     */
    #[Test]
    public function refusesARootThatIsNotADirectory(): void
    {
        $this->assertGateUsageError(self::gate(), $this->fixture()->path() . '/missing', 'Not a directory');
    }

    /**
     * A malformed rule must not adopt a following helper's selector. Model/
     * HAS a class, so a search leaking into buildRule() would wrongly accept.
     */
    #[Test]
    public function rejectsAMalformedRuleWithoutAdoptingAHelpersSelector(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::MALFORMED_WITH_HELPER);

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * A commented-out #[TestRule] example with a VACUOUS subject is ignored —
     * the shipped template carries such an example.
     */
    #[Test]
    public function acceptsACommentedOutRuleExample(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE, <<<'RULE'
                // Example — one #[TestRule] per boundary:
                //
                // #[TestRule]
                // public function exampleRule(): Rule
                // {
                //     return PHPat::rule()
                //         ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\DoesNotExist'))
                //         ->shouldNot()->dependOn()
                //         ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                //         ->because('Example.');
                // }
            RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A commented-out class is not counted in the inventory: Model/ holds only
     * a file whose class is inside a block comment.
     */
    #[Test]
    public function rejectsWhenTheOnlyClassInANamespaceIsCommentedOut(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeFile("{$dir}/src/Model/Node.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Mod\Model;

            /*
            final class Node
            {
            }
            */

            PHP);
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Model)');
    }

    /**
     * No ArchitectureTest at all: nothing to check.
     */
    #[Test]
    public function acceptsARepositoryWithoutAnArchitectureTest(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The second conventional location: tests/ArchitectureTest.php is read when
     * tests/Architecture/ArchitectureTest.php is absent. A vacuous rule there must
     * red the run — the skip for "no ArchitectureTest found" would hide it.
     */
    #[Test]
    public function rejectsAVacuousRuleInTheFlatArchitectureTestLocation(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE_ON_TRAITS, self::CONFIG_RULE));
        self::assertTrue(
            rename(self::archTestPath($dir), "{$dir}/tests/ArchitectureTest.php"),
            'Could not move the ArchitectureTest to the flat location.',
        );

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Traits) matches no class');
    }

    /**
     * A PHP identifier may carry bytes above 0x7F. With `\w+` and no `/u` the
     * head pattern stopped at the first such byte, so a rule named this way
     * was skipped in silence.
     */
    #[Test]
    public function rejectsARuleMethodNamedWithANonAsciiIdentifier(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function prüfeSchichten(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Model\Node'))
                        ->because('Injected.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * The bounding twin: the subject search stops at the NEXT `function`
     * declaration, and a helper whose name carries a non-ASCII byte must
     * still be such a bound — otherwise the search adopts its selector.
     */
    #[Test]
    public function rejectsAMalformedRuleWithoutAdoptingANonAsciiNamedHelpersSelector(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function malformed(): Rule
                {
                    return PHPat::rule()
                }

                private function hälfer(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Model\Node'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Model\Node'))
                        ->because('Helper.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * Not ported from the bash original, which predates it: the shipped
     * templates/ArchitectureTest.php, copied verbatim, is accepted once its
     * leafClassesAreFinal() subject namespace holds a class — so the template
     * and the gate cannot drift into a shape the gate cannot classify.
     */
    #[Test]
    public function acceptsTheShippedTemplateWhenItsSubjectNamespaceHoldsAClass(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Package\Model', 'final class', 'Node');
        self::copyTemplate($dir);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The template's other direction: with only a trait in its subject
     * namespace, the shipped leafClassesAreFinal() rule is the manifested
     * vacuous rule, and the gate says so by name.
     */
    #[Test]
    public function rejectsTheShippedTemplateWhenItsSubjectNamespaceHoldsOnlyATrait(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/NodeTrait.php', 'Vendor\Package\Model', 'trait', 'NodeTrait');
        self::copyTemplate($dir);

        $this->assertGateRejects(self::gate(), $dir, 'leafClassesAreFinal: subject inNamespace(Vendor\Package\Model) matches no class');
    }

    /**
     * Copies templates/ArchitectureTest.php to where a consumer puts it.
     *
     * @param string $dir The case directory.
     *
     * @return void
     */
    private static function copyTemplate(string $dir): void
    {
        self::makeDirectory("{$dir}/tests/Architecture");
        self::assertTrue(copy(self::root() . '/templates/ArchitectureTest.php', self::archTestPath($dir)), 'Could not copy the template.');
    }
}
