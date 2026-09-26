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

use function sprintf;
use function str_repeat;

/**
 * The token walk of the phpat subject-liveness guard,
 * bin/check-phpat-subjects.php (#184): the attribute spellings a text scan
 * could not see, the brace counter, the attribute-group scan, the class
 * inventory, NAMESPACE_ROOT resolution and the linear-time guarantees — see
 * AbstractPhpatSubjectsTestCase for how the ported suite is split.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckPhpatSubjectsTokenWalkTest extends AbstractPhpatSubjectsTestCase
{
    /**
     * A live rule whose argument pair both halves of the NAMESPACE_ROOT
     * cases reuse — `%s` is the attribute, then the method name, the two
     * selector suffixes and the because() literal.
     */
    private const string PRINTF_RULE = <<<'RULE'
            #[%s]
            public function %s(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
                    ->because(%s);
            }

        RULE;

    /**
     * GH-50: the old head pattern spelled the return type as the bare `Rule`.
     * The token walk reads the attribute, not the signature, so the rule is
     * now ANALYSED. The full sentence is asserted, not just its head: this is
     * the one positive assertion for the classname arm's wording, which the
     * must-not-carry checks elsewhere assume both liveness arms share.
     */
    #[Test]
    public function rejectsForItsSubjectARuleWithAQualifiedReturnType(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, <<<'RULE'
                #[TestRule]
                public function qualifiedReturn(): \PHPat\Test\Builder\Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\DoesNotExist'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                        ->because('Qualified return type.');
                }
            RULE));

        $this->assertGateRejects(self::gate(), $dir, 'qualifiedReturn: subject classname(Vendor\Mod\DoesNotExist) matches no class');
    }

    /**
     * `#[TestRule()]` written with parentheses is analysed.
     */
    #[Test]
    public function rejectsForItsSubjectAnAttributeWrittenWithParentheses(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, <<<'RULE'
                #[TestRule()]
                public function parenthesised(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\DoesNotExist'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                        ->because('Attribute written with parentheses.');
                }
            RULE));

        $this->assertGateRejects(self::gate(), $dir, 'parenthesised: subject classname');
    }

    /**
     * The spelling a consumer without the `use` writes.
     */
    #[Test]
    public function rejectsForItsSubjectAFullyQualifiedAttribute(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, <<<'RULE'
                #[\PHPat\Test\Attributes\TestRule]
                public function fullyQualified(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\DoesNotExist'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                        ->because('Fully qualified attribute.');
                }
            RULE));

        $this->assertGateRejects(self::gate(), $dir, 'fullyQualified: subject classname');
    }

    /**
     * A #[TestRule] on a property reads a subject from nothing, and must not
     * be carried forward onto the next method either — that would make an
     * ordinary helper a rule and hide the misplaced attribute.
     */
    #[Test]
    public function rejectsAnAttributeOnAPropertyWithoutCarryingItOntoTheNextMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                private string $notAMethod = 'x';

                public function helper(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                        ->because('Not a rule.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'attribute(s) found but only 0 resolved');
    }

    /**
     * The accepting twin: an ordinary attribute beside the rules is not
     * counted as one — totalling every attribute rather than the TestRule
     * ones reds this.
     */
    #[Test]
    public function acceptsAnOrdinaryAttributeBesideTheRules(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(<<<'RULE'
                #[\PHPUnit\Framework\Attributes\CoversNothing]
                public function notARule(): void
                {
                }
            RULE, self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * `"$x{"` lexes the brace as T_ENCAPSED_AND_WHITESPACE whose text is
     * exactly `{`. Counting it made a vacuous rule's body run into the helper
     * below, whose subject is live — one character turned a fail-closed
     * reject into `OK`. Only DELIMITER tokens bound a body.
     */
    #[Test]
    public function rejectsWhenAnInterpolatedBraceWouldExtendABodyIntoTheNextMethod(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function vacuous(): Rule
                {
                    $x    = 'note';
                    $note = "prefix $x{";

                    return $this->build($note);
                }

                public function build(string $note): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Model\Node'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                        ->because($note);
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * The mirror direction, a false RED on correct code: a stray `}` inside a
     * string cut the body short and a live rule was reported unparseable.
     */
    #[Test]
    public function acceptsAClosingBraceInsideAString(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function live(): Rule
                {
                    $what = 'x';
                    $note = "a $what}";

                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because($note);
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The two interpolation openers, whose CLOSING brace is an ordinary CHAR
     * token: count only CHAR tokens and that `}` decrements against nothing.
     */
    #[Test]
    public function acceptsBothInterpolationOpenersWithTheBraceDepthBalanced(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::INTERPOLATING_LIVE_RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A `::class` argument in a FOLLOWING attribute lexes as T_CLASS.
     * Re-walking the group let it hit the declaration barrier and clear the
     * flag `#[TestRule]` had just set, so a live rule was reported absent.
     */
    #[Test]
    public function acceptsAClassConstantArgumentInANeighbouringAttribute(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                #[\PHPUnit\Framework\Attributes\CoversClass(\Vendor\Mod\Configuration::class)]
                public function live(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('Live.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Bracket depth alone does not tell an attribute SEPARATOR from an
     * ARGUMENT separator, so a comma inside an argument list re-armed name
     * position and the second argument was read as an attribute name.
     */
    #[Test]
    public function acceptsATestRuleNameAsASecondAttributeArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(<<<'RULE'
                #[\PHPUnit\Framework\Attributes\UsesClass(\Vendor\Mod\Model\Node::class, \Vendor\Mod\TestRule::class)]
                public function notARule(): void
                {
                }
            RULE, self::LIVE_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Two attributes in one `#[…]` — the reason the comma arm exists at all.
     */
    #[Test]
    public function rejectsForItsSubjectATestRuleGroupedBehindAnotherAttribute(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                #[\PHPUnit\Framework\Attributes\CoversNothing, TestRule]
                public function grouped(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('Grouped.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Model)');
    }

    /**
     * An ARRAY argument closes the group early unless `[` raises the depth —
     * combined with the grouped spelling, a fail-open.
     */
    #[Test]
    public function rejectsForItsSubjectATestRuleAfterAnArrayArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, <<<'RULE'
                #[\PHPUnit\Framework\Attributes\TestWith([1, 2]), TestRule]
                public function afterAnArray(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                        ->because('After an array.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'inNamespace(Vendor\Mod\Model)');
    }

    /**
     * A body-less declaration ends on `;` — without that arm the body scan
     * runs into the NEXT method and adopts its selector.
     */
    #[Test]
    public function rejectsABodylessRuleWithoutAdoptingTheNextMethodsSelector(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                abstract public function declaredOnly(): Rule;

                #[TestRule]
                public function live(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Model'))
                        ->because('Live.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * A TestRule name used as a VALUE is not a rule; counting it produced a
     * false red pointing at the wrong file.
     */
    #[Test]
    public function acceptsATestRuleNameUsedAsAnAttributeArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeArchTest($dir, self::methods(<<<'RULE'
                #[\PHPUnit\Framework\Attributes\UsesClass(TestRule::class)]
                public function notARule(): void
                {
                }
            RULE, self::LIVE_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A rule that only LOOKS like one, inside a heredoc: tokens see one
     * string, so the emptiness guard fires instead of a text scan counting it.
     */
    #[Test]
    public function rejectsWhenTheOnlyRuleIsInsideAHeredoc(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                public function notARule(): string
                {
                    return <<<'CODE'
                #[TestRule]
                public function looksReal(): Rule
                {
                    return PHPat::rule()->classes(Selector::inNamespace('Vendor\Mod'));
                }
            CODE;
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, self::NO_RULES);
    }

    /**
     * A `class Node` inside a heredoc registered a class that does not exist
     * while the inventory was a line-anchored regex; tokens answer this by
     * construction.
     */
    #[Test]
    public function rejectsWhenTheOnlyClassIsNamedInsideAHeredoc(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Configuration.php', 'Vendor\Mod', 'final class', 'Configuration');
        self::writeFile("{$dir}/src/Model/template.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Mod\Model;

            return <<<'CODE'
            final class Node
            {
            }
            CODE;

            PHP);
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateRejects(self::gate(), $dir, 'matches no class');
    }

    /**
     * The inventory once took the FIRST match per file, so a second class was
     * invisible and any subject naming it was reported vacuous.
     */
    #[Test]
    public function acceptsASecondDeclarationInTheSameFile(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeFile("{$dir}/src/Pair.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Mod;

            final class Other
            {
            }

            final class Configuration
            {
            }

            PHP);
        self::writeArchTest($dir, self::methods(self::MODEL_RULE, self::CONFIG_RULE));

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The inventory's namespace-name lookahead once skipped only whitespace,
     * so `namespace /* c *\/ Vendor\Mod\Model;` put Node into the inventory
     * under its BARE name and `classname('Node')` was certified live.
     */
    #[Test]
    public function rejectsABareClassnameWhenACommentFollowsTheNamespaceKeyword(): void
    {
        $dir = $this->fixture()->path();
        self::writeClassRaw("{$dir}/src/Model/Node.php", 'namespace /* comment */ Vendor\Mod\Model;', 'final class Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function vacuous(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname('Node'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                        ->because('Vacuous — Node lives in Vendor\Mod\Model, not the global namespace.');
                }
            RULE);

        $this->assertGateRejects(self::gate(), $dir, 'classname(Node) matches no class');
    }

    /**
     * The class-name lookahead once landed on a same-line comment between
     * `class` and the name and dropped a genuinely live class.
     */
    #[Test]
    public function acceptsAClassWithACommentBetweenTheKeywordAndItsName(): void
    {
        $dir = $this->fixture()->path();
        self::writeClassRaw("{$dir}/src/Node.php", 'namespace Vendor\Mod;', 'final class/* comment */Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function live(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Node'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\NoSuchClass'))
                        ->because('Live — Node exists despite the comment between class and its name.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A DECOY class constant declared BEFORE the real one, whose string VALUE
     * reads like a NAMESPACE_ROOT declaration, once hijacked resolution under
     * the substring search the token walk replaced.
     */
    #[Test]
    public function acceptsDespiteADecoyStringReadingLikeANamespaceRootDeclaration(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class ArchitectureTest\n{\n"
            . "    private const string DECOY = \"const string NAMESPACE_ROOT = 'Vendor\\Fake'\";\n\n"
            . "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . self::printfRule('TestRule', 'live', "'\\Model'", "'\\NoSuchClass'", "'Live — Model exists under the real NAMESPACE_ROOT, not the decoy.'")
            . "}\n",
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * One T_CONST token covers a WHOLE comma-separated statement; checking
     * only its first name/value pair left NAMESPACE_ROOT unresolved.
     */
    #[Test]
    public function acceptsANamespaceRootDeclaredAsANonFirstConstantInAStatement(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class ArchitectureTest\n{\n"
            . "    private const string OTHER = 'unrelated', NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . self::printfRule('TestRule', 'live', "'\\Model'", "'\\NoSuchClass'", "'Live — Model exists under NAMESPACE_ROOT, which is not the first constant in its statement.'")
            . "}\n",
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A T_STRING AFTER `=` (the `NAMESPACE_ROOT` segment of an unrelated
     * constant's `Prefix::NAMESPACE_ROOT` value) once overwrote the name
     * being tracked and hijacked resolution. Prefix carries an unrelated
     * constant: the bug is purely tokenisation.
     */
    #[Test]
    public function acceptsDespiteAQualifiedConstantReferenceInADecoyValue(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class Prefix\n{\n    public const string OTHER_CONST = 'irrelevant';\n}\n\n"
            . "final class ArchitectureTest\n{\n"
            . "    private const string DECOY = Prefix::NAMESPACE_ROOT . 'Vendor\\\\Fake';\n\n"
            . "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . self::printfRule('TestRule', 'live', "'\\Model'", "'\\NoSuchClass'", "'Live — Model exists under the real NAMESPACE_ROOT, not the decoy value.'")
            . "}\n",
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * phpat's own selectors strip a leading and trailing `\` before comparing
     * (trimSeparators()), so the gate must trim the same way.
     */
    #[Test]
    public function acceptsALeadingBackslashOnABareLiteralClassnameArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function live(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname('\\Vendor\\Mod\\Model\\Node'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname('\\Vendor\\Mod\\NoSuchClass'))
                        ->because('Live — Node exists despite the leading backslash on the argument.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The trailing half of the same trim: reverting it to ltrim() alone left
     * the bash suite green until this case existed.
     */
    #[Test]
    public function acceptsATrailingBackslashOnABareLiteralClassnameArgument(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTest($dir, <<<'RULE'
                #[TestRule]
                public function live(): Rule
                {
                    return PHPat::rule()
                        ->classes(Selector::classname('Vendor\\Mod\\Model\\Node\\'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname('Vendor\\Mod\\NoSuchClass'))
                        ->because('Live — Node exists despite the trailing backslash on the argument.');
                }
            RULE);

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * PHP decodes `\n` in a double-quoted string, so `"Vendor\node"` is not
     * the literal text between the quotes. The class deliberately lives under
     * the LITERAL text, so reading the raw token would wrongly accept; a
     * double-quoted NAMESPACE_ROOT must fail closed instead.
     */
    #[Test]
    public function rejectsADoubleQuotedNamespaceRootInsteadOfReadingItAsRawText(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\node\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class ArchitectureTest\n{\n"
            . "    private const string NAMESPACE_ROOT = \"Vendor\\node\";\n\n"
            . self::printfRule('TestRule', 'live', "'\\Model'", "'\\NoSuchClass'", "'Vacuous either way — NAMESPACE_ROOT must fail to resolve, not silently decode.'")
            . "}\n",
        );

        $this->assertGateRejects(self::gate(), $dir, 'could not resolve');
    }

    /**
     * Many unterminated `use` keywords before the real, ALIASED import: the
     * walk must still resolve it (a bare #[TestRule] would be recognised even
     * with the walk broken). A functional check on the control flow of the
     * linear-time fix, not a timing regression guard.
     */
    #[Test]
    public function rejectsForItsSubjectAnAliasedRuleAfterManyUnterminatedUseKeywords(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir, str_repeat('use ', 500));
        self::appendArchTest(
            $dir,
            "use PHPat\\Test\\Attributes\\TestRule as RuleAlias;\n\n"
            . "final class ArchitectureTest\n{\n"
            . "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . self::printfRule('RuleAlias', 'vacuous', "'\\NoSuchNamespace'", "'\\Model\\Node'", "'Vacuous — proves the real aliased TestRule import is still found past 500 unterminated use keywords.'")
            . "}\n",
        );

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUCH_NAMESPACE);
    }

    /**
     * Many unterminated `const` keywords before the real declaration: the
     * real NAMESPACE_ROOT is still resolved past the noise.
     */
    #[Test]
    public function acceptsANamespaceRootAfterManyUnterminatedConstKeywords(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestHeader($dir);
        self::appendArchTest(
            $dir,
            "final class ArchitectureTest\n{\n"
            . str_repeat('const ', 500) . "\n\n"
            . "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . self::printfRule('TestRule', 'live', "'\\Model'", "'\\NoSuchClass'", "'Live — proves the real NAMESPACE_ROOT is still found past 500 unterminated const keywords.'")
            . "}\n",
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Many `public function testN` declarations with neither `{` nor `;`:
     * each is a candidate rule whose body scan runs to end-of-file. The run
     * must still fail closed past the noise.
     */
    #[Test]
    public function rejectsManyUnterminatedTestMethodDeclarations(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestBareOpen($dir);
        self::appendArchTest($dir, self::repeatedTestDeclarations('public function test%d ') . "\n}\n");

        $this->assertGateRejects(self::gate(), $dir, self::NO_SUBJECT);
    }

    /**
     * Many unterminated `testN()` declarations sharing ONE distant `;`: the
     * shape that tells the unconditional index skip from the discarded
     * conditional one. Only the total count discriminates — the conditional
     * fix still reports test1 — and it is anchored on the gate's prefix, since
     * a bare `1 problem(s)` is also a substring of `11 problem(s)`.
     */
    #[Test]
    public function reportsOneProblemForManyDeclarationsSharingOneDistantTerminator(): void
    {
        $dir = $this->fixture()->path();
        self::writeClass($dir, 'Model/Node.php', 'Vendor\Mod\Model', 'final class', 'Node');
        self::writeArchTestBareOpen($dir);
        self::appendArchTest($dir, self::repeatedTestDeclarations('public function test%d() ') . ";\n}\n");

        $this->assertGateRejects(self::gate(), $dir, 'check-phpat-subjects: 1 problem(s)');
    }

    /**
     * A live rule on Model/Configuration, after a non-rule attributed helper.
     */
    private const string LIVE_RULE = <<<'RULE'
            #[TestRule]
            public function live(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Live.');
            }
        RULE;

    /**
     * @param string $attribute The attribute name.
     * @param string $method    The method name.
     * @param string $subject   The subject's suffix literal.
     * @param string $target    The target's suffix literal.
     * @param string $because   The because() literal.
     *
     * @return string PRINTF_RULE filled in.
     */
    private static function printfRule(string $attribute, string $method, string $subject, string $target, string $because): string
    {
        return sprintf(self::PRINTF_RULE, $attribute, $method, $subject, $target, $because);
    }

    /**
     * @param string $format A declaration with one `%d` for the method number.
     *
     * @return string The declaration for test1 through test100, back to back.
     */
    private static function repeatedTestDeclarations(string $format): string
    {
        $out = '';

        for ($i = 1; $i <= 100; ++$i) {
            $out .= sprintf($format, $i);
        }

        return $out;
    }
}
