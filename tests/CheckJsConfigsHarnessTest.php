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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function preg_match;
use function rmdir;
use function str_contains;
use function unlink;

// scrubReportControlBytes() — the control-byte-strip + legacy-`##[`-break core
// bin/support/safe-report-value.php's own safeReportValue() applies to a shipped
// gate's own report line, and ScrubbedDiagnostics::scrubbedForDiagnostic() (inherited by
// this class, which extends GateTestCase through AbstractJsConfigsTestCase —
// see that method's own docblock for why the `::` step lives there rather
// than inside this shared core) shares for the same reason. Required here directly for
// scrubReportControlBytesReplacesControlBytesWithAQuestionMark() below, which
// drives the shared core itself rather than the inherited wrapper.
require_once __DIR__ . '/../bin/support/safe-report-value.php';

/**
 * Regression tests for AbstractJsConfigsTestCase's own harness, split out of
 * the former tests/CheckJsConfigsTest.php (#75): tearDown()'s
 * restore-failure handling and its invalidation of the shared
 * packagedConsumer() cache, makeTempDir()'s mkdir()-failure branch, the
 * scrubbing at the real npm pack/init/install throw sites
 * (requirePackedTarball()/requireSuccessfulInit()/requireSuccessfulInstall()),
 * assertMessageDoesNotForgeWorkflowCommand()'s own true-positive branch, and
 * the shared scrub core (scrubReportControlBytes()) plus the inherited
 * scrubbedForDiagnostic() wrapper, driven directly.
 *
 * Extends AbstractJsConfigsTestCase because every guarded helper is declared
 * there; see that class's own docblock for the split, the shared-cache
 * semantics, and why `#[Group('js-packaging')]` runs on one CI matrix leg
 * only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
#[Group('js-packaging')]
final class CheckJsConfigsHarnessTest extends AbstractJsConfigsTestCase
{
    /**
     * assertMessageDoesNotForgeWorkflowCommand()'s own true-positive branch:
     * every real call site of it only ever exercises the "needle
     * absent" (passing) path, so this drives it directly with a haystack
     * that genuinely contains the needle, proving the helper's own
     * str_contains() + self::fail() check actually fires rather than always
     * passing regardless of input.
     */
    #[Test]
    public function assertMessageDoesNotForgeWorkflowCommandFailsWhenTheNeedleIsPresent(): void
    {
        self::assertThrows(
            static fn () => self::assertMessageDoesNotForgeWorkflowCommand('haystack carrying ::error::forged', '::error::forged', 'label'),
            AssertionFailedError::class,
            'assertMessageDoesNotForgeWorkflowCommand() did not fail when the haystack genuinely carries the needle.',
        );
    }

    // -------------------------------------------------------------------
    // Hardening guards this suite's own harness relies on — tearDown()'s
    // restore-failure handling and makeTempDir()'s mkdir()-failure branch —
    // had no dedicated regression test before the two methods below (a
    // broken guard would have shipped silently).
    // -------------------------------------------------------------------

    /**
     * tearDown()'s own restore-failure handling: a mutation whose restore
     * fails must be REPORTED (naming the path) and must invalidate
     * self::$packagedConsumer, so the next test that calls packagedConsumer()
     * rebuilds a fresh, uncorrupted consumer instead of silently inheriting
     * the corruption. Calls tearDown() directly — it is `protected` and this
     * is a test method on the same class — then lets PHPUnit's own automatic
     * tearDown() call run once more afterwards as a no-op, since the manual
     * call already reset $this->consumerFileMutations.
     *
     * The mutation exercises the `$original === null` branch (a file
     * mutateConsumerFile() created rather than one it is restoring), then
     * replaces that file with a directory before tearDown() runs: unlink()
     * on a directory fails deterministically regardless of permissions,
     * mirroring FixtureDirectoryTest's own file-blocks-mkdir() pattern in the
     * opposite direction (a directory blocking unlink() here).
     *
     * Once both assertions hold, the consumer this test invalidated is put
     * back into the cache: the probe was the only thing it ever touched, and
     * the finally block has already removed it, so the consumer is back at
     * its baseline. Without that, the next test to call packagedConsumer() —
     * since the split (#75) possibly in a sibling CheckJsConfigs*Test suite,
     * in whatever order PHPUnit runs them — would repeat the whole
     * pack-and-install for a corruption that never happened.
     */
    #[Test]
    public function tearDownReportsEveryFailedPathAndInvalidatesTheSharedConsumerCache(): void
    {
        $consumer    = self::packagedConsumer();
        $consumerDir = $consumer['consumerDir'];
        $path        = $this->mutateConsumerFile($consumerDir, 'src/teardown-guard-probe.ts', "export const value = 1;\n");

        unlink($path);
        mkdir($path, 0o700);

        try {
            $thrown = self::assertThrows(
                fn () => $this->tearDown(),
                RuntimeException::class,
                'tearDown() did not report the failed restore.',
            );
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        self::assertStringContainsString($path, $thrown->getMessage());
        self::assertNull(
            self::$packagedConsumer,
            'A failed restore must invalidate the shared packagedConsumer cache so the next test rebuilds it.',
        );

        self::$packagedConsumer = $consumer;
    }

    /**
     * makeTempDir()'s own mkdir()-failure branch. The random suffix mkdir()
     * appends cannot be pre-occupied by name (unlike FixtureDirectoryTest's
     * own fixed-name probes), and overriding sys_get_temp_dir() itself via
     * `putenv("TMPDIR=...")` does not work here: as observed 2026-09-05
     * against the installed PHP (8.3-8.5), sys_get_temp_dir() memoizes its
     * result for the process lifetime — a putenv() call made after ANYTHING
     * else in the same process has already called sys_get_temp_dir(),
     * including PHPUnit's own harness, has no effect at all, even under
     * #[RunInSeparateProcess]. makeTempDir()'s own optional $baseDirectory
     * parameter exists for exactly this: a plain FILE (not a directory)
     * passed as the base forces mkdir()'s recursive creation to fail
     * deterministically, regardless of the random suffix.
     */
    #[Test]
    public function makeTempDirThrowsWhenMkdirCannotCreateTheDirectory(): void
    {
        $blocked = $this->fixture()->path() . '/not-a-directory';
        file_put_contents($blocked, 'blocking mkdir');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Could not create temporary directory: /');

        self::makeTempDir('probe', $blocked);
    }

    /**
     * Shared body for the three npm-pack/init/install "forgery regression"
     * tests below: each already ran $process against a deliberately poisoned
     * fixture that makes npm's own diagnostic echo the poisoned `##[`
     * sequence back verbatim, and each needs the same two things proved — the
     * control fixture actually traps for the claimed reason, and the REAL
     * production throw site named by $throwSite (one of
     * requirePackedTarball()/requireSuccessfulInit()/requireSuccessfulInstall()
     * in AbstractJsConfigsTestCase, invoked against the SAME already-run $process) throws a
     * RuntimeException whose own getMessage() no longer carries the poison.
     * Catching $throwSite()'s real exception rather than hand-reconstructing
     * the expected message string locally is what makes this discriminating:
     * reverting scrubbedForDiagnostic()'s wrap at that production throw site
     * fails the final assertion here, not merely leaves a differently-worded
     * but still-green test standing — a defect a regression test that only
     * hand-reconstructs the expected message locally, rather than catching
     * $throwSite()'s own real exception, would not actually catch.
     *
     * The control-fixture check below is a manual str_contains() + self::fail(),
     * the same shape AbstractJsConfigsTestCase::assertMessageDoesNotForgeWorkflowCommand() is
     * built from — $process->getErrorOutput() can legitimately still carry
     * the poison by this fixture's own deliberate construction, so a real
     * failure of assertStringContainsString() there would forge the very
     * annotation this test exists to prove is prevented; see that method's
     * own docblock for the dated PHPUnit Constraint::fail()/
     * failureDescription() re-embedding mechanism, not repeated here. The
     * final check on $thrown->getMessage() delegates to that same helper
     * directly.
     *
     * @param Process          $process   The already-run, deliberately failing npm subprocess.
     * @param callable(): void $throwSite Invokes the real production method that re-derives $process's own outcome and throws.
     * @param string           $label     A short description of the operation, used in every assertion message.
     *
     * @return void
     */
    private function assertRealThrowSiteCannotForgeAWorkflowCommand(Process $process, callable $throwSite, string $label): void
    {
        self::assertFalse(
            $process->isSuccessful(),
            self::diagnosticMessage("{$label} unexpectedly succeeded — this control fixture is not testing what it claims.", $process->getOutput() . $process->getErrorOutput()),
        );

        if (!str_contains($process->getErrorOutput(), '##[')) {
            self::fail(self::diagnosticMessage("{$label} — the control fixture's own raw npm error no longer carries the poisoned sequence; this test is not exercising the trap it claims to.", $process->getErrorOutput()));
        }

        $thrown = self::assertThrows(
            $throwSite,
            RuntimeException::class,
            "{$label} — the real production throw site did not throw a RuntimeException.",
        );

        self::assertMessageDoesNotForgeWorkflowCommand(
            $thrown->getMessage(),
            '##[',
            "{$label} — the scrubbed exception message still carries the legacy workflow-command prefix.",
        );
    }

    /**
     * The harder case CheckJsConfigsToolPinsTest::buildToolsFromDevDependenciesThrowsWithoutForgingAWorkflowCommand()
     * cannot reach: a devDependency VALUE BUILD_TOOLS_SCRIPT's own
     * unsafeAsArgument() does not reject at all (a non-empty string, no
     * whitespace, no NUL, no leading dash) still reaches npm's own argv as
     * the real `npm install` call packagedConsumer() drives, and npm's own
     * local package-name validation — no registry/network access needed —
     * quotes the offending spec verbatim in its error text, carrying the
     * embedded `##[` straight through to this class's own RuntimeException
     * message unless scrubbedForDiagnostic() breaks it first — measured
     * directly against the installed npm (2026-09-05): `npm error code
     * EINVALIDPACKAGENAME` / `npm error Invalid package name
     * "forges-a-workflow-command-##[error]forged" of package
     * "forges-a-workflow-command-##[error]forged@0.0.0-does-not-exist": name
     * can only contain URL-friendly characters.`. Drives a real
     * `npm install` directly against a throwaway project rather than through
     * packagedConsumer() itself, whose only devDependencies source is this
     * repository's own real package.json — then, discriminatingly, calls
     * packagedConsumer()'s own extracted requireSuccessfulInstall() against
     * this SAME real $install and asserts on the RuntimeException it actually
     * throws, rather than reconstructing the expected message locally.
     */
    #[Test]
    public function npmInstallFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path();

        $init = new Process(['npm', 'init', '-y'], $dir);
        $init->run();

        self::assertTrue($init->isSuccessful(), self::diagnosticMessage('npm init -y control failed.', $init->getErrorOutput()));

        // Not rejected by unsafeAsArgument() (a non-empty string, no
        // whitespace, no NUL, no leading dash) but not a URL-friendly npm
        // package name either.
        $poisonedTool = 'forges-a-workflow-command-##[error]forged@0.0.0-does-not-exist';

        $install = new Process(['npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts', $poisonedTool], $dir);
        $install->setTimeout(120.0);
        $install->run();

        $this->assertRealThrowSiteCannotForgeAWorkflowCommand(
            $install,
            static function () use ($install): void {
                self::requireSuccessfulInstall($install);
            },
            'npm install of a deliberately invalid package name',
        );
    }

    /**
     * The `npm pack` throw site's own regression twin: a deliberately
     * malformed package.json carrying the poisoned sequence makes npm's own
     * JSON-parse error quote a snippet of the raw file content verbatim —
     * measured directly against the installed npm (2026-09-05): "npm error
     * JSON.parse … while parsing near \"{ \"name\": \"x\", ##[error]forged
     * BROK...\"". No registry/network access needed. Drives a real
     * `npm pack` directly rather than through packagedConsumer(), whose own
     * package.json is always this repository's real, well-formed one — then,
     * discriminatingly, calls packagedConsumer()'s own extracted
     * requirePackedTarball() against this SAME real $pack and asserts on the
     * RuntimeException it actually throws, rather than reconstructing the
     * expected message locally.
     */
    #[Test]
    public function npmPackFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path();
        file_put_contents("{$dir}/package.json", '{ "name": "x", ##[error]forged BROKEN JSON');

        $pack = new Process(['npm', 'pack', '--ignore-scripts', '--pack-destination', $dir, '--loglevel=error'], $dir);
        $pack->setTimeout(120.0);
        $pack->run();

        $this->assertRealThrowSiteCannotForgeAWorkflowCommand(
            $pack,
            static function () use ($pack, $dir): void {
                self::requirePackedTarball($pack, $dir);
            },
            'npm pack over a deliberately malformed package.json',
        );
    }

    /**
     * The `npm init -y` throw site's own regression twin: a project
     * directory whose own NAME carries the poisoned sequence makes npm's
     * own package-name validation echo it back verbatim — measured directly
     * against the installed npm (2026-09-05): `npm error Invalid name:
     * "poisoned-##[error]forged-dir"`. No registry/network access needed.
     * Drives a real `npm init -y` directly rather than through
     * packagedConsumer(), whose own consumer directory name never carries
     * consumer-controlled content — then, discriminatingly, calls
     * packagedConsumer()'s own extracted requireSuccessfulInit() against this
     * SAME real $init and asserts on the RuntimeException it actually
     * throws, rather than reconstructing the expected message locally.
     */
    #[Test]
    public function npmInitFailureCannotForgeAWorkflowCommandThroughTheExceptionMessage(): void
    {
        $dir = $this->fixture()->path() . '/poisoned-##[error]forged-dir';
        mkdir($dir, 0o755, true);

        $init = new Process(['npm', 'init', '-y'], $dir);
        $init->setTimeout(120.0);
        $init->run();

        $this->assertRealThrowSiteCannotForgeAWorkflowCommand(
            $init,
            static function () use ($init): void {
                self::requireSuccessfulInit($init);
            },
            'npm init -y inside a deliberately poisoned directory name',
        );
    }

    /**
     * scrubReportControlBytes()'s own control-byte-stripping half, direct
     * and independent of any real subprocess invocation: every regression
     * test above only ever feeds a `##[`- or `::`-carrying value through the
     * scrub and checks that PREFIX is broken, so a broken or narrowed
     * `[\x00-\x1F\x7F]` character class (an off-by-one, a typo'd range)
     * could ship silently, unnoticed by any of them. The probe includes
     * `\x00` (NUL) alongside a mid-range C0 byte and DEL so a character class
     * narrowed to `[\x01-\x1F\x7F]` (dropping NUL) would still pass unchanged
     * without it — not because this function's own docblock singles NUL out
     * as the reason it exists (it groups every C0/DEL byte together instead),
     * and bin/support/safe-report-value.php's own wording ("reach column 0")
     * is about an embedded NEWLINE, not NUL specifically. Measured directly against the installed PHP (2026-09-05):
     * `scrubReportControlBytes("a\x00b\x1fc\x7fd")` produces `"a?b?c?d"` —
     * \x00 (NUL), \x1f (a C0 control byte) and \x7f (DEL) each replaced by a
     * literal `?`, the ordinary ASCII bytes either side left untouched.
     * Calls the shared bin/support/safe-report-value.php function directly
     * (required near the top of this file), not the inherited
     * ScrubbedDiagnostics::scrubbedForDiagnostic() wrapper, since the property under
     * test belongs to the shared core.
     */
    #[Test]
    public function scrubReportControlBytesReplacesControlBytesWithAQuestionMark(): void
    {
        self::assertSame('a?b?c?d', scrubReportControlBytes("a\x00b\x1fc\x7fd"));
    }

    /**
     * ScrubbedDiagnostics::scrubbedForDiagnostic()'s own `::`-breaking step, direct and
     * independent of any real subprocess invocation — the three forgery-regression
     * tests above only ever poison the LEGACY `##[` prefix, so a missing or
     * reverted `str_replace('::', ':?:', ...)` step could ship silently, unnoticed
     * by any of them. Composes the value the same way every real call site in
     * this file does: a literal `\n` from the surrounding message text,
     * immediately followed by $value — the only position in the final
     * message a `::` can ever open a line, since scrubReportControlBytes()
     * has already turned any embedded control byte (a raw newline included)
     * in $value itself into `?` before this method's own `::` step ever
     * runs. A poisoned value NOT placed at that position would not exercise
     * the property this method exists for at all.
     *
     * Asserted via assertFalse() on a boolean, never
     * assertDoesNotMatchRegularExpression() directly against $message —
     * $message is built from the very poisoned literal this test exists to
     * prove is broken, so it can legitimately still carry the unbroken `::`
     * prefix on exactly the regression this test exists to catch; see
     * AbstractJsConfigsTestCase::assertMessageDoesNotForgeWorkflowCommand()'s own docblock for the
     * dated PHPUnit Constraint::fail()/failureDescription() re-embedding
     * mechanism this guards against, not repeated here. assertFalse()'s own
     * failureDescription only ever exports the two BOOLEAN operands, never
     * $message, so the custom message text is what carries the (re-scrubbed)
     * diagnostic instead — built only on the failing branch, and only from a
     * value already passed back through scrubbedForDiagnostic() a second time,
     * never raw.
     */
    #[Test]
    public function scrubbedForDiagnosticBreaksAWorkflowCommandOpenedWithTheModernPrefix(): void
    {
        $message     = self::diagnosticMessage('npm error', '::error title=pwned::forged');
        $stillForged = preg_match('/^[ \t]*::/m', $message) === 1;

        self::assertFalse(
            $stillForged,
            $stillForged ? self::diagnosticMessage('scrubbedForDiagnostic() failed to break the modern :: workflow-command prefix.', $message) : '',
        );
    }
}
