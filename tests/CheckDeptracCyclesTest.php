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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_filter;
use function chmod;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function is_executable;
use function is_file;
use function mkdir;
use function posix_getuid;
use function sprintf;
use function str_starts_with;

/**
 * Fixture-driven cases for bin/check-deptrac-cycles.php (GH-192).
 *
 * The gate takes ONE argument, the dot file, so each case writes its graph
 * into this test's fixture() directory and hands GateTestCase's decisions
 * that file's path in the `$fixtureDir` slot — GateProcess::run() appends it
 * as the last argv entry, which is exactly the gate's `<dot-file>`.
 *
 * Most graphs are hand-written in the byte shape Deptrac 4.7.x's
 * `graphviz-dot` formatter emits (phpdocumentor/graphviz: one statement per
 * line group, every ID double-quoted, attribute lists split over lines, a
 * `color="red"` on a violating edge). The END-TO-END cases at the bottom run
 * this repository's own `.build/bin/deptrac` against a tiny fixture project
 * and feed its real output to the gate, so the parser is proven against the
 * formatter itself and not only against this file's idea of it. A missing
 * deptrac binary is a failure, not a skip — the same reasoning as
 * CheckPhpAnalyseTest: this class runs in the plain `composer ci:test:phpunit`
 * step, after `composer install`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckDeptracCyclesTest extends GateTestCase
{
    /**
     * Mirrors MAX_DOT_BYTES in bin/check-deptrac-cycles.php.
     */
    private const int MAX_DOT_BYTES = 1048576;

    /**
     * The report line of every could-not-parse refusal.
     */
    private const string NOT_A_GRAPH = 'is not a Deptrac graphviz-dot layer graph';

    /**
     * An acyclic graph as Deptrac writes it, including a violating (red) edge
     * and a layer that only has uncovered dependencies (a node, no edge).
     */
    private const string ACYCLIC = <<<'DOT'
        digraph "" {
        "Service" -> "Repository" [
        label="4"
        ]
        "Repository" -> "Model" [
        label="2"
        ]
        "Service" -> "Model" [
        label="1"
        color="red"
        ]
        "Service" [

        ]
        "Repository" [

        ]
        "Model" [

        ]
        "Enum" [

        ]
        }
        DOT;

    /**
     * An acyclic graph passes with the OK line.
     */
    #[Test]
    public function acceptsAnAcyclicLayerGraph(): void
    {
        $this->assertGateAccepts(self::gate(), $this->dot(self::ACYCLIC));
    }

    /**
     * The grouped shape (`formatters.graphviz.groups` + `point_to_groups`):
     * a `subgraph "cluster_…"` block, a graph attribute and `lhead`/`group`
     * attributes all parse.
     */
    #[Test]
    public function acceptsTheGroupedFormatterShape(): void
    {
        $this->assertGateAccepts(self::gate(), $this->dot(<<<'DOT'
            digraph "" {
            subgraph "cluster_Core" {
            label="Core"
            "Model" [
            group="Core"
            ]
            "Contract" [
            group="Core"
            ]
            }
            compound="true"
            "Service" -> "Model" [
            lhead="cluster_Core"
            label="3"
            ]
            "Model" -> "Contract" [
            label="1"
            ]
            "Service" [

            ]
            }
            DOT));
    }

    /**
     * A dependency of a layer on itself is not a cycle between layers.
     */
    #[Test]
    public function ignoresASelfLoop(): void
    {
        $this->assertGateAccepts(self::gate(), $this->dot(<<<'DOT'
            digraph "" {
            "Model" -> "Model" [
            label="7"
            ]
            "Model" -> "Enum" [
            label="1"
            ]
            }
            DOT));
    }

    /**
     * Model <-> Contract, the module-updater shape from the issue.
     */
    #[Test]
    public function rejectsATwoLayerCycleNamingBothLayers(): void
    {
        $dot = $this->dot(<<<'DOT'
            digraph "" {
            "Model" -> "Contract" [
            label="2"
            ]
            "Contract" -> "Model" [
            label="1"
            ]
            "Service" -> "Model" [
            label="1"
            ]
            }
            DOT);

        $this->assertGateRejects(self::gate(), $dot, '  - Contract, Model');
        $this->assertGateRejects(self::gate(), $dot, 'via Contract -> Model, Model -> Contract');
    }

    /**
     * Io -> Parser -> Mapping -> Io, the gedcom-parser shape from the issue;
     * a layer hanging off the cycle is not part of it.
     */
    #[Test]
    public function rejectsAThreeLayerCycleNamingEveryLayer(): void
    {
        $dot = $this->dot(<<<'DOT'
            digraph "" {
            "Io" -> "Parser" [
            label="1"
            ]
            "Parser" -> "Mapping" [
            label="1"
            ]
            "Mapping" -> "Io" [
            label="1"
            color="red"
            ]
            "Mapping" -> "Model" [
            label="5"
            ]
            }
            DOT);

        $this->assertGateRejects(self::gate(), $dot, "  - Io, Mapping, Parser\n");
        $this->assertGateRejects(self::gate(), $dot, 'via Io -> Parser, Mapping -> Io, Parser -> Mapping');
    }

    /**
     * Two disjoint cycles are both reported, each layer in exactly one of
     * them, and the count in the header says two.
     */
    #[Test]
    public function reportsTwoDisjointCyclesEachOnce(): void
    {
        $dot = $this->dot(<<<'DOT'
            digraph "" {
            "A" -> "B" [
            label="1"
            ]
            "B" -> "A" [
            label="1"
            ]
            "B" -> "C" [
            label="1"
            ]
            "C" -> "D" [
            label="1"
            ]
            "D" -> "E" [
            label="1"
            ]
            "E" -> "C" [
            label="1"
            ]
            }
            DOT);

        $result = $this->runGate($dot);

        self::assertSame(1, $result->exitCode, self::diagnosticMessage('Two cycles must fail the run.', $result->output));
        self::assertOutputContains($result, '2 layer cycle(s)', 'The header does not count both cycles.');

        $cycleLines = array_filter(
            explode("\n", $result->output),
            static fn (string $line): bool => str_starts_with($line, '  - '),
        );

        self::assertSame(
            ['  - A, B', '  - C, D, E'],
            [...$cycleLines],
            self::diagnosticMessage('Each cycle must be reported once, with its own layers only.', $result->output),
        );
    }

    /**
     * An `a -> b -> a` chain statement is two edges, not one — the cycle is
     * found (proves a chain is not truncated after its first edge).
     */
    #[Test]
    public function rejectsACycleWrittenAsOneEdgeChain(): void
    {
        $this->assertGateRejects(self::gate(), $this->dot("digraph G { A -> B -> A; }\n"), '  - A, B');
    }

    /**
     * A cycle whose nodes sit inside a group's subgraph is still found —
     * subgraph content is parsed, not skipped.
     */
    #[Test]
    public function rejectsACycleClosedByAnEdgeInsideASubgraph(): void
    {
        $this->assertGateRejects(self::gate(), $this->dot(<<<'DOT'
            digraph "" {
            subgraph "cluster_Core" {
            label="Core"
            "Model" -> "Contract" [
            label="1"
            ]
            }
            "Contract" -> "Model" [
            label="1"
            ]
            }
            DOT), '  - Contract, Model');
    }

    /**
     * Layer names keep their identity through the formatter's addslashes()
     * escaping, and a numeric layer name (an int array key in PHP) is still
     * compared and reported as the string it is.
     */
    #[Test]
    public function reportsEscapedAndNumericLayerNamesAsThemselves(): void
    {
        $this->assertGateRejects(self::gate(), $this->dot(<<<'DOT'
            digraph "" {
            "Say \"hi\"" -> "10" [
            label="1"
            ]
            "10" -> "9" [
            label="1"
            ]
            "9" -> "Say \"hi\"" [
            label="1"
            ]
            }
            DOT), '  - 10, 9, Say "hi"');
    }

    /**
     * A layer name is consumer content: control bytes and the legacy
     * workflow-command prefix are scrubbed in the cycle report.
     */
    #[Test]
    public function reportIsInertWhenALayerNameAttemptsToForgeAWorkflowCommand(): void
    {
        $this->assertGateReportIsInert(
            self::gate(),
            $this->dot("digraph \"\" {\n\"x##[error]forged\x1B[31m\nnext\" -> \"B\" [\nlabel=\"1\"\n]\n\"B\" -> \"x##[error]forged\x1B[31m\nnext\" [\nlabel=\"1\"\n]\n}\n"),
            'x##?[error]forged?[31m?next',
        );
    }

    /**
     * No argument at all is a usage error, not a pass over nothing.
     */
    #[Test]
    public function refusesAMissingArgument(): void
    {
        $result = (new GateProcess())->runRaw(self::gate());

        self::assertFalse($result->isDegraded(), 'The gate ran degraded — it emitted a diagnostic.');
        self::assertSame(2, $result->exitCode, self::diagnosticMessage('A missing argument must be exit 2.', $result->output));
        self::assertOutputContains($result, 'usage: check-deptrac-cycles.php <dot-file>', 'No usage line.');
    }

    /**
     * A second argument is a usage error too — not silently ignored.
     */
    #[Test]
    public function refusesAnExtraArgument(): void
    {
        $this->assertGateUsageError([...self::gate(), 'extra'], $this->dot(self::ACYCLIC), 'usage:');
    }

    /**
     * A dot file that does not exist — e.g. the deptrac run wrote nowhere.
     */
    #[Test]
    public function refusesAMissingFile(): void
    {
        $this->assertGateUsageError(self::gate(), $this->fixture()->path() . '/missing.dot', 'does not exist or is not a file');
    }

    /**
     * A directory is not a dot file.
     */
    #[Test]
    public function refusesADirectory(): void
    {
        $this->assertGateUsageError(self::gate(), $this->fixture()->path(), 'does not exist or is not a file');
    }

    /**
     * An unreadable file is exit 2 with the gate's own diagnostic, not PHP's warning.
     */
    #[Test]
    public function refusesAnUnreadableFile(): void
    {
        if (function_exists('posix_getuid') && (posix_getuid() === 0)) {
            self::markTestSkipped('running as root: mode 000 does not deny read.');
        }

        $dot = $this->dot(self::ACYCLIC);
        chmod($dot, 0o000);

        try {
            $this->assertGateUsageError(self::gate(), $dot, 'cannot be read');
        } finally {
            chmod($dot, 0o644);
        }
    }

    /**
     * Exactly at the cap is read; one byte past it is refused.
     */
    #[Test]
    public function readsAFileAtTheCapAndRefusesOnePastIt(): void
    {
        $atCap = self::padTextToCap(self::MAX_DOT_BYTES, "digraph \"\" {\n\"A\" -> \"B\" [\nlabel=\"1\"\n]\n", ' ', "}\n");

        $this->assertGateAccepts(self::gate(), $this->dot($atCap, 'at-cap.dot'));
        $this->assertGateUsageError(
            self::gate(),
            $this->dot($atCap . ' ', 'past-cap.dot'),
            sprintf('is larger than the %d bytes this gate reads', self::MAX_DOT_BYTES),
        );
    }

    /**
     * Graphs with no layer at all prove nothing and are refused.
     *
     * @return array<string, array{0: string}>
     */
    public static function vacuousGraphProvider(): array
    {
        return [
            'an empty digraph'             => ["digraph \"\" {\n\n}\n"],
            'graph attributes only'        => ["digraph \"\" {\ncompound=\"true\"\n}\n"],
            'an empty subgraph'            => ["digraph \"\" {\nsubgraph \"cluster_Core\" {\nlabel=\"Core\"\n}\n}\n"],
            'default attribute statements' => ["digraph \"\" {\nnode [shape=\"box\"]\n}\n"],
        ];
    }

    /**
     * @param string $contents A graph without a single node.
     */
    #[Test]
    #[DataProvider('vacuousGraphProvider')]
    public function refusesAVacuousGraph(string $contents): void
    {
        $this->assertGateUsageError(self::gate(), $this->dot($contents), 'holds no layer at all');
    }

    /**
     * Input outside the formatter's DOT subset fails closed: a skipped
     * statement could be the edge that closes a cycle.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unparseableProvider(): array
    {
        return [
            'an empty file'              => ['', 'no `digraph` header'],
            'plain text'                 => ["Script dumped to layers\n", 'no `digraph` header'],
            'an undirected graph'        => ["graph G {\nA\n}\n", 'an undirected graph'],
            'an undirected edge'         => ["digraph G {\nA -- B\n}\n", 'unexpected input on line 2'],
            'a comment'                  => ["digraph G {\n// A -> B\nB -> A\n}\n", 'unexpected input on line 2'],
            'an HTML string'             => ["digraph G {\nA [label=<b>]\n}\n", 'unexpected input on line 2'],
            'a port'                     => ["digraph G {\nA:n -> B\n}\n", 'unexpected input on line 2'],
            'an unterminated quote'      => ["digraph G {\n\"A -> B\n}\n", 'unexpected input on line 2'],
            'an unterminated block'      => ["digraph G {\nA -> B\n", 'an unterminated `{` block'],
            'an unterminated attributes' => ["digraph G {\nA -> B [\nlabel=\"1\"\n", 'an attribute or edge without its identifier'],
            'an edge without a target'   => ["digraph G {\nA -> [label=\"1\"]\n}\n", 'an attribute or edge without its identifier'],
            'content after the graph'    => ["digraph G {\nA -> B\n}\nB -> A\n", 'content after the closing brace'],
            'a second graph'             => ["digraph G {\nA\n}\ndigraph H {\nB\n}\n", 'content after the closing brace'],
            'a stray closing bracket'    => ["digraph G {\n]\n}\n", 'neither a node, an edge'],
        ];
    }

    /**
     * @param string $contents A document the gate must refuse.
     * @param string $reason   The diagnostic it must give.
     */
    #[Test]
    #[DataProvider('unparseableProvider')]
    public function refusesInputOutsideTheFormatterSubset(string $contents, string $reason): void
    {
        $dot = $this->dot($contents);

        $this->assertGateUsageError(self::gate(), $dot, self::NOT_A_GRAPH);
        $this->assertGateUsageError(self::gate(), $dot, $reason);
    }

    /**
     * Nesting is bounded, so crafted input cannot recurse without limit.
     */
    #[Test]
    public function refusesSubgraphsNestedPastTheBound(): void
    {
        $open  = '';
        $close = '';

        for ($i = 0; $i < 40; ++$i) {
            $open  .= "subgraph s{$i} {\n";
            $close .= "}\n";
        }

        $this->assertGateUsageError(
            self::gate(),
            $this->dot("digraph G {\n{$open}A -> B\n{$close}}\n"),
            'subgraphs nested deeper than 32 levels',
        );
    }

    /**
     * END-TO-END: the real deptrac writes a cyclic layer graph (and exits 1,
     * since the ruleset forbids the closing edge — the dot file is written
     * all the same), and the gate names the cycle.
     */
    #[Test]
    public function endToEndRejectsTheCycleInARealDeptracGraph(): void
    {
        $dot = $this->runDeptracOnFixtureProject(true, 1);

        $this->assertGateRejects(self::gate(), $dot, "  - Alpha, Beta, Gamma\n");
    }

    /**
     * END-TO-END: the real deptrac writes an acyclic graph with the grouped
     * formatter configuration, and the gate accepts it.
     */
    #[Test]
    public function endToEndAcceptsARealAcyclicDeptracGraph(): void
    {
        $dot = $this->runDeptracOnFixtureProject(false, 0);

        $this->assertGateAccepts(self::gate(), $dot);
    }

    /**
     * Builds a three-layer project (Alpha -> Beta -> Gamma, optionally
     * Gamma -> Alpha), runs the real deptrac with the graphviz-dot formatter
     * against it, and returns the dot file's path.
     *
     * @param bool $cyclic           Whether Gamma depends back on Alpha.
     * @param int  $expectedExitCode The exit code deptrac itself must return.
     *
     * @return string The dot file deptrac wrote.
     */
    private function runDeptracOnFixtureProject(bool $cyclic, int $expectedExitCode): string
    {
        $deptrac = self::root() . '/.build/bin/deptrac';

        if (!is_executable($deptrac)) {
            self::fail("The root deptrac binary is missing or not executable ({$deptrac}) — run `composer install` first.");
        }

        $project = $this->fixture()->path() . '/project';

        $this->write($project . '/src/Alpha/A.php', <<<'PHP'
            <?php

            namespace Fixture\Alpha;

            final class A
            {
                public function beta(): \Fixture\Beta\B
                {
                    return new \Fixture\Beta\B();
                }

                public function vendor(): ?\Some\Vendor\Thing
                {
                    return null;
                }
            }

            PHP);

        $this->write($project . '/src/Beta/B.php', <<<'PHP'
            <?php

            namespace Fixture\Beta;

            final class B
            {
                public function gamma(): ?\Fixture\Gamma\G
                {
                    return null;
                }
            }

            PHP);

        $this->write($project . '/src/Gamma/G.php', $cyclic ? <<<'PHP'
            <?php

            namespace Fixture\Gamma;

            final class G
            {
                public function alpha(): ?\Fixture\Alpha\A
                {
                    return null;
                }

                public function self(): ?G
                {
                    return null;
                }
            }

            PHP : <<<'PHP'
            <?php

            namespace Fixture\Gamma;

            final class G
            {
            }

            PHP);

        $this->write($project . '/deptrac.yaml', <<<'YAML'
            deptrac:
                paths:
                    - ./src
                layers:
                    - name: Alpha
                      collectors:
                          - type: directory
                            value: .*/Alpha/.*
                    - name: Beta
                      collectors:
                          - type: directory
                            value: .*/Beta/.*
                    - name: Gamma
                      collectors:
                          - type: directory
                            value: .*/Gamma/.*
                ruleset:
                    Alpha: [Beta]
                    Beta: [Gamma]
                formatters:
                    graphviz:
                        point_to_groups: true
                        groups:
                            Upper: [Alpha, Beta]

            YAML);

        $dot    = $project . '/layers.dot';
        $result = (new GateProcess())->runRaw(
            [
                $deptrac,
                'analyse',
                '--no-progress',
                '--no-cache',
                '--config-file=' . $project . '/deptrac.yaml',
                '--formatter=graphviz-dot',
                '--output=' . $dot,
            ],
            $project,
            [],
            300.0,
        );

        if ($result->exitCode !== $expectedExitCode) {
            self::fail(self::diagnosticMessage(
                sprintf('deptrac exited %d, expected %d.', $result->exitCode, $expectedExitCode),
                $result->output,
            ));
        }

        self::assertTrue(is_file($dot), self::diagnosticMessage('deptrac wrote no dot file.', $result->output));

        return $dot;
    }

    /**
     * Writes $contents as a dot file into this test's fixture directory.
     *
     * @param string $contents The dot document.
     * @param string $name     The file name.
     *
     * @return string The file's path.
     */
    private function dot(string $contents, string $name = 'layers.dot'): string
    {
        $path = $this->fixture()->path() . '/' . $name;
        $this->write($path, $contents);

        return $path;
    }

    /**
     * Writes $contents to $path, creating any missing parent directory.
     *
     * @param string $path     Absolute path inside this test's fixture directory.
     * @param string $contents The exact bytes to write.
     *
     * @return void
     */
    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o777, true), "Could not create {$dir}.");
        }

        self::assertNotFalse(file_put_contents($path, $contents), "Could not write {$path}.");
    }

    /**
     * Runs the gate once for a check the assertGate*() decisions do not cover.
     *
     * @param string $dot The dot file.
     *
     * @return GateResult The run.
     */
    private function runGate(string $dot): GateResult
    {
        $result = (new GateProcess())->run(self::gate(), $dot);

        self::assertFalse($result->isDegraded(), 'The gate ran degraded — it emitted a diagnostic.');

        return $result;
    }

    /**
     * @return list<string> The interpreter and gate script.
     */
    private static function gate(): array
    {
        return ['php', self::root() . '/bin/check-deptrac-cycles.php'];
    }
}
