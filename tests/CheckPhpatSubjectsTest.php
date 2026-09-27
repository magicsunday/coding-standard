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
 * guard. The GH-190 cases drive the selector-expression evaluator over one
 * shared src/ tree (compositeFixture()): every composite, regex and
 * supertype selector from both sides, each semantic the gate replicates
 * from phpat, and the expression shapes that must fail closed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatSubjectsTest extends AbstractPhpatSubjectsTestCase
{
    /**
     * A classes() call with two subject selectors, Model and Traits.
     */
    private const string MULTI_SELECTOR_RULE = <<<'RULE'
            #[TestRule]
            public function modelAndTraitsAreLeaves(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'), Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Model and Traits are leaves.');
            }
        RULE;

    /**
     * The ArchitectureTest imports of the `Foo::class` cases: the plain
     * TestRule import plus two class aliases, one of them naming a class src/
     * does not declare.
     */
    private const string ALIAS_IMPORTS = <<<'PHP'
        use PHPat\Test\Attributes\TestRule;
        use Vendor\Mod\Service\GithubProvider as Gh;
        use Vendor\Mod\Service\Removed as Gone;
        PHP;

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
     * phpat's classes() is variadic, and its StatementBuilder makes every
     * subject selector a statement of its own — so a live first selector must
     * not carry a trait-only second one through: the vacuous argument is
     * reported by position.
     */
    #[Test]
    public function rejectsAMultiSelectorClassesCallWithOneVacuousArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MULTI_SELECTOR_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(
            self::gate(),
            $dir,
            'modelAndTraitsAreLeaves: classes() argument 2 of 2: subject inNamespace(Vendor\Mod\Traits) matches no class',
        );
    }

    /**
     * The accepting twin: once the second namespace holds a class too, every
     * argument of the multi-selector call is live.
     */
    #[Test]
    public function acceptsAMultiSelectorClassesCallWhoseEveryArgumentIsLive(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Traits/Registry.php', 'Vendor\Mod\Traits', 'final class', 'Registry');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MULTI_SELECTOR_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
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
     * The obituary-matcher shape (GH-190): an AllOf() narrowing the module
     * root with Not() exclusions — live while one class survives them all.
     */
    #[Test]
    public function acceptsAnAllOfSubjectNarrowedByNotExclusions(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::inNamespace(self::NAMESPACE_ROOT),
                Selector::Not(Selector::classname('#Repository$#', true)),
                Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Contract')),
            )
            SUBJECT));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Each operand of the AllOf() is live on its own, their intersection is
     * not — the composite is judged as a set, not operand by operand.
     */
    #[Test]
    public function rejectsAnAllOfSubjectWhoseIntersectionIsEmpty(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'),
                Selector::Not(Selector::classname('#Repository$#', true)),
            )
            SUBJECT));

        $this->assertGateRejects(
            self::gate(),
            $dir,
            'composite: subject AllOf(inNamespace(Vendor\Mod\Repository), Not(classname(#Repository$#, regex))) matches no class',
        );
    }

    /**
     * AnyOf() is a union: one live branch makes the subject live, even beside
     * a trait-only one.
     */
    #[Test]
    public function acceptsAnAnyOfSubjectWithOneLiveBranch(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(
            "Selector::AnyOf(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Traits'), Selector::classname(self::NAMESPACE_ROOT . '\\Service\\PlainService'))",
        ));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * AnyOf() over branches that are all empty selects nothing.
     */
    #[Test]
    public function rejectsAnAnyOfSubjectWhoseEveryBranchIsEmpty(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(
            "Selector::AnyOf(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Traits'), Selector::inNamespace(self::NAMESPACE_ROOT . '\\Nowhere'))",
        ));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject AnyOf(inNamespace(Vendor\Mod\Traits), inNamespace(Vendor\Mod\Nowhere)) matches no class');
    }

    /**
     * Not() is the complement against every class phpat can see (measured:
     * every class, interface and enum outside the operand, never a trait) —
     * here every src/ declaration lives under the root, so it is empty.
     */
    #[Test]
    public function rejectsANotSubjectWhoseComplementIsEmpty(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT))'));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject Not(inNamespace(Vendor\Mod)) matches no class');
    }

    /**
     * The accepting twin of the complement: NoneOf() over two predicates
     * still leaves the concrete classes.
     */
    #[Test]
    public function acceptsANoneOfSubjectWithANonEmptyComplement(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::NoneOf(Selector::isInterface(), Selector::isEnum())'));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Nested composites evaluate inside out: AnyOf() inside AllOf() inside
     * Not() inside AllOf() — one class (PlainService) survives.
     */
    #[Test]
    public function acceptsANestedCompositeSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::AnyOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\\Service'),
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\\Traits'),
                ),
                Selector::Not(Selector::AllOf(Selector::classname('/Provider$/', true), Selector::Not(Selector::isInterface()))),
            )
            SUBJECT));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The nested twin: one more exclusion removes the last surviving class.
     */
    #[Test]
    public function rejectsANestedCompositeSubjectThatSelectsNothing(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Service'),
                Selector::Not(Selector::AnyOf(
                    Selector::classname('/Provider$/', true),
                    Selector::classname(self::NAMESPACE_ROOT . '\\Service\\PlainService'),
                )),
            )
            SUBJECT));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject AllOf(inNamespace(Vendor\Mod\Service), Not(AnyOf(');
    }

    /**
     * phpat runs a classname() regex against the FQCN (measured: an anchored
     * short name matched nothing), so a FQCN-shaped pattern is live….
     */
    #[Test]
    public function acceptsAClassnameRegexMatchedAgainstTheFullyQualifiedName(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::classname('/^Vendor.Mod.Service.GithubProvider$/', true)"));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * …and a pattern anchored on the short name selects nothing.
     */
    #[Test]
    public function rejectsAClassnameRegexAnchoredOnTheShortName(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::classname('/^GithubProvider$/', true)"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject classname(/^GithubProvider$/, regex) matches no class');
    }

    /**
     * An inNamespace() regex runs against the declaration's NAMESPACE, not
     * its FQCN: `Service$` matches the namespace Vendor\Mod\Service….
     */
    #[Test]
    public function acceptsAnInNamespaceRegexMatchedAgainstTheNamespace(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::inNamespace('/Service$/', true)"));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * …while a pattern only a class name satisfies selects nothing.
     */
    #[Test]
    public function rejectsAnInNamespaceRegexThatOnlyAClassNameSatisfies(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::inNamespace('/PlainService$/', true)"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject inNamespace(/PlainService$/, regex) matches no class');
    }

    /**
     * A class is not inside a namespace named after itself — phpat compares
     * the class's namespace (measured: inNamespace('A\Plain') matched
     * nothing for class A\Plain). The gate once accepted this subject.
     */
    #[Test]
    public function rejectsAnInNamespaceSubjectNamingAClassRatherThanANamespace(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::inNamespace(self::NAMESPACE_ROOT . '\\Service\\PlainService')"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject inNamespace(Vendor\Mod\Service\PlainService) matches no class');
    }

    /**
     * A regex PHP cannot compile fails closed, rather than PHP's own warning
     * leaking ahead of the report and the selector reading as empty.
     */
    #[Test]
    public function rejectsARegexThatDoesNotCompile(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::classname('/unterminated', true)"));

        $this->assertGateRejects(self::gate(), $dir, 'the classname() regular expression `/unterminated` does not compile');
    }

    /**
     * implements() is transitive, as phpat's is (measured): GithubProvider
     * reaches Contract\Provider only through its abstract parent, which
     * implements SpecialProvider — through a src/ alias import — which
     * extends Provider.
     */
    #[Test]
    public function acceptsAnImplementsSubjectReachedOnlyTransitively(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::implements(self::NAMESPACE_ROOT . '\\Contract\\Provider'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Service\\GithubProvider'),
            )
            SUBJECT));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * An interface does not implement itself (measured: implements(I1) did
     * not match I1), so this intersection is empty.
     */
    #[Test]
    public function rejectsAnImplementsSubjectOnlyTheInterfaceItselfWouldSatisfy(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(<<<'SUBJECT'
            Selector::AllOf(
                Selector::implements(self::NAMESPACE_ROOT . '\\Contract\\Provider'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Contract\\Provider'),
            )
            SUBJECT));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject AllOf(implements(Vendor\Mod\Contract\Provider), classname(');
    }

    /**
     * implements() of a CLASS selects nothing — only interface names count.
     */
    #[Test]
    public function rejectsAnImplementsSubjectNamingAClass(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::implements(self::NAMESPACE_ROOT . '\\Service\\AbstractProvider')"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject implements(Vendor\Mod\Service\AbstractProvider) matches no class');
    }

    /**
     * extends() matches every descendant, transitively (measured) ….
     */
    #[Test]
    public function acceptsAnExtendsSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::extends(self::NAMESPACE_ROOT . '\\Service\\AbstractProvider')"));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * … and nothing extends a final leaf.
     */
    #[Test]
    public function rejectsAnExtendsSubjectNoClassExtends(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::extends(self::NAMESPACE_ROOT . '\\Service\\GithubProvider')"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject extends(Vendor\Mod\Service\GithubProvider) matches no class');
    }

    /**
     * `Foo::class` resolves through the ArchitectureTest's own `use` imports,
     * alias included, the way PHP binds it.
     */
    #[Test]
    public function acceptsAClassnameSubjectWrittenAsAnAliasedClassConstant(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::classname(Gh::class)'), self::ALIAS_IMPORTS);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * An aliased import of a class src/ does not declare is vacuous, and the
     * report names the resolved FQCN, not the alias.
     */
    #[Test]
    public function rejectsAnAliasedClassConstantNamingNoClass(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::classname(Gone::class)'), self::ALIAS_IMPORTS);

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject classname(Vendor\Mod\Service\Removed) matches no class');
    }

    /**
     * An unimported `Foo::class` binds to the ArchitectureTest's OWN
     * namespace — PHP never falls back to the global namespace for a class.
     */
    #[Test]
    public function rejectsAnUnimportedClassConstantResolvedAgainstTheTestNamespace(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::classname(GithubProvider::class)'));

        $this->assertGateRejects(self::gate(), $dir, 'classname(Vendor\Mod\Test\Architecture\GithubProvider) matches no class');
    }

    /**
     * The module-updater shape: Not(isInterface()) over a namespace that
     * holds interfaces only is vacuous.
     */
    #[Test]
    public function rejectsANotIsInterfaceSubjectOverAnInterfaceOnlyNamespace(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(
            "Selector::AllOf(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Contract'), Selector::Not(Selector::isInterface()))",
        ));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject AllOf(inNamespace(Vendor\Mod\Contract), Not(isInterface())) matches no class');
    }

    /**
     * isEnum() selects an enum, backed or not.
     */
    #[Test]
    public function acceptsAnIsEnumSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::AllOf(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'), Selector::isEnum())"));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Inside a composite, isAbstract() is an ordinary set — only the bare
     * top-level form is the unchecked conditional guard.
     */
    #[Test]
    public function rejectsAnIsAbstractOperandThatSelectsNothing(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::AllOf(Selector::isAbstract(), Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'))"));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject AllOf(isAbstract(), inNamespace(Vendor\Mod\Repository)) matches no class');
    }

    /**
     * isTrait() can never select anything phpat checks: it never visits a
     * trait at all.
     */
    #[Test]
    public function rejectsAnIsTraitSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::isTrait()'));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject isTrait() matches no class');
    }

    /**
     * all() selects every class phpat can see.
     */
    #[Test]
    public function acceptsAnAllSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::all()'));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * `->excluding(…)` is deliberately not evaluated: a subject its
     * exclusions narrow to nothing is a conditional guard, like a bare
     * isAbstract() — the module-updater's contractsAreAbstract() rule.
     */
    #[Test]
    public function acceptsASubjectItsExclusionsNarrowToNothing(): void
    {
        $dir = $this->compositeFixture(<<<'RULE'
                #[TestRule]
                public function contractsAreAbstract(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Contract'))
                        ->excluding(Selector::isInterface(), Selector::isTrait())
                        ->should()->beAbstract()
                        ->because('Contracts are abstract.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * An unknown selector nested inside a composite still fails closed —
     * isFinal() also honours a `@final` tag, which this gate does not model.
     */
    #[Test]
    public function rejectsAnUnhandledSelectorNestedInAComposite(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::AllOf(Selector::inNamespace(self::NAMESPACE_ROOT), Selector::isFinal())'));

        $this->assertGateRejects(self::gate(), $dir, 'composite: unhandled subject selector Selector::isFinal()');
    }

    /**
     * A composite operand that is not a selector call cannot be evaluated.
     */
    #[Test]
    public function rejectsACompositeOperandThatIsNotASelectorCall(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::AllOf(Selector::all(), $this->more())'));

        $this->assertGateRejects(self::gate(), $dir, 'composite: could not resolve the AllOf() argument `$this->more()`');
    }

    /**
     * Not() declares one parameter and PHP drops the rest silently, so a
     * second operand is refused rather than read.
     */
    #[Test]
    public function rejectsANotWithTwoOperands(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::Not(Selector::isInterface(), Selector::isEnum())'));

        $this->assertGateRejects(self::gate(), $dir, 'Selector::Not() takes exactly one selector, not 2 argument(s)');
    }

    /**
     * A named argument is a shape the gate does not read.
     */
    #[Test]
    public function rejectsANamedArgument(): void
    {
        $dir = $this->compositeFixture(self::subjectRule("Selector::classname('/Provider$/', regex: true)"));

        $this->assertGateRejects(self::gate(), $dir, 'classname() takes a named argument, which this gate does not read');
    }

    /**
     * An expression nested past MAX_SELECTOR_DEPTH fails closed instead of
     * recursing until PHP's own stack guard kills the run with exit 255.
     */
    #[Test]
    public function rejectsASubjectNestedDeeperThanTheCap(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(str_repeat('Selector::Not(', 40) . 'Selector::all()' . str_repeat(')', 40)));

        $this->assertGateRejects(self::gate(), $dir, 'the expression nests deeper than 32 selector calls');
    }

    /**
     * The cap's other side: 31 Not()s around all() is 32 calls deep, and an
     * odd number of complements leaves the complement of all() — empty.
     */
    #[Test]
    public function evaluatesASubjectNestedExactlyToTheCap(): void
    {
        $dir = $this->compositeFixture(self::subjectRule(str_repeat('Selector::Not(', 31) . 'Selector::all()' . str_repeat(')', 31)));

        $this->assertGateRejects(self::gate(), $dir, 'composite: subject Not(Not(');
    }

    /**
     * A `->classes(` whose parenthesis never closes (a brace-balanced body
     * that PHP itself would refuse) fails closed.
     */
    #[Test]
    public function rejectsAnUnclosedClassesCall(): void
    {
        $dir = $this->compositeFixture(<<<'RULE'
                #[TestRule]
                public function composite(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::AllOf(Selector::all())
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname('Vendor\Mod\Nowhere'))
                        ->because('Unclosed.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'composite: could not identify a subject selector');
    }

    /**
     * A mismatched bracket inside the argument list abandons the pairing, so
     * the parser fails closed rather than guessing where the call ends.
     */
    #[Test]
    public function rejectsAMismatchedBracketInsideTheSubject(): void
    {
        $dir = $this->compositeFixture(self::subjectRule('Selector::AllOf(Selector::all(])'));

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * A rule whose subject is $subject, written verbatim into ->classes().
     *
     * @param string $subject The subject selector expression.
     *
     * @return string The rule-method body, named composite().
     */
    private static function subjectRule(string $subject): string
    {
        return <<<RULE
                #[TestRule]
                public function composite(): Rule
                {
                    return PHPat::rule()
                        ->classes({$subject})
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname('Vendor\\Mod\\Nowhere'))
                        ->because('Composite subject.');
                }
            RULE;
    }

    /**
     * The src/ tree the selector-expression cases (GH-190) share, plus an
     * ArchitectureTest around $rules:
     *
     *   - Contract\Provider, and Contract\SpecialProvider extending it
     *     (interfaces only — the namespace holds no class);
     *   - Service\AbstractProvider, implementing SpecialProvider through a
     *     src/ ALIAS import; Service\GithubProvider extending it (so it
     *     reaches Provider only transitively); Service\PlainService;
     *   - Repository\NodeRepository; Model\Kind, a backed enum;
     *     Traits\HelperTrait, a trait phpat never visits.
     *
     * @param string $rules      The rule-method block.
     * @param string $importLine The ArchitectureTest's import line(s), if not the plain TestRule one.
     *
     * @return string The case directory.
     */
    private function compositeFixture(string $rules, string $importLine = ''): string
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Contract/Provider.php', 'Vendor\Mod\Contract', 'interface', 'Provider');
        self::writeClass($dir, 'Contract/SpecialProvider.php', 'Vendor\Mod\Contract', 'interface', 'SpecialProvider extends Provider');
        self::writeFile("{$dir}/src/Service/AbstractProvider.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Mod\Service;

            use Vendor\Mod\Contract\SpecialProvider as Special;

            abstract class AbstractProvider implements Special
            {
            }

            PHP);
        self::writeClass($dir, 'Service/GithubProvider.php', 'Vendor\Mod\Service', 'final class', 'GithubProvider extends AbstractProvider');
        self::writeClass($dir, 'Service/PlainService.php', 'Vendor\Mod\Service', 'final class', 'PlainService');
        self::writeClass($dir, 'Repository/NodeRepository.php', 'Vendor\Mod\Repository', 'final class', 'NodeRepository');
        self::writeClass($dir, 'Model/Kind.php', 'Vendor\Mod\Model', 'enum', 'Kind: string');
        self::writeClass($dir, 'Traits/HelperTrait.php', 'Vendor\Mod\Traits', 'trait', 'HelperTrait');
        self::writeArchTest($dir, $rules, '', $importLine);

        return $dir;
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
