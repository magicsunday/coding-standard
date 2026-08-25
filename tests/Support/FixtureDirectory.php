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

use function bin2hex;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function is_link;
use function json_encode;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const E_WARNING;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * A throwaway fixture directory, real on disk, that a test writes consumer
 * config files into before invoking a gate against it. Replaces
 * tests/harness.sh's harness_workdir() (mktemp -d + EXIT trap) and the
 * hand-built JSON heredocs in manifest_fixture() — PHPUnit's own tearDown()
 * lifecycle replaces the bash EXIT trap, so no signal handling is needed here.
 * cleanup() tolerates being called more than once (directly, then again from
 * tearDown()), the same way the trap's `rm -rf` silently no-ops on an
 * already-removed path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
final readonly class FixtureDirectory
{
    /**
     * The real, absolute path to this fixture's throwaway root.
     */
    private string $path;

    /**
     * Creates a real temporary directory with a collision-free name.
     *
     * The path is generated locally (not reserved via tempnam()) so mkdir()
     * is the only filesystem call that decides existence — no unlink()-then-
     * recreate gap for a co-resident process to win a symlink race in.
     *
     * @throws RuntimeException If mkdir() cannot create the fixture directory.
     */
    public function __construct()
    {
        $path = sprintf('%s/gate-fixture-%s', sys_get_temp_dir(), bin2hex(random_bytes(16)));

        if (!mkdir($path, 0o700)) {
            throw new RuntimeException(sprintf('Could not create fixture directory: %s', $path));
        }

        $this->path = $path;
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
     * @return void
     *
     * @throws RuntimeException If intermediate directories cannot be created or the file cannot be written.
     */
    public function writeJson(string $relativePath, array $data): void
    {
        $target = sprintf('%s/%s', $this->path, $relativePath);
        $dir    = dirname($target);

        if (!is_dir($dir)) {
            // Scoped, not @-suppressed: a blocked intermediate segment (e.g.
            // a plain file already occupying that path) raises a native PHP
            // warning here that PHPUnit's zero-tolerance policy would turn
            // into a risky test; the check below already converts the
            // failure into this descriptive RuntimeException, so the raw
            // warning is redundant noise, not lost information.
            set_error_handler(static fn (): bool => true, E_WARNING);

            try {
                $created = mkdir($dir, 0o700, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        // Scoped, not @-suppressed: an unwritable target (e.g. blocked by an
        // existing directory, or a permission failure) raises the same kind
        // of warning; the check below already converts every failure shape
        // — false or a short write — into this descriptive RuntimeException.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $written = file_put_contents($target, $json);
        } finally {
            restore_error_handler();
        }

        if ($written !== strlen($json)) {
            throw new RuntimeException(sprintf('Could not write fixture file: %s', $target));
        }
    }

    /**
     * Removes this fixture directory and everything under it. A no-op when
     * the directory no longer exists (idempotent, so a test may call this
     * itself and still let tearDown() call it again without failing).
     *
     * @return void
     *
     * @throws RuntimeException If the directory or any file cannot be removed.
     */
    public function cleanup(): void
    {
        $this->removeRecursively($this->path);
    }

    /**
     * A symlink entry is removed as itself, never followed: is_dir() and
     * scandir() both resolve through a symlink to its target, so checking
     * is_link() first is what keeps a symlinked entry from routing real
     * files outside this fixture into the recursive delete below.
     *
     * @param string $path The path to remove.
     *
     * @return void
     *
     * @throws RuntimeException If a file, directory or symlink cannot be removed.
     */
    private function removeRecursively(string $path): void
    {
        if (is_link($path)) {
            if (!unlink($path)) {
                throw new RuntimeException(sprintf('Could not remove symlink: %s', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            if (!file_exists($path)) {
                return;
            }

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
            if (($entry === '.') || ($entry === '..')) {
                continue;
            }

            $this->removeRecursively(sprintf('%s/%s', $path, $entry));
        }

        if (!rmdir($path)) {
            throw new RuntimeException(sprintf('Could not remove directory: %s', $path));
        }
    }
}
