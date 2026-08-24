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

use function file_get_contents;
use function is_dir;
use function json_decode;
use function sprintf;

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
        self::assertSame(['linter' => ['enabled' => true]], json_decode($written, true, flags: \JSON_THROW_ON_ERROR));

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
}
