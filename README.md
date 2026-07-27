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
php-cs-fixer, PHPStan and its rule packs, Rector, phplint, phpat **and PHPUnit**
(`^12.0 || ^13.0`). A consumer on the **base** tier therefore declares nothing
else in `require-dev`; the runner and every analysis tool are version-pinned
here, in one place, and bumped once for all repositories. The opt-in strict
PHPStan tier (`phpstan/strict.neon`) and Infection are the exception — they need
the extra packages listed under `suggest`, added directly by the repositories
that adopt them.

For the JS/TS configs, add a GitHub git dependency (no npm-registry account needed —
the same mechanism `webtrees-chart-lib` uses):

```shell
npm install --save-dev github:magicsunday/coding-standard#1.6.1
```

which records in `package.json`:

```json
{
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.6.1"
    }
}
```

## Layout

The directory a file lives in states how it is meant to be consumed:

| Location | Kind | How a consumer uses it |
|---|---|---|
| `php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/` | **importable** | referenced straight out of the Composer vendor directory or `node_modules/` — `includes:`, `require`, `extends` |
| `templates/` | **copy-and-adapt** | copied into the consumer's own repository; these formats (PHPUnit, phplint, Infection, jscpd, editorconfig) cannot be imported, their tools expect the file at the repo root |
| repository root | **this package's own dev config** | `.phplint.yml`, `.github/`, `tests/` — all `export-ignore`d, so a consumer never receives them. The package lints itself with its own template. |

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
rule extensions (phpstan-strict-rules, deprecation-rules, phpstan-phpunit, phpat)
through explicit relative `includes`. That is deliberate: `phpstan/extension-installer`
does not reach Rector's bundled PHPStan, so a base relying on it makes `rector.php`'s
`phpstanConfig` fail on an unknown parameter.

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

services:
    -
        class: Vendor\Namespace\Test\Architecture\ArchitectureTest
        tags:
            - phpat.test
```

State `phpVersion` as a **`min`/`max` range** whenever the repository supports a
span of PHP versions: set `min` to *that repository's own* supported floor and
`max` to its ceiling. PHPStan then analyses across the whole span — it flags both
use of a feature newer than the floor *and* a symbol deprecated at the ceiling.
The `80300`/`80500` above are only an example (the chart modules' `8.3 - 8.5`
support window); each repository substitutes its own bounds. A single value
(`phpVersion: 80300`) only analyses "as if on 8.3" and silently misses a
deprecation introduced at a higher version, so a repository pinned to a single
PHP version — and only then — keeps the scalar form.

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

This supersedes the older per-repo phpat layer rules and the
`check-phpat-subjects.php` subject-liveness guard below; the guard stays until
every consumer has migrated its layer rules to Deptrac.

## Templates (copy-and-adapt)

Files under `templates/` are not importable — copy them into the consumer and
adjust the paths. The `check-consumer-config.php` lockstep gate below keeps them
from drifting from this package.

| Template | Copy to | Notes |
|---|---|---|
| `templates/phpunit.xml.dist` | `phpunit.xml.dist` | strict flag set incl. `requireCoverageMetadata`; PHPUnit itself is provided by the package `require`, so it stays out of the consumer's `require-dev` |
| `templates/infection.json5` | `infection.json5` | `timeoutsAsEscaped: true`; set the MSI floor per repo |
| `templates/editorconfig` | `.editorconfig` | 4-space, tab for Makefiles |
| `templates/gitattributes` | `.gitattributes` | `export-ignore` dist hygiene |
| `templates/phplint.yml` | `.phplint.yml` | the `ci:test:php:lint` gate the reusable workflow invokes — path-driven, never a hand-kept file list |
| `templates/jscpd.json` | `.jscpd.json` | zero-tolerance copy-paste gate |
| `templates/ArchitectureTest.php` | `tests/Architecture/ArchitectureTest.php` | phpat layering + `Abstract*` naming + `beFinal` |
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

### phpat subject-liveness guard — `bin/check-phpat-subjects.php`

phpat rules run inside PHPStan, and a rule whose **subject matches nothing** enforces
nothing while looking active — both PHPStan and PHPUnit stay green. This bit a chart
module once: a rule whose subject was a `Traits` namespace was a silent no-op, because
phpat resolves a subject through PHPStan's `InClassNode`, which never fires for a trait.

This guard parses a consumer's `ArchitectureTest`, extracts each `#[TestRule]` method's
subject selector, and asserts it matches at least one real class in `src/`:
`Selector::inNamespace(NS)` needs a non-trait class in `NS` (a trait-only namespace, the
manifested bug, reds here); `Selector::classname(FQCN)` needs that class to exist (a
renamed or mistyped target reds); `Selector::isAbstract()` is a conditional naming guard
that legitimately matches nothing until an abstract class is added, so it is not
liveness-checked. It is a **static** check — it does not run PHPStan — and fails closed:
every rule method must yield a classifiable subject. A repo with no `ArchitectureTest` is
skipped. Wire it as a consumer `ci:test:php:phpat-subjects` script
(`["check-phpat-subjects.php ."]`), rolled out the same script-first way.

## JS/TS configs

```jsonc
// biome.json
{ "extends": ["@magicsunday/coding-standard/biome/base.json"] }
```

```jsonc
// tsconfig.json
{ "extends": "@magicsunday/coding-standard/tsconfig/base.json" }
```

Lint with `biome ci --error-on-warnings` so every warning is CI-fatal.

## License

MIT — see [LICENSE](LICENSE).
