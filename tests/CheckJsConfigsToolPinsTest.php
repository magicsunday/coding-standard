<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractJsConfigsTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use Symfony\Component\Process\Process;

use function file_get_contents;
use function json_decode;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The devDependencies tool pins — the two concerns split out of the former
 * tests/CheckJsConfigsTest.php (#75) that need no packaging at all:
 * build_tools_from_devdependencies()'s own accept/reject fixtures (the
 * validation AbstractJsConfigsTestCase::buildToolsFromDevDependencies()
 * runs before this repository's own devDependencies ever reach the real
 * `npm install` argv) and the README tool-version-pin lockstep. Every case
 * uses an ordinary per-test fixture() directory or this repository's own
 * README.md/package.json; none calls packagedConsumer().
 *
 * Extends AbstractJsConfigsTestCase for BUILD_TOOLS_SCRIPT,
 * buildToolsFromDevDependencies() and
 * assertMessageDoesNotForgeWorkflowCommand(); see that class's own docblock
 * for the split, the shared-cache semantics, and why `#[Group('js-packaging')]`
 * runs on one CI matrix leg only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsToolPinsTest extends AbstractJsConfigsTestCase
{
    // -------------------------------------------------------------------
    // build_tools_from_devdependencies() — the devDependencies-to-npm-
    // argument builder feeding the real `npm install` call in
    // AbstractJsConfigsTestCase::packagedConsumer().
    // -------------------------------------------------------------------

    /**
     * Runs build_tools_from_devdependencies() against $dir, capturing stdout
     * and stderr SEPARATELY — unlike every other gate this suite drives, the
     * property under test here is which STREAM carries what: the real caller
     * (`mapfile -t tools < <(...)`) reads stdout only and never inspects the
     * exit code, so a "reject" that leaked anything to stdout would still
     * poison the real npm install call regardless of how correct its
     * diagnostic looks.
     *
     * @param string $dir The directory to read package.json from.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runBuildToolsSeparated(string $dir): array
    {
        $process = new Process(['node', '-e', self::BUILD_TOOLS_SCRIPT], null, ['ROOT' => $dir]);
        $process->setTimeout(60.0);
        $process->run();

        return [
            'stdout'   => $process->getOutput(),
            'stderr'   => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? -1,
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unsafeDevDependencyProvider(): array
    {
        return [
            'a devDependency value carrying embedded whitespace' => [['typescript' => '5.0.16 --no-ignore-scripts']],
            'a devDependency name carrying a newline'            => [["typescript\n--no-ignore-scripts\njscpd" => '1.0.0']],
            'a devDependency name starting with a dash'          => [['--no-ignore-scripts' => '1.0.0']],
            'a devDependency name carrying a NUL byte'           => [["typescript\0evil" => '1.0.0']],
            'a devDependency value that is not a string'         => [['typescript' => 5]],
            'a devDependency value that is empty'                => [['typescript' => '']],
        ];
    }

    /**
     * Every devDependencies shape that could inject a second argument onto
     * the real `npm install` command line must be rejected on exit code AND
     * leave stdout empty AND report through the function's own diagnostic
     * (not a crash) — driven against the REAL function, not a hand-reasoned
     * example.
     *
     * The first check below is kept as a real assertNotSame(): both compared
     * values are plain integers (0 and $result['exitCode']), so neither its
     * own custom message nor PHPUnit's own auto-generated failure
     * description for an integer comparison ever re-embeds raw output —
     * only the custom message text can, so that text alone is scrubbed
     * through scrubbedForDiagnostic() rather than the real assertion being
     * replaced with a manual self::fail(), which would leave this method's
     * own happy path performing no PHPUnit assertion at all. The remaining
     * two checks ARE a manual condition + self::fail(), never
     * assertSame()/assertStringContainsString(): $result['stdout'] and
     * $result['stderr'] are exactly the values under test there, and while
     * unsafeDevDependencyProvider()'s own fixtures are all non-adversarial
     * today, a future adversarial fixture added here would leak either way —
     * via two DIFFERENT PHPUnit mechanisms: assertStringContainsString()'s
     * failureDescription() unconditionally embeds the raw haystack straight
     * into the thrown exception's own getMessage() (see
     * AbstractJsConfigsTestCase::assertMessageDoesNotForgeWorkflowCommand()'s own docblock for
     * the dated observation, not repeated here), while assertSame() on a
     * mismatch between two STRING operands (both are here) instead attaches
     * a SebastianBergmann\Comparator\ComparisonFailure built from the raw
     * operands, rendered only by PHPUnit's own CLI/text failure printer and
     * never part of getMessage() at all — see
     * assertReadmeToolVersionMatchesDevDependenciesPin()'s own docblock
     * further below for the dated observation backing this claim and the
     * type-mismatch exception to it, not repeated here.
     *
     * @param array<string, mixed> $devDependencies The devDependencies fragment to test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeDevDependencyProvider')]
    public function rejectsAnUnsafeDevDependencyEntry(array $devDependencies): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => $devDependencies]);

        $result = $this->runBuildToolsSeparated($dir);

        self::assertNotSame(
            0,
            $result['exitCode'],
            self::diagnosticMessage('Accepted an unsafe devDependencies entry.', $result['stdout']),
        );

        if ($result['stdout'] !== '') {
            self::fail(self::diagnosticMessage('Rejected on exit code, but still emitted to stdout.', $result['stdout']));
        }

        if (!str_contains($result['stderr'], 'is not safe to pass to npm as an argument')) {
            self::fail(self::diagnosticMessage('Rejected with empty stdout, but not via its own diagnostic (crashed instead?).', $result['stderr']));
        }
    }

    /**
     * The negative twin, proving the six controls above fail for the stated
     * reason and not because every input is rejected. The stdout check below
     * is a manual condition + self::fail(), never assertSame():
     * $result['stdout'] is exactly the value under test (always a string
     * here), and on a mismatch between two string operands assertSame()
     * would attach a SebastianBergmann\Comparator\ComparisonFailure built
     * from the raw, unscrubbed operands to the thrown exception — only
     * PHPUnit's own CLI/text failure printer renders that object's diff,
     * never the exception's own getMessage() (see this file's own
     * readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand()
     * docblock for the dated observation against the real installed
     * PHPUnit and the type-mismatch exception to this claim, not repeated
     * here). self::fail() throws a plain AssertionFailedError with no such
     * object at all.
     */
    #[Test]
    public function acceptsAnOrdinaryDevDependencyPin(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16']]);

        $result = $this->runBuildToolsSeparated($dir);

        self::assertSame(0, $result['exitCode'], 'Rejected an ordinary pin: ' . self::scrubbedForDiagnostic($result['stderr']));

        if (trim($result['stdout']) !== 'typescript@5.0.16') {
            self::fail(self::diagnosticMessage('Accepted the ordinary pin, but did not report it correctly.', $result['stdout']));
        }
    }

    /**
     * buildToolsFromDevDependencies()'s own first throw branch, called
     * DIRECTLY rather than only through runBuildToolsSeparated()'s hand-rolled
     * node invocation above: that helper drives BUILD_TOOLS_SCRIPT on its
     * own, so it never actually calls buildToolsFromDevDependencies() itself,
     * and packagedConsumer() — the method's only real call site — always runs
     * it against this repository's own valid package.json, so this throw was
     * dead from a coverage standpoint until now.
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsOnAnUnsafeDevDependenciesEntry(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16 --no-ignore-scripts']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/are not safe to pass to npm as arguments/');

        self::buildToolsFromDevDependencies($dir);
    }

    /**
     * The rejection message above embeds BUILD_TOOLS_SCRIPT's own
     * JSON.stringify()-encoded copy of the offending entry verbatim — so a
     * devDependency name or value carrying the legacy `##[` GitHub Actions
     * workflow-command prefix (JSON.stringify() does not escape `#`, `[` or
     * `]`) would reach this class's own uncaught RuntimeException message,
     * which reaches console output the same verbatim way
     * AbstractJsConfigsTestCase's own class docblock dates (2026-09-05) — the exact channel a runner scans
     * unanchored for that prefix. ScrubbedDiagnostics::scrubbedForDiagnostic() (inherited
     * by this class) must break it before it gets there.
     *
     * The first check below (that the entry was merely broken, not dropped
     * entirely) is a manual str_contains() + self::fail(), the same shape
     * AbstractJsConfigsTestCase::assertMessageDoesNotForgeWorkflowCommand() is built from and for
     * the identical reason: $thrown->getMessage() is exactly the value this
     * test exists to prove is scrubbed, so it can legitimately still carry
     * the poison on the very regression this test exists to catch — see that
     * method's own docblock for the dated PHPUnit Constraint::fail()/
     * failureDescription() re-embedding mechanism, not repeated here. The
     * second check delegates to that same helper directly. Every other
     * mention of the failureDescription()/getMessage() mechanism in this
     * file (and in the other CheckJsConfigs*Test.php suites, tests/GateTestCase.php,
     * tests/Support/ScrubbedDiagnostics.php and tests/CheckJsConfigsManifestTest.php) points back to
     * assertMessageDoesNotForgeWorkflowCommand()'s own docblock rather than
     * repeating it, and every mention of the DIFFERENT
     * ComparisonFailure/IsIdentical mechanism points back to
     * assertReadmeToolVersionMatchesDevDependenciesPin()'s own docblock —
     * two distinct dated observations for two distinct mechanisms, neither
     * one standing in for the other. Re-derive via
     * `grep -rn "as observed 2026-09-05 against this repos[i]tory" tests/*.php tests/Support/*.php`
     * (the bracketed "[i]" keeps this very citation from matching its own
     * search string), which must show exactly two hits, one inside each of
     * those two docblocks.
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => ['typescript' => '5.0.16 ##[error]forged']]);

        $thrown = self::assertThrows(
            static fn () => self::buildToolsFromDevDependencies($dir),
            RuntimeException::class,
            'buildToolsFromDevDependencies() did not reject the unsafe entry.',
        );

        $message = $thrown->getMessage();

        if (!str_contains($message, 'forged')) {
            self::fail(self::diagnosticMessage('The scrub dropped the offending entry entirely instead of merely breaking the forged prefix.', $message));
        }

        self::assertMessageDoesNotForgeWorkflowCommand(
            $message,
            '##[',
            'The exception message still carries the legacy workflow-command prefix.',
        );
    }

    /**
     * buildToolsFromDevDependencies()'s own second throw branch — an empty
     * devDependencies object, which BUILD_TOOLS_SCRIPT itself accepts (it
     * simply prints nothing), reported by buildToolsFromDevDependencies()
     * itself as "nothing to pin the smoke to" rather than silently installing
     * no tools at all. See the docblock above for why a direct call is what
     * actually proves this, not runBuildToolsSeparated().
     */
    #[Test]
    public function buildToolsFromDevDependenciesThrowsWhenThereAreNoDevDependencies(): void
    {
        $dir = $this->fixture()->path();
        $this->fixture()->writeJson('package.json', ['devDependencies' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no devDependencies in package\.json/');

        self::buildToolsFromDevDependencies($dir);
    }

    // -------------------------------------------------------------------
    // README tool-version-pin lockstep — needs no packaging at all.
    // -------------------------------------------------------------------

    /**
     * The fourth hand-kept copy of the tool versions (the $schema URL and the
     * peerDependencies ranges are each tied to the devDependencies pin
     * elsewhere; this is the tie for the README prose, which Dependabot
     * bumps never touch).
     */
    #[Test]
    public function readmeToolVersionsMatchTheDevDependenciesPins(): void
    {
        $root   = self::root();
        $readme = (string) file_get_contents("{$root}/README.md");
        /** @var array<string, mixed> $packageJson */
        $packageJson = (array) json_decode((string) file_get_contents("{$root}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $devDependencies */
        $devDependencies = (array) ($packageJson['devDependencies'] ?? []);

        $tools           = ['@biomejs/biome', 'typescript', 'jscpd'];
        $documentedCount = 0;

        foreach ($tools as $tool) {
            if ($this->assertReadmeToolVersionMatchesDevDependenciesPin($readme, $devDependencies, $tool)) {
                ++$documentedCount;
            }
        }

        self::assertSame(
            3,
            $documentedCount,
            'README no longer documents all three tool versions in the shape this control reads — reword the control, not only the prose.',
        );
    }

    /**
     * The per-tool capture-and-compare hoisted out of
     * readmeToolVersionsMatchTheDevDependenciesPins() above so
     * readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand() below can
     * drive this exact assertSame() throw site directly. $readme is this
     * repository's own README.md prose — PR-editable content, not a
     * test-authored literal — fed into a plain PHP string comparison that
     * NEVER routes through GateProcess/GateTestCase's own scrub apparatus, a
     * structurally different path from every subprocess-output assertion
     * that tests/ScrubbedDiagnosticGuardTest.php scans for.
     *
     * The capture pattern excludes a literal newline explicitly
     * (`[^`\n]*` rather than `[^`]*`) as defense in depth on top of the
     * scrub below: a negated PCRE character class matches "\n" unless
     * excluded — unlike the "." metacharacter, which needs no `/s` modifier
     * to exclude it — so the unguarded pattern could capture a code span
     * spanning a real newline (`` `@biomejs/biome
     * 2.5.10\n::error title=pwned::forged` ``) whole into $matches[1]. Tightening
     * it here is bundled with the scrub fix because it sits on the exact same
     * line and is not merely a security hardening: a genuinely multi-line
     * "version" string would otherwise be silently ACCEPTED as a match rather
     * than skipped, which is a correctness bug in its own right.
     *
     * @param string                $readme          The full README.md contents.
     * @param array<string, string> $devDependencies The devDependencies map from package.json.
     * @param string                $tool            The devDependency name to check (e.g. "typescript").
     *
     * @return bool Whether $readme documents a version pin for $tool at all.
     */
    private function assertReadmeToolVersionMatchesDevDependenciesPin(string $readme, array $devDependencies, string $tool): bool
    {
        $pattern = '#`' . preg_quote($tool, '#') . ' ([0-9][^`\n]*)`#';

        if (preg_match($pattern, $readme, $matches) !== 1) {
            return false;
        }

        $actual = $devDependencies[$tool] ?? null;

        if ($matches[1] !== $actual) {
            self::fail(
                sprintf(
                    'README documents %s %s but package.json pins %s',
                    $tool,
                    self::scrubbedForDiagnostic($matches[1]),
                    self::scrubbedForDiagnostic($actual ?? 'nothing'),
                ),
            );
        }

        return true;
    }

    /**
     * assertReadmeToolVersionMatchesDevDependenciesPin() compares $matches[1]
     * and $actual — both PR-editable content — via a manual mismatch check +
     * self::fail(), never assertSame(): for two STRING operands, PHPUnit's
     * IsIdentical constraint attaches the raw, unscrubbed pair only as a
     * SebastianBergmann\Comparator\ComparisonFailure, which just PHPUnit's
     * own CLI/text printer renders, never getMessage(), as observed 2026-09-05 against this repository's
     * own installed PHPUnit; re-derive via `grep -n 'failureDescription'
     * .build/vendor/phpunit/phpunit/src/Framework/Constraint/IsIdentical.php`.
     * EXCEPTION: a type-mismatched pair (e.g. one operand `null`) takes a
     * different path that DOES reach getMessage() instead — none of
     * IsIdentical::failureDescription()'s own branches (object/resource/
     * string/array) match a type-mismatched pair, so it falls through to the
     * base Constraint::failureDescription() in a DIFFERENT file
     * (.build/vendor/phpunit/phpunit/src/Framework/Constraint/Constraint.php),
     * which embeds the raw operand via Exporter::export() — not this class's own concern,
     * since every operand pair here is a string, but
     * tests/ScrubbedDiagnosticGuardTest.php's own class docblock polices it
     * for every guarded call and points back to THIS docblock for
     * the dated observation and re-derivation command above, so keep the
     * two consistent. self::fail() builds no ComparisonFailure at all, so
     * scrubbedForDiagnostic() on both operands here is the whole of what
     * can ever reach the console.
     */
    #[Test]
    public function readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand(): void
    {
        $readme = '`typescript 5.0.16 ::error title=pwned::forged`';

        $thrown = self::assertThrows(
            fn () => $this->assertReadmeToolVersionMatchesDevDependenciesPin($readme, ['typescript' => '5.0.16'], 'typescript'),
            AssertionFailedError::class,
            'The lockstep check did not reject a mismatched pin.',
        );

        self::assertMessageDoesNotForgeWorkflowCommand(
            $thrown->getMessage(),
            '::error title=pwned::forged',
            'The lockstep mismatch diagnostic forged a workflow command.',
        );

        self::assertNotInstanceOf(
            ExpectationFailedException::class,
            $thrown,
            'The mismatch threw an ExpectationFailedException carrying a ComparisonFailure — '
            . "PHPUnit's own CLI diff renderer would then print the raw, unscrubbed README/"
            . 'package.json content to the console, a sink getMessage() alone cannot see.',
        );
    }

    /**
     * The capture pattern's `[^`\n]*` exclusion — narrower than a plain
     * `[^`]*` — must stop the match at a literal newline: a markdown
     * code span that genuinely spans multiple lines around the version
     * number must be skipped entirely (preg_match() finds no match, so this
     * method returns false), not silently matched with the newline and
     * whatever follows it swallowed into the captured "version" — the
     * correctness bug the tightening exists to prevent, independent of the
     * scrub regression readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand()
     * above already covers.
     *
     * The devDependencies pin below deliberately equals the FULL buggy
     * capture ("5.0.16\nunexpected trailing content"), not the real
     * "5.0.16" — on a reversion of the `[^`\n]*` fix, the buggy `[^`]*`
     * pattern would capture that whole multi-line span into $matches[1],
     * and assertReadmeToolVersionMatchesDevDependenciesPin()'s own internal
     * mismatch check (`$matches[1] !== $actual`) would then compare it
     * against whatever $actual is. Pinning a real "5.0.16" there would make
     * that internal check itself fail on a REVERTED regex (mismatch:
     * "5.0.16\nunexpected trailing content" !== "5.0.16"), so the test would
     * go red via that unrelated check instead of via the
     * self::assertFalse() line below, on the wrong assertion's own message.
     * Matching the pin to the full buggy capture makes that internal check
     * pass on a reverted regex, so execution reaches self::assertFalse()
     * and fails with its own authored message instead.
     */
    #[Test]
    public function readmeToolVersionCaptureRejectsACodeSpanSpanningANewline(): void
    {
        $readme = "`typescript 5.0.16\nunexpected trailing content`";

        $matched = $this->assertReadmeToolVersionMatchesDevDependenciesPin(
            $readme,
            ['typescript' => "5.0.16\nunexpected trailing content"],
            'typescript',
        );

        self::assertFalse(
            $matched,
            'The tool-version capture matched across a literal newline inside the code span instead of skipping the multi-line span entirely.',
        );
    }
}
