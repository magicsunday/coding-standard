<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function preg_quote;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function sprintf;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

use const E_WARNING;
use const JSON_THROW_ON_ERROR;

/**
 * Tests for FixtureDirectory, the throwaway per-test config-fixture directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversClass(FixtureDirectory::class)]
final class FixtureDirectoryTest extends TestCase
{
    /**
     * The fixture under test, cleaned up in tearDown() so a failed assertion
     * never leaks a throwaway directory into the system temp dir. cleanup()
     * tolerates being called again after a test's own explicit call, the
     * same idempotence cleanupIsIdempotentWhenCalledTwice() below pins.
     */
    private ?FixtureDirectory $fixture = null;

    /**
     * Removes this test's fixture directory, if one was created.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->fixture?->cleanup();

        parent::tearDown();
    }

    /**
     * Verifies that the fixture path is a real, existing directory.
     */
    #[Test]
    public function pathIsARealExistingDirectory(): void
    {
        $this->fixture = new FixtureDirectory();

        self::assertTrue(is_dir($this->fixture->path()));
    }

    /**
     * Verifies that writeJson encodes data as valid JSON at the specified path.
     */
    #[Test]
    public function writeJsonWritesValidJsonAtTheGivenRelativePath(): void
    {
        $this->fixture = new FixtureDirectory();
        $this->fixture->writeJson('biome.json', ['linter' => ['enabled' => true]]);

        $written = file_get_contents(sprintf('%s/biome.json', $this->fixture->path()));

        self::assertNotFalse($written);
        self::assertSame(['linter' => ['enabled' => true]], json_decode($written, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Verifies that writeJson creates intermediate directories automatically.
     */
    #[Test]
    public function writeJsonCreatesIntermediateDirectories(): void
    {
        $this->fixture = new FixtureDirectory();
        $this->fixture->writeJson('nested/dir/tsconfig.json', ['compilerOptions' => []]);

        self::assertFileExists(sprintf('%s/nested/dir/tsconfig.json', $this->fixture->path()));
    }

    /**
     * Verifies that cleanup removes the fixture directory entirely.
     */
    #[Test]
    public function cleanupRemovesTheDirectory(): void
    {
        $this->fixture = new FixtureDirectory();
        $path          = $this->fixture->path();
        $this->fixture->cleanup();

        self::assertFalse(is_dir($path));
    }

    /**
     * Verifies that cleanup tolerates being called more than once — the same
     * tolerance tests/harness.sh's `rm -rf` EXIT trap has for an
     * already-removed path, which this class replaces.
     */
    #[Test]
    public function cleanupIsIdempotentWhenCalledTwice(): void
    {
        $this->fixture = new FixtureDirectory();
        $this->fixture->cleanup();

        $this->fixture->cleanup();

        self::assertFalse(is_dir($this->fixture->path()));
    }

    /**
     * Verifies that writeJson throws when the target path cannot be written.
     * A directory sitting at the target path makes file_put_contents() return
     * false, driving the guard's outright-failure arm to true. The sibling
     * short-write arm (a write that starts but is truncated) is accepted as
     * untested: forcing a genuine partial file_put_contents() write portably
     * needs a custom stream wrapper or a filesystem quota — unlike the
     * intermediate-directory mkdir() branch below, a plain file placed at
     * the path segment reliably forces mkdir() to fail regardless of
     * permissions or process privilege, so that branch gets its own test
     * instead of sharing this exemption.
     */
    #[Test]
    public function writeJsonThrowsWhenTheTargetPathCannotBeWritten(): void
    {
        $this->fixture = new FixtureDirectory();
        mkdir(sprintf('%s/blocked.json', $this->fixture->path()), 0o700);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            sprintf('/^%s/', preg_quote('Could not write fixture file: ', '/'))
        );

        $this->fixture->writeJson('blocked.json', ['key' => 'value']);
    }

    /**
     * Verifies that writeJson throws when an intermediate directory
     * component cannot be created — a plain file already occupying that
     * path segment makes mkdir() fail regardless of permissions or process
     * privilege, unlike the permission-based failures this class otherwise
     * cannot portably force under a root-run CI container.
     */
    #[Test]
    public function writeJsonThrowsWhenAnIntermediateDirectoryCannotBeCreated(): void
    {
        $this->fixture = new FixtureDirectory();
        file_put_contents(sprintf('%s/blocked', $this->fixture->path()), 'not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            sprintf('/^%s/', preg_quote('Could not create directory: ', '/'))
        );

        $this->fixture->writeJson('blocked/sub.json', ['key' => 'value']);
    }

    /**
     * Verifies that cleanup removes a symlinked entry as itself, never
     * following it into the real directory it points at — a symlink to a
     * directory outside the fixture must not have its contents deleted.
     */
    #[Test]
    public function cleanupRemovesASymlinkWithoutFollowingItIntoItsTarget(): void
    {
        $externalDir = sprintf('%s/gate-fixture-symlink-target-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        try {
            // The external fixture setup is inside the try too: a failure
            // here (mkdir(), the sentinel write, or FixtureDirectory's own
            // constructor) must still reach the finally below, or it leaks
            // $externalDir the same way an assertion failure further down
            // would without this guard.
            mkdir($externalDir, 0o700);
            file_put_contents(sprintf('%s/sentinel.txt', $externalDir), 'still here');

            $this->fixture = new FixtureDirectory();

            // Scoped, not @-suppressed: a failed symlink() raises a native
            // PHP warning that PHPUnit's zero-tolerance policy would turn
            // into a risky test before the check below ever gets to route
            // it to markTestSkipped() instead.
            set_error_handler(static fn (): bool => true, E_WARNING);

            try {
                $linked = symlink($externalDir, sprintf('%s/link', $this->fixture->path()));
            } finally {
                restore_error_handler();
            }

            if (!$linked) {
                self::markTestSkipped('This platform could not create a symlink.');
            }

            // cleanup() itself is inside the try: the regression this test
            // exists to catch (removeRecursively() following the symlink)
            // makes cleanup() THROW — rmdir() fails on the symlink's own
            // path once its target's contents are gone — so the external
            // fixture must still be reachable for removal on that path too,
            // not only when the later assertion is what fails. tearDown()'s
            // own cleanup() call afterwards is then a no-op (idempotent),
            // the same safety net every other test in this class relies on.
            $this->fixture->cleanup();

            self::assertFileExists(sprintf('%s/sentinel.txt', $externalDir));
        } finally {
            // A failure above is exactly the regression this test exists to
            // catch; guard each removal so that case does not also leak the
            // external fixture, nor mask the original failure with a
            // secondary warning about a file already gone.
            if (is_dir($externalDir)) {
                if (file_exists(sprintf('%s/sentinel.txt', $externalDir))) {
                    unlink(sprintf('%s/sentinel.txt', $externalDir));
                }

                rmdir($externalDir);
            }
        }
    }
}
