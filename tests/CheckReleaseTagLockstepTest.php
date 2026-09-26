<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\GateProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function is_file;
use function sprintf;
use function substr;
use function trim;
use function unlink;

/**
 * Fixture-driven cases for tests/check-release-tag-lockstep.php (GH-42),
 * migrated off tests/check-release-tag-lockstep-cases.sh (#71).
 *
 * Run against this repository alone, the gate only ever takes the happy
 * path — whatever tag package.json currently names either does not exist yet
 * (nothing to check) or, once this repository's own release procedure has
 * run, is an ancestor of HEAD. These cases put it in every OTHER state on
 * purpose.
 *
 * Unlike every sibling gate's cases, this gate's fixtures are not static
 * file trees: the gate shells out to `git ls-remote`/`git fetch` against a
 * CHECK_RELEASE_TAG_REMOTE, so each case builds a small real bare repository
 * under its own fixture() directory to play that role, rather than writing
 * files the gate merely reads — never the real network. The environment
 * variable reaches the gate through an `env` prefix on the command
 * (gate(), or `env -u` for the default-remote cases), because GateTestCase's assertGate*()
 * decisions take a plain command and pass no environment of their own. The
 * gate is one of this package's own tests/check-*.php scripts and needs no
 * installed consumer fixture, so GateTestCase's accept/reject/usage-error/
 * report-is-inert exit-code contract applies directly, and this class runs
 * in the plain `composer ci:test:phpunit` step — on every pull_request, unlike
 * the gate itself (`composer ci:test:release-tag`), which only runs on a push
 * to `main` or a tag push.
 *
 * The bash original's bookkeeping self-test (harness_assert_no_stray_increments)
 * is not ported: GateTestCase's own meta-suite already proves its decisions
 * generically, including the non-default expected exit code of
 * assertGateReportIsInert() that reportIsInertWhenAPackageJsonVersionAttemptsToForgeAWorkflowCommand()
 * relies on.
 *
 * Deliberately NOT covered, as in the bash original: the cleanup call's own
 * exit code (the "could not delete the local probe ref" note) is checked and
 * surfaced as a diagnostic without escalating into the gate's own verdict. A
 * pre-existing lock file at PROBE_REF's path was tried first and rejected:
 * git's ref-locking is symmetric across create/update/delete, so a lock in
 * place BEFORE the run blocks the earlier FETCH (which also writes
 * PROBE_REF) at "could not fetch it" and the cleanup call is never reached at
 * all — measured, not assumed. Making cleanup specifically fail while the
 * preceding fetch specifically succeeds needs the lock to appear in the
 * narrow window between them, which needs actual concurrency.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckReleaseTagLockstepTest extends GateTestCase
{
    /**
     * The environment variable the gate reads its remote from.
     */
    private const string REMOTE_ENV = 'CHECK_RELEASE_TAG_REMOTE';

    /**
     * A value carrying a legacy `##[…]` workflow command.
     */
    private const string FORGED = 'pwned##[error]forged';

    /**
     * The shape safeReportValue() scrubs FORGED to.
     */
    private const string SCRUBBED = 'pwned##?[error]forged';

    /**
     * The process runner for fixture setup (git init/commit/push/tag) —
     * separate from GateTestCase's own runner, which is private to it and
     * reserved for the gate under test.
     */
    private ?GateProcess $setupProcess = null;

    /**
     * The two spellings of "no override" the gate must both treat as the
     * literal remote name 'origin': the variable unset entirely, and set to an
     * explicit empty string. The second is a realistic shape in GitHub
     * Actions — an `env:` value interpolated from an unset vars/secrets
     * context evaluates to `''`, not an absent variable — and a genuinely
     * distinct code path (`getenv()` returns a string, not `false`), so the
     * unset case alone cannot discriminate a regression that dropped that arm.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function defaultRemoteSpellingProvider(): array
    {
        return [
            'the override unset entirely'         => [['env', '-u', self::REMOTE_ENV]],
            'the override set to an empty string' => [['env', self::REMOTE_ENV . '=']],
        ];
    }

    /**
     * Shape 1: no tag on the remote yet — the state `main` is in, briefly and
     * by design, between a version-bump push and the tag push that follows
     * it. Nothing to check yet, not a violation (see this gate's own
     * docblock for why: npm already fails loudly for a consumer who tries the
     * pin before it exists).
     */
    #[Test]
    public function acceptsWhenNoMatchingTagIsOnTheRemoteYet(): void
    {
        $dir = $this->repoPair('not-found', '1.0.0');

        $this->assertGateAccepts(
            self::gate($dir . '-origin.git'),
            $dir,
            'no matching tag on the remote yet is nothing to check, not a violation',
        );
    }

    /**
     * The tag exists and IS HEAD — the state a release push leaves `main` in
     * the instant the tag is also pushed, before anything else lands.
     */
    #[Test]
    public function acceptsAResolvedTagThatNamesHeadItself(): void
    {
        $dir = $this->repoPair('resolved-is-head', '1.0.0');
        $this->tagAndPush($dir, '1.0.0');

        $this->assertGateAccepts(self::gate($dir . '-origin.git'), $dir, 'a resolved tag that names HEAD itself is accepted');
    }

    /**
     * The tag exists and is an ANCESTOR of HEAD, with ordinary commits on top
     * and package.json's version left UNCHANGED — the actual, common shape of
     * `main` between two releases (see README.md's "Releasing this package"
     * section for why this is the case a tree-equality design got
     * backwards). This case is what makes that regression impossible to
     * reintroduce silently: a reverted ancestor-check (back to tree
     * equality) turns this exact fixture into a false reject, since the
     * follow-up commit changes HEAD's tree but not the tag's.
     */
    #[Test]
    public function acceptsATagThatIsAnAncestorOfHeadWithOrdinaryCommitsOnTop(): void
    {
        $dir = $this->repoPair('resolved-ancestor', '1.0.0');
        $this->tagAndPush($dir, '1.0.0');
        $this->commitFollowUpAndPush($dir);

        $this->assertGateAccepts(
            self::gate($dir . '-origin.git'),
            $dir,
            'a tag that is an ancestor of HEAD, with ordinary commits on top, is accepted',
        );
    }

    /**
     * The drift this gate actually exists to catch: the tag resolves to a
     * commit this branch's own history never contains at all — a SEPARATE,
     * unrelated commit graph, so there is no ancestry to find regardless of
     * how far `main` has moved.
     *
     * Named distinctly from "resolved-not-ancestor-origin.git" on purpose:
     * that is the path repoPair() derives from the fixture name it is given,
     * and creating it here first would make that later, unrelated push
     * collide with this one's history.
     */
    #[Test]
    public function rejectsATagResolvingToACommitOutsideThisBranchsHistory(): void
    {
        $orphanOrigin = $this->work() . '/orphan-history-origin.git';
        $this->unrelatedTaggedRemote($orphanOrigin, $this->work() . '/orphan-history-seed');

        $dir = $this->repoPair('resolved-not-ancestor', '1.0.0');

        $this->assertGateRejects(
            self::gate($orphanOrigin),
            $dir,
            'MISMATCH',
            "a tag resolving to a commit outside this branch's history is reported",
        );
    }

    /**
     * The remote cannot be reached at all — a transport failure, distinct
     * from "no matching tag" (`git ls-remote --exit-code` answers 2 for the
     * latter and some OTHER non-zero code, with a `fatal:` line on stderr,
     * for this).
     */
    #[Test]
    public function reportsAnUnreachableRemoteAsASetupFailureNotAsAMissingTag(): void
    {
        $dir = $this->repoPair('remote-unreachable', '1.0.0');

        $this->assertGateUsageError(
            self::gate($this->work() . '/does-not-exist'),
            $dir,
            'Could not query',
            'an unreachable remote is reported as a setup failure, not as a missing tag',
        );
    }

    /**
     * package.json's version is not shaped like a git tag — it is repository
     * content, about to become part of a `refs/tags/<version>` argument
     * handed to git, and this gate refuses it up front rather than letting an
     * unrecognisable ref simply fail to resolve (which would misreport a
     * malformed version as shape 1, "nothing to check yet").
     */
    #[Test]
    public function refusesAPackageJsonVersionNotShapedLikeATag(): void
    {
        $dir = $this->repoPair('not-tag-shaped', '1.0.0_hotfix');

        $this->assertGateUsageError(
            self::gate($dir . '-origin.git'),
            $dir,
            'not shaped like a version tag',
            'a package.json version not shaped like a tag is refused, not silently treated as unresolved',
        );
    }

    /**
     * With no override, the default must genuinely be the literal string
     * 'origin', not merely "something ls-remote accepts" — a repository
     * whose ONLY remote is named something else proves it, since the gate
     * must fail trying to resolve a remote literally named 'origin' rather
     * than falling through to whatever remote does exist. Run once per
     * spelling of "no override" (see defaultRemoteSpellingProvider()).
     *
     * @param list<string> $envPrefix The `env` invocation that leaves the override unset or empty.
     */
    #[Test]
    #[DataProvider('defaultRemoteSpellingProvider')]
    public function queriesTheRemoteLiterallyNamedOriginWithoutAnOverride(array $envPrefix): void
    {
        $dir = $this->repoPair('default-remote-name', '1.0.0');
        $this->git('-C', $dir, 'remote', 'rename', 'origin', 'upstream');

        $this->assertGateUsageError(
            [...$envPrefix, 'php', self::gatePath()],
            $dir,
            'query origin for',
            "with no override, the queried remote is literally 'origin'",
        );
    }

    /**
     * A tag that resolves via ls-remote (the ref advertisement) but cannot
     * actually be fetched (its objects are unreachable on the remote) — a
     * real, non-racy git failure mode (a corrupted or partially-pruned
     * remote), built deterministically here via a SEPARATE, unrelated
     * history the fixture under test has never fetched from, so the missing
     * object cannot already be present locally the way it would be for any
     * commit reachable from the fixture's own HEAD. Constructed once, not
     * timed: a permanent broken remote state, not a race.
     *
     * Named "…-ALIEN-…", not "fetch-fails-origin.git": that latter spelling
     * is the exact path repoPair() derives from the fixture name
     * "fetch-fails", and this repository must stay a physically separate
     * bare repository from the one repoPair() creates — reusing the same
     * path by coincidence would silently make this case's real origin the
     * repoPair() one instead of the deliberately corrupted one, without
     * changing a single assertion.
     */
    #[Test]
    public function reportsATagThatResolvesButCannotBeFetchedAsASetupFailure(): void
    {
        $alien     = $this->work() . '/fetch-fails-ALIEN-origin.git';
        $alienSeed = $this->work() . '/fetch-fails-alien-seed';

        $this->git('init', '-q', '--bare', $alien);
        $this->git('init', '-q', $alienSeed);
        $this->localIdentity($alienSeed);
        file_put_contents($alienSeed . '/alien.txt', "alien content unrelated to the fixture under test\n");
        $this->git('-C', $alienSeed, 'add', 'alien.txt');
        $this->git('-C', $alienSeed, 'commit', '-q', '-m', 'Alien commit');
        $this->git('-C', $alienSeed, 'remote', 'add', 'origin', $alien);
        $this->git('-C', $alienSeed, 'push', '-q', 'origin', 'HEAD:refs/heads/alien-tmp');
        $this->git('-C', $alienSeed, 'tag', '-m', 'Release 1.0.0', '1.0.0');
        $this->git('-C', $alienSeed, 'push', '-q', 'origin', '--tags');

        // The branch only existed to get the commit onto the remote; removing
        // it leaves the TAG as the sole reason the corrupted object is still
        // advertised, matching how this gate would encounter it —
        // resolvable, unfetchable.
        $this->git('-C', $alienSeed, 'push', '-q', 'origin', ':refs/heads/alien-tmp');

        $alienCommit = trim($this->git('-C', $alienSeed, 'rev-parse', '1.0.0^{commit}'));
        $objectPath  = sprintf('%s/objects/%s/%s', $alien, substr($alienCommit, 0, 2), substr($alienCommit, 2));

        // Asserted, unlike the bash original's `rm -f`: an object that was
        // packed rather than loose would leave the remote intact, and this
        // case would then prove nothing about the could-not-fetch arm.
        self::assertTrue(is_file($objectPath) && unlink($objectPath), 'the alien commit object is not a loose object on the remote, so it could not be removed');

        $dir = $this->repoPair('fetch-fails', '1.0.0');

        $this->assertGateUsageError(
            self::gate($alien),
            $dir,
            'could not fetch it',
            'a tag that resolves but cannot be fetched is reported as a setup failure',
        );
    }

    /**
     * A tag that resolves AND fetches successfully but is not shaped like
     * something with a commit at all — a lightweight tag pointing directly
     * at a BLOB rather than a commit or an annotated tag object (git tag
     * accepts any object type for a lightweight tag). `<ref>^{commit}` has
     * nothing to peel through on a blob, so this is the "fetched
     * successfully, still cannot proceed" arm distinct from every arm above
     * it.
     */
    #[Test]
    public function reportsATagResolvingToABlobAsASetupFailure(): void
    {
        $dir = $this->repoPair('fetch-ok-not-a-commit', '1.0.0');

        $blobSource = $this->work() . '/blob-source.txt';
        file_put_contents($blobSource, "just a blob, not a commit\n");

        $blob = trim($this->git('-C', $dir, 'hash-object', '-w', '--', $blobSource));
        $this->git('-C', $dir, 'tag', '1.0.0', $blob);
        $this->git('-C', $dir, 'push', '-q', 'origin', '--tags');

        $this->assertGateUsageError(
            self::gate($dir . '-origin.git'),
            $dir,
            'could not resolve it to a commit',
            'a tag resolving to a blob rather than a commit is reported as a setup failure',
        );
    }

    /**
     * HEAD itself cannot be resolved to a commit — an unborn HEAD (a
     * repository with no local commit at all, though its remote already
     * carries a real, fetchable, resolvable tag). The shallow-repository
     * probe still SUCCEEDS here (`git rev-parse --is-shallow-repository`
     * answers `false` on an unborn HEAD too), so the failure this case pins
     * is `merge-base --is-ancestor` itself reporting 128. Asserted on the
     * message's own TAIL ("is an ancestor of HEAD"), not the "Could not
     * determine whether" prefix both this message and the shallow-detection
     * failure message share — the shared prefix would keep passing even if a
     * regression redirected this exact input into the OTHER branch instead.
     */
    #[Test]
    public function reportsAnUnbornLocalHeadAsASetupFailure(): void
    {
        $dir    = $this->work() . '/unborn-head';
        $origin = $this->work() . '/unborn-head-origin.git';
        $seed   = $this->work() . '/unborn-head-seed';

        $this->git('init', '-q', $dir);
        $this->localIdentity($dir);
        $this->git('init', '-q', '--bare', $origin);
        $this->git('init', '-q', $seed);
        $this->localIdentity($seed);
        self::writePackageJson($seed, '1.0.0');
        $this->git('-C', $seed, 'add', 'package.json');
        $this->git('-C', $seed, 'commit', '-q', '-m', 'Initial commit');
        $this->git('-C', $seed, 'remote', 'add', 'origin', $origin);
        $this->git('-C', $seed, 'push', '-q', 'origin', 'HEAD:main');
        $this->tagAndPush($seed, '1.0.0');

        self::writePackageJson($dir, '1.0.0');
        $this->git('-C', $dir, 'remote', 'add', 'origin', $origin);

        $this->assertGateUsageError(
            self::gate($origin),
            $dir,
            'is an ancestor of HEAD',
            'an unborn local HEAD is reported as a setup failure, not misread as a resolvable commit',
        );
    }

    /**
     * A genuinely SHALLOW checkout (the `actions/checkout` default this
     * repository's own CI runs under — `fetch-depth: 1`) has no local parent
     * graph for `merge-base --is-ancestor` to walk at all, even when the tag
     * really is an ancestor. Reproduced independently against a real
     * `git clone --depth 1` of THIS repository before this case existed:
     * `merge-base --is-ancestor <the real 1.8.0 tag commit> HEAD` answered 1
     * ("not an ancestor") on a checkout where it demonstrably is one — the
     * exact false-MISMATCH class the ancestry redesign exists to close.
     * `git clone --depth 1` is used rather than a hand-rolled shallow marker,
     * since git already produces a shallow repository's on-disk shape for
     * free.
     *
     * Cloned from the BARE ORIGIN, not from the working clone: that is what
     * makes this clone's own `origin` remote genuinely be the bare
     * repository, the same topology `actions/checkout` produces against the
     * real GitHub remote. The gate runs with no override for the same reason
     * (the default 'origin' already resolves correctly here), but with the
     * variable explicitly unset so an inherited value cannot leak in.
     *
     * `--branch main` is load-bearing: the bare origin's own HEAD symref
     * points at whatever `init.defaultBranch` this machine defaults to, so a
     * plain clone could check out nothing and leave package.json absent.
     * `file://` is load-bearing too: a bare filesystem PATH triggers git's
     * local hardlink-clone optimisation, which silently IGNORES `--depth`
     * ("warning: --depth is ignored in local clones") and produces a full,
     * non-shallow copy that could never discriminate this fix from a
     * reverted one.
     */
    #[Test]
    public function acceptsAShallowCheckoutByDeepeningItBeforeTheAncestryCheck(): void
    {
        $dir = $this->repoPair('shallow-checkout', '1.0.0');
        $this->tagAndPush($dir, '1.0.0');
        $this->commitFollowUpAndPush($dir);

        $shallowClone = $this->work() . '/shallow-checkout-shallow-clone';
        $this->git(
            'clone',
            '-q',
            '--no-tags',
            '--depth',
            '1',
            '--branch',
            'main',
            '--',
            'file://' . $this->work() . '/shallow-checkout-origin.git',
            $shallowClone,
        );
        $this->localIdentity($shallowClone);

        // A manual check rather than assertSame(): a failure message must not
        // re-embed raw subprocess output unscrubbed (#160).
        $isShallow = $this->git('-C', $shallowClone, 'rev-parse', '--is-shallow-repository');

        if (trim($isShallow) !== 'true') {
            self::fail(self::diagnosticMessage(
                'The fixture clone is not shallow, so this case cannot discriminate the unshallow step:',
                $isShallow,
            ));
        }

        $this->assertGateAccepts(
            ['env', '-u', self::REMOTE_ENV, 'php', self::gatePath()],
            $shallowClone,
            'a shallow checkout is deepened before the ancestry check runs, rather than false-failing',
        );
    }

    /**
     * The local PROBE_REF this gate fetches a resolved tag into must not
     * survive the run — checked directly rather than inferred, since every
     * other case only proves the EXIT verdict, not this side effect. The
     * success path is the one asserted: it is the only one that reaches both
     * the fetch AND the ancestry-check call before the shared cleanup site,
     * so it is the arm most exposed to a cleanup call that was dropped or
     * misplaced.
     *
     * The exit code is checked first: a leftover listing that is empty
     * because the gate never got far enough to create PROBE_REF would read as
     * "cleaned up" vacuously, proving nothing about the cleanup call.
     */
    #[Test]
    public function deletesTheLocalProbeRefAfterASuccessfulRun(): void
    {
        $dir = $this->repoPair('cleanup-after-success', '1.0.0');
        $this->tagAndPush($dir, '1.0.0');

        $result = $this->setupProcess()->runRaw(
            ['php', self::gatePath(), $dir],
            null,
            [self::REMOTE_ENV => $dir . '-origin.git'],
        );

        if ($result->exitCode !== 0) {
            self::fail(self::diagnosticMessage(
                sprintf('The fixture setup itself did not reach a successful run (exit %d), so this case proves nothing about cleanup.', $result->exitCode),
                $result->output,
            ));
        }

        self::assertFalse($result->isDegraded(), 'The gate ran degraded — it emitted a diagnostic.');

        $leftover = $this->git('-C', $dir, 'for-each-ref', 'refs/check-release-tag-lockstep/');

        if ($leftover !== '') {
            self::fail(self::diagnosticMessage(
                'PROBE_REF (refs/check-release-tag-lockstep/probe) was not deleted after a successful run:',
                $leftover,
            ));
        }
    }

    /**
     * safeReportValue() wiring on CHECK_RELEASE_TAG_REMOTE, one of the two
     * operands this gate itself echoes rather than merely passing through
     * git's own stderr. It needs the drift verdict specifically (a genuine
     * not-an-ancestor mismatch), not merely a resolvable tag: only a REAL,
     * working bare repository can produce git's own success output, so the
     * forged text has to live in that repository's own PATH rather than in a
     * value this gate merely fails to resolve — an unreachable path exits 2,
     * which would not exercise this arm of safeReportValue() at all.
     *
     * The remote is passed RELATIVE to the fixture clone (`../<forged>…`),
     * not as an absolute path: safeReportValue() caps the echoed value at 64
     * bytes, and an absolute path under the per-test fixture directory
     * (sys_get_temp_dir() plus a 32-hex-digit name) would eat that budget
     * and could push the scrubbed marker past the cut on a machine with a
     * longer TMPDIR — the same hazard the bash original could only narrow,
     * by putting $forged directly after its mktemp path. git resolves a
     * relative local remote against the directory the gate's own `git -C`
     * names, so the relative spelling reaches the same bare repository.
     */
    #[Test]
    public function reportIsInertWhenARemotePathAttemptsToForgeAWorkflowCommand(): void
    {
        $this->unrelatedTaggedRemote(
            $this->work() . '/' . self::FORGED . '-origin.git',
            $this->work() . '/poison-remote-seed',
        );

        $dir = $this->repoPair('poison-remote', '1.0.0');

        $this->assertGateReportIsInert(
            self::gate('../' . self::FORGED . '-origin.git'),
            $dir,
            self::SCRUBBED,
            'a forged remote path cannot inject a workflow command into the report',
        );
    }

    /**
     * safeReportValue() wiring on package.json's `version`, on the one path
     * that echoes it BEFORE the shape check has ruled out anything
     * forge-prone — the "not shaped like a version tag" message itself,
     * which by definition handles a version the shape check has NOT yet
     * approved. isVersionTagShaped() rejects a forge-prone version before
     * anything else runs, at exit 2, by construction — the shape check IS
     * the reason nothing forge-prone can ever reach the drift verdict (exit
     * 1) via $version — so this is the one call that needs
     * assertGateReportIsInert()'s non-default expected exit code (GH-42).
     */
    #[Test]
    public function reportIsInertWhenAPackageJsonVersionAttemptsToForgeAWorkflowCommand(): void
    {
        $dir = $this->repoPair('poison-version', '1.0.0-' . self::FORGED);

        $this->assertGateReportIsInert(
            self::gate($dir . '-origin.git'),
            $dir,
            self::SCRUBBED,
            'a forged package.json version cannot inject a workflow command into the report',
            2,
        );
    }

    /**
     * An IO failure on package.json reads as one, via the shared
     * readPackageJsonVersion() this gate shares with
     * tests/check-version-lockstep.php — one representative case to prove
     * THIS gate is actually wired to it; the byte-cap/oversize/
     * malformed-JSON branches of that shared function are exhaustively
     * covered by tests/CheckVersionLockstepTest.php already, against the
     * identical function both gates call.
     */
    #[Test]
    public function reportsARepositoryWithNoPackageJsonAsUnreadable(): void
    {
        $dir = $this->work() . '/missing-package-json';

        $this->git('init', '-q', $dir);
        $this->localIdentity($dir);
        file_put_contents($dir . '/.gitkeep', '');
        $this->git('-C', $dir, 'add', '.gitkeep');
        $this->git('-C', $dir, 'commit', '-q', '-m', 'Initial commit');

        $this->assertGateUsageError(
            self::gate($dir . '-origin.git'),
            $dir,
            'Cannot read',
            'a repository with no package.json reports as unreadable',
        );
    }

    /**
     * @return string This test's fixture root, under which every bare
     *                origin, working clone and seed repository is created.
     */
    private function work(): string
    {
        return $this->fixture()->path();
    }

    /**
     * @return GateProcess The process runner for fixture setup commands.
     */
    private function setupProcess(): GateProcess
    {
        return $this->setupProcess ??= new GateProcess();
    }

    /**
     * Runs one fixture-setup git command and returns its combined output,
     * failing the test outright when it does not succeed — a fixture that
     * silently failed to build would otherwise hand the gate a state the
     * case never meant to test.
     *
     * @param string ...$args The git arguments, `git` itself excluded.
     *
     * @return string The command's combined stdout+stderr.
     */
    private function git(string ...$args): string
    {
        $result = $this->setupProcess()->runRaw(['git', ...$args]);

        if ($result->exitCode !== 0) {
            self::fail(self::diagnosticMessage(
                sprintf('Fixture setup failed: git %s exited %d.', $args[0] ?? '', $result->exitCode),
                $result->output,
            ));
        }

        return $result->output;
    }

    /**
     * Every fixture commit and tag needs a git identity, and neither may
     * depend on (or trip over) whatever signing configuration the machine
     * running this suite carries globally — set LOCALLY on each fixture
     * repository rather than via `git config --global`, which would mutate
     * the real identity of whoever runs this suite.
     *
     * This repository's own tags ARE annotated, and tagAndPush() creates
     * them the same way — the two tag overrides only stop that creation from
     * failing on a machine whose global config additionally demands a
     * signature this throwaway identity cannot produce. commit.gpgSign is a
     * SEPARATE key: every fixture commit is otherwise silently signed with
     * whatever identity the machine has configured globally, or fails/hangs
     * outright where that flag is set but no non-interactive signing key is
     * available.
     *
     * @param string $dir The fixture repository to configure.
     */
    private function localIdentity(string $dir): void
    {
        $this->git('-C', $dir, 'config', 'user.email', 'check-release-tag-lockstep-fixtures@example.invalid');
        $this->git('-C', $dir, 'config', 'user.name', 'check-release-tag-lockstep fixtures');
        $this->git('-C', $dir, 'config', 'tag.gpgSign', 'false');
        $this->git('-C', $dir, 'config', 'tag.forceSignAnnotated', 'false');
        $this->git('-C', $dir, 'config', 'commit.gpgSign', 'false');
    }

    /**
     * Creates a bare "<work>/<name>-origin.git" and a working clone at
     * "<work>/<name>" whose package.json already names $version and is
     * committed and pushed to `main` — the state every case starts from,
     * whether or not it goes on to tag anything.
     *
     * @param string $name    The fixture name; also derives the bare origin's path.
     * @param string $version The package.json `version` to commit.
     *
     * @return string The working clone's path.
     */
    private function repoPair(string $name, string $version): string
    {
        $origin = $this->work() . '/' . $name . '-origin.git';
        $dir    = $this->work() . '/' . $name;

        $this->git('init', '-q', '--bare', $origin);
        $this->git('init', '-q', $dir);
        $this->localIdentity($dir);
        $this->git('-C', $dir, 'remote', 'add', 'origin', $origin);

        self::writePackageJson($dir, $version);
        $this->git('-C', $dir, 'add', 'package.json');
        $this->git('-C', $dir, 'commit', '-q', '-m', 'Initial commit');
        $this->git('-C', $dir, 'push', '-q', 'origin', 'HEAD:main');

        return $dir;
    }

    /**
     * Tags $dir's current HEAD as $version, annotated, and pushes the tag to
     * origin — the shape this repository's own releases use.
     *
     * @param string $dir     The working clone to tag.
     * @param string $version The tag name.
     */
    private function tagAndPush(string $dir, string $version): void
    {
        $this->git('-C', $dir, 'tag', '-m', 'Release ' . $version, $version);
        $this->git('-C', $dir, 'push', '-q', 'origin', '--tags');
    }

    /**
     * Adds an ordinary follow-up commit (not a version bump) on top of $dir's
     * HEAD and pushes it to `main`, so a tag cut before it is an ancestor of
     * HEAD rather than HEAD itself.
     *
     * @param string $dir The working clone to commit in.
     */
    private function commitFollowUpAndPush(string $dir): void
    {
        file_put_contents($dir . '/drift.txt', "an ordinary follow-up file, not a version bump\n");
        $this->git('-C', $dir, 'add', 'drift.txt');
        $this->git('-C', $dir, 'commit', '-q', '-m', 'Ordinary follow-up commit');
        $this->git('-C', $dir, 'push', '-q', 'origin', 'HEAD:main');
    }

    /**
     * Creates a bare $origin carrying one unrelated commit on `main`, tagged
     * 1.0.0 — a history no repoPair() clone ever contains, so a tag on it is
     * never an ancestor of that clone's HEAD.
     *
     * @param string $origin The bare repository to create.
     * @param string $seed   The throwaway working repository that seeds it.
     */
    private function unrelatedTaggedRemote(string $origin, string $seed): void
    {
        $this->git('init', '-q', '--bare', $origin);
        $this->git('init', '-q', $seed);
        $this->localIdentity($seed);
        file_put_contents($seed . '/orphan.txt', "unrelated history, never a commit on the fixture under test\n");
        $this->git('-C', $seed, 'add', 'orphan.txt');
        $this->git('-C', $seed, 'commit', '-q', '-m', 'Orphan commit');
        $this->git('-C', $seed, 'remote', 'add', 'origin', $origin);
        $this->git('-C', $seed, 'push', '-q', 'origin', 'HEAD:main');
        $this->tagAndPush($seed, '1.0.0');
    }

    /**
     * @param string $dir     The directory to write package.json into.
     * @param string $version The `version` to record.
     */
    private static function writePackageJson(string $dir, string $version): void
    {
        file_put_contents($dir . '/package.json', sprintf("{\n    \"version\": \"%s\"\n}\n", $version));
    }

    /**
     * @return string Absolute path to the gate script under test.
     */
    private static function gatePath(): string
    {
        return self::root() . '/tests/check-release-tag-lockstep.php';
    }

    /**
     * @param string $remote The value CHECK_RELEASE_TAG_REMOTE is set to.
     *
     * @return list<string> The gate invocation with its remote override set.
     */
    private static function gate(string $remote): array
    {
        return ['env', self::REMOTE_ENV . '=' . $remote, 'php', self::gatePath()];
    }
}
