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

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

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
     * Verifies that the fixture path is a real, existing directory.
     */
    #[Test]
    public function pathIsARealExistingDirectory(): void
    {
        $fixture = new FixtureDirectory();

        self::assertTrue(is_dir($fixture->path()));

        $fixture->cleanup();
    }

    /**
     * Verifies that writeJson encodes data as valid JSON at the specified path.
     */
    #[Test]
    public function writeJsonWritesValidJsonAtTheGivenRelativePath(): void
    {
        $fixture = new FixtureDirectory();
        $fixture->writeJson('biome.json', ['linter' => ['enabled' => true]]);

        $written = file_get_contents(sprintf('%s/biome.json', $fixture->path()));

        self::assertNotFalse($written);
        self::assertSame(['linter' => ['enabled' => true]], json_decode($written, true, flags: JSON_THROW_ON_ERROR));

        $fixture->cleanup();
    }

    /**
     * Verifies that writeJson creates intermediate directories automatically.
     */
    #[Test]
    public function writeJsonCreatesIntermediateDirectories(): void
    {
        $fixture = new FixtureDirectory();
        $fixture->writeJson('nested/dir/tsconfig.json', ['compilerOptions' => []]);

        self::assertFileExists(sprintf('%s/nested/dir/tsconfig.json', $fixture->path()));

        $fixture->cleanup();
    }

    /**
     * Verifies that cleanup removes the fixture directory entirely.
     */
    #[Test]
    public function cleanupRemovesTheDirectory(): void
    {
        $fixture = new FixtureDirectory();
        $path    = $fixture->path();
        $fixture->cleanup();

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
        $fixture = new FixtureDirectory();
        $fixture->cleanup();

        $fixture->cleanup();

        self::assertFalse(is_dir($fixture->path()));
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
        mkdir($externalDir, 0o700);
        file_put_contents(sprintf('%s/sentinel.txt', $externalDir), 'still here');

        $fixture = new FixtureDirectory();
        symlink($externalDir, sprintf('%s/link', $fixture->path()));

        $fixture->cleanup();

        try {
            self::assertFileExists(sprintf('%s/sentinel.txt', $externalDir));
        } finally {
            // A failed assertion above is exactly the regression this test
            // exists to catch (cleanup followed the symlink and consumed the
            // target); guard each removal so that case does not also leak
            // the external fixture, nor mask the assertion failure with a
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
