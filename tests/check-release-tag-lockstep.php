<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Checks the release tag the version lockstep gate can only assert IN-REPO
 * agreement about against what actually reaches a consumer over the network.
 *
 * `composer ci:test:version` (tests/check-version-lockstep.php) proves
 * package.json's `version` and every README pin agree — both operands live
 * inside this repository, so that gate is green by construction the moment a
 * release edits them together. It cannot see the one thing that decides
 * whether `npm install --save-dev github:magicsunday/coding-standard#<tag>`
 * actually installs what the README describes: the `<tag>` ref on the real
 * remote. Two failure shapes fall through the in-repo gate (GH-42):
 *
 *   1. The pin resolves to nothing — pacote answers `Could not resolve
 *      reference "<tag>"`. This is the state `main` is IN, briefly and by
 *      design, between a version-bump push and the tag push that follows it
 *      — `git tag`/`git push --tags` is a separate command from `git push
 *      origin main`, and the release procedure documented in this
 *      repository's own README does not (yet) make them one atomic step.
 *      This gate treats that state as nothing to check yet rather than a
 *      violation, on purpose: npm already fails LOUDLY for a consumer who
 *      tries the pin before it exists, so nothing here needs to fail loudly
 *      a second time on this repository's own CI for the same reason.
 *   2. The pin resolves to a commit that never became part of this
 *      repository's own history — a tag cut from an orphaned branch, a
 *      force-pushed/rewritten commit no longer reachable from `main`, or a
 *      plain mistake naming the wrong ref at tag time. `npm install` then
 *      installs whatever that commit contains, which the README's own
 *      history no longer accounts for. This is the shape that needs a gate.
 *
 * What this gate does NOT check: whether the tagged commit's CONTENT is
 * itself correct (a JSON config that Biome refuses to load, say). That is
 * unrelated to what git alone can prove and belongs to the gates that
 * actually run the tool in question (`ci:test:templates`, the JS consumer
 * smoke) — this file only proves the tag names a commit this repository's
 * own history actually contains.
 *
 * This checks ANCESTRY (`git merge-base --is-ancestor <tag-commit> HEAD`),
 * not a tree match against `HEAD`: a tree-equality design was tried first
 * and reverted before it ever reached `main` — the README's "Releasing this
 * package" section carries the full reproduction and reasoning, not
 * restated here. In one sentence: `package.json`'s `version` is bumped only
 * at release time while the tag is routinely cut from a later commit, so
 * `HEAD` keeps moving on every ordinary post-release push while the tag does
 * not — tree-equality read that routine, healthy gap as drift on every one
 * of them, while ancestry stays true for the whole life of the branch once a
 * tag is cut.
 *
 * Why this cannot run on `pull_request`: the release PR is the one place
 * where the tag legitimately does not exist yet, so a check that demanded it
 * would block every release. Two triggers call it instead, for two different
 * reasons: `push` to `main` in .github/workflows/ci.yml (`if:
 * github.event_name == 'push'` on the step that calls this) re-checks
 * ancestry on every ordinary commit, cheaply, as a continuous safety net;
 * .github/workflows/release-tag-lockstep.yml additionally runs it on every
 * `push: tags:`, checked out against `main`'s own tip rather than the tag
 * itself — `git tag`/`git push --tags` is a separate command from `git push
 * origin main` and does not trigger a `push: branches:` workflow at all, so
 * without this second trigger a wrong or orphaned tag would go unchecked
 * from the moment it is created until whatever unrelated commit next lands
 * on `main`, and a consumer could install it in the meantime.
 *
 * Exit codes: 0 is a pass (including the "nothing to check yet" case above),
 * 1 is the drift verdict (shape 2), 2 says the gate could not run at all — an
 * unreadable/unparseable package.json, a `version` not shaped like a tag, or
 * `git` itself failing for a reason other than "the tag is not there" (a
 * network problem, a timeout, an unreadable repository). Conflating that
 * last class with "not found" would turn a transient CI infrastructure
 * failure into a report that reads as this repository's own release being
 * broken.
 *
 * Run from the package root: php tests/check-release-tag-lockstep.php
 *
 * An optional path argument points it at another directory, and the
 * CHECK_RELEASE_TAG_REMOTE environment variable overrides which remote is
 * queried (`origin` unless set) — together what let
 * tests/check-release-tag-lockstep-cases.sh drive this over disposable local
 * git repositories acting as the "remote", rather than this gate ever
 * touching the real network in a test. There is no second positional
 * argument for the remote: every other gate in this directory takes exactly
 * one, the directory to check, and tests/harness.sh's shared `harness_accepts`
 * and friends call each gate with exactly that one argument — adding a second
 * positional here would need a second harness call shape for this gate alone.
 */

$root = $argv[1] ?? dirname(__DIR__);

require_once __DIR__ . '/../bin/support/safe-report-value.php';
require_once __DIR__ . '/../bin/support/read-quietly.php';
require_once __DIR__ . '/../bin/support/read-package-json-version.php';
require_once __DIR__ . '/../bin/support/version-tag-shape.php';

/**
 * The largest package.json this gate reads, in bytes — the same bound
 * tests/check-version-lockstep.php applies to the same file, for the same
 * reason: re-derive before raising it with `wc -c package.json`.
 */
const MAX_PACKAGE_JSON_BYTES = 1048576;

/**
 * The local ref this gate fetches a resolved remote tag into, so its commit
 * can be inspected. `git ls-remote` reports only a SHA; walking that SHA's
 * ancestry needs the object itself, which only a fetch brings into this
 * checkout. Namespaced under a path no branch or tag this package ships
 * would ever use, so a real `refs/heads/…`/`refs/tags/…` can never collide
 * with it.
 */
const PROBE_REF = 'refs/check-release-tag-lockstep/probe';

/**
 * The longest this gate lets any single `git` call run, in seconds.
 *
 * `ls-remote` and `fetch` talk to the real, unauthenticated `origin` over the
 * network — a stalled connection (a dropped packet through a flaky proxy, a
 * slow-loris-shaped hang) never produces the `fatal:`/non-zero exit the rest
 * of this file classifies on; it just never returns, and nothing upstream of
 * this file bounds that (`.github/workflows/ci.yml` sets no `timeout-minutes`
 * on the step). Applied uniformly to every call, including the local-only
 * ones (`rev-parse`, `merge-base`, `update-ref`): those finish in
 * milliseconds regardless, so the bound costs them nothing, and a single
 * mechanism is simpler than a network/local split that a future call site
 * could put on the wrong side of.
 */
const GIT_TIMEOUT_SECONDS = 30;

/**
 * Whether a proc_open() process resource is still running.
 *
 * A named wrapper, not an inline `proc_get_status($process)['running']` at
 * each call site: PHPStan has no model of an external signal changing a
 * process's live OS state between calls, so it treats repeated evaluations
 * of that exact expression within one function as returning the same value
 * it already narrowed at an earlier call — reported as `booleanNot.alwaysFalse`
 * / `if.alwaysTrue` on a second check this file's own timeout-escalation loop
 * needs to be genuinely fresh each iteration.
 *
 * @param resource $process A process resource from proc_open().
 *
 * @return bool Whether the process is still running.
 */
function stillRunning($process): bool
{
    return proc_get_status($process)['running'];
}

/**
 * Reads whatever is currently buffered on both non-blocking pipes and
 * appends it to $stdout/$stderr.
 *
 * A named helper rather than the two-line pair repeated inline: runGit()'s
 * own polling loop, the drain after that loop observes the process as no
 * longer running (a child can still write its FINAL bytes in the gap
 * between one iteration's read and the `running` check that follows —
 * `git rev-parse` writing its SHA and exiting is short enough for exactly
 * that interleaving, and losing those bytes silently turned a genuinely
 * successful run into a confusing setup failure downstream), and any future
 * call site all need the identical two reads.
 *
 * @param array{1: resource, 2: resource} $pipes  The stdout/stderr pipes,
 *                                                 already set non-blocking.
 * @param string                          $stdout Accumulator, by reference.
 * @param string                          $stderr Accumulator, by reference.
 */
function drainPipes(array $pipes, string &$stdout, string &$stderr): void
{
    $chunk = stream_get_contents($pipes[1]);
    $stdout .= $chunk === false ? '' : $chunk;
    $chunk = stream_get_contents($pipes[2]);
    $stderr .= $chunk === false ? '' : $chunk;
}

/**
 * Kills $process's whole process group, escalating from SIGTERM to SIGKILL.
 *
 * Split out of runGit()'s own timeout branch, which PHPMD flagged as too
 * long/complex once this escalation grew a grace period and a group-wide
 * SIGKILL sweep on top of the original single SIGTERM — the same complexity
 * this repository's own `use function`/early-return conventions exist to
 * keep in check.
 *
 * @param resource $process The process resource from proc_open().
 * @param int      $pid     The PID proc_open() tracks — also the process
 *                          GROUP id, since runGit() launches every command
 *                          through `setsid`.
 */
function killProcessGroup($process, int $pid): void
{
    // The group-wide signal first: on the (network-bound) calls this matters
    // for, the process proc_open() tracks has already exec'd into a helper
    // of its own, and proc_terminate() alone reaches only that top PID, not
    // the group setsid put it in charge of.
    if (function_exists('posix_kill')) {
        posix_kill(-$pid, \SIGTERM);
    }

    proc_terminate($process);

    // A brief grace period for an ordinary process to actually exit on
    // SIGTERM before escalating — bounded, so this cannot itself turn into
    // the unbounded wait GIT_TIMEOUT_SECONDS exists to prevent. SIGKILL,
    // unlike SIGTERM, cannot be caught, blocked or ignored, so it is what
    // actually makes the bound a HARD one rather than a request a stuck
    // process is free to sit on — proc_close() in the caller blocks until
    // the process is gone, so without this escalation a SIGTERM-resistant
    // process would make GIT_TIMEOUT_SECONDS mean nothing.
    for ($i = 0; $i < 20; $i++) {
        if (!stillRunning($process)) {
            break;
        }

        usleep(100000);
    }

    // Unconditional, not gated on stillRunning($process) again: that call
    // reports only the process proc_open() itself tracks (the group LEADER
    // after setsid), so a leader that already exited while a descendant it
    // spawned (a `git-remote-https` helper) did not would read as "not
    // running" and skip this — leaving exactly the descendant leak `setsid`
    // exists to let this reach. A signal to an already-empty process group
    // is a harmless no-op (posix_kill()/proc_terminate() on a gone PID/PGID
    // fail silently), so sending it unconditionally costs nothing on the
    // common path where SIGTERM alone already finished the job.
    //
    // Accepted, not closed: $pid could in principle be RECYCLED by the
    // kernel into an unrelated process's own PGID between the leader exiting
    // and this call, making that kill target the wrong group. Closing that
    // fully needs a stable handle a raw PID is not (Linux
    // `pidfd_send_signal`, unavailable from PHP without a C extension) —
    // disproportionate for a race this narrow (a single specific recycled
    // PID becoming another process's OWN group leader inside a two-second
    // window) guarding an already-rare trigger (a hung git network call) on
    // a path that only ever bounds worst-case CI duration and never affects
    // the gate's own ancestry verdict.
    if (function_exists('posix_kill')) {
        posix_kill(-$pid, \SIGKILL);
    }

    proc_terminate($process, \SIGKILL);
}

/**
 * Runs a git subcommand without a shell, bounded by GIT_TIMEOUT_SECONDS, and
 * reports its outcome.
 *
 * The ARGV array bypasses the shell entirely (PHP >= 7.4's proc_open()
 * accepts one directly) — the same argv-array discipline this package's own
 * security review applies to every subprocess call carrying content this
 * file does not fully control. Every argument built from this repository's
 * own content ($version, by way of PROBE_REF/the tag refspec) is already
 * shape-checked by isVersionTagShaped() before it reaches here, and $remote
 * is either the fixed literal 'origin' or a test-controlled local path — a
 * shell-string command would additionally need each of those escaped
 * correctly, an ARGV array needs no escaping because there is no shell
 * grammar for a special character to participate in.
 *
 * Both pipes are drained NON-BLOCKING in the same loop that watches the
 * process, rather than reading stdout to completion before stderr is ever
 * touched: `git fetch`'s stderr carries progress/warning text (git suppresses
 * the interactive progress meter once stderr is not a tty, as it is not
 * here, but does not suppress warnings), and a blocking `stream_get_contents`
 * on stdout while the child blocks on a full stderr pipe (commonly 64 KB on
 * Linux) is the textbook proc_open deadlock — neither side would ever
 * proceed. Polled rather than `stream_select()`-driven: this loop already
 * needs a wall-clock deadline for GIT_TIMEOUT_SECONDS, and a single
 * poll-drain-check cycle serves both concerns without a second mechanism.
 *
 * `setsid --` makes the launched `git` (or, on `ls-remote`/`fetch`, the
 * `git-remote-https`/`git-remote-ssh` HELPER it execs for the actual network
 * I/O) its own process-group leader, so a timeout can kill the whole group
 * rather than only the top PID proc_open() itself hands back. Reproduced
 * without it: `proc_terminate()` on a hung `git ls-remote` against an
 * unroutable address kills the visible `git` process, but the
 * `git-remote-https` child actually blocked on the network is reparented
 * and keeps running — the exact leak GIT_TIMEOUT_SECONDS exists to bound.
 * `setsid` re-execs in place, so the PID `proc_open()` reports is still
 * `git`'s own; negating it for `posix_kill()` targets the whole group.
 *
 * @param list<string> $argv The full argv, `git` itself included.
 *
 * @return array{stdout: string, stderr: string, exitCode: int} ExitCode is
 *         -1 when the process could not even be started (a missing `git`
 *         binary) and -2 when GIT_TIMEOUT_SECONDS was reached and the
 *         process was killed — neither is ever a code `git` itself returns.
 */
function runGit(array $argv): array
{
    $process = proc_open(array_merge(['setsid', '--'], $argv), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'Could not start git.', 'exitCode' => -1];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout   = '';
    $stderr   = '';
    $deadline = microtime(true) + GIT_TIMEOUT_SECONDS;

    while (true) {
        drainPipes($pipes, $stdout, $stderr);

        if (!proc_get_status($process)['running']) {
            break;
        }

        if (microtime(true) > $deadline) {
            killProcessGroup($process, proc_get_status($process)['pid']);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return [
                'stdout'   => $stdout,
                'stderr'   => $stderr . sprintf("\n[killed after %d seconds without completing]", GIT_TIMEOUT_SECONDS),
                'exitCode' => -2,
            ];
        }

        usleep(50000);
    }

    // One more drain after the loop observed the process as no longer
    // running: the child can still write its FINAL bytes in the gap between
    // this iteration's two read calls above and the `running` check that
    // broke the loop — `git rev-parse` writing its SHA and exiting is short
    // enough for exactly that interleaving. Skipping this final read risked
    // losing those bytes silently, turning a genuinely successful run into
    // `$tagCommit` reading empty and a false setup failure downstream.
    drainPipes($pipes, $stdout, $stderr);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout'   => $stdout,
        'stderr'   => $stderr,
        'exitCode' => proc_close($process),
    ];
}

$remoteEnv = getenv('CHECK_RELEASE_TAG_REMOTE');
$remote    = ($remoteEnv === false) || ($remoteEnv === '') ? 'origin' : $remoteEnv;

$version = readPackageJsonVersion($root, MAX_PACKAGE_JSON_BYTES);

// package.json's `version` is repository content, not something this gate
// controls — and it is about to become part of a `refs/tags/<version>`
// argument handed to `git`. Rejecting anything not shaped like a version tag
// here, rather than passing it through, is the same defence
// tests/check-version-lockstep.php applies to a README pin, applied to the
// operand this gate reads instead.
if (!isVersionTagShaped($version)) {
    fwrite(\STDERR, sprintf(
        "package.json's version %s is not shaped like a version tag — cannot resolve it as a git ref.\n",
        safeReportValue($version)
    ));

    exit(2);
}

$tagRef = 'refs/tags/' . $version;

// `--exit-code` turns "no matching ref" into its own exit code (2) rather
// than an empty stdout this gate would otherwise have to distinguish from a
// transport failure by parsing stderr text — verified against git 2.39.5 and
// 2.54.0: a resolved tag exits 0 with one stdout line, an unresolved one
// exits 2 with empty output, and every other failure (an unreachable remote,
// a malformed repository) exits some other non-zero code with a `fatal:`
// line on stderr.
$lsRemote = runGit(['git', '-C', $root, 'ls-remote', '--tags', '--exit-code', '--', $remote, $tagRef]);

if ($lsRemote['exitCode'] === 2) {
    // Shape 1 (see this file's own docblock) — expected right after a
    // version-bump push and before the tag push that follows it, and already
    // caught loudly elsewhere. Not this gate's concern.
    printf(
        "check-release-tag-lockstep: %s names no tag on %s yet — nothing to check.\n",
        safeReportValue($tagRef),
        safeReportValue($remote)
    );

    exit(0);
}

if ($lsRemote['exitCode'] !== 0) {
    fwrite(\STDERR, sprintf(
        "Could not query %s for %s: %s\n",
        safeReportValue($remote),
        safeReportValue($tagRef),
        safeReportValue(trim($lsRemote['stderr']))
    ));

    exit(2);
}

// The resolved ref is fetched into PROBE_REF so its ancestry can be walked,
// then deleted again below — before either exit() call that can follow it,
// not in a `finally` block: exit()/die() terminate the process immediately
// and do NOT run a pending `finally` (verified on 8.5.8 — a `finally` after
// an `exit()` in its own `try` never runs, unlike an actual thrown
// exception), so a `finally` around these two calls would silently skip the
// cleanup on exactly the two paths that most need it. The leading `+` forces
// the fetch's ref update regardless of what PROBE_REF already names, so a
// ref left behind by a prior interrupted run (or, in the fixture cases, a
// prior case reusing the same working tree) cannot make a later run's fetch
// fail on a spurious non-fast-forward.
$fetch = runGit(['git', '-C', $root, 'fetch', '--no-tags', '--', $remote, '+' . $tagRef . ':' . PROBE_REF]);

$commitExitCode = -1;
$commitStdout   = '';
$commitStderr   = '';

if ($fetch['exitCode'] === 0) {
    // `^{commit}` peels an annotated tag object through to the commit it
    // wraps, and is a no-op on a lightweight tag that already names a commit
    // directly — either way this is the SHA merge-base below walks ancestry
    // from. A tag pointing at anything else (a blob, a tree) fails to peel
    // here, which is this arm's own "could not run" case.
    $commit          = runGit(['git', '-C', $root, 'rev-parse', PROBE_REF . '^{commit}']);
    $commitExitCode  = $commit['exitCode'];
    $commitStdout    = $commit['stdout'];
    $commitStderr    = $commit['stderr'];
}

// Reached on every path past this point — success, a failed fetch, or a
// failed commit resolution alike — so PROBE_REF never survives this gate's
// own run regardless of how it ends. Its own exit code is intentionally not
// escalated into this gate's verdict (a delete failure here — a locked ref
// file from a genuinely concurrent process — does not mean the check above
// was wrong), but IS surfaced as a diagnostic: silently leaking a local ref
// is exactly the kind of state a later run would otherwise trip over with no
// explanation.
//
// The diagnostic is gated on the fetch having actually SUCCEEDED: when it
// did not, PROBE_REF was never created in the first place, so this same
// `update-ref -d` fails too — for that ordinary, expected reason rather
// than a genuinely stuck lock — and reporting it would print a confusing
// "could not delete" note ahead of the real "could not fetch it" cause
// below, about a ref that was never there to delete.
$cleanup = runGit(['git', '-C', $root, 'update-ref', '-d', PROBE_REF]);

if (($fetch['exitCode'] === 0) && ($cleanup['exitCode'] !== 0)) {
    fwrite(\STDERR, sprintf(
        "Note: could not delete the local probe ref %s afterwards: %s\n",
        safeReportValue(PROBE_REF),
        safeReportValue(trim($cleanup['stderr']))
    ));
}

if ($fetch['exitCode'] !== 0) {
    fwrite(\STDERR, sprintf(
        "Resolved %s on %s but could not fetch it: %s\n",
        safeReportValue($tagRef),
        safeReportValue($remote),
        safeReportValue(trim($fetch['stderr']))
    ));

    exit(2);
}

if ($commitExitCode !== 0) {
    fwrite(\STDERR, sprintf(
        "Fetched %s but could not resolve it to a commit: %s\n",
        safeReportValue($tagRef),
        safeReportValue(trim($commitStderr))
    ));

    exit(2);
}

$tagCommit = trim($commitStdout);

// `actions/checkout` defaults to `fetch-depth: 1` — this repository's own
// `.github/workflows/ci.yml` does not override it, since none of the
// `build` job's OTHER steps need history — and a shallow HEAD has no
// parent graph for `merge-base` to walk at all. Reproduced against a real
// depth-1 clone of this repository: `git merge-base --is-ancestor <the real
// 1.8.0 tag commit> HEAD` answers 1 ("not an ancestor") even though the tag
// genuinely is one, because there is no local commit graph connecting them
// yet — not a git limitation to work around by other means, `--unshallow`
// is git's own documented fix for exactly this. Guarded on
// `--is-shallow-repository` first: unconditionally unshallowing would
// itself error (`fatal: --unshallow on a complete repository does not make
// sense`) on every checkout that already has full history — the fixture
// suite's disposable repositories included.
$shallow = runGit(['git', '-C', $root, 'rev-parse', '--is-shallow-repository']);

if ($shallow['exitCode'] !== 0) {
    fwrite(\STDERR, sprintf(
        "Could not determine whether this checkout is shallow: %s\n",
        safeReportValue(trim($shallow['stderr']))
    ));

    exit(2);
}

if (trim($shallow['stdout']) === 'true') {
    $unshallow = runGit(['git', '-C', $root, 'fetch', '--no-tags', '--unshallow', '--', $remote]);

    if ($unshallow['exitCode'] !== 0) {
        fwrite(\STDERR, sprintf(
            "This checkout is shallow and could not be deepened, so ancestry cannot be determined: %s\n",
            safeReportValue(trim($unshallow['stderr']))
        ));

        exit(2);
    }
}

// The actual GH-42 check: is the tagged commit part of THIS branch's own
// history? `--is-ancestor` exits 0 for an ancestor (or the same commit), 1
// for a commit this branch's history genuinely never contains, and anything
// else (128 — an unresolvable operand, a corrupt repository) is this gate's
// own could-not-run class rather than either verdict.
$ancestor = runGit(['git', '-C', $root, 'merge-base', '--is-ancestor', $tagCommit, 'HEAD']);

if ($ancestor['exitCode'] === 1) {
    fwrite(\STDERR, sprintf(
        "MISMATCH  %s on %s resolves to %s, which is not part of this branch's history.\n",
        safeReportValue($tagRef),
        safeReportValue($remote),
        safeReportValue($tagCommit)
    ));
    fwrite(\STDERR, "\nRetag from a commit that is actually on this branch.\n");

    exit(1);
}

if ($ancestor['exitCode'] !== 0) {
    fwrite(\STDERR, sprintf(
        "Could not determine whether %s is an ancestor of HEAD: %s\n",
        safeReportValue($tagCommit),
        safeReportValue(trim($ancestor['stderr']))
    ));

    exit(2);
}

printf(
    "check-release-tag-lockstep: OK — %s on %s resolves to %s, which is part of this branch's history.\n",
    safeReportValue($tagRef),
    safeReportValue($remote),
    safeReportValue($tagCommit)
);
exit(0);
