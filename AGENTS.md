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
| `phpstan/strict.neon` | importable | opt-in tier — shipmonk + symplify packs + extra-strict report params |
| `rector/base.php` | importable | applies the shared rule sets/skips to a `RectorConfig`; 2nd arg is the target PHP floor (`80300`–`80600`, or null to keep the caller's) and derives the matching `UP_TO_PHP_8x` set — the `rector/rector: ^2.4` floor guarantees every mapped set exists (`UP_TO_PHP_86` landed in 2.4.0) |
| `templates/*` | copy-and-adapt | `phpunit.xml.dist`, `infection.json5`, `phplint.yml`, `editorconfig`, `gitattributes`, `jscpd.json`, `ArchitectureTest.php` (phpat: `Abstract*` naming + `beFinal`) |
| `biome/base.json`, `tsconfig/base.json` | importable (`extends`) | the JS/TS repos |
| `bin/check-consumer-config.php` | executable (composer `bin`) | the template lockstep gate — asserts each consumer copy's stable region (strict phpunit flags, jscpd/phplint/editorconfig invariants, uniform `src`/`tests`), ignores per-repo paths |

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
  `suggest`ed shipmonk/symplify/infection packs, added directly by the adopting
  repository.
- **JS/TS:** a GitHub **git dependency** — `github:magicsunday/coding-standard#<tag>`
  (never published to the npm registry, like `webtrees-chart-lib`). `biome.json` and
  `tsconfig.json` `extends` the shared files from `node_modules`.

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
- **Indentation is 4 spaces in every file** (YAML, JSON, PHP, neon).
- **README.md and this file ship in the same change** as any layout/config/consumer
  claim they describe.
- **Versioning:** the Composer package is tag-versioned (Packagist); tag `X.Y.Z` and
  keep `package.json`'s `version` in step for the npm git-dependency pin.

## CI and security

The reusable `magicsunday/.github` workflows provide code-scanning, zizmor,
scorecard, dependency-review, yamllint, commit-convention, label-sync and
auto-merge; `.github/dependabot.yml` covers composer + github-actions. Community
health files (`SECURITY.md`, `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`) are inherited
from `magicsunday/.github`.

## License

MIT — tooling/config, deliberately permissive so both the GPL webtrees modules and
the MIT standalone libraries can consume it without friction.
