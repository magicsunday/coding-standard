# AGENTS.md — magicsunday/coding-standard

Single source of truth for the shared PHP and JS/TS tooling configuration of the
`magicsunday/*` projects. This repository ships **configuration only** — no runtime
library code and no test suite. Its correctness is proven by *consumer adoption*
(a repo wires the configs and its own `composer ci:test` stays green), not by tests
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
| `bin/check-consumer-config.php` | executable (composer `bin`) | the template lockstep gate — asserts each consumer copy's stable region (strict phpunit flags, jscpd/phplint/editorconfig invariants, the `deptrac.yaml` shared import, uniform `src`/`tests`), ignores per-repo paths; also covers `biome.json`/`tsconfig.json` on the narrower extends-stub contract (shared `extends` present, strict flags not overridden to false, no `"//"` key in the Biome config), parsed as JSONC |
| `bin/check-phpat-subjects.php` | executable (composer `bin`) | the phpat subject-liveness guard — parses a consumer's ArchitectureTest and asserts every `#[TestRule]` subject matches a real class (a trait-only namespace subject, the manifested vacuous-rule bug, reds); static, fails closed |

**Layout rule:** the directory states the consumption mode — a tool-named directory
(`php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/`) holds an **importable**
config; `templates/` holds **copy-and-adapt** files whose tools require the file at the
consumer's repo root and therefore cannot be imported; the repository root holds only
this package's **own** dev config, all of it `export-ignore`d. Put a new config in the
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
  does for PHP — each consumer installs `@biomejs/biome` and `typescript` itself. The
  versions the shared configs are proven against are declared as **optional**
  `peerDependencies` (`^2.4.11`, `^5.0.0 || ^6.0.0 || ^7.0.0`); optional, because a
  repo adopting only one of the two must not be nagged about the other. **Node tool
  versions track the current major — always pin forward.** The ranges are `^2.5.0` and
  `^7.0.2`, and a new tool release moves the floor up rather than being added next to
  the old major: a range spanning majors that CI never exercises is a promise this
  package cannot keep. The exact versions CI proves live in the root
  `devDependencies`; `tests/check-js-configs.sh` runs against those pins, never
  against `latest`, so a tool release cannot red the build on a day nothing changed
  here. Moving a range means bumping the pin, letting the smoke vet it, and only then
  widening the peer range.

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
  tolerate the key and keep it; Biome's documentation belongs in the README instead.
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
  `composer ci:test:version` asserts all of them agree — bump them in the same commit
  as the tag. The gate fails closed if the README documents no pin at all, so deleting
  the instructions cannot make it pass vacuously.
- **A no-op config is worse than a missing one, and tool names are where they hide.**
  Two shipped configs looked active while enforcing nothing: a `"//"` key made
  `biome/base.json` unloadable, and jscpd's `format` takes FORMAT names, so the
  plausible `"ts"`/`"js"` scan nothing at all and report a clean run. Neither errors —
  both go green. When adding or editing a shipped config, run its real tool against a
  fixture that MUST produce a finding, and only trust the config once that finding
  appears.

## CI and security

The reusable `magicsunday/.github` workflows provide code-scanning, zizmor,
scorecard, dependency-review, yamllint, commit-convention, label-sync and
auto-merge; `.github/dependabot.yml` covers composer + npm + github-actions. The npm
ecosystem tracks the root `devDependencies`, NOT the `peerDependencies` — Dependabot's
npm parser reads `dependencies`, `devDependencies` and `optionalDependencies` only, so
a package.json carrying peer ranges alone would give it nothing to do. The
own `ci.yml` adds a `js` job running the JS consumer smoke, the counterpart to the
PHP consumer smoke. Community
health files (`SECURITY.md`, `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`) are inherited
from `magicsunday/.github`.

## License

MIT — tooling/config, deliberately permissive so both the GPL webtrees modules and
the MIT standalone libraries can consume it without friction.
