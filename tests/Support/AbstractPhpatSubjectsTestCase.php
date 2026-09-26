<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use MagicSunday\CodingStandard\Test\GateTestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

use function chmod;
use function dirname;
use function file_put_contents;
use function function_exists;
use function implode;
use function is_dir;
use function mkdir;
use function posix_getuid;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strlen;

use const FILE_APPEND;

/**
 * Shared base for the fixture-driven suites of bin/check-phpat-subjects.php
 * (#184), ported from tests/check-phpat-subjects-cases.sh, which #45 removed
 * together with the gate (`git show da192b4^:tests/check-phpat-subjects-cases.sh`).
 *
 * The cases are split along the bash original's own section seams, one final
 * class each, so a new case goes into the class covering the part of the gate
 * it drives:
 *
 *   - tests/CheckPhpatSubjectsTest.php          -> the three liveness arms
 *     (inNamespace/classname/isAbstract), the fail-closed selector and
 *     argument reports, the read arms (size cap, unreadable file) and the
 *     usage errors
 *   - tests/CheckPhpatSubjectsTokenWalkTest.php -> the token walk: attribute
 *     spellings, the brace counter, the attribute-group scan, the class
 *     inventory, NAMESPACE_ROOT resolution and the linear-time guarantees
 *   - tests/CheckPhpatSubjectsReportTest.php    -> the report-is-inert cases
 *     for a consumer-controlled subject, argument and rule name
 *   - tests/CheckPhpatSubjectsDiscoveryTest.php -> phpat's second discovery
 *     path (a public test*-named method, GH-58), the visibility and nesting
 *     rules both paths share, and TestRule import-alias tracking
 *
 * This base holds what all four share: the gate command, the fixture writers
 * (ports of the bash write_class/write_archtest/write_archtest_header/
 * write_archtest_bare_open/write_class_raw helpers, byte for byte) and the
 * rule-method bodies several cases reuse. The bash original's bookkeeping
 * self-test (harness_assert_no_stray_increments) is not ported:
 * GateTestCase's own meta-suite proves its decisions generically, and a
 * failed PHPUnit decision throws rather than bumping a counter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
abstract class AbstractPhpatSubjectsTestCase extends GateTestCase
{
    /**
     * The largest PHP source the gate reads, in bytes. Mirrors
     * MAX_SOURCE_BYTES in bin/check-phpat-subjects.php.
     */
    protected const int MAX_SOURCE_BYTES = 262144;

    /**
     * The report line of every size-cap rejection.
     */
    protected const string PAST_THE_CAP = 'is larger than the 262144 bytes this gate reads';

    /**
     * The report of a rule method without a classifiable subject.
     */
    protected const string NO_SUBJECT = 'could not identify a subject selector';

    /**
     * The report of an ArchitectureTest in which neither discovery path finds a rule.
     */
    protected const string NO_RULES = 'no #[TestRule] or test*-named public rule methods found';

    /**
     * The report of the vacuous subject the alias/visibility fixtures share.
     */
    protected const string NO_SUCH_NAMESPACE = 'inNamespace(Vendor\Mod\NoSuchNamespace) matches no class';

    /**
     * A live inNamespace(Model) rule.
     */
    protected const string MODEL_RULE = <<<'RULE'
            #[TestRule]
            public function modelIsALeaf(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Model is a leaf.');
            }
        RULE;

    /**
     * MODEL_RULE repointed at a Traits namespace — vacuous whenever that
     * namespace holds traits only (the manifested bug).
     */
    protected const string MODEL_RULE_ON_TRAITS = <<<'RULE'
            #[TestRule]
            public function modelIsALeaf(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Model is a leaf.');
            }
        RULE;

    /**
     * A classname(Configuration) rule.
     */
    protected const string CONFIG_RULE = <<<'RULE'
            #[TestRule]
            public function configurationIsALeaf(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->because('Configuration is a leaf.');
            }
        RULE;

    /**
     * An isAbstract() rule — the conditional guard the gate does not liveness-check.
     */
    protected const string ABSTRACT_RULE = <<<'RULE'
            #[TestRule]
            public function abstractClassesAreAbstractPrefixed(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::isAbstract())
                    ->should()->beNamed('/Abstract/', true)
                    ->because('House rule.');
            }
        RULE;

    /**
     * A rule whose subject is not a Selector call at all.
     */
    protected const string BROKEN_RULE = <<<'RULE'
            #[TestRule]
            public function brokenSubject(): Rule
            {
                return PHPat::rule()
                    ->classes($this->dynamicSelector())
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                    ->because('Broken.');
            }
        RULE;

    /**
     * A rule whose SUBJECT targets an abstract class by name — exercises the
     * 'abstract-class' inventory kind as a valid classname target.
     */
    protected const string ABSTRACT_TARGET_RULE = <<<'RULE'
            #[TestRule]
            public function baseNodeIsALeaf(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\AbstractNode'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->because('The base node is a leaf.');
            }
        RULE;

    /**
     * A rule whose subject is a real but UNHANDLED selector — exercises the
     * distinct "unhandled subject selector" fail-closed path.
     */
    protected const string UNKNOWN_SELECTOR_RULE = <<<'RULE'
            #[TestRule]
            public function implementorRule(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::implement(self::NAMESPACE_ROOT . '\SomeInterface'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                    ->because('Implementor rule.');
            }
        RULE;

    /**
     * A rule whose subject argument cannot be resolved (a property, not
     * NAMESPACE_ROOT or a literal).
     */
    protected const string UNRESOLVABLE_ARG_RULE = <<<'RULE'
            #[TestRule]
            public function dynamicNamespaceRule(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace($this->rootNamespace))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                    ->because('Dynamic namespace.');
            }
        RULE;

    /**
     * A live rule whose body interpolates with both openers, `{$x}` and `${x}`.
     */
    protected const string INTERPOLATING_LIVE_RULE = <<<'RULE'
            #[TestRule]
            public function live(): Rule
            {
                $what = 'x';
                $note = "a {$what} and ${what}";

                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because($note);
            }
        RULE;

    /**
     * A plain helper — neither attributed nor test*-named.
     */
    protected const string NON_RULE_METHOD = <<<'RULE'
            public function helper(): string
            {
                return 'not a rule';
            }
        RULE;

    /**
     * A test*-named method phpat itself never runs: TestParser reflects
     * PUBLIC methods only. Its body carries no ->classes(Selector::…) at all,
     * so picking it up would fail closed.
     */
    protected const string TEST_NAMED_PRIVATE_HELPER = <<<'RULE'
            private function testHelperNotARule(): string
            {
                return 'not a rule';
            }
        RULE;

    /**
     * A malformed #[TestRule] (delegating, no ->classes(Selector) in its own
     * body) followed by a helper that DOES carry one — the search must stop at
     * the helper's declaration and fail closed, not adopt its selector.
     */
    protected const string MALFORMED_WITH_HELPER = <<<'RULE'
            #[TestRule]
            public function malformedRule(): Rule
            {
                return $this->buildRule();
            }

            public function buildRule(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                    ->because('Helper.');
            }
        RULE;

    /**
     * A subject argument composed with ANOTHER constant — the gate does not
     * model it, so it must fail closed rather than resolve to the root alone.
     */
    protected const string COMPOSED_ARG_RULE = <<<'RULE'
            #[TestRule]
            public function composedNamespaceRule(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . self::MODEL_SUFFIX))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
                    ->because('Composed namespace.');
            }
        RULE;

    /**
     * @return list<string> The interpreter and gate script.
     */
    protected static function gate(): array
    {
        return ['php', self::root() . '/bin/check-phpat-subjects.php'];
    }

    /**
     * The gate run from INSIDE the fixture directory against `.`, so every
     * path it reports is relative and short. For the cases whose poisoned
     * file NAME must survive safeReportValue()'s 64-byte cap: that cap cuts
     * from the end, and an absolute fixture path (the bash original kept its
     * case directory deliberately two characters long for the same reason)
     * would eat the poison and leave the must-carry asserting a temp prefix.
     * GateProcess appends the fixture directory as the last argument, which
     * the shell receives as $1.
     *
     * @return list<string> The shell wrapper, with the gate script as its $0.
     */
    protected static function gateFromInside(): array
    {
        return ['sh', '-c', 'cd -- "$1" && exec php "$0" .', self::root() . '/bin/check-phpat-subjects.php'];
    }

    /**
     * Runs the gate once more for a must-not-carry/must-carry check of its
     * own, beyond what the assertGate*() decision already asserted.
     *
     * @param string $dir The directory to run the gate against.
     *
     * @return GateResult The captured run.
     *
     * @throws ProcessStartFailedException If the gate process could not be started.
     * @throws ProcessTimedOutException    If the gate process exceeded its timeout.
     * @throws ProcessSignaledException    If the gate process was killed by a signal.
     */
    protected static function runGate(string $dir): GateResult
    {
        return (new GateProcess())->run(self::gate(), $dir);
    }

    /**
     * Joins rule-method bodies the way the bash original's "$A\n\n$B" did.
     *
     * @param string ...$methods The rule-method bodies.
     *
     * @return string The bodies separated by one blank line.
     */
    protected static function methods(string ...$methods): string
    {
        return implode("\n\n", $methods);
    }

    /**
     * Derives a test*-named (no-attribute) rule from an attributed one, so the
     * two discovery-path fixtures proving identical subject behaviour share one
     * body. Port of the bash as_test_named_rule().
     *
     * @param string $rule    An attributed rule-method body.
     * @param string $oldName The method name it declares.
     * @param string $newName The test*-prefixed name to give it.
     *
     * @return string The body with the attribute line dropped and the method renamed.
     */
    protected static function asTestNamedRule(string $rule, string $oldName, string $newName): string
    {
        $withoutAttribute = (string) preg_replace('/^.*#\[TestRule\].*\n/m', '', $rule);

        return str_replace("public function {$oldName}(", "public function {$newName}(", $withoutAttribute);
    }

    /**
     * Replaces a rule's `public` modifier, keeping its name. Port of the bash
     * as_modifier_variant().
     *
     * @param string $rule      A rule-method body declared `public function`.
     * @param string $modifiers The modifiers to declare it with instead.
     *
     * @return string The modifier variant.
     */
    protected static function asModifierVariant(string $rule, string $modifiers): string
    {
        return str_replace('public function', "{$modifiers} function", $rule);
    }

    /**
     * A #[<attribute>]-attributed rule whose subject is the fixed, vacuous
     * inNamespace(…NoSuchNamespace) the alias-tracking cluster shares. Port of
     * the bash as_vacuous_alias_rule().
     *
     * @param string $attribute  The attribute token.
     * @param string $method     The method name.
     * @param string $returnType The declared return type.
     * @param string $because    The because() message.
     *
     * @return string The rule-method body.
     */
    protected static function vacuousAliasRule(string $attribute, string $method, string $returnType, string $because): string
    {
        return <<<RULE
                #[{$attribute}]
                public function {$method}(): {$returnType}
                {
                    return PHPat::rule()
                        ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\NoSuchNamespace'))
                        ->shouldNot()->dependOn()
                        ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
                        ->because('{$because}');
                }
            RULE;
    }

    /**
     * Writes a src/ class file. Port of the bash write_class().
     *
     * @param string $dir       The case directory.
     * @param string $relative  The path under src/.
     * @param string $namespace The namespace to declare.
     * @param string $kind      The declaration keyword(s), e.g. `final class` or `trait`.
     * @param string $name      The declared name.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function writeClass(string $dir, string $relative, string $namespace, string $kind, string $name): void
    {
        self::writeFile(
            "{$dir}/src/{$relative}",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$kind} {$name}\n{\n}\n",
        );
    }

    /**
     * Writes a src/ file with a caller-supplied namespace statement and class
     * line verbatim — for a same-line comment writeClass() cannot express.
     * Port of the bash write_class_raw().
     *
     * @param string $file          The absolute file path.
     * @param string $namespaceLine The namespace statement.
     * @param string $classLine     The class declaration line.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function writeClassRaw(string $file, string $namespaceLine, string $classLine): void
    {
        self::writeFile($file, "<?php\n\ndeclare(strict_types=1);\n\n{$namespaceLine}\n\n{$classLine}\n{\n}\n");
    }

    /**
     * Writes tests/Architecture/ArchitectureTest.php around $methods. Port of
     * the bash write_archtest().
     *
     * @param string $dir        The case directory.
     * @param string $methods    The rule-method block (already inside the class body).
     * @param string $preamble   A top-level declaration written before the class, if any.
     * @param string $importLine A line replacing the plain TestRule import, if any.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function writeArchTest(string $dir, string $methods, string $preamble = '', string $importLine = ''): void
    {
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod\\Test\\Architecture;\n\n"
            . "use PHPat\\Selector\\Selector;\n"
            . (($importLine !== '') ? $importLine : 'use PHPat\Test\Attributes\TestRule;') . "\n"
            . "use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n"
            . (($preamble !== '') ? "{$preamble}\n\n" : '')
            . "final class ArchitectureTest\n{\n"
            . "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
            . "{$methods}\n}\n";

        self::writeFile(self::archTestPath($dir), $source);
    }

    /**
     * Writes the opening every ArchitectureTest fixture shares (`<?php`
     * through the four imports), truncating the file to it, for a fixture
     * whose class body writeArchTest() cannot express; the caller appends the
     * rest with appendArchTest(). Port of the bash write_archtest_header().
     *
     * @param string $dir           The case directory.
     * @param string $beforeImports Content written between the namespace and the imports, if any.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function writeArchTestHeader(string $dir, string $beforeImports = ''): void
    {
        self::writeFile(
            self::archTestPath($dir),
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod\\Test\\Architecture;\n\n"
            . (($beforeImports !== '') ? "{$beforeImports}\n\n" : '')
            . "use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n"
            . "use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n",
        );
    }

    /**
     * Writes the `<?php` through `final class ArchitectureTest {` opening with
     * no imports at all, truncating the file to it. Port of the bash
     * write_archtest_bare_open().
     *
     * @param string $dir The case directory.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function writeArchTestBareOpen(string $dir): void
    {
        self::writeFile(
            self::archTestPath($dir),
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod\\Test\\Architecture;\n\nfinal class ArchitectureTest\n{\n",
        );
    }

    /**
     * Appends $content to the ArchitectureTest a writeArchTest*() call started.
     *
     * @param string $dir     The case directory.
     * @param string $content The bytes to append.
     *
     * @return void
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected static function appendArchTest(string $dir, string $content): void
    {
        if (file_put_contents(self::archTestPath($dir), $content, FILE_APPEND) !== strlen($content)) {
            throw new RuntimeException(sprintf('Could not append to fixture file: %s', self::archTestPath($dir)));
        }
    }

    /**
     * @param string $dir The case directory.
     *
     * @return string The ArchitectureTest path the gate looks for first.
     */
    protected static function archTestPath(string $dir): string
    {
        return "{$dir}/tests/Architecture/ArchitectureTest.php";
    }

    /**
     * Writes $contents to $path, creating any missing parent directory.
     *
     * @param string $path     Absolute path inside this test's fixture directory.
     * @param string $contents The exact bytes to write.
     *
     * @return void
     *
     * @throws RuntimeException If the parent directory or the file cannot be written.
     */
    protected static function writeFile(string $path, string $contents): void
    {
        self::makeDirectory(dirname($path));

        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException(sprintf('Could not write fixture file: %s', $path));
        }
    }

    /**
     * Creates $path and any missing parent, a no-op when it already exists.
     *
     * @param string $path Absolute path inside this test's fixture directory.
     *
     * @return void
     *
     * @throws RuntimeException If the directory cannot be created.
     */
    protected static function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0o700, true)) {
            throw new RuntimeException(sprintf('Could not create fixture directory: %s', $path));
        }
    }

    /**
     * Removes every permission bit from $path.
     *
     * @param string $path The fixture file to make unreadable.
     *
     * @return void
     *
     * @throws RuntimeException If the mode cannot be changed.
     */
    protected static function makeUnreadable(string $path): void
    {
        if (!chmod($path, 0o000)) {
            throw new RuntimeException(sprintf('Could not chmod fixture file: %s', $path));
        }
    }

    /**
     * Skips the calling test when running as root: uid 0 bypasses DAC, so
     * mode 000 stays readable — a false regression, not a real one. CI runs
     * non-root, so the branch stays exercised there. Same helper as
     * AbstractConsumerConfigTestCase's own.
     *
     * @return void
     */
    protected function skipIfRunningAsRoot(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }
    }
}
