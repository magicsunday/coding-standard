<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Defines isVersionTagShaped() for the PHP gates that decide whether a token
 * could name a `git tag` this package cuts.
 *
 * Extracted out of tests/check-version-lockstep.php's own `$shape` local once
 * tests/check-release-tag-lockstep.php (GH-42) needed the identical shape check
 * against a second string: there, package.json's `version` itself, used to build
 * a `refs/tags/<version>` argument handed to `git`, where a token shaped
 * differently than a real tag would either resolve to nothing meaningful or, if
 * ever passed through unchecked, isn't guaranteed to be free of characters `git`
 * treats specially. Keeping one definition also keeps the two gates from ever
 * silently drifting apart on what counts as a version tag — the exact class of
 * bug GH-36 is about, one document at a time.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Whether $token is shaped like a semantic-version git tag this package cuts
 * (`1.8.0`, `1.2.3-beta.1+build.5`, `1`, `1.0`, ...).
 *
 * It has to hold for a prerelease and build metadata too: each `-`/`+` group is
 * dot-separated alphanumerics rather than a class containing `.`, and the group
 * repeats, so `1.2.3-beta.1+build.5` is taken whole instead of truncated at the
 * prerelease.
 *
 * `[.-]` in the inner class was tried and is `-`-ambiguous: a `-` could open a
 * new group through the outer `[-+]` or continue the current one, so
 * `1(-a)^N!` has 2^N parses and a trailing `!` forces all of them — measured on
 * PHP 8.5, at N=20 preg_match() returns FALSE with `Backtrack limit exhausted`,
 * turning the verdict into a function of `pcre.backtrack_limit`. Dropping `-`
 * from the inner class removes the ambiguity without narrowing the language:
 * the OUTER group repeats, so `1.0.0-alpha-1` still matches, as two groups
 * rather than one. Verified identical over `1.8.0`, `1.2.3-beta.1+build.5`,
 * `1.0.0-alpha-1`, `1`, `1.0`, `2.0.0+build.5`, `1.8.0_hotfix`, `1.7.0..`,
 * `v1.8.0`, `1.8.0-`, `1.8.0.`, `''`, `'1.8.0 '` and `"1.8.0\n"`.
 *
 * `\d`/`[0-9A-Za-z]`, never `/u`: this is a semantic-version shape, ASCII by
 * definition, not by anything `git check-ref-format` itself enforces — that
 * command happily accepts a non-ASCII ref name (`git check-ref-format
 * 'refs/tags/é'` exits 0, verified). The regex's own character classes are
 * what confines a match to ASCII, so there is no multi-byte text for a
 * Unicode mode to protect here regardless of what git itself would allow.
 *
 * @param string $token The candidate tag name.
 *
 * @return bool Whether $token is shaped like a version tag.
 */
function isVersionTagShaped(string $token): bool
{
    return preg_match('~^\d+(?:\.\d+)*(?:[-+][0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)*$~D', $token) === 1;
}
