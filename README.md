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

For the JS/TS configs, add a GitHub git dependency (no npm-registry account needed —
the same mechanism `webtrees-chart-lib` uses):

```shell
npm install --save-dev github:magicsunday/coding-standard#1.0.0
```

which records in `package.json`:

```json
{
    "devDependencies": {
        "@magicsunday/coding-standard": "github:magicsunday/coding-standard#1.0.0"
    }
}
```

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

`base.neon` sets `level: max` and wires phpat; the rule extensions load through
`phpstan/extension-installer`.

```neon
# phpstan.neon
includes:
    - vendor/magicsunday/coding-standard/phpstan/base.neon

parameters:
    phpVersion: 80300
    paths:
        - src
        - tests

services:
    -
        class: Vendor\Namespace\Test\Architecture\ArchitectureTest
        tags:
            - phpat.test
```

`strict.neon` (includes `base.neon`) adds the shipmonk/symplify rule packs and the
extra-strict report parameters. It is opt-in — install the packs first and adopt
via the `adopt-strict-phpstan-ruleset` workflow:

```shell
composer require --dev shipmonk/phpstan-rules symplify/phpstan-rules
```

### Rector — `rector/base.php`

```php
// rector.php
use Rector\Config\RectorConfig;

return static function (RectorConfig $config): void {
    $config->paths([__DIR__ . '/src/', __DIR__ . '/tests/']);
    $config->phpVersion(80300);
    $config->phpstanConfig(__DIR__ . '/phpstan.neon');

    (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($config);
};
```

## Templates (copy-and-adapt)

Files under `templates/` are not importable — copy them into the consumer and
adjust the paths. A lockstep check keeps them from drifting from this package.

| Template | Copy to | Notes |
|---|---|---|
| `templates/phpunit.xml.dist` | `phpunit.xml.dist` | strict flag set incl. `requireCoverageMetadata` |
| `templates/infection.json5` | `infection.json5` | `timeoutsAsEscaped: true`; set the MSI floor per repo |
| `templates/editorconfig` | `.editorconfig` | 4-space, tab for Makefiles |
| `templates/gitattributes` | `.gitattributes` | `export-ignore` dist hygiene |
| `templates/jscpd.json` | `.jscpd.json` | zero-tolerance copy-paste gate |
| `templates/ArchitectureTest.php` | `tests/Architecture/ArchitectureTest.php` | phpat layering + `Abstract*` naming + `beFinal` |

## JS/TS configs

```jsonc
// biome.json
{ "extends": ["@magicsunday/coding-standard/biome.json"] }
```

```jsonc
// tsconfig.json
{ "extends": "@magicsunday/coding-standard/tsconfig.base.json" }
```

Lint with `biome ci --error-on-warnings` so every warning is CI-fatal.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
