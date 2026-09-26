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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function str_replace;

/**
 * Rule discovery in the phpat subject-liveness guard,
 * bin/check-phpat-subjects.php (#184): phpat's SECOND discovery path — a
 * public test*-named method, no attribute needed (GH-58) — the visibility
 * and nesting rules both paths share, and the tracking of every import
 * spelling that makes some other name the TestRule attribute — see
 * AbstractPhpatSubjectsTestCase for how the ported suite is split.
 *
 * phpat's TestParser reflects `getMethods(IS_PUBLIC)` on the one extracted
 * ArchitectureTest class and accepts a method carrying `#[TestRule]` (by
 * FQCN, so any alias and any casing) OR one matching its case-sensitive
 * `/^(test)…/` — re-derive with
 * `grep -n 'getMethods\|getAttributes\|preg_match' tests/consumer/.build/vendor/phpat/phpat/src/Test/TestParser.php`.
 * Every case here holds the gate to that same definition in one direction
 * or the other.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatSubjectsDiscoveryTest extends AbstractPhpatSubjectsTestCase
{
    /**
     * A trait whose one method a class-body `use` adapts.
     */
    private const string HELPER_TRAIT = <<<'PHP'
        trait Helper
        {
            public function someMethod(): void
            {
            }
        }
        PHP;

    /**
     * A test-prefixed rule with a vacuous subject, mixed in the SAME file with
     * an attributed one: the vacuous one is flagged, and mixing the two styles
     * hides neither.
     */
    #[Test]
    public function rejectsForItsSubjectATestNamedRuleMixedWithAnAttributedOne(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::testNamedRuleOnTraits(), self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * A standalone test-prefixed rule with a live subject.
     */
    #[Test]
    public function acceptsATestNamedRuleWithALiveSubject(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::testNamedRuleLive());

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A PRIVATE test-prefixed method is not a rule; picked up, its body (no
     * selector at all) would fail closed and flip this to a reject.
     */
    #[Test]
    public function acceptsWithAPrivateTestNamedHelperIgnored(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::testNamedRuleLive(), self::TEST_NAMED_PRIVATE_HELPER));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The same PUBLIC-only filter applies to the ATTRIBUTE path. The ignored
     * rule's subject is deliberately vacuous, so dropping the visibility
     * guard flips this to a reject.
     */
    #[Test]
    public function acceptsWithAProtectedAttributedMethodIgnored(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::CONFIG_RULE, str_replace(
            ['public function modelIsALeaf', 'Model is a leaf.'],
            ['protected function protectedRuleIsIgnored', 'Should never run.'],
            self::MODEL_RULE_ON_TRAITS,
        )));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A test*-named method NESTED inside an anonymous class within a rule's
     * body is as invisible to phpat as a private one.
     */
    #[Test]
    public function acceptsWithATestNamedMethodNestedInAnAnonymousClassIgnored(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                public function testConfigurationIsALeaf(): Rule
                {
                    $probe = new class {
                        public function testShouldNotBeARule(): string
                        {
                            return 'nested, not a rule';
                        }
                    };

                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->because('Configuration is a leaf.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * phpat's regex is case-sensitive: a `Test…`-named method does not
     * qualify. Standalone and live, so a case-insensitive match would flip
     * this to an accept.
     */
    #[Test]
    public function rejectsWhenTheOnlyCandidateIsPascalCaseTestNamed(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                public function TestConfigurationIsALeaf(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->because('Configuration is a leaf.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * Trait-conflict-resolution syntax (`use Helper { m as private; }`) emits
     * a bare T_PRIVATE with no declaration of its own; a forward-carried
     * "non-public" flag would mark the REAL rule after it non-public and hide
     * its vacuous subject.
     */
    #[Test]
    public function rejectsForItsSubjectARuleAfterATraitAdaptationToPrivate(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest(
            $dir,
            self::methods('    use Helper { someMethod as private; }', self::MODEL_RULE_ON_TRAITS),
            self::HELPER_TRAIT,
        );

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * `private static function test*`: the backward modifier scan meets
     * `static` FIRST, and only continuing past it reaches `private`.
     * Standalone, so it can only pass if the method is genuinely excluded.
     */
    #[Test]
    public function rejectsWhenTheOnlyCandidateIsAPrivateStaticTestNamedMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::asModifierVariant(self::testNamedRuleLive(), 'private static'));

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * The same ordering argument for T_FINAL: `protected final function`.
     */
    #[Test]
    public function rejectsWhenTheOnlyCandidateIsAProtectedFinalTestNamedMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::asModifierVariant(self::testNamedRuleLive(), 'protected final'));

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * The file-wide $topDepth counter must balance across both interpolation
     * openers, or a desync inside one rule offsets every rule found after it.
     */
    #[Test]
    public function rejectsForItsSubjectARuleAfterAnEarlierOneWithInterpolation(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::INTERPOLATING_LIVE_RULE, self::MODEL_RULE_ON_TRAITS));

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * The same ordering argument for T_ABSTRACT: a body-less `protected
     * abstract function test*`. Without the arm this still rejects, but for
     * the wrong reason — the exact substring is what discriminates.
     */
    #[Test]
    public function rejectsWhenTheOnlyCandidateIsAProtectedAbstractTestNamedMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, '    protected abstract function testConfigurationIsALeaf(): Rule;');

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * Return-by-reference inserts a token the name lookahead must skip. This
     * standalone shape only proves the rule is analysed at all; the mixed
     * case below is the one that discriminates a silent OK.
     */
    #[Test]
    public function rejectsForItsSubjectAReturnByReferenceTestNamedRule(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::RETURN_BY_REFERENCE_RULE);

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * Beside a LIVE attributed rule, a return-by-reference rule the lookahead
     * missed would vanish silently while the live rule satisfies every other
     * check — the gate printed OK before the fix.
     */
    #[Test]
    public function rejectsForItsSubjectAReturnByReferenceRuleMixedWithALiveRule(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Traits/ModuleTrait.php', 'Vendor\Mod\Traits', 'trait', 'ModuleTrait');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::CONFIG_RULE, self::RETURN_BY_REFERENCE_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Traits) matches no class');
    }

    /**
     * A #[TestRule] nested in an anonymous class inside a live rule's body
     * was once counted as resolved, so the misattachment check never fired.
     * It must reject as a misattachment (2 attributes found, 1 resolved).
     *
     * The second assertion pins the documented subject MISATTRIBUTION for
     * this shape (the nested rule's subject reported under the enclosing
     * rule's name) — a naming defect, not a fail-open one, pinned so a change
     * to the extraction logic cannot alter it unnoticed.
     */
    #[Test]
    public function rejectsANestedTestRuleWithoutCountingItAsResolved(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function live(): Rule
                {
                    $probe = new class {
                        #[TestRule]
                        public function nestedVacuousRule(): Rule
                        {
                            return PHPat::rule()
                                ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
                                ->shouldNot()->dependOn()
                                ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                                ->because('Vacuous — must never be silently counted as resolved.');
                        }
                    };

                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('Model is a leaf.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'attribute(s) found but only');
        self::assertOutputContains(
            self::runGate($dir),
            'live: subject ' . self::NO_SUCH_NAMESPACE,
            'The documented subject-misattribution for a nested #[TestRule] changed shape — update the comment at the subject-extraction site.',
        );
    }

    /**
     * Every import spelling that makes another local name the TestRule
     * attribute. Each row pairs a MODEL_RULE with one rule attributed through
     * that name whose subject is vacuous; an untracked alias leaves it
     * uninspected while the live rule keeps the run green.
     *
     * @return array<string, array{string, string, string, string, string}> The attribute, method, return type, because() message and import line (empty: the plain TestRule import).
     */
    public static function trackedAliasProvider(): array
    {
        return [
            // `use …\TestRule as Rule2;` — PHP resolves the alias, and phpat
            // filters by FQCN, not by the literal text `TestRule`.
            'a single import alias' => [
                'Rule2', 'aliasedVacuousRule', 'Rule',
                'Vacuous — must never hide behind an import alias.',
                'use PHPat\Test\Attributes\TestRule as Rule2;',
            ],
            // The scan once broke at the first `,` of a multi-import.
            'an alias second on a comma-separated use line' => [
                'Rule3', 'commaAliasedVacuousRule', 'RuleX',
                'Vacuous — must never hide behind a comma-separated import.',
                'use PHPat\Test\Builder\Rule as RuleX, PHPat\Test\Attributes\TestRule as Rule3;',
            ],
            // The scan once captured the group PREFIX and never descended.
            'an alias inside a brace-grouped import' => [
                'Rule4', 'groupedAliasedVacuousRule', 'Rule',
                'Vacuous — must never hide behind a grouped import.',
                'use PHPat\Test\Attributes\{TestRule as Rule4};',
            ],
            // PHP resolves an attribute reference case-insensitively.
            'the attribute written in a different case' => [
                'testrule', 'lowercaseAttributeVacuousRule', 'Rule',
                'Vacuous — must never hide behind a differently-cased attribute.',
                '',
            ],
            // …and the IMPORTED name too.
            'the imported name spelled in a different case' => [
                'Rule5', 'lowercaseImportAliasedVacuousRule', 'Rule',
                'Vacuous — must never hide behind a differently-cased import.',
                'use phpat\test\attributes\testrule as Rule5;',
            ],
            // A per-item `function` keyword must not leak past its own `,`
            // and poison a real class alias later in the same group.
            'a class alias after a function item in the same group' => [
                'X', 'vacuousAliasedRule', 'Rule',
                'Vacuous — a function item earlier in the group must not poison this one.',
                'use PHPat\Test\Attributes\{function helperFn, TestRule as X};',
            ],
            // A bare, unqualified import — the exact-match branch rather than
            // the `…\TestRule` suffix branch every qualified import takes.
            'a bare, unqualified import alias' => [
                'X', 'bareAliasedVacuousRule', 'Rule',
                'Vacuous — must never hide behind a bare, unqualified import.',
                'use TestRule as X;',
            ],
            // A zero-newline comment stripped to nothing glued `as` and the
            // alias into one token (`asAlias`), losing T_AS entirely.
            'a same-line comment between as and the alias' => [
                'Alias', 'hidden', 'Rule',
                'Vacuous — must never hide behind a same-line comment in the alias.',
                'use PHPat\Test\Attributes\TestRule as/**/Alias;',
            ],
        ];
    }

    /**
     * @param string $attribute  The attribute token the vacuous rule carries.
     * @param string $method     The vacuous rule's method name.
     * @param string $returnType Its declared return type.
     * @param string $because    Its because() message.
     * @param string $importLine The line replacing the plain TestRule import, or empty.
     */
    #[Test]
    #[DataProvider('trackedAliasProvider')]
    public function rejectsForItsSubjectARuleAttributedThroughATrackedAlias(
        string $attribute,
        string $method,
        string $returnType,
        string $because,
        string $importLine,
    ): void {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest(
            $dir,
            self::methods(self::MODEL_RULE, self::vacuousAliasRule($attribute, $method, $returnType, $because)),
            '',
            $importLine,
        );

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUCH_NAMESPACE);
    }

    /**
     * The mirror direction: an import that aliases a FUNCTION or CONSTANT
     * named TestRule — a different symbol table — never makes `#[X]` the
     * class attribute. Tracking it would report a method phpat never runs.
     *
     * @return array<string, array{string, string}> The because() message and the import line.
     */
    public static function untrackedImportProvider(): array
    {
        return [
            // A per-item `function` inside a group.
            'a function item in a group' => [
                'Not a real TestRule — X aliases a FUNCTION import, not a class.',
                'use PHPat\Test\Attributes\{function TestRule as X};',
            ],
            // A declaration-level `use function` applies to EVERY item of the
            // group; the per-item flag was once reset at `{`.
            'a declaration-level use-function group' => [
                'Not a real TestRule — X aliases a FUNCTION import, not a class.',
                'use function PHPat\Test\Attributes\{TestRule as X};',
            ],
            // …and to an unbraced list; the flag was once reset at every `,`.
            'a declaration-level use-function list' => [
                'Not a real TestRule — X aliases a FUNCTION import, not a class.',
                'use function PHPat\Test\Attributes\bar, PHPat\Test\Attributes\TestRule as X;',
            ],
            // The T_CONST disjunct: a third symbol table.
            'a declaration-level use-const group' => [
                'Not a real TestRule — X aliases a CONST import, not a class.',
                'use const PHPat\Test\Attributes\{TestRule as X};',
            ],
        ];
    }

    /**
     * @param string $because    The not-a-rule method's because() message.
     * @param string $importLine The line replacing the plain TestRule import.
     */
    #[Test]
    #[DataProvider('untrackedImportProvider')]
    public function acceptsWithAMethodAttributedThroughAFunctionOrConstImportIgnored(string $because, string $importLine): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest(
            $dir,
            self::methods(self::MODEL_RULE, self::vacuousAliasRule('X', 'notARealRule', 'Rule', $because)),
            '',
            $importLine,
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The `$depth === 0` bound on import recognition: a trait adaptation
     * `use Helper { TestRule as X; }` INSIDE the class body tokenises exactly
     * like a grouped import, yet renames a trait method, never an attribute.
     */
    #[Test]
    public function acceptsWithATraitAdaptationRenamingATestRuleMethodIgnored(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest(
            $dir,
            self::methods(
                '    use Helper { TestRule as X; }',
                self::MODEL_RULE,
                self::vacuousAliasRule('X', 'notARealRule', 'Rule', 'Not a real TestRule — X renames a trait method, not an import.'),
            ),
            str_replace('someMethod', 'TestRule', self::HELPER_TRAIT),
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The test*-name path's twin of the comment-glue case above:
     * `function/**\/testHidden` collapsed to `functiontestHidden`, losing
     * T_FUNCTION entirely.
     */
    #[Test]
    public function rejectsForItsSubjectATestNamedRuleWithACommentAfterTheFunctionKeyword(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, <<<'RULE'
                public function/**/testHidden(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('Vacuous — must never hide behind a same-line comment in the name.');
                }
            RULE));

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUCH_NAMESPACE);
    }

    /**
     * A return-by-reference, test*-named rule whose subject is a trait-only
     * namespace.
     */
    private const string RETURN_BY_REFERENCE_RULE = <<<'RULE'
            public function &testModelIsALeaf(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Model is a leaf.');
            }
        RULE;

    /**
     * @return string MODEL_RULE_ON_TRAITS on phpat's test*-name path, no attribute.
     */
    private static function testNamedRuleOnTraits(): string
    {
        return self::asTestNamedRule(self::MODEL_RULE_ON_TRAITS, 'modelIsALeaf', 'testModelIsALeaf');
    }

    /**
     * @return string CONFIG_RULE on phpat's test*-name path, no attribute.
     */
    private static function testNamedRuleLive(): string
    {
        return self::asTestNamedRule(self::CONFIG_RULE, 'configurationIsALeaf', 'testConfigurationIsALeaf');
    }
}
