# magicsunday/coding-standard

Shared coding-standard, static-analysis, test and CI configuration for the
`magicsunday/*` projects. One source of truth for the PHP and JS/TS toolchain so
the individual repositories stop carrying near-identical config copies that drift.

The PHP configs are consumed through **Composer** (Packagist). The Biome/TypeScript
configs are consumed as a **GitHub git dependency** — the package is never published
to the npm registry, exactly like `webtrees-chart-lib`.

## Installation

```shell
composer require --dev magicsunday/coding-standard
```

This single dev dependency pulls in the whole PHP toolchain transitively —
php-cs-fixer, PHPStan and its rule packs, Rector, phplint **and PHPUnit**
(`^12.0 || ^13.0`). A consumer on the **base** tier therefore declares nothing
else in `require-dev`; the runner and every analysis tool are version-pinned
here, in one place, and bumped once for all repositories. The opt-in strict
PHPStan tier (`phpstan/strict.neon`) and Infection are the exception — they need
the extra packages listed under `suggest`, added directly by the repositories
that adopt them.

For the JS/TS configs, add a GitHub git dependency (no npm-registry account needed —
the same mechanism `webtrees-chart-lib` uses):

```shell
npm install --save-dev github:magicsunday/coding-standard#1.8.0
```

which records in `package.json`:

```json
{
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.8.0"
    }
}
```

**The npm side is not the mirror image of the Composer side.** The Composer package
delivers the whole PHP toolchain transitively; the npm package ships the two shared
config directories with no tooling of their own, so it installs no `biome`/
`typescript` tooling — each consumer adds those itself (`bin/check-js-config.mjs`
is a deliberate, separate exception, further down):

```shell
npm install --save-dev @biomejs/biome@^2.5.0 typescript@^7.0.2
```

The versions the shared configs are proven against are declared as **optional**
`peerDependencies` — `@biomejs/biome ^2.5.0` and `typescript ^7.0.2`. Optional, because
a repository adopting only the Biome config should not be warned about a missing
TypeScript, and vice versa; npm still validates the range of whichever one is
installed.

**The ranges track the current major, they are not a compatibility promise.** A tool
release is adopted here and the floor moves up with it, rather than accumulating old
majors a green CI never exercises — so a consumer on an older Biome or TypeScript
updates its tools together with this package, not independently of it. That is the
same bargain as the PHP side, where the toolchain versions are pinned here once for
every repository; only the mechanism differs, because npm cannot deliver the tools.

The root `devDependencies` pin the exact versions CI proves (`@biomejs/biome 2.5.9`,
`typescript 7.0.2`, `jscpd 5.0.16`) and are what Dependabot tracks — `peerDependencies` are not parsed
by Dependabot's npm ecosystem (verified 2026-07-28), so the pins are the moving part and the ranges are
widened by hand once a bump is green.

`devEngines` declares **Node >= 24**, the house floor. It is deliberately higher than
what the tools themselves demand — derive them rather than trusting these numbers:
`node -p "require('@biomejs/biome/package.json').engines.node"` and the same for
`typescript` (14.21.3 and 16.20.0 as of 2026-08-26): those floors are years behind the maintained release lines, so meeting
them says nothing about a repository being current.

`devEngines` and `engines` point at two different audiences and do not move
together. `devEngines` (**Node >= 24**) constrains this repository's own
development/CI toolchain — the floor is real here, but npm cannot rely on it
alone: it is honoured only by npm >= 10.9, which with `onFail: "error"`
hard-fails the `install`, `ci` or `run` it precedes, and ignored entirely by
older versions (npm/cli PR 7766, shipped in v10.9.0 — re-derive with
`curl -s https://api.github.com/repos/npm/cli/releases/tags/v10.9.0 | grep -c 4d57928`).
`tests/check-js-configs.sh` is the backstop: it fails outright on an older
Node, and the CI job pins `node-version: 24` rather than the floating `lts/*`
alias, which would move up a major on its own every October.

`engines` (**Node >= 20**) is the separate, consumer-facing constraint added
for `bin/check-js-config.mjs`: npm evaluates it on every install of this
package and prints `EBADENGINE` in the *consumer's* log. Unlike the
importable `biome/`/`tsconfig/` JSON, that script is real code that runs on a
consumer's Node — why >=20 specifically, and exactly when `EBADENGINE` is a
hard failure rather than a warning, are both on the `sourceContainsLoneSurrogate`
docblock in `bin/check-js-config.mjs`, not restated here. A consumer below
that floor gets an uncaught crash instead of a clean
gate report if nothing declares the requirement. `tests/check-js-configs.sh`
enforces this floor too, independently of the devEngines one: it rejects a
`package.json` whose `engines.node` is anything other than a single, literal
`>=X[.Y[.Z]]` lower bound at or above what the shipped script needs — absent,
unparseable, too low, and any shape it does not fully evaluate (an OR-range,
a caret/tilde/`.x` range, a bare version, `*`) all reject, because a shape it
cannot verify could state a floor npm actually reads as looser than it looks
— the OR-range example and its semver verification are on the check itself
in `tests/check-js-configs.sh`, not restated here. Bumping `devEngines` to
track a newer toolchain does not raise
`engines`, and the reverse — the two are reasoned about, and checked,
separately.

## Layout

The directory a file lives in states how it is meant to be consumed:

| Location | Kind | How a consumer uses it |
|---|---|---|
| `php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/` | **importable** | referenced straight out of the Composer vendor directory or `node_modules/` — `includes:`, `require`, `extends` |
| `templates/` | **copy-and-adapt** | copied into the consumer's own repository; these formats (PHPUnit, phplint, Infection, jscpd, editorconfig) cannot be imported, their tools expect the file at the repo root |
| repository root | **this package's own dev config** | `.phplint.yml`, `.github/`, `tests/`, `phpunit.xml.dist` (`composer ci:test:phpunit`, GH-77) — all `export-ignore`d, so a consumer never receives them. `package.json` is the exception and stays in the archive: a `github:` dependency is served from it. The package lints itself with its own template. |

Every include path below is written as `.build/vendor/…`, the house layout: the
`magicsunday/*` repositories set `config.vendor-dir` to `.build/vendor` and
`config.bin-dir` to `.build/bin`, so that generated dependencies sit with every other
build artefact instead of in a second top-level directory. This package and its CI
fixture use the same layout. **The prefix is the consumer's own `vendor-dir`** — a
repository left on Composer's default substitutes `vendor/` throughout. Nothing in the
shipped configs depends on the choice; `base.neon`'s relative includes resolve from the
package's own position either way.

## PHP configs

### php-cs-fixer — `php-cs-fixer/base.php`

A factory that returns a configured `PhpCsFixer\Config`; the consumer supplies its
own file header and finder.

```php
// .php-cs-fixer.dist.php
$factory = require __DIR__ . '/.build/vendor/magicsunday/coding-standard/php-cs-fixer/base.php';

return $factory(<<<EOF
    This file is part of the package magicsunday/<repo>.

    For the full copyright and license information, please read the
    LICENSE file that was distributed with this source code.
    EOF)
    ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude(['.build', 'node_modules'])
            ->in([__DIR__ . '/src/', __DIR__ . '/tests/'])
    );
```

A repository that lints PHTML views appends `->name('*.php')->name('*.phtml')` to
its finder.

### PHPStan — `phpstan/base.neon`, `phpstan/strict.neon`

`base.neon` sets `level: max`, `treatPhpDocTypesAsCertain: false`, and pulls in the
rule extensions (phpstan-strict-rules, deprecation-rules, phpstan-phpunit)
through explicit relative `includes`. That is deliberate: `phpstan/extension-installer`
does not reach Rector's bundled PHPStan, so a base relying on it for its rule packs
loses them silently there instead of failing — the opt-in `disallowed-calls.neon`
sets an extension-owned parameter (`disallowedFunctionCalls`) and would instead fail
loudly with an unknown-parameter error, since that parameter has no meaning without
its own explicit `includes`.

```neon
# phpstan.neon
includes:
    - .build/vendor/magicsunday/coding-standard/phpstan/base.neon

parameters:
    phpVersion:
        min: 80300
        max: 80500
    paths:
        - src
        - tests
```

State `phpVersion` as a **`min`/`max` range** whenever the repository supports a
span of PHP versions: set `min` to *that repository's own* supported floor and
`max` to its ceiling. The `80300`/`80500` above are only an example (the chart
modules' `8.3 - 8.5` support window); each repository substitutes its own
bounds. A repository pinned to a single PHP version — and only then — keeps
the scalar form.

A range does **not** widen the feature/deprecation rules themselves: PHPStan
resolves `min`/`max` down to the floor and hands that single value to the
rules that flag a feature newer than it or a symbol deprecated as of it, so
`min: 80300, max: 80500` reports the same set as a scalar `phpVersion: 80300`
for those rules — a deprecation introduced above the floor is missed either
way (re-derive: `grep -a -B4 -A2 "phpVersion\['min'\]"
.build/vendor/phpstan/phpstan/phpstan.phar`). What the range does change is the
version-conditioned surface — `PHP_VERSION_ID`-style constants and
`version_compare()` become ranges instead of fixed values, each through its
own consumer of the configured range (re-derive: `grep -a -n --
"->getVersionRange()" .build/vendor/phpstan/phpstan/phpstan.phar`, which hits
both the constant resolver and the `version_compare()` extension) — so an
always-true/false finding on a version-conditioned branch is reported only
where it holds across the whole span, instead of being asserted from the floor
alone. That is the reason to prefer the range on a multi-version repository;
it does not extend deprecation coverage to the ceiling.

A real deprecation introduced AT OR BELOW the floor is already caught
statically by the deprecation rule described above (it flags a symbol marked
`@deprecated`), whether or not a test executes the call. One introduced ABOVE
the floor is invisible to that rule, but is covered separately whenever a
test actually executes it — `templates/phpunit.xml.dist` sets
`failOnDeprecation="true"` and `bin/check-consumer-config.php` requires it
(re-derive: `grep -n "failOnDeprecation" templates/phpunit.xml.dist
bin/check-consumer-config.php`), so such a call fails the build regardless of
what the PHPStan pin targets — provided the CI matrix runs an interpreter new
enough to trigger it. A deprecation introduced above the floor, in code no
test executes, is missed by both mechanisms.

`chr()` illustrates a DIFFERENT, narrower gap that the pin — at any
`phpVersion` value — never closes. PHP 8.5.0 deprecates passing it an integer
outside 0-255, and PHPStan's stub signature encodes exactly that by narrowing
its `ascii` PARAMETER to `int<0, 255>` in the 8.5 stubs, plain `int` below it
— the return type stays `non-empty-string` at both (re-derive, which prints
the `new`/8.5 entry under its `'new'` label and the `old`/pre-8.5 entry under
`'old'`: `grep -a -B2 "'chr' => \['non-empty-string', 'ascii'=>'int"
.build/vendor/phpstan/phpstan/phpstan.phar`). This is an argument-TYPE check,
not the deprecation rule above: `phpstan-deprecation-rules` only flags a
whole symbol marked `@deprecated`, never an argument-value narrowing (its
`RestrictedDeprecatedFunctionUsageExtension` tests
`FunctionReflection::isDeprecated()`, which `chr()` itself never satisfies).
So the pin can flag a `chr()` call whose argument is provably in range by
construction — e.g. `hexdec()` over a regex-guaranteed two-hex-digit capture,
which can only produce 0-255 — even though the PHP 8.5 deprecation can never
actually fire for it: a static-only false positive, not a real defect.

### The two tiers

`base.neon` is the **floor** — every repository runs it, no exceptions.

`strict.neon` (which includes `base.neon`) is the **target** — the tier every
repository is expected to reach, not a permanent alternative. It adds the
shipmonk/symplify rule packs, the case-folding bans from `disallowed-calls.neon`,
and the extra-strict report parameters. The reason it
is staged rather than folded into the base is cost, not preference: turning it on
surfaces real findings that need triaging per repository, so forcing it into the
base would block every adoption on an unrelated backlog.

To keep that staging from becoming drift, **a repository that runs only `base.neon`
carries an open issue for reaching `strict.neon`**. The gap stays visible and
terminated instead of quietly permanent.

```shell
composer require --dev shipmonk/phpstan-rules symplify/phpstan-rules spaze/phpstan-disallowed-calls
```

Adopt via the `adopt-strict-phpstan-ruleset` workflow, triaging each finding.

### Case folding — `phpstan/disallowed-calls.neon`

`strtoupper()`, `strtolower()`, `ucfirst()`, `lcfirst()` and `ucwords()` fold ASCII A–Z only and
leave every multi-byte character untouched, so on UTF-8 text they return a
half-folded string:

```php
strtolower('GEBÜRTIGE')       // 'gebÜrtige' — never matches 'gebürtige'
strtoupper('über')            // 'üBER'
ucfirst('über')               // 'über' — silently does nothing
ucwords("anna\u{00A0}maria")  // "Anna\u{00A0}maria" — no split, "maria" stays lower-case
```

The damage is a fold-then-compare lookup that quietly stops matching, or a
"capitalise the first letter" that is a no-op — and a genealogy domain is full of
the names and places this hits. No other gate here catches it: phpstan-strict-rules,
php-cs-fixer and rector all pass it through.

**This is not a locale problem.** PHP 8.2 made these functions locale-independent,
so the historical Turkish dotted-I bug (`strtoupper('i')` not yielding `'I'` under
`tr_TR`) cannot occur on the `8.3 - 8.5` floor — verified by folding under an active
`tr_TR.UTF-8` locale on 8.1, 8.3 and 8.5: only 8.1 still leaves `'i'` unfolded. The
multi-byte behaviour above is what remains, and it is version-independent.

This file bans the five calls. It is included by `strict.neon`, so a repository
reaching that tier gets it automatically, and it can also be included on its own by
a repository that wants the gate earlier:

```neon
# phpstan.neon
includes:
    - .build/vendor/magicsunday/coding-standard/phpstan/base.neon
    - .build/vendor/magicsunday/coding-standard/phpstan/disallowed-calls.neon
```

The replacement depends on what the call is for. Matching a tag, enum case or
keyword should compare case-insensitively or map explicitly, rather than fold at
all; folding whole text uses `mb_strtoupper()` / `mb_strtolower()` /
`mb_convert_case()` with an explicit `'UTF-8'` encoding argument. Folding only the
*first* character has no direct replacement below PHP 8.4 (`mb_convert_case()` has no
such mode, `mb_ucfirst()` needs 8.4), so it is spelled out:
`mb_strtoupper(mb_substr($v, 0, 1), 'UTF-8') . mb_substr($v, 1, null, 'UTF-8')`.

`mb_convert_case()` with `MB_CASE_TITLE` is **not** a drop-in for `ucwords()`, which is
why the ban's message qualifies it. `ucwords()` only touches each word's first
character and leaves the rest alone; `MB_CASE_TITLE` normalises the whole word and
also treats `-` as a separator:

```php
ucwords('McDONALD anna-maria')                          // 'McDONALD Anna-maria'
mb_convert_case('McDONALD anna-maria', MB_CASE_TITLE)   // 'Mcdonald Anna-Maria'
```

For a display-name normalisation that is usually the better result. For input whose
interior capitals must survive — an acronym, a `McDONALD`-style name kept as entered —
it silently rewrites the data, so upper-case each word initial explicitly instead.

Not every hit is a defect — a fold on known-ASCII input (a hex digest, a
`strtolower()` on an already-validated enum value) is harmless, and the rule cannot
tell the two apart. Re-allow such a site deliberately with `allowIn`, which takes
`fnmatch()` patterns resolved against the working directory:

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'strtoupper()'
            message: 'it is byte-wise'
            allowIn:
                - src/Formatter/LabelFormatter.php
```

Such an entry **replaces** the shipped one rather than merging into it, so an
override restates the `message` it wants to keep. When PHPStan does not run from
the repository root, set `filesRootDir` so the `allowIn` paths still resolve.

### Rector — `rector/base.php`

The factory takes the target PHP floor as its second argument and both sets it on
the config and applies the matching version level set (`80300` → `UP_TO_PHP_83`,
… `80600` → `UP_TO_PHP_86`), so a repository above 8.3 gets that version's
modernizations rather than being pinned to 8.3. State the floor once — the
consumer no longer calls `phpVersion()` itself.

```php
// rector.php
use Rector\Config\RectorConfig;

return static function (RectorConfig $config): void {
    $config->paths([__DIR__ . '/src/', __DIR__ . '/tests/']);
    $config->phpstanConfig(__DIR__ . '/phpstan.neon');

    (require __DIR__ . '/.build/vendor/magicsunday/coding-standard/rector/base.php')($config, 80300);
};
```

### Backward-compatibility check — `roave/backward-compatibility-check`

A public-API break — most often a new constructor parameter inserted *before* the
existing ones instead of appended, which breaks every positional caller — is the one
defect class none of the gates here catch. PHPStan analyses a single revision, so it
has nothing to compare against. `roave/backward-compatibility-check` diffs the public
API against the last tag and reports the break mechanically.

**Never `composer require --dev` it into the root manifest.** The tool requires
`php: ~8.4.0 || ~8.5.0` from 8.20.0 on, and a root `require` writes it into the root
`composer.lock`. Every *other* job of the same matrix then runs `composer install`
against that lock and aborts on the 8.3 leg with *"Your lock file does not contain a
compatible set of packages"* — verified: a `^8.3` library with the tool required at the
root fails `composer install` with exit 2 under PHP 8.3. That hits exactly the
repositories this section is for, the ones with a `^8.3` floor and an 8.3/8.4/8.5
matrix. A single-leg job does not help, because the poisoned lock is shared.

Give the tool **its own manifest** instead, so it never enters the root resolution
(it resolves the analysed project's dependencies internally and does not share the
root `vendor/`) — `tools/backward-compatibility/composer.json`:

```json
{
    "require": {
        "roave/backward-compatibility-check": "^8.21"
    },
    "config": {
        "bin-dir": ".build/bin",
        "vendor-dir": ".build/vendor"
    }
}
```

The `bin-dir`/`vendor-dir` overrides keep this in step with the house layout the
modules use, so the tool's own dependencies land under `.build/` like every other
generated artefact rather than in a second top-level `vendor/`.

**Then wire it as a single-leg CI job, never a matrix job.** The check compares API
signatures against the previous tag and is runtime-independent, so running it once per
PHP version buys nothing — and a matrix job would have to pin `8.19.*` to stay
installable on 8.3. Even pinned that does not hold: `roave/better-reflection` then
resolves to 6.69.0 on 8.3 but 6.71.0 on 8.4/8.5 (6.70+ require `~8.4.1 || ~8.5.0`), so
a lock written on 8.5 is not installable on 8.3. One 8.4 or 8.5 job avoids all of it
and tracks the current release:

```yaml
    backward-compatibility:
        name: Backward compatibility
        runs-on: ubuntu-latest

        permissions:
            contents: read

        steps:
            - uses: actions/checkout@9c091bb21b7c1c1d1991bb908d89e4e9dddfe3e0 # v7.0.0
              with:
                  fetch-depth: 0
                  persist-credentials: false

            - uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2
              with:
                  php-version: '8.4'
                  extensions: intl
                  tools: composer:v2

            - run: composer install --prefer-dist --no-interaction --no-progress --working-dir=tools/backward-compatibility
            - run: tools/backward-compatibility/.build/bin/roave-backward-compatibility-check
```

Three details are easy to miss:

- **`ext-intl`** is required transitively (via `php-standard-library/date-time` and
  `/locale`) — resolved and confirmed against the 8.21 tree. `ext-bcmath` is *not*:
  it came from `azjezz/psl`, which the 8.20+ releases no longer depend on. Declaring
  `intl` is belt-and-braces, since setup-php already installs it by default, but it
  documents a hard requirement.
- **`fetch-depth: 0`** plus tags, or there is no previous release to compare against.
- **At least one tag must exist.** A library adopting the job before its first release
  gets an uncaught `Could not detect any released versions for the given repository`,
  which reads like a tool bug rather than a missing precondition. Adopt the job with,
  or after, the first tag.

**Adding the job does not make it gate.** A new job's status context is not
automatically a required status check, so a detected break reports red and the PR
still merges. Register it in the same change:

```shell
# Read the existing entries and append the new one in the same step. `checks` REPLACES
# the whole list, so an entry left out is silently un-required — and each entry's
# `app_id` pins WHICH integration may satisfy that check, so rebuilding entries from
# their `context` alone would quietly widen them to "any app". Passing the returned
# objects through verbatim avoids both. `strict` is optional and stays untouched when
# the body does not mention it.
gh api "repos/<owner>/<repo>/branches/main/protection/required_status_checks" \
    --jq '{checks: (.checks + [{context: "Backward compatibility"}])}' > checks.json

gh api -X PATCH "repos/<owner>/<repo>/branches/main/protection/required_status_checks" \
    --input checks.json
```

Note the interaction with the house rule on first-party libraries: where every consumer
of a library is one of our own repositories, an obsolete API is removed outright rather
than deprecated. The check reports that removal as a break — which is the point. It
turns "did anyone think about the major bump?" into an answer the build gives you.

### Deptrac — architecture layers — `deptrac/layers.yaml`

The canonical layered architecture every module is expected to follow, enforced
by [Deptrac](https://github.com/deptrac/deptrac) (pulled in by this package's
`require`, so a consumer declares nothing extra). One shared ruleset lives here;
each consumer copies `templates/deptrac.dist.yaml` to `deptrac.yaml`, which
`imports` this file and only declares its own `paths`.

Layers are matched by **namespace segment** through a `directory` collector
(`.*/Repository/.*`, …), not by the repository root, so the same ruleset ports
across every module **without renaming anything** — a class under `<any>/Repository/`
lands in the `Repository` layer wherever the module lives. A `directory` collector
matches only the analysed `src` files, so a referenced third-party class (which
Deptrac never analyses and has no path for) falls to *uncovered* naturally — the
reason the collector is path-based and not a `classNameRegex`, which would also
match vendor FQCNs carrying a canonical segment (`Illuminate\Support\…`) and file
them into a layer. The canonical layers are `Enum`, `Model`, `Contract`,
`Configuration`, `Support`, `Repository`, `Adapter`, `Service`, `Facade` and `Module`.

```yaml
# deptrac.yaml
imports:
    - .build/vendor/magicsunday/coding-standard/deptrac/layers.yaml

deptrac:
    paths:
        - src
```

Wire it as a consumer `ci:test:php:deptrac` script
(`["deptrac analyse --no-progress"]`), rolled out the same script-first way. The
ruleset is deliberately permissive at this stage — it forbids only the
uncontroversial upward edges (a leaf depending on a higher layer, anything
depending on the composition root), and keeps the domain core (`Enum`/`Model`/
`Contract`/`Configuration`) mutually permissive to avoid a false `Model`↔`Contract`
cycle. Tighten individual edges per module only after a `deptrac analyse` dry-run
proves the stricter edge is violation-free. Dependencies on classes outside every
layer (the framework, webtrees core) are reported as "uncovered" but do not fail
the run; `--fail-on-uncovered` is left off because every external dependency is
uncovered.

This supersedes the older per-repo phpat layer rules. phpat and its
subject-liveness guard have been removed from this package; a consumer still
carrying phpat rules migrates the layer-dependency ones to Deptrac and re-homes
the `Abstract*`/`final` structural rules itself — as a PHPStan rule, or a PHPUnit
test **outside** `tests/Architecture/`, which the shipped `phpunit.xml.dist`
template excludes from the suite unconditionally. Deptrac cannot express either
structural rule itself: its collectors model `classLike`/`class`/`interface`/
`trait` and have no notion of a class modifier.

## Templates (copy-and-adapt)

Files under `templates/` are not importable — copy them into the consumer and
adjust the paths. The `check-consumer-config.php` lockstep gate below keeps them
from drifting from this package.

| Template | Copy to | Notes |
|---|---|---|
| `templates/phpunit.xml.dist` | `phpunit.xml.dist` | strict flag set incl. `requireCoverageMetadata`; PHPUnit itself is provided by the package `require`, so it stays out of the consumer's `require-dev` |
| `templates/infection.json5` | `infection.json5` | `timeoutsAsEscaped: true`; set the MSI floor per repo |
| `templates/editorconfig` | `.editorconfig` | 4-space, tab for Makefiles |
| `templates/gitattributes` | `.gitattributes` | `export-ignore` dist hygiene. Registry npm ignores it and goes by `files` in `package.json` — but a `github:` git dependency does NOT: pacote fetches GitHub's codeload archive, which has `export-ignore` applied, so anything removed here is removed from what such a consumer receives |
| `templates/phplint.yml` | `.phplint.yml` | the `ci:test:php:lint` gate the reusable workflow invokes — path-driven, never a hand-kept file list |
| `templates/jscpd.json` | `.jscpd.json` | zero-tolerance copy-paste gate, PHP **and** JS/TS — use jscpd's format names (`php`, `javascript`, `typescript`, `jsx`, `tsx`), never the extensions `js`/`ts`: an unknown name is not an error, it silently scans nothing. The lockstep gate rejects the extension spellings for that reason |
| `templates/deptrac.dist.yaml` | `deptrac.yaml` | `imports` the shared `deptrac/layers.yaml` + declares `paths`; see the Deptrac section above |

### Lockstep gate — `bin/check-consumer-config.php`

The importable configs are consumed by reference, so their rule content cannot
drift. The copy-and-adapt templates have no include-from-vendor mechanism, so each
consumer keeps a physical copy — and that copy is where the house standard silently
drifts loose (a `phpunit.xml` that quietly drops `requireCoverageMetadata`, a jscpd
config left on the removed v4 reporter name). This gate asserts the **stable region**
of each copy — the strict flags and the uniform `src`/`tests` layout every module
shares — while ignoring the genuinely per-repo parts (the vendor-dir-dependent path
prefixes, the per-repo `format`/`path`/`ignore` lists). It is assertion-based, not a
byte-diff, so a consumer that legitimately scans an extra JS directory is not flagged,
but a loosened strictness flag is.

The package `require` places it on the consumer's bin path, so wire it as a
`ci:test:php:templates` script (vendor-dir-independent) in the consumer's
`composer.json`:

```json
"scripts": {
    "ci:test:php:templates": ["check-consumer-config.php ."]
}
```

Add that step to the reusable `php-quality` workflow so it gates in CI (see AGENTS —
every consumer needs the script before the shared step is added, or the step reds the
repos that lack it). A missing optional file (a PHP-only repo has no `.jscpd.json`) is
skipped; the strict PHPUnit config is required — the gate accepts it as either
`phpunit.xml` or `phpunit.xml.dist`.

The gate also covers `biome.json` (or `biome.jsonc`) and `tsconfig.json`, on a
narrower contract, and only for a repository that **declares the npm dependency**
(`@magicsunday/coding-standard` in `dependencies` / `devDependencies` /
`optionalDependencies` / `peerDependencies`). That gate on adoption is not politeness: a consumer cannot pin
an npm tag before the tag exists, so a check that demanded the link the moment the file
existed would red every repository that ships a `biome.json` today — on the very update
that first delivers the check, for a link they never claimed to have. Align first,
enforce second, exactly as the template gate itself was staged.

Four reports do **not** wait for adoption, each because it names a defect on the
file's own terms rather than a missing link:

- a `"//"` key in the Biome config — it makes the file unloadable for Biome whether or
  not it extends anything, so a repository writing its own config is just as broken by it;
- a `biome.json`/`biome.jsonc` or `tsconfig.json` that **cannot be opened** — no reader
  tolerance is in play there, the file simply is not readable, and that is true whoever
  wrote it;
- a `package.json` that cannot be read or does not parse, **in a repository that has a
  `biome.json` or `tsconfig.json` at all** — that file *is* the adoption probe, so
  treating a broken one as "has not adopted" would switch the whole JS/TS contract off
  precisely when the repository's own tooling is in an unknown state;
- a `biome.json`/`biome.jsonc` or `tsconfig.json` past the size this gate reads — the
  file is not scanned at all, so nothing downstream of it was checked, and that is
  true whoever wrote it. A repository with
  neither config is not probed for the JS/TS contract in the first place, so nothing is
  reported there.

A JSON(C) **parse** failure of a Biome or TypeScript config, by contrast, is gated on
adoption: this reader is not Biome's, it can reject a file the real tool accepts, and
reporting that to a repository which never claimed the link is the failure mode the
adoption gate exists to prevent.

Once the dependency is declared, the files are treated as one-line `extends` stubs, so
their rule content genuinely cannot drift — the **link** can. What is asserted, with
`bin/check-consumer-config.php` as the list rather than this paragraph: the
shared config is actually extended (a look-alike package name does not count), none of
`linter`, `formatter` and `assist` is switched off — Biome offers those
toggles in three nested
places and they combine: the document, every `overrides` entry, and a per-language block
inside either of those, so `javascript.linter.enabled: false` silences the shared
standard for every JS/TS file while the top-level key still reads `true`; `files.includes`
carries at least one positive pattern, since an all-negative list checks nothing while
every `enabled` still reads `true`; the
strict flags are not overridden back to `false` underneath the `extends` link
(the nine options `strict` switches on as a group — TypeScript treats a specific one
written back as an override of the umbrella, so pinning only `strict` pins nothing —
plus `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `noImplicitOverride`,
`forceConsistentCasingInFileNames` and `isolatedModules`, which the shared base sets
itself; `$pinnedFlags` in `bin/check-consumer-config.php` is the list),
`biome.json` carries no `"//"` key — Biome rejects unknown keys and refuses the whole
config, so that one key makes a file that is valid JSON completely unloadable — and
the recommended rule floor is still on. That last one is checked everywhere Biome
offers it, which is more places than it first appears: two spellings (the
`recommended` boolean deprecated in 2.5, and `preset`), on `linter.rules` **and on
every rule group beneath it**, and again inside **every `overrides` entry**. Each
combination reaches the same end: `linter.rules.suspicious.preset: "none"` lets a
`debugger` statement through while every top-level key still reads as it should. A
narrower check would close the front door and leave those open. Legitimate
`overrides` use — relaxing a single rule for one path — stays untouched.
Ergonomics flags stay free: turning `skipLibCheck` off is stricter, not drift, and
`module`/`target`/`lib`/`jsx`/`paths` are per-repository by design. Both files are
parsed as JSONC, because `tsconfig.json` is JSONC by specification — comments and
trailing commas are accepted, and a `//` inside a string value is not mistaken for
one.

**What it does not do, stated because the list above reads as if it did.** The gate
inspects the consumer's own config file for explicit off-switches; it does not
compute the configuration the tool ends up with. Two consequences, both measured
against Biome 2.5.5 rather than reasoned about:

- An `extends` list may name a further **local** file after the shared one, and that
  file wins. `["@magicsunday/coding-standard/biome/base.json", "./biome.loose.json"]`
  with `{"linter": {"enabled": false}}` in the second lets `a == b` through while the
  gate reports OK. The same holds for `tsconfig.json`, where a later entry setting
  `noUncheckedIndexedAccess: false` survives into `tsc --showConfig`.
- A single rule may be switched off by name — `"noDoubleEquals": "off"` — which the
  gate does not look at. It pins the preset floor and each rule group, not the
  individual rules the base enables.

So this is a **drift detector, not a bypass guard**: it catches a consumer copy that
has fallen out of step, not one that deliberately steers around the standard — and a
repository willing to do the latter can equally drop the gate from its CI. Closing
both would mean resolving the `extends` chain and deriving the rule names from the
shared base; that is tracked in #36 rather than half-done here.

### Node-only front end — `bin/check-js-config.mjs`

`bin/check-consumer-config.php` is a Composer-installed entry point, so it only ever
runs in a repository that consumes the PHP side too — which excludes exactly the
repositories whose whole toolchain **is** the shared Biome/TypeScript setup (a pure-JS
module with no `composer.json`). `bin/check-js-config.mjs` is that same
`biome.json`/`tsconfig.json` contract — the adoption gate, the `"//"` guard, the
`linter`/`formatter`/`assist` walk across the document/`overrides`/per-language scopes,
the `files.includes` no-positive-pattern check, the recommended/preset floor at every
scope, the `extends` link check, and the pinned strict-flag list — run against a path
argument instead, with no PHP or Composer involved. It is a second front end for the
SAME rule, not a second rule: every `biome.json`/`tsconfig.json` case in
`tests/check-consumer-config-cases.sh` also runs this gate against the identical
fixture directory and requires the identical verdict, so the two cannot silently drift
apart the way two independently maintained fixture lists could.

Shipped as an npm `bin` entry, so a consumer wires it as a plain npm script:

```json
"scripts": {
    "ci:test:js:config": "check-js-config ."
}
```

or invokes it directly: `node node_modules/@magicsunday/coding-standard/bin/check-js-config.mjs .`.
Exit code 0 means every present config matches the shared standard, 1 means at least
one drift, 2 means the path argument is not a directory. A repository with neither
`biome.json`/`biome.jsonc` nor `tsconfig.json` is not probed at all, exactly as on the
PHP side.

Everything the "What it does not do" note above says about `bin/check-consumer-config.php`
— a drift detector, not a bypass guard, and blind to a later `extends` entry or an
individually named rule — applies here identically, since it is the same contract.

## Releasing this package

The version this package ships lives in three places: the git tag, `package.json`'s
`version`, and every `github:magicsunday/coding-standard#<tag>` pin written in this
README. Nothing links them, so a release that bumps the manifest and forgets a
README pin documents an install command for a tag that does not exist — and a
consumer following it silently gets the older code.

`composer ci:test:version` re-derives every documented pin from the README and
compares it against `package.json`. A pin that is not a version tag, a pin that
disagrees with the manifest, and a README that documents no pin at all are each a
finding; the last one matters because a gate with nothing to compare would
otherwise pass vacuously. `composer ci:test:version-lockstep` is its fixture-driven
self-test, which drives the gate into each of those states on purpose.

Unlike the consumer gates in this README, this one is not shipped for anyone else to
run — it guards this repository's own release hygiene. Bump `package.json` and every
README pin in the same commit as the tag.

## JS/TS configs

```jsonc
// biome.json
{ "extends": ["@magicsunday/coding-standard/biome/base.json"] }
```

```jsonc
// tsconfig.json
{ "extends": "@magicsunday/coding-standard/tsconfig/base.json" }
```

Lint with `biome ci --error-on-warnings` so every warning is CI-fatal. The TypeScript
base carries no `module`/`target`/`lib`/`jsx` and no `paths`; those are per-repository
and belong in the consumer's own `compilerOptions`.

`useImportExtensions` runs with an `extensionMappings` table (`ts`/`tsx` → `js`,
`mts` → `mjs`, `cts` → `cjs`), so a local ESM import spells the extension `.js` in
TypeScript sources too — which is what TS ESM emits and what `tsc` resolves. Without
it the two tools contradict each other and no spelling satisfies both: Biome demands
`./bar.ts`, which `tsc` then rejects with TS5097 unless `allowImportingTsExtensions`
is on, while the house spelling `./bar.js` is reported as a violation.

The blunter `forceJsExtensions: true` settles the same conflict and was tried first.
It is wrong for a shared base, because it rewrites the suggestion for **every**
extension rather than the TypeScript ones: measured against Biome 2.5.5,
`import "./theme.css"` and `import palette from "./palette.json"` are both reported,
each carrying a *Safe* fix that rewrites the specifier to a `.js` path that does not
exist — so a plain `biome check --write` or an editor save-action silently breaks a
consumer that imports a stylesheet or a JSON asset. `extensionMappings` buys the
TypeScript case and leaves the rest alone. The smoke asserts both directions plus the
asset imports, so neither the rule the base exists to settle nor the regression that
option class invites is left for a consumer to discover.

The base carries **no `vcs` block** on purpose. `useIgnoreFile: true` would look like
the obvious way to keep a consumer's gitignored build output out of the lint run, but
Biome then aborts with `couldn't find an ignore file` in any repository that has none
beside its config — a configuration error rather than a finding, so the whole run
dies. Excluding build output stays a consumer decision, made where the build output is
known.

The Biome base turns the recommended rule set on through `linter.rules.preset`, not
the `recommended` boolean, which Biome's configuration reference marks deprecated in
favour of it. Both spellings still enable the same rules on 2.5, so nothing lints
differently — but the choice is **not** free, and the cost is a version floor rather
than behaviour: `preset` does not exist before Biome 2.5, and Biome refuses a config
carrying an unknown key outright rather than ignoring it. Measured against 2.4.11,
the shared base answers `Found an unknown key 'preset'` and the whole run dies. So a
repository extending this base needs **Biome 2.5 or newer** — the same floor the
`^2.5.0` peer declares, stated here because an optional peer is not consulted when
Biome is installed at a workspace root, run through `npx`, or installed globally.

A consumer overriding either spelling to its off value (`preset: "none"`,
`recommended: false`) is reported by the lockstep gate.

**Do not reach for `biome migrate --write` to make that move.** Measured against
2.5.0 and 2.5.5, it rewrites `linter.rules.recommended` to `preset: "none"` — the
OFF value — and it does so for `true` and `false` alike, discarding the distinction
rather than translating it. A repository that follows the tool's own migration path
therefore ends up with every recommended rule silently disabled. The gate rejects
exactly that, so the failure surfaces as a lockstep violation on a config the
consumer believes it just migrated correctly; the fix is to write
`"preset": "recommended"` by hand.

### The `"//"` note key is decided per tool, not banned

JSON has no comments, so a note is conventionally smuggled in as a `"//"` key. Whether
that works is a property of the reader, and the three readers here disagree — so this
package uses the key in some shipped files and forbids it in others. That looks like a
contradiction until the measurements are written down, so here they are:

| File | `"//"` | Because |
|---|---|---|
| `tsconfig/base.json` | **yes** | `tsc` ignores unknown top-level keys — verified against 7.0.2, the config loads and compiles |
| `templates/jscpd.json` | **yes** | jscpd reads JSON5 and ignores it; the smoke runs the template verbatim, note key and all |
| `biome/base.json` | **no** | Biome's deserializer rejects unknown keys and refuses the WHOLE config |

The gate follows the same split: it reports a `"//"` key in a consumer's
`biome.json`/`biome.jsonc` and says nothing about one in `tsconfig.json`. That check is
one of the few that does not wait for adoption, because the file is unloadable however
it was written.

The Biome case is not hypothetical — this package shipped a `biome/base.json` carrying
one, and it was dead config for every consumer that extended it while `ci:test:json`
reported the file as perfectly valid JSON. That is what the JS smoke exists for.

`tests/check-js-configs.sh` guards this — it packs the package as npm
ships it, installs it into a throwaway consumer, and runs Biome and `tsc` against the
shared configs, with controls proving a `==` comparison and an unchecked array index
are actually rejected. The `js` CI job runs it on every pull request and on every push to `main`.

## License

MIT — see [LICENSE](LICENSE).
