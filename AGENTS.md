# AGENTS.md — magicsunday/coding-standard

Single source of truth for the shared PHP and JS/TS tooling configuration of the
`magicsunday/*` projects. This repository ships **configuration only** — no runtime
library code is distributed to consumers. It does carry its own `require-dev`-only
PHPUnit suite (`phpunit.xml.dist`, `composer ci:test:phpunit`) for first-party
test-support classes under `tests/` (GH-77) — a repository-internal concern, kept out
of the distributed package via `.gitattributes` `export-ignore`, not something a
consumer installs. Its correctness is proven two ways: by *consumer
adoption* (a repo wires the configs and its own `composer ci:test` stays green), and
by the fixture-driven gates under `tests/`, each of which drives the thing it certifies
against inputs that must produce a finding — including `tests/check-js-configs.sh`,
which runs the real Biome, `tsc` and jscpd against the shared configs and the shipped `templates/jscpd.json`. A gate that cannot be
shown to fail proves nothing, so a gate here is not trusted without its failure
path — every first-party gate has one. `ci:test:php:lint` is a third-party syntax
check with no first-party logic of its own to drive, which is why it is not
counted here. `ci:test:php:analyse` (a parameterised PHPStan run over the PHP
files under `bin/` and `tests/`) DOES carry a first-party piece — the
`disallowedFunctionCalls` ban list `/phpstan.neon` includes — so it gets its own
failure path too: `ci:test:php:analyse-cases`.

## Layout

| Path | Kind | Consumed by |
|---|---|---|
| `php-cs-fixer/base.php` | importable | a factory returning a `PhpCsFixer\Config`; the consumer adds header + finder |
| `phpstan/base.neon` | importable | `includes:` — `level: max`, wires the strict/deprecation/phpunit rule packs via explicit relative includes |
| `phpstan/strict.neon` | importable | opt-in tier — shipmonk + symplify packs + `disallowed-calls.neon` + extra-strict report params |
| `phpstan/disallowed-calls.neon` | importable | the case-folding bans (`strtoupper`/`strtolower`/`ucfirst`/`lcfirst`/`ucwords`, byte-wise on UTF-8) via `spaze/phpstan-disallowed-calls`; included by `strict.neon`, also includable on its own; a verified-safe site is re-allowed per entry with `allowIn`; carries the public-facing documentation, the actual ban list lives in `phpstan/disallowed-function-calls.neon` (below) |
| `phpstan/disallowed-function-calls.neon` | `includes:`d by `phpstan/disallowed-calls.neon` AND by `/phpstan.neon` | the `disallowedFunctionCalls` entries (re-derive the count rather than trusting a number here: `grep -c "function:" phpstan/disallowed-function-calls.neon`) as a plain parameters-only fragment with no relative `includes:` of its own — one source both a consumer's vendor-nested install and this repository's own root-level self-analysis can include unchanged, rather than either restating the list |
| `rector/base.php` | importable | applies the shared rule sets/skips to a `RectorConfig`; 2nd arg is the target PHP floor (`80300`–`80600`, or null to keep the caller's) and derives the matching `UP_TO_PHP_8x` set — the `rector/rector: ^2.4` floor guarantees every mapped set exists (`UP_TO_PHP_86` landed in 2.4.0) |
| `deptrac/layers.yaml` | importable (`imports:`) | the canonical layered-architecture ruleset (Deptrac); layers matched by namespace segment via a `directory` collector (`.*/Repository/.*`), which matches only analysed `src` classes so a referenced vendor class like `Illuminate\Support\…` falls to uncovered naturally (a `classNameRegex` cannot, because Deptrac has no path for a referenced class to exclude it); ports across repos without renaming; permissive start (only uncontroversial upward edges forbidden, domain core mutually permissive); pulled in by `require` (`deptrac/deptrac ^4.2`, 8.2+) |
| `templates/*` | copy-and-adapt | `phpunit.xml.dist`, `infection.json5`, `phplint.yml`, `editorconfig`, `gitattributes`, `jscpd.json` (PHP + JS/TS formats), `deptrac.dist.yaml` (`imports` the shared layers.yaml + declares `paths`) |
| `/phpunit.xml.dist` | root dev config, `export-ignore`d | this repository's OWN PHPUnit run (`composer ci:test:phpunit`), not a consumer-facing template — unlike `templates/phpunit.xml.dist`, its `bootstrap`/`xsi:noNamespaceSchemaLocation` point at `.build/vendor` because this repo's own `composer.json` overrides `config.vendor-dir` to `.build/vendor` (and `bin-dir` to `.build/bin`) |
| `/phpstan.neon` | root dev config, `export-ignore`d | this repository's OWN PHPStan run (`composer ci:test:php:analyse`) over the PHP files under `bin/` and `tests/` (`tests/consumer` excluded — its own fixture project, covered by the "Consumer smoke" CI steps instead), level 6 plus `phpstan/disallowed-function-calls.neon`'s case-folding bans (see that row — not a restated copy); cannot `includes: - phpstan/disallowed-calls.neon` the way a consumer does, see the file's own comment for why. Its own include of the ban list is proven live by `composer ci:test:php:analyse-cases` (`tests/check-php-analyse-cases.sh`) — a link `check-disallowed-calls-cases.sh` cannot cover, since that one only ever runs through `tests/consumer`'s installed copy |
| `biome/base.json`, `tsconfig/base.json` | importable (`extends`) | the JS/TS repos |
| `bin/check-consumer-config.php` | executable (composer `bin`) | the template lockstep gate — asserts each consumer copy's stable region (strict phpunit flags, jscpd/phplint/editorconfig invariants, the `deptrac.yaml` shared import, uniform `src`/`tests`), ignores per-repo paths; also covers `biome.json`/`biome.jsonc`/`tsconfig.json` on the narrower extends-stub contract, keyed on the consumer declaring the npm dependency (the `"//"` guard, an unopenable Biome/TypeScript config, one past the size cap and a broken `package.json` probe do not wait for adoption — the probe itself runs only where a `biome.json`, `biome.jsonc` or `tsconfig.json` exists), parsed as JSONC |
| `bin/check-js-config.mjs` | executable (npm `bin`, `check-js-config`) | the node-only front end for the SAME `biome.json`/`biome.jsonc`/`tsconfig.json` contract `bin/check-consumer-config.php` covers — for a repository with no `composer.json` at all (#32). Not a reimplementation kept in step by hand: every biome/tsconfig case in `tests/check-consumer-config-cases.sh` also runs this gate against the identical fixture directory and requires the identical verdict (`assert_accepts_js`/`assert_rejects_js`/`assert_usage_error_js`/`assert_report_is_inert_js`/`assert_reports_once_js` there), so the two contracts cannot silently drift apart |
| `bin/support/safe-report-value.php`, `bin/support/safe-report-value.mjs` | `require`d / `import`ed by the gates that echo a value read out of a repository file (`grep -rln "^require_once .*safe-report-value" bin tests` for the PHP requirers — anchored on the statement, since the bare path also matches files that only mention it — and `grep -rl "from '\./support/safe-report-value.mjs'" bin` for the one node requirer, `bin/check-js-config.mjs`); `tests/check-js-configs.sh`'s own inline `encodeValue()` is a THIRD, unrelated scrub for a different trust boundary (that script's own INFO lines about the npm pack/install it runs) and is not this file | scrubs C0/DEL and breaks the legacy `##[` workflow-command prefix on any such value, and caps it at 64 bytes — the `bin/` gates run in the consumer's CI over pull-request content and this repository's own gate over its own, and the runner scans both STDERR and STDOUT for workflow commands; NOT a `bin` entry point, so it needs no `"bin"` row in composer.json/package.json but must stay inside the dist archive/npm tarball |
| `bin/support/read-quietly.php` | `require`d by every PHP gate that reads a repository file whose size a pull request can influence (`grep -rln "^require_once .*read-quietly" bin tests`: `bin/check-consumer-config.php`, `tests/check-version-lockstep.php`, `tests/lint-json.php`) — extracted at the same three-copy duplication threshold `safe-report-value.php` above was | `readQuietly(string $path, int $maxBytes): string\|false` suppresses PHP's own unsuppressed E_WARNING on an unreadable file via a scoped `set_error_handler`, capping the read at `$maxBytes` so an oversize file cannot exhaust memory before the gate gets to report it; `readCapped(string $path, int $maxBytes): string\|false\|null` wraps it with the true/false/null "content, unreadable, or too large" split every caller's own oversize message needs — not named `readBounded()`, which is `bin/check-consumer-config.php`'s own pre-existing, differently-shaped self-reporting closure; NOT a `bin` entry point, so it needs no `"bin"` row in composer.json but must stay inside the dist archive |
| `tests/GateTestCase.php` | `require-dev`-only PHPUnit infrastructure, not a `bin` entry point | abstract base class (`MagicSunday\CodingStandard\Test\GateTestCase`) for suites migrated off `tests/harness.sh` (#71) — the five `assertGateAccepts`/`assertGateRejects`/`assertGateUsageError`/`assertGateReportIsInert`/`assertGateReportsOnce` decisions plus `fixture()`, ported from `harness.sh`'s `harness_decide_*` functions; consumed by its own meta-suite `tests/GateTestCaseTest.php` (`grep -c '#\[Test\]' tests/GateTestCaseTest.php`) today, and the intended base for every suite migrated in #78–#82 (see those issues for current migration status). Deliberately exempt from the `Abstract*` naming rule (see the class's own docblock): it plays the same role as PHPUnit's own `TestCase`, itself abstract and unprefixed — every future suite extends it the same way it extends `TestCase` |
| `tests/Support/GateResult.php`, `tests/Support/GateProcess.php`, `tests/Support/FixtureDirectory.php` | `require-dev`-only PHPUnit infrastructure, not `bin` entry points | the collaborators `GateTestCase` composes (namespace `MagicSunday\CodingStandard\Test\Support`) — `GateProcess::run()` invokes a gate as a real subprocess (array argv, combined stdout+stderr in best-effort arrival order) and returns a `GateResult` (`output`, `exitCode`, `isDegraded()`); `FixtureDirectory` is the per-test throwaway fixture root (`path()`, `writeJson()`, `cleanup()`); not consumed by any gate/`bin` script directly — used via `GateTestCase` composition, and each also has its own dedicated unit-test suite (`GateResultTest.php`, `GateProcessTest.php`, `FixtureDirectoryTest.php`, all extending `PHPUnit\Framework\TestCase` directly, not `GateTestCase`) |

**Layout rule:** the directory states the consumption mode — a tool-named directory
(`php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/`) holds an **importable**
config; `templates/` holds **copy-and-adapt** files whose tools require the file at the
consumer's repo root and therefore cannot be imported; the repository root holds only
this package's **own** dev config, all of it `export-ignore`d — except
`/package.json`, which a `github:` consumer must receive. This repository tracks no
lock file; a repository that DOES commit `/package-lock.json` has the same exception
for that specific file, and the header of
`templates/gitattributes` is where that is stated. Put a new config in the
directory that matches how it is consumed, never at the root for convenience.

## How it is consumed

- **PHP:** `composer require --dev magicsunday/coding-standard` (Packagist). The
  importable configs are `require`d / `includes:`d from `vendor/`; the templates are
  copied and adapted, with a lockstep check keeping them from drifting. The package
  `require` delivers the entire PHP toolchain transitively — php-cs-fixer, PHPStan +
  rule packs, Rector, phplint **and PHPUnit** (`^12.0 || ^13.0`) — so a
  **base-tier** consumer's `require-dev` is just this one entry; the PHPUnit
  constraint is pinned here and bumped once for every repository, never per-repo.
  The opt-in strict PHPStan tier and Infection are the exception: they pull the
  `suggest`ed shipmonk/symplify/spaze/infection packs, added directly by the adopting
  repository.
- **JS/TS:** a GitHub **git dependency** — `github:magicsunday/coding-standard#<tag>`
  (never published to the npm registry, like `webtrees-chart-lib`). `biome.json` and
  `tsconfig.json` `extends` the shared files from `node_modules`. **The npm side is
  deliberately not the mirror image of the Composer side:** the two shared config
  directories carry no tooling of their own, so `package.json` does NOT deliver a
  `biome`/`typescript` toolchain the way `require` does for PHP — each consumer
  installs `@biomejs/biome` and `typescript` itself. (`bin/check-js-config.mjs` is
  a deliberate, separate exception — see the Node-floor bullet below.)
  **Node tool versions track the current major — always pin forward.** The peer
  ranges never span a major CI does not exercise — read them rather than trusting a
  copy here (`jq -r '.peerDependencies' package.json`), and note that
  `tests/check-js-configs.sh` asserts each against the exact pin it installs; moving one
  means bumping the pin and the range in the SAME edit, then letting
  `tests/check-js-configs.sh` vet both — the gate is a lockstep check BETWEEN the two
  fields, so it cannot vet one of them alone. Bumping the pin first and widening
  afterwards was written here and reds the gate on exactly the case this bullet sets
  up: verified, pin `3.0.0` against a still-`^2.5.0` range exits 1 with `a
  peerDependencies range is not satisfied by the pin the smoke proves`. An agent
  following that order reads the red as "the new version fails the smoke" and reverts
  a good bump. **Two independent Node floors, do not conflate them:** `devEngines`
  (`node >= 24`) governs developing this repository; `engines` (`node >= 20`, added
  for `bin/check-js-config.mjs`, #32) is the separate, consumer-facing floor npm
  enforces on install. Bumping one is not a reason to bump the other. CI
  pins `node-version: 24` — do not put the floating `lts/*` back, it changes major on
  its own schedule. Dependabot's npm ecosystem reads
  `devDependencies`, not `peerDependencies` (verified 2026-07-28), which is why the pins are the moving
  part. The reasoning behind each of these — why the peers are optional, why the
  ranges are a policy rather than a compatibility promise, and what a consumer below
  them actually hits — is in the README under *The npm side is not the mirror image of
  the Composer side*; keep it in one place.

## Conventions

- **This repo DEFINES the house style.** The universal rules the `*-reviewer` agents
  enforce (php-cs-fixer ruleset, phpstan level/params, the `Abstract*` naming and
  `final` structural rules) live here. Changing a rule here is a normative change —
  run the `spec-first-rule-change` discipline first (a verified decision table before
  the edit), and remember every consumer inherits it on its next version bump.
- **`base.neon` is the floor, `strict.neon` is the target — not two equal options.**
  Every repository runs the base; the strict tier is staged only because enabling it
  surfaces findings that need per-repo triage. A repository still on the base carries
  an **open issue** for reaching the strict tier, so the gap is visible and finite
  instead of becoming the next drift. Never present the two tiers as a free choice.
- **Making the base stricter is a change to every consumer.** Verify it against at
  least one real adopter before releasing, and ship it as its own version — a
  consumer on `^1.0` picks a stricter base up on its next update, and a red build
  they did not ask for is the failure mode to avoid.
- **Never put a `"//"` note key in `biome/base.json`.** Biome's config deserializer is
  strict about unknown keys and refuses the whole file (`Found an unknown key "//"`),
  so a config that is perfectly valid JSON is still unloadable for every consumer —
  which is exactly what shipped once. `tsconfig/base.json` and `templates/jscpd.json`
  tolerate the key and keep it — measured, not assumed, and the mixture is therefore
  not an inconsistency to tidy up. The gate follows the same split: it reports a `"//"`
  key in a consumer's `biome.json`/`biome.jsonc` and says nothing about one in
  `tsconfig.json`. A new shipped config gets the key only after its tool has been run
  against a copy carrying it. Biome's documentation belongs in the README instead.
  `composer ci:test:json` cannot catch this class of break (it only proves the file
  parses); only `tests/check-js-configs.sh`, which loads the config with the real
  tool, can. Any new shipped config gets a guard that runs its actual tool, not a
  syntax check.
- **The importable PHP configs must stay valid in Rector's `phpstanConfig` context**,
  not only the main PHPStan run: the rule extensions are pulled in by explicit
  relative `includes` in `base.neon` (not `phpstan/extension-installer`, which does
  not reach Rector's bundled PHPStan). Do not reintroduce extension-installer here.
- **A multi-version consumer sets `phpVersion` as a `min`/`max` range**, not a
  single value: `min` = floor, `max` = ceiling. The range narrows only the
  version-conditioned surface (`PHP_VERSION_ID`-style constants,
  `version_compare()`) — it does **not** extend the feature/deprecation rules
  to the ceiling — see the README's `phpVersion` explanation for the mechanism,
  the re-derive commands, what actually catches a real runtime deprecation, and
  a narrowed-stub-type case where PHPStan can false-positive in the opposite
  direction. A single-PHP repository keeps the single value.
- **The template lockstep gate rolls out script-first, workflow-step-last.** The
  reusable `php-quality` workflow runs a FIXED list of `composer ci:test:php:*` steps,
  so adding a `Templates` step that runs `ci:test:php:templates` reds EVERY consumer
  that lacks that script — not just the chart modules but every PHP repo on the shared
  workflow. So the order is: (1) ship `bin/check-consumer-config.php` here, (2) add the
  `ci:test:php:templates` script to every consumer and align its template copies to the
  canon, (3) only then add the step to the reusable workflow. Never add the workflow
  step before all consumers carry the script.
- **`bin/check-consumer-config.php` alone does not reach a repository without
  Composer.** It is a Composer-installed entry point a consumer runs from
  `ci:test:php:templates`, so it only ever sees repositories that consume the PHP
  side too — the pure-JS repositories have no `composer.json` and are therefore
  unreachable through it. `bin/check-js-config.mjs` (#32) is the node-only front end
  for the same `biome.json`/`biome.jsonc`/`tsconfig.json` contract, wired as an npm
  `bin` entry instead, and does reach them — but SHIPPING the gate is not the same as
  a given repository ADOPTING it: a pure-JS repository still needs the npm script
  wired into its own CI. Re-derive which repositories have a JS/TS config but no
  `composer.json` — the rollout candidates for that wiring — rather than trusting a
  list here:

  Save it and run it; do not paste it into an interactive shell, since both guards
  `exit`:

  ```
  #!/usr/bin/env bash
  repos="$(gh repo list magicsunday --limit 1000 --no-archived --json name --jq '.[].name')" \
      || { echo 'gh failed — RESULT UNKNOWN, not clean' >&2; exit 1; }
  [ -n "$repos" ] || { echo 'no repositories listed — RESULT UNKNOWN' >&2; exit 1; }

  for r in $repos; do
      for f in biome.json biome.jsonc tsconfig.json; do
          gh api "repos/magicsunday/$r/contents/$f" >/dev/null 2>&1 || continue
          gh api "repos/magicsunday/$r/contents/composer.json" >/dev/null 2>&1 || echo "$r"
          break
      done
  done
  ```

  The first two lines are the point, not boilerplate: without them an unauthenticated
  or rate-limited `gh` yields an empty word list, the loop never runs, and the block
  exits 0 printing NOTHING — byte-identical to "every JS/TS repository also has a
  composer.json". Measured with `gh` stubbed to fail. That is the shape this file
  forbids below (*a gate that aborts must not read as a gate that
  passed*), so the recipe has to carry the third state itself.

  All three spellings, because the gate covers all three and a `biome.json`-only
  probe halves the answer. The per-file probe ABOVE has the same blind spot in the
  small: `|| continue` treats a 403 or a rate limit exactly like a 404, so a
  throttled repository drops out of the answer without a word. A repository
  appearing here after an unexplained `gh` error is worth re-running before acting on.

  Note that the probes write to `/dev/null 2>&1`, so no such error is visible. Let the
  composer.json probe's stderr through if you need to see one, since its `|| echo`
  turns a 403 into a FALSE POSITIVE rather than a silent drop.

  Do not read a green run as "every consumer's JS config is checked" — it means no
  candidate for the `bin/check-js-config.mjs` rollout was found among currently
  reachable repositories, not that every one already runs the gate.
- **A gate over a shared link keys on ADOPTION, never on the file being present.**
  The JS/TS half of the lockstep gate asserts that `biome.json`, `biome.jsonc` and `tsconfig.json` extend
  the shared configs — but only once the repository declares the npm dependency. Keyed
  on the file instead, it reds every consumer that ships a `biome.json` and has not
  adopted, on the update that first delivers the gate; verified against the four real
  consumers, each of which went to exit 1 on a link they never claimed. And they could
  not have pre-empted it: a consumer cannot pin an npm tag that does not exist yet, so
  "align first, enforce second" is the only order the dependency direction allows. Same
  staging rule as the workflow step, one layer down. The exceptions are the checks that
  cannot be answered, or are a defect however the file was written: the `"//"` key
  (which makes a Biome config unloadable whoever wrote it), an unreadable file, one
  past the size cap, and a `package.json` the probe cannot parse. Those four stay
  unconditional; the row in the Layout table above lists the same set.
- **A stricter template is a change to every consumer, same as a stricter base.** The
  canonical `templates/*` are the house standard, not a starting point to loosen: a
  consumer copy must not drop a strict flag. When tightening a template, verify the
  aligned consumers stay green (the chart modules' suites already pass under the full
  strict `phpunit.xml` — proven via the buildbox) before the gate is wired.
- **Generated dependencies live under `.build/`, here as much as in the modules.** Both
  manifests (root and `tests/consumer`) set `config.vendor-dir` to `.build/vendor` and
  `config.bin-dir` to `.build/bin`, matching the sibling repositories — a repo that
  defines the house layout and then ignores it teaches the wrong path to everyone
  copying from it. Documentation examples use the `.build/vendor/…` prefix for the same
  reason, with the note that the prefix is the consumer's own `vendor-dir`. No shipped
  config may depend on the choice: `base.neon` reaches its sibling rule packs with
  `../../../`, resolved from the package's own position, so both layouts work and the
  fixture proves it.
- **Every gate invoked as a single command has exactly one definition, and CI invokes
  that one.** The manifest declaring the gate is the definition — `composer.json` for
  the PHP gates, `package.json` for the JS one, since the `js` job runs without PHP. A
  workflow step that repeats the inner command instead is a second definition that
  drifts silently: a composer script can grow a second command — an array entry, or a
  string promoted to one — and that command would never run in CI, which keeps passing
  the first command alone. It also decides where a gate is discoverable, and locally
  reproducible: run `composer ci:test:<name>` and `npm run ci:test:js`, not the script
  paths, which is how CI runs them.

  The exceptions, by step name rather than by a criterion that selects the wrong set
  (`grep -c 'working-directory: tests/consumer' .github/workflows/ci.yml` answers 4,
  because the fixture's own install carries it too):

  | step | why it is not a manifest script |
  |---|---|
  | *Validate composer.json* | runs BEFORE `composer install` and against a manifest that may not parse, so a script in that manifest cannot be its home |
  | *Consumer smoke - install the package as a consumer would* | a `composer install` bootstrapping the fixture; the thing a script would live in does not exist yet |
  | *Consumer smoke - phpstan / php-cs-fixer / rector* | run under `working-directory: tests/consumer`, whose manifest declares no `scripts` block |

  The root `composer install` at the top of the build job is the same shape as the
  second row. Enumerating both manifests therefore does NOT give full local coverage.
  Reproduce the excluded class directly:

  ```
  composer install --working-dir=tests/consumer
  cd tests/consumer && .build/bin/phpstan analyse && .build/bin/php-cs-fixer check && .build/bin/rector process --dry-run
  ```

  Declaring those three as `ci:test:*` scripts in `tests/consumer/composer.json` and
  having `ci.yml` call them would remove the exception rather than document it — the
  better fix, and not this branch's scope.
- **Indentation is 4 spaces in every file** (YAML, JSON, PHP, neon).
- **A tool with a PHP constraint narrower than the consumer's matrix gets its OWN
  manifest, never a root `require --dev`.** The case that forced the rule is
  `roave/backward-compatibility-check` (`php: ~8.4.0 || ~8.5.0` from 8.20.0 on): required
  at the root of an `^8.3` library it writes itself into the root `composer.lock`, and
  every other leg of the 8.3/8.4/8.5 matrix then aborts `composer install` with *"Your
  lock file does not contain a compatible set of packages"* — verified, exit 2. A
  single-leg CI job does not help, because the poisoned lock is shared. Give it
  `tools/<name>/composer.json` with `bin-dir: .build/bin` / `vendor-dir: .build/vendor`
  (the house `.build/` layout) and install it with `--working-dir`. This repository only
  DOCUMENTS the tool for consumers — it declares it under `suggest`, and the adoption
  recipe (single-leg job, `fetch-depth: 0`, `ext-intl`, at least one existing tag, and
  registering the status context as required) lives in the README's
  *Backward-compatibility check* section.
- **README.md and this file ship in the same change** as any layout/config/consumer
  claim they describe.
- **Versioning:** the Composer package is tag-versioned (Packagist); tag `X.Y.Z` and
  keep `package.json`'s `version` in step for the npm git-dependency pin. The README
  writes that tag out a third time in its install instructions, so
  `composer ci:test:version` asserts that the manifest and every README pin agree —
  it reads no git, so the TAG is kept in step by bumping all of them in the same
  commit as the tag, not by the gate. The gate fails closed if the README documents no pin at all, so deleting
  the instructions cannot make it pass vacuously.
  **Narrowing an npm peer range ships as a MINOR, and the release notes say so.** In
  the npm ecosystem a raised peer floor is the canonical breaking change — npm 7+
  answers a conflict with a hard `ERESOLVE`, not a warning — so the reflex is to call
  it MAJOR. Here it is not, because one tag serves both halves and they want opposite
  answers: the npm side is consumed by an exact `#<tag>` pin, where the number carries
  no mechanical force at all, while the Composer consumers sit on a caret range that a
  peer bump does not touch. Forcing a major would push a `^2.0` migration onto every
  Composer consumer for a change that cannot reach them. Invert this the day the npm
  side moves to a caret range. Raising the shared Biome base's own floor — a key that
  does not exist in the older tool, which Biome rejects wholesale — falls under the
  same rule and the same obligation to name it in the notes.
- **A gate that aborts must not read as a gate that passed.** These harnesses report
  their results line by line, so a run that dies before its first assertion prints no
  failure marker at all — and anything judging the run by grepping its output for one
  reads the abort as a clean pass. That is not hypothetical: an apostrophe inside a
  comment within an embedded `node -e '…'` block closed the surrounding single quote,
  bash died at that line having run nothing, and a `grep -E '^FAILED'` check reported
  green. The rule that follows: **judge a run by its exit code, never by the absence of a
  string in its output** — the string is missing for both outcomes. That rule is free
  and closes the class outright: every harness is invoked by exactly one CI step that
  judges its exit code, and bash exits 2 on a syntax error. A `bash -n` gate over the
  same files was written and removed again — it could not see a defect the harness's
  own step does not already red, and cost 347 lines to say so a second time.
- **A no-op config is worse than a missing one, and tool names are where they hide.**
  Two shipped configs looked active while enforcing nothing: a `"//"` key made
  `biome/base.json` unloadable, and jscpd's `format` takes FORMAT names, so the
  plausible `"ts"`/`"js"` scan nothing at all and report a clean run. Neither errors —
  both go green. When adding or editing a shipped config, run its real tool against a
  fixture that MUST produce a finding, and only trust the config once that finding
  appears.
- **A shipped config uses the tool's current spelling, and a gate over it covers
  every spelling.** `linter.rules.recommended` still works, but Biome's configuration
  reference marks it deprecated in favour of `preset` — a shared config left on a
  deprecated key hands its eventual removal to every consumer at once. (Say only that:
  no announcement of a removal *version* exists, and claiming one made the migration
  read as urgent for a reason that could not be checked.) Two consequences,
  and the second is the one that gets missed: the deprecation surfaces only inside
  Biome's `biome migrate` advisory (which appears when `$schema` and CLI version
  differ), so a repo with a matching `$schema` never sees the notice; and while the
  old spelling survives, the gate must reject BOTH off values, since the new one is
  otherwise an unguarded way to drop the same rules.

## CI and security

The reusable `magicsunday/.github` workflows provide code-scanning, zizmor,
scorecard, dependency-review, yamllint, commit-convention, label-sync and
auto-merge; `.github/dependabot.yml` covers composer + npm + github-actions. The
own `ci.yml` adds a `js` job running the JS consumer smoke, the counterpart to the
PHP consumer smoke. **That job publishes the status context `JS/TS configs` — no
spaces around the slash, and no matrix suffix — and it must be registered in the
branch's `required_status_checks`.** Read the setting rather than trusting this
sentence about it:
`gh api repos/magicsunday/coding-standard/branches/main/protection --jq '.required_status_checks'`.

Nothing enforces an order here — the API accepts any context string, registered or
reported or not, and this one is registered although the job has never run on `main`.
What decides whether a PR is stuck is its OWN head: protection evaluates the checks on
the head SHA, and a `pull_request` run takes its workflow files from that PR's merge
ref. So the PR that introduces the job reports the context and merges; every PR
branched before it does not, and sits BLOCKED with all visible checks green. Merging
the job does NOT release them, because no `pull_request` event fires when the base
moves — each needs a rebase or a synchronise first. Adding a matrix dimension to that
job later would rewrite the context and desync the setting silently, the same way a
`Build (8.x)` leg does. Community
health files (`SECURITY.md`, `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`) are inherited
from `magicsunday/.github`.

## License

MIT — tooling/config, deliberately permissive so both the GPL webtrees modules and
the MIT standalone libraries can consume it without friction.
