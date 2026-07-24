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
npm install --save-dev github:magicsunday/coding-standard#1.2.0
```

which records in `package.json`:

```json
{
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.2.0"
    }
}
```

## Layout

The directory a file lives in states how it is meant to be consumed:

| Location | Kind | How a consumer uses it |
|---|---|---|
| `php-cs-fixer/`, `phpstan/`, `rector/`, `biome/`, `tsconfig/` | **importable** | referenced straight out of `vendor/` or `node_modules/` — `includes:`, `require`, `extends` |
| `templates/` | **copy-and-adapt** | copied into the consumer's own repository; these formats (PHPUnit, phplint, Infection, jscpd, editorconfig) cannot be imported, their tools expect the file at the repo root |
| repository root | **this package's own dev config** | `.phplint.yml`, `.github/`, `tests/` — all `export-ignore`d, so a consumer never receives them. The package lints itself with its own template. |

## PHP configs

### php-cs-fixer — `php-cs-fixer/base.php`

A factory that returns a configured `PhpCsFixer\Config`; the consumer supplies its
own file header and finder.

```php
// .php-cs-fixer.dist.php
$factory = require __DIR__ . '/vendor/magicsunday/coding-standard/php-cs-fixer/base.php';

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
    - vendor/magicsunday/coding-standard/phpstan/base.neon

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
shipmonk/symplify rule packs and the extra-strict report parameters. The reason it
is staged rather than folded into the base is cost, not preference: turning it on
surfaces real findings that need triaging per repository, so forcing it into the
base would block every adoption on an unrelated backlog.

To keep that staging from becoming drift, **a repository that runs only `base.neon`
carries an open issue for reaching `strict.neon`**. The gap stays visible and
terminated instead of quietly permanent.

```shell
composer require --dev shipmonk/phpstan-rules symplify/phpstan-rules
```

Adopt via the `adopt-strict-phpstan-ruleset` workflow, triaging each finding.

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

    (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($config, 80300);
};
```

## Templates (copy-and-adapt)

Files under `templates/` are not importable — copy them into the consumer and
adjust the paths. A lockstep check keeps them from drifting from this package.

| Template | Copy to | Notes |
|---|---|---|
| `templates/phpunit.xml.dist` | `phpunit.xml.dist` | strict flag set incl. `requireCoverageMetadata`; PHPUnit itself is provided by the package `require`, so it stays out of the consumer's `require-dev` |
| `templates/infection.json5` | `infection.json5` | `timeoutsAsEscaped: true`; set the MSI floor per repo |
| `templates/editorconfig` | `.editorconfig` | 4-space, tab for Makefiles |
| `templates/gitattributes` | `.gitattributes` | `export-ignore` dist hygiene |
| `templates/phplint.yml` | `.phplint.yml` | the `ci:test:php:lint` gate the reusable workflow invokes — path-driven, never a hand-kept file list |
| `templates/jscpd.json` | `.jscpd.json` | zero-tolerance copy-paste gate |
| `templates/ArchitectureTest.php` | `tests/Architecture/ArchitectureTest.php` | phpat layering + `Abstract*` naming + `beFinal` |

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
