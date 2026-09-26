<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function chmod;
use function file_put_contents;
use function function_exists;
use function mkdir;
use function posix_getuid;
use function sprintf;
use function str_repeat;
use function symlink;

/**
 * Fixture-driven cases for the .gitattributes lockstep gate (GH-38),
 * tests/check-gitattributes-lockstep.php, migrated off
 * tests/check-gitattributes-lockstep-cases.sh (#71).
 *
 * Run against this repository alone, the gate only ever takes the happy
 * path — every applicable templates/gitattributes entry is already mirrored
 * in .gitattributes, so a green CI is indistinguishable from a gate that
 * cannot fail. These cases put it in each failing state on purpose. Like
 * CheckVersionLockstepTest, the gate is one of this package's own tests/
 * check-*.php scripts and needs no installed consumer fixture, so
 * GateTestCase's accept/reject/usage-error/report-is-inert exit-code contract
 * applies directly and this class needs no setUp() self-skip.
 *
 * Every case builds its repository under a `case/` subdirectory of this
 * test's fixture directory rather than at the fixture root itself, so the
 * path-containment cases have somewhere OUTSIDE the gate's root, but still
 * inside the throwaway fixture, to point a `..` template entry at — the role
 * the bash original's shared $work directory played.
 *
 * The bash original's bookkeeping self-test (harness_assert_no_stray_increments,
 * proving each assert_* wrapper moved exactly one counter) is not ported:
 * GateTestCase's own meta-suite already proves that generically for every
 * caller, and a failed PHPUnit decision throws rather than bumping a counter.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckGitattributesLockstepTest extends GateTestCase
{
    /**
     * The largest file the gate under test reads, in bytes. Mirrors
     * MAX_GITATTRIBUTES_BYTES in tests/check-gitattributes-lockstep.php.
     */
    private const int MAX_GITATTRIBUTES_BYTES = 1048576;

    /**
     * The report line every "applicable entry is missing" rejection below
     * carries for the fixture's `/.github` directory.
     */
    private const string GITHUB_MISSING = '/.github: missing `export-ignore`';

    /**
     * The canon: every applicable template entry is mirrored.
     */
    #[Test]
    public function acceptsWhenOwnGitattributesCarriesEveryApplicableTemplateEntry(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/.github');
        $this->makeDirectory($dir . '/tests');
        $this->writeTemplate($dir, "/.github    export-ignore\n/tests      export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n/tests      export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The qualifier: a template entry naming a path this repository does not
     * have is not required — the whole difficulty this gate exists to get
     * right (templates/gitattributes lists rector.php, infection.json5 and
     * friends for a CONSUMER, and this package ships none of them as a root
     * file of its own).
     */
    #[Test]
    public function acceptsATemplateEntryNamingAPathThisRepositoryDoesNotHave(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "/rector.php    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A commented-out template directive is not a requirement, even when the
     * path exists — templates/gitattributes keeps biome.json/tsconfig.json
     * export-ignore INACTIVE on purpose (a github: dependency's prepare
     * script needs them), and this gate must not resurrect that as a demand.
     * The on-disk artifact sits at "#/biome.json", not "biome.json": ltrim()
     * only strips a leading `/`, so an UN-skipped comment line would mis-parse
     * $matches[1] as the literal path "#/biome.json" — placing the file there
     * is what makes this case actually discriminate a removed comment-skip
     * guard (it would then resolve and, since .gitattributes never declares
     * "#/biome.json", flip this case to a rejection) rather than passing
     * either way regardless of whether the guard exists.
     */
    #[Test]
    public function acceptsACommentedOutTemplateDirectiveEvenThoughThePathExists(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/.github');
        $this->makeDirectory($dir . '/#');
        $this->writeTemplate($dir, "#/biome.json    export-ignore\n/.github        export-ignore\n");
        $this->writeFile($dir . '/#/biome.json', '');
        $this->writeOwn($dir, "/.github        export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * An applicable entry missing from .gitattributes entirely.
     */
    #[Test]
    public function rejectsAnApplicableTemplateEntryMissingFromGitattributesEntirely(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * A negated attribute must not satisfy the requirement — proves the
     * check matches the exact token `export-ignore`, not merely the path's
     * presence.
     */
    #[Test]
    public function rejectsANegatedExportIgnoreAttribute(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    -export-ignore\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * A LATER negation for the same path overrides an earlier positive — the
     * gitattributes(5) last-line-wins rule. The single-line case above cannot
     * catch a parser that only ever APPENDS on the positive token and never
     * removes on the negative one: such a parser reports this path satisfied
     * even though the file's real, git-effective state for it is NOT
     * export-ignored — a green-while-red gap proven by mutation (reverting
     * $parseExportIgnorePaths to the append-only shape turns this case's
     * rejection into a false accept, along with the same-line and
     * !export-ignore cases below, which share the same negation-handling
     * branch).
     */
    #[Test]
    public function rejectsWhenALaterNegationLineOverridesAnEarlierExportIgnore(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n/.github    -export-ignore\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * The same rule in the other direction: a later positive overrides an
     * earlier negation, so the path IS satisfied — proves this is genuinely
     * last-line-wins and not merely "any negation anywhere wins".
     */
    #[Test]
    public function acceptsWhenALaterExportIgnoreLineOverridesAnEarlierNegation(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    -export-ignore\n/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The SAME rule one level down: two tokens for one path on a SINGLE line,
     * not two lines. A parser deciding a line by "does export-ignore appear
     * anywhere in its attribute list" (checked before "-export-ignore") cannot
     * tell `export-ignore -export-ignore` (real git verdict: unset) from
     * `-export-ignore export-ignore` (real git verdict: set) — both tokens are
     * simply present either way. Only iterating the tokens in order and
     * letting each overwrite the state as it is reached reproduces git's
     * real, git-effective last-TOKEN-wins rule here too (verified against a
     * real checkout: `git check-attr export-ignore` on a line ending in
     * `-export-ignore` reports `unset` regardless of an earlier token on that
     * same line).
     */
    #[Test]
    public function rejectsWhenALaterNegationTokenOnTheSameLineOverridesAnEarlierExportIgnore(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore -export-ignore\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * The same-line counterpart of the other direction: a later positive
     * token overrides an earlier negation token on the same line.
     */
    #[Test]
    public function acceptsWhenALaterExportIgnoreTokenOnTheSameLineOverridesAnEarlierNegation(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    -export-ignore export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * gitattributes(5) has a THIRD token form, not just `attr`/`-attr`:
     * `!attr` ("unspecified" — resets to unset, as if no rule had matched). A
     * parser that only recognises `export-ignore`/`-export-ignore` leaves
     * $state untouched for `!export-ignore`, so an earlier positive survives
     * — reproduced against a real checkout: `git archive` of a commit whose
     * .gitattributes reads `/x export-ignore` then `/x !export-ignore` still
     * includes /x, and `git check-attr` reports `unspecified` for it.
     */
    #[Test]
    public function rejectsWhenALaterUnspecifiedTokenResetsAnEarlierExportIgnore(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n/.github    !export-ignore\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * A path present with only an unrelated attribute is still missing the
     * one this gate asserts.
     */
    #[Test]
    public function rejectsAPathPresentWithOnlyAnUnrelatedAttribute(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    linguist-vendored\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * export-ignore among several attributes on the same line is still
     * recognised — the same tolerance real gitattributes files use.
     */
    #[Test]
    public function acceptsExportIgnoreAmongSeveralAttributesOnTheSameLine(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore linguist-vendored\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * No .gitattributes at all is real drift, not a skip — is_file() is
     * checked before the read so this is not misreported as an IO failure
     * either.
     */
    #[Test]
    public function rejectsARepositoryWithNoGitattributesAtAllAsDrift(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * A symlinked own .gitattributes must not be followed to its target's
     * content — is_file() alone follows the link, so without is_link() this
     * would read the target's satisfying entry and pass. Git itself does not
     * read a symlinked .gitattributes for attribute purposes either (git
     * check-attr on a real checkout reports it unspecified), so the archive
     * this gate certifies would silently disagree with the archive git
     * actually produces.
     */
    #[Test]
    public function rejectsASymlinkedOwnGitattributesAsAbsentRatherThanFollowingIt(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeFile($dir . '/gitattributes-target', "/.github    export-ignore\n");
        $this->makeSymlink('gitattributes-target', $dir . '/.gitattributes');

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * Two applicable entries: a gate that stopped after the first match would
     * pass this file's own canon case, so both misses need their own assertion.
     */
    #[Test]
    public function rejectsAndReportsBothOfTwoMissingEntries(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/.github');
        $this->makeDirectory($dir . '/tests');
        $this->writeTemplate($dir, "/.github    export-ignore\n/tests      export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateRejects(self::gate(), $dir, '/.github: missing', 'the first of two missing entries is reported');
        $this->assertGateRejects(self::gate(), $dir, '/tests: missing', 'the second of two missing entries is reported as well');
    }

    /**
     * A template declaring no active export-ignore entry at all cannot drive
     * this gate — the same vacuity guard tests/check-version-lockstep.php
     * applies to a README documenting no pin. Distinct from "none of the
     * entries apply here", which is the legitimate pass of
     * acceptsATemplateEntryNamingAPathThisRepositoryDoesNotHave().
     */
    #[Test]
    public function rejectsATemplateDeclaringNoActiveExportIgnoreEntry(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "# just a comment, no directive\n");
        $this->writeOwn($dir, '');

        $this->assertGateRejects(self::gate(), $dir, 'declares no active');
    }

    /**
     * A missing templates/gitattributes is a setup failure, not a content
     * defect — distinct from a missing .gitattributes, which is the drift the
     * gate exists to report.
     */
    #[Test]
    public function reportsUsageErrorWhenTemplatesGitattributesIsMissing(): void
    {
        $dir = $this->fixture()->path() . '/case';
        $this->makeDirectory($dir);

        $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
    }

    /**
     * A symlinked templates/gitattributes must not be followed to its
     * target's content — unlike a symlinked own .gitattributes (treated as
     * absent, above), this repository's own release process never produces a
     * symlinked template file, so following it is a setup failure (exit 2),
     * not drift. Without an is_link() guard, $readOrExit would follow the link
     * via file_get_contents() and parse the target's content as if it were
     * templates/gitattributes, echoing fragments of an arbitrary file the
     * gate's author did not intend it to read (GH-114).
     */
    #[Test]
    public function reportsUsageErrorForASymlinkedTemplatesGitattributesRatherThanFollowingIt(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/.github');
        $this->writeFile($dir . '/templates/gitattributes-target', "/.github    export-ignore\n");
        $this->makeSymlink('gitattributes-target', $dir . '/templates/gitattributes');

        $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
    }

    /**
     * IO failure: an unreadable templates/gitattributes must report as such
     * rather than as a content defect. Skipped for uid 0: root bypasses DAC,
     * so mode 000 stays readable and the case would read as a false
     * regression.
     */
    #[Test]
    public function reportsUsageErrorWhenTemplatesGitattributesIsUnreadable(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->makeCase();
        $this->writeTemplate($dir, "/.github    export-ignore\n");
        chmod($dir . '/templates/gitattributes', 0o000);

        try {
            $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
        } finally {
            chmod($dir . '/templates/gitattributes', 0o644);
        }
    }

    /**
     * The counterpart IO failure: an unreadable own .gitattributes reports as
     * unreadable, not as absent. Skipped for uid 0 for the same reason.
     */
    #[Test]
    public function reportsUsageErrorWhenOwnGitattributesIsUnreadableRatherThanAbsent(): void
    {
        $this->skipIfRunningAsRoot();

        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n");
        chmod($dir . '/.gitattributes', 0o000);

        try {
            $this->assertGateUsageError(self::gate(), $dir, 'Cannot read');
        } finally {
            chmod($dir . '/.gitattributes', 0o644);
        }
    }

    /**
     * Oversize: a read past the bound is reported as oversize, not scanned —
     * both files, since each read site holds its own bound check. 1 byte past
     * the 1048576-byte cap (plus the comment opener and trailing newline),
     * matching readCapped()'s own "at the bound" vs "past it" semantics.
     */
    #[Test]
    public function reportsUsageErrorWhenTemplatesGitattributesExceedsTheSizeCap(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, self::oversizeComment());

        $this->assertGateUsageError(self::gate(), $dir, 'is larger than');
    }

    /**
     * The oversize counterpart on the own .gitattributes read.
     */
    #[Test]
    public function reportsUsageErrorWhenOwnGitattributesExceedsTheSizeCap(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, self::oversizeComment());

        $this->assertGateUsageError(self::gate(), $dir, 'is larger than');
    }

    /**
     * safeReportValue wiring: a template path name is echoed into the
     * violation report verbatim once scrubbed, and it is pull-request branch
     * content in this repository's own CI just as much as it is in every
     * consumer's — the same trust boundary bin/support/safe-report-value.php
     * documents. Proven with a real fixture rather than assumed.
     */
    #[Test]
    public function reportIsInertWhenATemplatePathNameAttemptsToForgeALegacyWorkflowCommand(): void
    {
        $forged = 'pwned##[error]forged';

        $dir = $this->makeCase();
        $this->writeTemplate($dir, sprintf("/%s    export-ignore\n", $forged));
        $this->writeFile($dir . '/' . $forged, '');
        $this->writeOwn($dir, '');

        $this->assertGateReportIsInert(self::gate(), $dir, 'pwned##?[error]forged');
    }

    /**
     * Path containment: a template entry escaping the fixture root via `..`
     * must not resolve outside it. Reproduced against the real gate before
     * the realpath() fix landed: with a bare `ltrim($path, '/')`, this exact
     * entry resolved to a real file OUTSIDE the reviewed repository and was
     * reported as a violation for a path that has nothing to do with this
     * repository — the gate must instead treat it as not applicable, the
     * same verdict an absent path gets.
     */
    #[Test]
    public function acceptsATemplatePathEscapingTheRootViaDotDotAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->writeFile($this->fixture()->path() . '/traversal-target', '');
        $this->writeTemplate($dir, "../traversal-target    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A raw `..` template path must not escape containment through the LEAF.
     * The parent-directory containment check resolves dirname("$root/..") to
     * $root itself (dirname() strips the trailing ".." textually before
     * realpath() ever runs), which correctly passes containment — but
     * re-joining basename() of the UNRESOLVED, attacker-controlled $target
     * then rebuilds "$root/.." again, one directory ABOVE the very root just
     * proven safe. Found by security-reviewer during GH-112's own review:
     * reproduced against the parent-directory-containment fix itself, not the
     * pre-fix code, and confirmed to report a false violation for a path that
     * has nothing to do with this repository.
     */
    #[Test]
    public function acceptsABareDotDotTemplatePathAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "..    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The same escape one level DEEPER: a real subdirectory followed by two
     * `..` segments still resolves its parent to $root (the trailing ".."
     * folds away during dirname()'s own string handling before realpath()
     * sees it), so the bug is not limited to the single-token case above.
     */
    #[Test]
    public function acceptsANestedDotDotDotDotTemplatePathAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/subdir');
        $this->writeTemplate($dir, "subdir/../..    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The guard rejects "." as well as "..", and this is the ONLY case that
     * pins that half specifically: found by the Codex cross-model pass during
     * the same review — the two cases above both produce basename() === "..",
     * so a guard narrowed to check only ".." would still pass them. A template
     * path ending in "/." (a real subdirectory's own self-entry, not a path
     * git ever tracks as distinct from the directory itself) makes basename()
     * return ".": without the "." half of the guard, is_link()||file_exists()
     * on "$parentReal/." is TRUE (every directory contains itself), so the
     * path would be wrongly treated as applicable and reported as a
     * missing-export-ignore violation for a path that is really just the
     * subdirectory under a different name.
     */
    #[Test]
    public function acceptsASubdirDotTemplatePathAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/subdir');
        $this->writeTemplate($dir, "subdir/.    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The `$parentReal === false` disjunct of the containment check has its
     * own discriminating case here: found by the CE testing persona during
     * the same review, by mutation — every OTHER not-applicable fixture in
     * this class drives realpath(dirname($target)) to a path that DOES
     * resolve (either $realRoot itself, via a ".."-folding parent, or an
     * already-real sibling directory), so removing this disjunct still passes
     * every one of them and only crashes on an ORDINARY multi-segment path
     * whose parent directory genuinely does not exist — exactly the everyday
     * "a consumer-only nested path this repository does not have" case this
     * gate's own qualifier exists to handle silently. Verified by mutation:
     * with this disjunct removed, this fixture crashes with an uncaught
     * TypeError out of str_starts_with() instead of accepting.
     */
    #[Test]
    public function acceptsATemplatePathWhoseParentDirectoryDoesNotExistAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "nonexistent-dir/child    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A git-tracked DANGLING symlink at a required path must not read as
     * absent. git tracks a symlink as a blob holding the literal target
     * string, independent of whether that target resolves, and `git archive`
     * includes it unconditionally (verified 2026-08-31; see the commit history
     * for the reproduction recipe) — so a template-required path that is a
     * dangling symlink is genuinely tracked and genuinely needs the template's
     * export-ignore line. realpath() on the FULL target follows the link and
     * fails once it cannot resolve the missing final target, exactly the same
     * outcome as "this repository does not have that path" — a false ACCEPT
     * that hides real drift (GH-112).
     */
    #[Test]
    public function rejectsADanglingSymlinkAtARequiredPathAsDriftRatherThanAbsent(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "/dangling-symlink    export-ignore\n");
        $this->writeOwn($dir, '');
        $this->makeSymlink('/nonexistent-target-xyz', $dir . '/dangling-symlink');

        $this->assertGateRejects(self::gate(), $dir, '/dangling-symlink: missing `export-ignore`');
    }

    /**
     * The control for the case above: a genuinely absent path (no symlink,
     * no file) at the same name stays not-applicable — isolating the
     * dangling-symlink/realpath() interaction as the actual discriminator,
     * not a general loosening of the applicability check.
     */
    #[Test]
    public function acceptsAGenuinelyAbsentPathThatIsNotASymlinkAsNotApplicable(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "/dangling-symlink    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A dangling symlink that already carries export-ignore in .gitattributes
     * must still be accepted — proves the presence check does not itself
     * force a rejection.
     */
    #[Test]
    public function acceptsADanglingSymlinkAtARequiredPathThatIsAlreadyExportIgnored(): void
    {
        $dir = $this->makeCase();
        $this->writeTemplate($dir, "/dangling-symlink    export-ignore\n");
        $this->writeOwn($dir, "/dangling-symlink    export-ignore\n");
        $this->makeSymlink('/nonexistent-target-xyz', $dir . '/dangling-symlink');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A dangling symlink NESTED one directory down must be detected too —
     * proves the fix resolves the immediate PARENT directory, not just the
     * repository root itself, before testing the leaf.
     */
    #[Test]
    public function rejectsANestedDanglingSymlinkAtARequiredPathAsDrift(): void
    {
        $dir = $this->makeGithubCase("/.github/dangling-symlink    export-ignore\n");
        $this->writeOwn($dir, '');
        $this->makeSymlink('/nonexistent-target-xyz', $dir . '/.github/dangling-symlink');

        $this->assertGateRejects(self::gate(), $dir, '/.github/dangling-symlink: missing `export-ignore`');
    }

    /**
     * A template entry naming a dangling symlink whose PARENT directory
     * escapes the repository root via `..` must still be treated as not
     * applicable — the parent-directory containment check must reject a
     * traversal exactly as the former full-target containment check did.
     */
    #[Test]
    public function acceptsADanglingSymlinkWhoseParentEscapesTheRootViaDotDotAsNotApplicable(): void
    {
        $dir     = $this->makeCase();
        $outside = $this->fixture()->path() . '/traversal-parent';
        $this->makeDirectory($outside);
        $this->makeSymlink('/nonexistent-target-xyz', $outside . '/dangling-symlink');
        $this->writeTemplate($dir, "../traversal-parent/dangling-symlink    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A template line whose path has no attribute list at all (no whitespace
     * after it) must not be treated as a requirement — the shape the
     * block-parse regex is built to reject. /orphan-path exists on disk and
     * is NOT export-ignored in the fixture's own .gitattributes, so a parser
     * that loosened the regex enough to match a bare path would turn this
     * into a false violation; keeping the file present makes that regression
     * observable rather than vacuously passing either way.
     */
    #[Test]
    public function acceptsATemplateLineWithABarePathAndNoAttributeList(): void
    {
        $dir = $this->makeGithubCase("/orphan-path\n/.github    export-ignore\n");
        $this->writeFile($dir . '/orphan-path', '');
        $this->writeOwn($dir, "/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A template line naming a canonical-integer-string path (no leading
     * slash) must not crash the applicability check. PHP casts such a string
     * USED AS AN ARRAY KEY to an int, so $parseExportIgnorePaths()'s $state
     * map would hand back an int where its own signature promises
     * list<string> — and the gate declares strict_types=1, so that int
     * reaching ltrim()'s string-typed first parameter throws an uncaught
     * TypeError instead of the gate's own graceful exit path. The numeric
     * line sits FIRST so the crash (if the strval() fix regresses) happens
     * before /.github is ever reached.
     */
    #[Test]
    public function acceptsATemplateLineNamingABareNumericPathWithoutCrashing(): void
    {
        $dir = $this->makeGithubCase("123    export-ignore\n/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A NUL byte embedded in a captured path (\S does not exclude it) must
     * not crash the gate with an uncaught ValueError out of realpath() — PHP
     * 8+ rejects any NUL-byte path unconditionally, not a strict_types-only
     * behavior — verified identically on PHP 8.3/8.4/8.5, and reproduced
     * against the pre-fix code. The poisoned line sits FIRST so the crash, if
     * the guard regresses, happens before /.github is ever reached.
     */
    #[Test]
    public function acceptsANulByteEmbeddedInATemplatePathWithoutCrashing(): void
    {
        $dir = $this->makeGithubCase("/orphan\x00suffix    export-ignore\n/.github    export-ignore\n");
        $this->writeOwn($dir, "/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A UTF-8 BOM at the start of templates/gitattributes must not corrupt
     * the FIRST parsed path — reproduced against the pre-fix code: the BOM
     * bytes attached to the leading path token, it could never
     * realpath()-resolve, and a genuinely-required, genuinely-missing entry
     * was silently treated as "not applicable" — a false ACCEPT that hid real
     * drift, the worst failure mode for a drift-detection gate.
     */
    #[Test]
    public function rejectsAMissingEntryEvenWhenTemplatesGitattributesStartsWithABom(): void
    {
        $dir = $this->makeGithubCase("\xEF\xBB\xBF/.github    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * The same tolerance in the other file: a BOM-prefixed .gitattributes
     * must still be recognised as satisfying a requirement.
     */
    #[Test]
    public function acceptsABomPrefixedOwnGitattributesAsSatisfyingARequirement(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn($dir, "\xEF\xBB\xBF/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * A SINGLE `if`-shaped strip only removes one BOM. Two concatenated UTF-8
     * BOMs reproduce the exact false-accept the single-strip fix was written
     * to close, one BOM deeper — reproduced against the single-strip code.
     * Looping closes the whole stacked-BOM class instead of the next report
     * finding three.
     */
    #[Test]
    public function rejectsAMissingEntryEvenWhenTemplatesGitattributesStartsWithTwoBoms(): void
    {
        $dir = $this->makeGithubCase("\xEF\xBB\xBF\xEF\xBB\xBF/.github    export-ignore\n");
        $this->writeOwn($dir, '');

        $this->assertGateRejects(self::gate(), $dir, self::GITHUB_MISSING);
    }

    /**
     * At-cap: content exactly AT MAX_GITATTRIBUTES_BYTES must still be read
     * in full and compared, not silently truncated. The oversize cases above
     * only prove content past the cap is rejected; a bound shrunk by mutation
     * would still trip those (they sit far past any plausible shrunk value)
     * while truncating a legitimate file near the real cap — this is the
     * counterpart that catches that, mirroring CheckVersionLockstepTest's own
     * at-cap package.json/README pair. Padding is a trailing comment line,
     * verified by padTextToCap()'s own self-check to land the file at EXACTLY
     * the cap before the gate ever sees it.
     */
    #[Test]
    public function acceptsATemplatesGitattributesExactlyAtTheSizeCap(): void
    {
        $dir = $this->makeGithubCase(
            self::padTextToCap(self::MAX_GITATTRIBUTES_BYTES, "/.github    export-ignore\n# ", 'a', "\n"),
        );
        $this->writeOwn($dir, "/.github    export-ignore\n");

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * The at-cap counterpart for the own .gitattributes side.
     */
    #[Test]
    public function acceptsAnOwnGitattributesExactlyAtTheSizeCap(): void
    {
        $dir = $this->makeGithubCase("/.github    export-ignore\n");
        $this->writeOwn(
            $dir,
            self::padTextToCap(self::MAX_GITATTRIBUTES_BYTES, "/.github    export-ignore\n# ", 'a', "\n"),
        );

        $this->assertGateAccepts(self::gate(), $dir);
    }

    /**
     * Creates this test's case repository — a `case/` directory with an empty
     * `templates/` subdirectory — under the fixture root, mirroring the
     * deleted bash suite's own mk_case() helper.
     *
     * @return string The case repository's path.
     */
    private function makeCase(): string
    {
        $dir = $this->fixture()->path() . '/case';
        $this->makeDirectory($dir . '/templates');

        return $dir;
    }

    /**
     * The most common case shape: a case repository that has a `.github`
     * directory and whose templates/gitattributes carries $template.
     *
     * @param string $template The full templates/gitattributes content.
     *
     * @return string The case repository's path.
     */
    private function makeGithubCase(string $template): string
    {
        $dir = $this->makeCase();
        $this->makeDirectory($dir . '/.github');
        $this->writeTemplate($dir, $template);

        return $dir;
    }

    /**
     * @param string $dir      The case repository.
     * @param string $contents The full templates/gitattributes content.
     *
     * @return void
     */
    private function writeTemplate(string $dir, string $contents): void
    {
        $this->writeFile($dir . '/templates/gitattributes', $contents);
    }

    /**
     * @param string $dir      The case repository.
     * @param string $contents The full .gitattributes content.
     *
     * @return void
     */
    private function writeOwn(string $dir, string $contents): void
    {
        $this->writeFile($dir . '/.gitattributes', $contents);
    }

    /**
     * Writes a fixture file, failing the test outright if it cannot — a
     * silently missing fixture file would turn several reject cases into
     * vacuous ones.
     *
     * @param string $path     The file to write.
     * @param string $contents Its full content.
     *
     * @return void
     */
    private function writeFile(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents), sprintf('could not write fixture file %s', $path));
    }

    /**
     * @param string $path The directory to create, parents included.
     *
     * @return void
     */
    private function makeDirectory(string $path): void
    {
        self::assertTrue(mkdir($path, 0o777, true), sprintf('could not create fixture directory %s', $path));
    }

    /**
     * @param string $target The link target, written verbatim (relative targets resolve against the link's directory).
     * @param string $link   The symlink to create.
     *
     * @return void
     */
    private function makeSymlink(string $target, string $link): void
    {
        self::assertTrue(symlink($target, $link), sprintf('could not create fixture symlink %s', $link));
    }

    /**
     * Skips the calling test when running as root: uid 0 bypasses DAC, so
     * mode 000 stays readable and the gate correctly reads the file — a
     * false regression, not a real one. CI runs non-root, so the branch
     * stays exercised there.
     *
     * @return void
     */
    private function skipIfRunningAsRoot(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }
    }

    /**
     * @return string A single comment line one byte past the size cap (plus
     *                its `# ` opener and trailing newline), as the bash
     *                original's `head -c 1048577 /dev/zero | tr` produced.
     */
    private static function oversizeComment(): string
    {
        return '# ' . str_repeat('a', self::MAX_GITATTRIBUTES_BYTES + 1) . "\n";
    }

    /**
     * @return list<string> The interpreter and gate script under test.
     */
    private static function gate(): array
    {
        return ['php', self::root() . '/tests/check-gitattributes-lockstep.php'];
    }
}
