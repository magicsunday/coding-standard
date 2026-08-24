<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test\Support;

use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * A throwaway fixture directory, real on disk, that a test writes consumer
 * config files into before invoking a gate against it. Replaces
 * tests/harness.sh's harness_workdir() (mktemp -d + EXIT trap) and the
 * hand-built JSON heredocs in manifest_fixture() — PHPUnit's own tearDown()
 * lifecycle replaces the bash EXIT trap, so no signal handling is needed here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final class FixtureDirectory
{
    /**
     * The real, absolute path to this fixture's throwaway root.
     */
    private readonly string $path;

    /**
     * Creates a real temporary directory with a collision-free name.
     *
     * @throws RuntimeException If tempnam() cannot reserve a temporary path.
     * @throws RuntimeException If mkdir() cannot create the fixture directory.
     */
    public function __construct()
    {
        $stub = tempnam(sys_get_temp_dir(), 'gate-fixture-');

        if ($stub === false) {
            throw new RuntimeException('Could not reserve a temporary path for a fixture directory.');
        }

        // tempnam() creates a FILE at $stub; replace it with a directory of the
        // same name so every fixture root is still guaranteed collision-free.
        unlink($stub);

        if (!mkdir($stub, 0o700) && !is_dir($stub)) {
            throw new RuntimeException(sprintf('Could not create fixture directory: %s', $stub));
        }

        $this->path = $stub;
    }

    /**
     * @return string The real, absolute path to this fixture's throwaway root.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Writes $data as JSON at $relativePath inside this fixture directory,
     * creating any missing intermediate directories.
     *
     * @param string               $relativePath Path relative to this fixture's root.
     * @param array<string, mixed> $data         The data to encode as JSON.
     *
     * @throws RuntimeException If intermediate directories cannot be created.
     *
     * @return void
     */
    public function writeJson(string $relativePath, array $data): void
    {
        $target = sprintf('%s/%s', $this->path, $relativePath);
        $dir    = dirname($target);

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
        }

        file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Removes this fixture directory and everything under it.
     *
     * @return void
     */
    public function cleanup(): void
    {
        $this->removeRecursively($this->path);
    }

    /**
     * @param string $path The path to remove.
     *
     * @throws RuntimeException If a file or directory cannot be removed.
     *
     * @return void
     */
    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            if (!unlink($path)) {
                throw new RuntimeException(sprintf('Could not remove file: %s', $path));
            }

            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new RuntimeException(sprintf('Could not read directory: %s', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursively(sprintf('%s/%s', $path, $entry));
        }

        if (!rmdir($path)) {
            throw new RuntimeException(sprintf('Could not remove directory: %s', $path));
        }
    }
}
