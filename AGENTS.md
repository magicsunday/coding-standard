# AGENTS.md — magicsunday/coding-standard

Single source of truth for the shared PHP and JS/TS tooling configuration of the
`magicsunday/*` projects. This repository ships **configuration only** — no runtime
library code, and no PHPUnit suite. Its correctness is proven two ways: by *consumer
adoption* (a repo wires the configs and its own `composer ci:test` stays green), and
by the fixture-driven gates under `tests/`, each of which drives the thing it certifies
against inputs that must produce a finding — including `tests/check-js-configs.sh`,
which runs the real Biome, `tsc` and jscpd against the shared configs and the shipped `templates/jscpd.json`. A gate that cannot be
shown to fail proves nothing, so a gate here is not trusted without its failure
path — of the FIRST-PARTY gates, `ci:test:json` is the one that still lacks one,
tracked in #41 rather than left implicit. `ci:test:php:lint` is a third-party
syntax check with no first-party logic to drive, which is why it is not counted
here.

## Layout

| Path | Kind | Consumed by |
|---|---|---|
| `php-cs-fixer/base.php` | importable | a factory returning a `PhpCsFixer\Config`; the consumer adds header + finder |
| `phpstan/base.neon` | importable | `includes:` — `level: max`, wires phpat + the strict/deprecation/phpunit rule packs via explicit relative includes |
| `phpstan/strict.neon` | importable | opt-in tier — shipmonk + symplify packs + `disallowed-calls.neon` + extra-strict report params |
| `phpstan/disallowed-calls.neon` | importable | the case-folding bans (`strtoupper`/`strtolower`/`ucfirst`/`lcfirst`/`ucwords`, byte-wise on UTF-8) via `spaze/phpstan-disallowed-calls`; included by `strict.neon`, also includable on its own; a verified-safe site is re-allowed per entry with `allowIn` |
| `rector/base.php` | importable | applies the shared rule sets/skips to a `RectorConfig`; 2nd arg is the target PHP floor (`80300`–`80600`, or null to keep the caller's) and derives the matching `UP_TO_PHP_8x` set — the `rector/rector: ^2.4` floor guarantees every mapped set exists (`UP_TO_PHP_86` landed in 2.4.0) |
| `deptrac/layers.yaml` | importable (`imports:`) | the canonical layered-architecture ruleset (Deptrac); layers matched by namespace segment via a `directory` collector (`.*/Repository/.*`), which matches only analysed `src` classes so a referenced vendor class like `Illuminate\Support\…` falls to uncovered naturally (a `classNameRegex` cannot, because Deptrac has no path for a referenced class to exclude it); ports across repos without renaming; permissive start (only uncontroversial upward edges forbidden, domain core mutually permissive); pulled in by `require` (`deptrac/deptrac ^4.2`, 8.2+) |
| `templates/*` | copy-and-adapt | `phpunit.xml.dist`, `infection.json5`, `phplint.yml`, `editorconfig`, `gitattributes`, `jscpd.json` (PHP + JS/TS formats), `ArchitectureTest.php` (phpat: `Abstract*` naming + `beFinal`), `deptrac.dist.yaml` (`imports` the shared layers.yaml + declares `paths`) |
| `biome/base.json`, `tsconfig/base.json` | importable (`extends`) | the JS/TS repos |
| `bin/check-consumer-config.php` | executable (composer `bin`) | the template lockstep gate — asserts each consumer copy's stable region (strict phpunit flags, jscpd/phplint/editorconfig invariants, the `deptrac.yaml` shared import, uniform `src`/`tests`), ignores per-repo paths; also covers `biome.json`/`tsconfig.json` on the narrower extends-stub contract, keyed on the consumer declaring the npm dependency (the `"//"` guard, an unopenable Biome/TypeScript config, one past the size cap and a broken `package.json` probe do not wait for adoption — the probe itself runs only where a `biome.json`, `biome.jsonc` or `tsconfig.json` exists), parsed as JSONC |
| `bin/check-phpat-subjects.php` | executable (composer `bin`) | the phpat subject-liveness guard — parses a consumer's ArchitectureTest and asserts every `#[TestRule]` subject matches a real class (a trait-only namespace subject, the manifested vacuous-rule bug, reds); static, fails closed |
| `bin/support/safe-report-value.php` | `require`d by the PHP gates that echo a value read out of a repository file (`grep -rln safe-report-value.php bin tests`); the node gate carries its own `encodeValue()`, which cannot require a PHP file | scrubs C0/DEL and breaks the legacy `##[` workflow-command prefix on any such value, and caps it at 64 bytes — the `bin/` gates run in the consumer's CI over pull-request content and this repository's own gate over its own, and the runner scans both STDERR and STDOUT for workflow commands; NOT a `bin` entry point, so it needs no `"bin"` row in composer.json but must stay inside the dist archive |

**Layout rule:** the directory states the consumption mode — a tool-named directory
(`php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/`) holds an **importable**
config; `templates/` holds **copy-and-adapt** files whose tools require the file at the
consumer's repo root and therefore cannot be imported; the repository root holds only
this package's **own** dev config, all of it `export-ignore`d — except `/package.json`, which a `github:` consumer must receive (see the header of `templates/gitattributes`). Put a new config in the
directory that matches how it is consumed, never at the root for convenience.

## How it is consumed

- **PHP:** `composer require --dev magicsunday/coding-standard` (Packagist). The
  importable configs are `require`d / `includes:`d from `vendor/`; the templates are
  copied and adapted, with a lockstep check keeping them from drifting. The package
  `require` delivers the entire PHP toolchain transitively — php-cs-fixer, PHPStan +
  rule packs, Rector, phplint, phpat **and PHPUnit** (`^12.0 || ^13.0`) — so a
  **base-tier** consumer's `require-dev` is just this one entry; the PHPUnit
  constraint is pinned here and bumped once for every repository, never per-repo.
  The opt-in strict PHPStan tier and Infection are the exception: they pull the
  `suggest`ed shipmonk/symplify/spaze/infection packs, added directly by the adopting
  repository.
- **JS/TS:** a GitHub **git dependency** — `github:magicsunday/coding-standard#<tag>`
  (never published to the npm registry, like `webtrees-chart-lib`). `biome.json` and
  `tsconfig.json` `extends` the shared files from `node_modules`. **The npm side is
  deliberately not the mirror image of the Composer side:** `package.json` ships the
  two configs and nothing else, so it does NOT deliver the toolchain the way `require`
  does for PHP — each consumer installs `@biomejs/biome` and `typescript` itself.
  **Node tool versions track the current major — always pin forward.** The peer
  ranges never span a major CI does not exercise — read them rather than trusting a
  copy here (`jq -r '.peerDependencies' package.json`), and note that
  `tests/check-js-configs.sh` asserts each against the exact pin it installs; moving one
  means bumping the exact root `devDependencies` pin first, letting
  `tests/check-js-configs.sh` vet it, and only then widening the range. The Node floor
  is **node >= 24**, declared in `devEngines` and NOT in `engines` — `engines` is
  consumer-facing and this package ships no code that runs on Node, so a floor there
  would fail a consumer's install over a constraint the artifact never exercises. CI
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
  single value: `min` = floor, `max` = ceiling, so PHPStan checks the whole
  supported span (a single value only analyses at the floor and misses a
  higher-version deprecation). A single-PHP repository keeps the single value.
- **The template lockstep gate rolls out script-first, workflow-step-last.** The
  reusable `php-quality` workflow runs a FIXED list of `composer ci:test:php:*` steps,
  so adding a `Templates` step that runs `ci:test:php:templates` reds EVERY consumer
  that lacks that script — not just the chart modules but every PHP repo on the shared
  workflow. So the order is: (1) ship `bin/check-consumer-config.php` here, (2) add the
  `ci:test:php:templates` script to every consumer and align its template copies to the
  canon, (3) only then add the step to the reusable workflow. Never add the workflow
  step before all consumers carry the script.
- **The JS/TS lockstep checks do not reach a repository without Composer.** They live
  in `bin/check-consumer-config.php`, a Composer-installed entry point a consumer runs
  from `ci:test:php:templates` — so they only ever see repositories that consume the
  PHP side too. The pure-JS repositories have no `composer.json` and are therefore
  unreachable. Re-derive which they are rather than trusting a list here:

  ```
  repos="$(gh repo list magicsunday --limit 100 --no-archived --json name --jq '.[].name')" \
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
  forbids eighty lines further down (*a gate that aborts must not read as a gate that
  passed*), so the recipe has to carry the third state itself.

  All three spellings, because the gate covers all three and a `biome.json`-only
  probe halves the answer. The per-file probe below has the same blind spot in the
  small: `|| continue` treats a 403 or a rate limit exactly like a 404, so a
  throttled repository drops out of the answer without a word. A repository
  appearing here after an unexplained `gh` error is worth
  re-running before acting on.

  Do not read a green run as "every consumer's JS config is checked"; a node-side
  entry point for those repositories is #32.
- **A gate over a shared link keys on ADOPTION, never on the file being present.**
  The JS/TS half of the lockstep gate asserts that `biome.json`/`tsconfig.json` extend
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

  The stated exceptions, all in `ci.yml` because they cannot be a single command:
  `composer validate --strict`, and the three consumer-smoke steps
  (`phpstan`, `php-cs-fixer`, `rector`), which run under
  `working-directory: tests/consumer`. Enumerating both manifests therefore does NOT
  give full local coverage — a sweep that assumes it will report the smoke as run when
  it was not.
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
`gh api repos/magicsunday/coding-standard/branches/main/protection --jq '.required_status_checks.contexts[]?'`.
Register the context AFTER the job has reported once on the default branch: the
other order leaves every open PR permanently `BLOCKED` with all checks green,
waiting on a context no workflow on `main` can emit. A branch-protection setting is invisible in git,
so it is recorded here: without it the smoke cannot block a merge, and the npm
Dependabot group auto-merges patch and minor bumps of the very tools it smokes, so a
Biome release lands with the gate red and unread. Adding a matrix dimension to that
job later would rewrite the context and desync the setting silently, the same way a
`Build (8.x)` leg does. Community
health files (`SECURITY.md`, `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`) are inherited
from `magicsunday/.github`.

## License

MIT — tooling/config, deliberately permissive so both the GPL webtrees modules and
the MIT standalone libraries can consume it without friction.
