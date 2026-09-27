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
use MagicSunday\CodingStandard\Test\Support\GateResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function array_diff;
use function array_keys;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_executable;
use function mkdir;
use function preg_match_all;
use function sort;
use function sprintf;

/**
 * Proves the shared Deptrac ruleset (deptrac/layers.yaml) is exactly the
 * decision table of #191, by running the real Deptrac against it.
 *
 * The fixture holds one directory per canonical layer. Each carries an
 * `Anchor` class plus one `Uses<Target>` class per other layer that depends
 * on that layer's anchor, so a single Deptrac run exercises every ordered
 * layer pair. An edge the table allows must stay silent and an edge it does
 * not allow must be reported. A ruleset that drifts from the table in either
 * direction, or loses its import, reds here.
 *
 * The table itself is the specification: it is kept here, not read from the
 * YAML, so the test does not merely restate the file it checks. Its
 * acyclicity is asserted separately, which together with the pair check
 * proves the shipped ruleset is a DAG (Acyclic Dependencies Principle).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckDeptracLayersTest extends GateTestCase
{
    /**
     * The decision table: each canonical layer and the layers it may depend
     * on. A layer may always depend on itself.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'Support'       => [],
        'Enum'          => ['Support'],
        'Model'         => ['Support', 'Enum'],
        'Configuration' => ['Support', 'Enum'],
        'Contract'      => ['Support', 'Enum', 'Model', 'Configuration'],
        'Repository'    => ['Support', 'Enum', 'Model', 'Configuration', 'Contract'],
        'Adapter'       => ['Support', 'Enum', 'Model', 'Configuration', 'Contract'],
        'Service'       => ['Support', 'Enum', 'Model', 'Configuration', 'Contract'],
        'Facade'        => ['Support', 'Enum', 'Model', 'Configuration', 'Contract', 'Repository', 'Adapter', 'Service'],
        'Module'        => ['Support', 'Enum', 'Model', 'Configuration', 'Contract', 'Repository', 'Adapter', 'Service', 'Facade'],
    ];

    /**
     * Every ordered pair of canonical layers gets exactly the verdict the
     * decision table gives it.
     */
    #[Test]
    public function everyLayerPairFollowsTheDecisionTable(): void
    {
        $src = $this->fixture()->path() . '/src';

        foreach (array_keys(self::ALLOWED) as $layer) {
            mkdir($src . '/' . $layer, 0o777, true);
            file_put_contents(
                $src . '/' . $layer . '/Anchor.php',
                sprintf("<?php\n\nnamespace Fixture\\%s;\n\nfinal class Anchor\n{\n}\n", $layer),
            );

            foreach (array_keys(self::ALLOWED) as $target) {
                if ($target === $layer) {
                    continue;
                }

                file_put_contents(
                    $src . '/' . $layer . '/Uses' . $target . '.php',
                    sprintf(
                        "<?php\n\nnamespace Fixture\\%s;\n\nfinal class Uses%s\n{\n    public function anchor(): ?\\Fixture\\%s\\Anchor\n    {\n        return null;\n    }\n}\n",
                        $layer,
                        $target,
                        $target,
                    ),
                );
            }
        }

        $result = $this->deptrac('analyse', '--formatter=github-actions');

        preg_match_all(
            '/Fixture\\\\(\w+)\\\\Uses(\w+) must not depend on Fixture\\\\\w+\\\\Anchor/',
            $result->output,
            $matches,
            PREG_SET_ORDER,
        );

        $reported = [];

        foreach ($matches as $match) {
            $reported[] = $match[1] . ' -> ' . $match[2];
        }

        $expected = [];

        foreach (self::ALLOWED as $layer => $allowed) {
            foreach (array_keys(self::ALLOWED) as $target) {
                if (($target !== $layer) && !in_array($target, $allowed, true)) {
                    $expected[] = $layer . ' -> ' . $target;
                }
            }
        }

        sort($reported);
        sort($expected);

        $unexpected = array_values(array_diff($reported, $expected));
        $missing    = array_values(array_diff($expected, $reported));

        if (($unexpected !== []) || ($missing !== [])) {
            self::fail(self::diagnosticMessage(
                'deptrac/layers.yaml drifted from the decision table.'
                . "\nReported although the table allows it: " . implode(', ', $unexpected)
                . "\nAllowed although the table forbids it: " . implode(', ', $missing),
                $result->output,
            ));
        }

        self::assertSame(1, $result->exitCode, self::diagnosticMessage('Deptrac reported the forbidden edges but did not fail.', $result->output));

        $unassigned = $this->deptrac('debug:unassigned');

        self::assertSame(0, $unassigned->exitCode, self::diagnosticMessage('A fixture layer directory matched no shared layer.', $unassigned->output));
    }

    /**
     * The decision table names exactly the layers deptrac/layers.yaml defines,
     * so a layer added to the YAML cannot escape the pair check above.
     */
    #[Test]
    public function theDecisionTableCoversEveryDefinedLayer(): void
    {
        $yaml = file_get_contents(self::root() . '/deptrac/layers.yaml');

        self::assertIsString($yaml);

        preg_match_all('/^\s+name:\s*(\w+)\s*$/m', $yaml, $matches);

        $defined = $matches[1];
        $table   = array_keys(self::ALLOWED);

        sort($defined);
        sort($table);

        self::assertSame($table, $defined, 'The decision table and deptrac/layers.yaml name different layers.');
    }

    /**
     * The decision table is a DAG: repeatedly removing the layers whose every
     * allowed dependency is already removed empties the table. A cycle leaves
     * layers behind.
     */
    #[Test]
    public function theDecisionTableIsAcyclic(): void
    {
        $remaining = self::ALLOWED;
        $placed    = [];

        do {
            $progress = false;

            foreach ($remaining as $layer => $allowed) {
                if (array_diff($allowed, $placed) === []) {
                    $placed[] = $layer;
                    unset($remaining[$layer]);
                    $progress = true;
                }
            }
        } while ($progress);

        self::assertSame([], array_keys($remaining), 'The decision table has a cycle among these layers.');
    }

    /**
     * Runs this repository's own Deptrac binary against a config that imports
     * the shipped deptrac/layers.yaml and analyses the fixture's src/.
     *
     * @param string ...$arguments The Deptrac command and its options.
     *
     * @return GateResult The run's combined output and exit code.
     */
    private function deptrac(string ...$arguments): GateResult
    {
        $deptrac = self::root() . '/.build/bin/deptrac';

        if (!is_executable($deptrac)) {
            self::fail("The root deptrac binary is missing or not executable ({$deptrac}) — run `composer install` first.");
        }

        $fixture = $this->fixture()->path();
        $config  = $fixture . '/deptrac.yaml';

        file_put_contents(
            $config,
            "imports:\n    - " . self::root() . "/deptrac/layers.yaml\n\ndeptrac:\n    paths:\n        - " . $fixture . "/src\n",
        );

        return (new GateProcess())->runRaw(
            [$deptrac, ...$arguments, '--config-file=' . $config, '--no-interaction', ...($arguments[0] === 'analyse' ? ['--no-progress', '--no-cache'] : [])],
            $fixture,
            [],
            300.0,
        );
    }
}
