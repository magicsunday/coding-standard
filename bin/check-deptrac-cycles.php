<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Fail on a cycle in the ACTUAL layer graph Deptrac measured (GH-192).
 *
 * Deptrac reports a dependency that the ruleset forbids, but it never asks whether
 * the dependencies it ALLOWS form a cycle. Its only cycle check covers transitive
 * `+Layer` references inside the ruleset itself; a consumer's local widenings
 * (`Model: [Contract]` next to `Contract: [Model]`) still let two layers depend on
 * each other with `deptrac analyse` green. The Acyclic Dependencies Principle is
 * about the dependencies that exist, not the ones an allow-list permits, so this
 * gate reads the graph Deptrac itself writes and reports every strongly connected
 * component of more than one layer:
 *
 *     deptrac analyse --no-progress --formatter=graphviz-dot --output=.build/deptrac-layers.dot
 *     check-deptrac-cycles.php .build/deptrac-layers.dot
 *
 * The input is the `graphviz-dot` formatter's output (Deptrac 4.7.x, rendered by
 * phpdocumentor/graphviz): one `"From" -> "To" [ label="N" … ]` statement per layer
 * pair with at least one dependency (a violating edge additionally carries
 * `color="red"`; this gate counts it like any other, it is a real dependency), one
 * `"Layer" [ … ]` statement per layer, and — when `formatters.graphviz.groups` is
 * configured — `subgraph "cluster_<group>" { … }` blocks plus a `compound="true"`
 * graph attribute. A dependency of a layer on itself is never a cycle between
 * layers and is ignored. The parser accepts the DOT subset that formatter can
 * produce (quoted and bare IDs, attribute lists, `;`/`,` separators, nested
 * subgraphs, `a -> b -> c` chains) and FAILS CLOSED on anything else — a comment,
 * an HTML string, a port, an undirected `graph`, an unbalanced brace — rather than
 * guessing, because a statement this gate skipped could be the edge closing a
 * cycle. A graph with no layer at all is refused as vacuous for the same reason:
 * it is what an unconfigured or empty `paths:` produces, and "no cycle" would be
 * true only by omission.
 *
 * Blind spot: a layer listed under `formatters.graphviz.hidden_layers` is dropped
 * from the dot output together with every edge touching it, so a cycle through it
 * is invisible here. Hide only overlay layers (every member also in a visible
 * layer), never a layer whose classes belong to no other layer.
 *
 * Usage: check-deptrac-cycles.php <dot-file>
 *
 * Exit 0 = the layer graph is acyclic; 1 = at least one cycle, every one reported
 * with its layers and the edges between them; 2 = the gate could not run (no or
 * extra arguments, an unreadable or oversize file, input that is not a DOT digraph
 * of the expected shape, or a graph without a single layer).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

// This is a global-namespace entry script, so built-in functions are called
// unqualified (a `use function` import would be a no-op here).

// safeReportValue() — shared, see its header for the boundary and the requirers.
// Layer names come from the consumer's deptrac.yaml, i.e. pull-request content.
require_once __DIR__ . '/support/safe-report-value.php';

// readCapped() — shared, see its header. The dot file is generated from
// pull-request content, so the read is capped and PHP's own warning on an
// unreadable file is suppressed ahead of this gate's own diagnostic.
require_once __DIR__ . '/support/read-quietly.php';

/**
 * The largest dot file this gate reads, in bytes.
 *
 * One mebibyte. Deptrac writes a few dozen bytes per layer and per layer pair, so
 * even a hundred fully interconnected layers stay far below this; the bound only
 * ever meets a file that is not a Deptrac layer graph.
 */
const MAX_DOT_BYTES = 1048576;

/**
 * The deepest `subgraph`/`{ … }` nesting this gate follows.
 *
 * Deptrac's formatter nests exactly one level (a group's cluster). The bound keeps
 * the recursive-descent parser from recursing without limit on crafted input.
 */
const MAX_SUBGRAPH_DEPTH = 32;

/**
 * Prints a could-not-run diagnostic and exits 2.
 *
 * @param string $message The diagnostic, already scrubbed where it carries file content.
 *
 * @return never
 */
function usageError(string $message): never
{
    fwrite(\STDERR, 'check-deptrac-cycles: ' . $message . "\n");

    exit(2);
}

/**
 * Splits a dot document into tokens, failing closed on anything outside the
 * DOT subset Deptrac's graphviz formatter produces.
 *
 * Each token is `[kind, value, offset]`: kind `q` is a quoted string (value
 * unescaped; the formatter escapes names with addslashes(), which stripslashes()
 * reverses exactly), `id` a bare identifier or numeral, `p` punctuation.
 *
 * @param string $source The dot document.
 * @param string $label  The file name, for diagnostics (already scrubbed).
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function tokenizeDot(string $source, string $label): array
{
    $pattern = '/\G(?:(?<ws>\s+)'
        . '|"(?<q>(?:[^"\\\\]++|\\\\.)*+)"'
        . '|(?<p>->|[{}\[\]=;,])'
        . '|(?<id>[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*+|-?(?:\.[0-9]++|[0-9]++(?:\.[0-9]*+)?)))/s';

    $tokens = [];
    $offset = 0;
    $length = strlen($source);

    while ($offset < $length) {
        $matched = preg_match($pattern, $source, $match, \PREG_UNMATCHED_AS_NULL, $offset);

        if ($matched !== 1) {
            usageError(sprintf(
                '%s is not a Deptrac graphviz-dot layer graph: unexpected input on line %d.',
                $label,
                lineOf($source, $offset)
            ));
        }

        if ($match['q'] !== null) {
            $tokens[] = ['q', stripslashes($match['q']), $offset];
        } elseif ($match['p'] !== null) {
            $tokens[] = ['p', $match['p'], $offset];
        } elseif ($match['id'] !== null) {
            $tokens[] = ['id', $match['id'], $offset];
        }

        $offset += strlen($match[0]);
    }

    return $tokens;
}

/**
 * @param string $source The dot document.
 * @param int    $offset A byte offset into it.
 *
 * @return int The 1-based line number of $offset.
 */
function lineOf(string $source, int $offset): int
{
    return substr_count($source, "\n", 0, min($offset, strlen($source))) + 1;
}

/**
 * A recursive-descent parser over the tokens of one DOT digraph, collecting its
 * nodes and edges.
 */
final class DotLayerGraphParser
{
    /**
     * Every node name, as a set.
     *
     * @var array<string, true>
     */
    public array $nodes = [];

    /**
     * Every edge between two different nodes, as `from => [to => true]`.
     *
     * @var array<string, array<string, true>>
     */
    public array $edges = [];

    private int $position = 0;

    /**
     * @param list<array{0: string, 1: string, 2: int}> $tokens The tokens of the document.
     * @param string                                    $source The document, for line numbers.
     * @param string                                    $label  The file name, for diagnostics (already scrubbed).
     */
    public function __construct(
        private readonly array $tokens,
        private readonly string $source,
        private readonly string $label,
    ) {
    }

    /**
     * Parses `[strict] digraph [ID] { stmt_list }` and requires the end of input after it.
     *
     * @return void
     */
    public function parse(): void
    {
        if ($this->isKeyword('strict')) {
            ++$this->position;
        }

        if ($this->isKeyword('graph')) {
            $this->fail('an undirected graph, not the digraph Deptrac writes');
        }

        if (!$this->isKeyword('digraph')) {
            $this->fail('no `digraph` header');
        }

        ++$this->position;

        if ($this->isId()) {
            ++$this->position;
        }

        $this->expectPunct('{');
        $this->parseStatements(0);
        $this->expectPunct('}');

        if ($this->position < count($this->tokens)) {
            $this->fail('content after the closing brace of the digraph');
        }
    }

    /**
     * Parses statements up to (not including) the closing `}` of the current block.
     *
     * @param int $depth The current subgraph nesting depth.
     *
     * @return void
     */
    private function parseStatements(int $depth): void
    {
        while (!$this->isPunct('}')) {
            if ($this->position >= count($this->tokens)) {
                $this->fail('an unterminated `{` block');
            }

            $this->parseStatement($depth);

            if ($this->isPunct(';')) {
                ++$this->position;
            }
        }
    }

    /**
     * Parses one statement: a subgraph, an attribute statement, a graph
     * attribute, a node or an edge chain.
     *
     * @param int $depth The current subgraph nesting depth.
     *
     * @return void
     */
    private function parseStatement(int $depth): void
    {
        if ($this->isKeyword('subgraph') || $this->isPunct('{')) {
            if ($depth >= MAX_SUBGRAPH_DEPTH) {
                $this->fail(sprintf('subgraphs nested deeper than %d levels', MAX_SUBGRAPH_DEPTH));
            }

            if ($this->isKeyword('subgraph')) {
                ++$this->position;

                if ($this->isId()) {
                    ++$this->position;
                }
            }

            $this->expectPunct('{');
            $this->parseStatements($depth + 1);
            $this->expectPunct('}');

            return;
        }

        if ($this->isKeyword('graph') || $this->isKeyword('node') || $this->isKeyword('edge')) {
            ++$this->position;
            $this->parseAttributeLists(true);

            return;
        }

        if (!$this->isId()) {
            $this->fail('a statement that is neither a node, an edge, a subgraph nor an attribute');
        }

        $from = $this->tokens[$this->position][1];
        ++$this->position;

        if ($this->isPunct('=')) {
            ++$this->position;
            $this->expectId();

            return;
        }

        $this->nodes[$from] = true;

        while ($this->isPunct('->')) {
            ++$this->position;
            $to = $this->expectId();

            $this->nodes[$to] = true;

            if ($to !== $from) {
                $this->edges[$from][$to] = true;
            }

            $from = $to;
        }

        $this->parseAttributeLists(false);
    }

    /**
     * Parses zero or more `[ ID = ID [;,] … ]` lists.
     *
     * @param bool $required Whether at least one list must follow.
     *
     * @return void
     */
    private function parseAttributeLists(bool $required): void
    {
        if ($required && !$this->isPunct('[')) {
            $this->fail('an attribute statement without an attribute list');
        }

        while ($this->isPunct('[')) {
            ++$this->position;

            while (!$this->isPunct(']')) {
                $this->expectId();
                $this->expectPunct('=');
                $this->expectId();

                if ($this->isPunct(';') || $this->isPunct(',')) {
                    ++$this->position;
                }
            }

            ++$this->position;
        }
    }

    /**
     * @param string $keyword A DOT keyword, lower-case.
     *
     * @return bool Whether the current token is that keyword, unquoted (DOT keywords are case-insensitive).
     */
    private function isKeyword(string $keyword): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return ($token !== null) && ($token[0] === 'id') && (strcasecmp($token[1], $keyword) === 0);
    }

    /**
     * @return bool Whether the current token is an ID (quoted string, bare identifier or numeral).
     */
    private function isId(): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return ($token !== null) && ($token[0] !== 'p');
    }

    /**
     * @param string $punct The punctuation to test for.
     *
     * @return bool Whether the current token is $punct.
     */
    private function isPunct(string $punct): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return ($token !== null) && ($token[0] === 'p') && ($token[1] === $punct);
    }

    /**
     * @return string The current token's value, which must be an ID; advances past it.
     */
    private function expectId(): string
    {
        if (!$this->isId()) {
            $this->fail('an attribute or edge without its identifier');
        }

        return $this->tokens[$this->position++][1];
    }

    /**
     * Requires $punct as the current token and advances past it.
     *
     * @param string $punct The expected punctuation.
     *
     * @return void
     */
    private function expectPunct(string $punct): void
    {
        if (!$this->isPunct($punct)) {
            $this->fail(sprintf('a missing `%s`', $punct));
        }

        ++$this->position;
    }

    /**
     * Refuses the document at the current token, exit 2.
     *
     * @param string $what What was found, as a noun phrase.
     *
     * @return never
     */
    private function fail(string $what): never
    {
        $token  = $this->tokens[$this->position] ?? null;
        $offset = $token !== null ? $token[2] : strlen($this->source);

        usageError(sprintf(
            '%s is not a Deptrac graphviz-dot layer graph: %s on line %d.',
            $this->label,
            $what,
            lineOf($this->source, $offset)
        ));
    }
}

/**
 * Finds every strongly connected component of more than one node (Tarjan's
 * algorithm, iterative, so a long dependency chain cannot exhaust the stack).
 *
 * @param list<list<int>> $successors The successor ids of every node id.
 *
 * @return list<list<int>> Each cyclic component's node ids, ascending, the components ordered by their first id.
 */
function findCyclicComponents(array $successors): array
{
    $nextIndex  = 0;
    $indices    = [];
    $lowLinks   = [];
    $onStack    = [];
    $stack      = [];
    $components = [];

    foreach (array_keys($successors) as $root) {
        if (isset($indices[$root])) {
            continue;
        }

        $indices[$root]  = $nextIndex;
        $lowLinks[$root] = $nextIndex;
        ++$nextIndex;
        $stack[]        = $root;
        $onStack[$root] = true;
        $work           = [[$root, 0]];

        while ($work !== []) {
            $top               = count($work) - 1;
            [$node, $position] = $work[$top];

            if ($position < count($successors[$node])) {
                $work[$top][1] = $position + 1;
                $successor     = $successors[$node][$position];

                if (!isset($indices[$successor])) {
                    $indices[$successor]  = $nextIndex;
                    $lowLinks[$successor] = $nextIndex;
                    ++$nextIndex;
                    $stack[]             = $successor;
                    $onStack[$successor] = true;
                    $work[]              = [$successor, 0];
                } elseif (isset($onStack[$successor])) {
                    $lowLinks[$node] = min($lowLinks[$node], $indices[$successor]);
                }

                continue;
            }

            array_pop($work);

            if ($work !== []) {
                $parent            = $work[count($work) - 1][0];
                $lowLinks[$parent] = min($lowLinks[$parent], $lowLinks[$node]);
            }

            if ($lowLinks[$node] !== $indices[$node]) {
                continue;
            }

            $component = [];

            do {
                $member = array_pop($stack);
                unset($onStack[$member]);
                $component[] = $member;
            } while ($member !== $node);

            if (count($component) > 1) {
                sort($component);
                $components[] = $component;
            }
        }
    }

    usort($components, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    return $components;
}

// $argv is only guaranteed under the CLI SAPI; `??` keeps a missing one a
// usage error instead of an undefined-variable notice.
$arguments = $argv ?? [];

if (count($arguments) !== 2) {
    usageError('usage: check-deptrac-cycles.php <dot-file> (the output of `deptrac analyse --formatter=graphviz-dot --output=<dot-file>`).');
}

$dotFile = $arguments[1];
$label   = safeReportValue($dotFile);

if (!is_file($dotFile)) {
    usageError(sprintf('%s does not exist or is not a file — did `deptrac analyse --formatter=graphviz-dot --output=…` run first?', $label));
}

$source = readCapped($dotFile, MAX_DOT_BYTES);

if ($source === false) {
    usageError(sprintf('%s cannot be read.', $label));
}

if ($source === null) {
    usageError(sprintf('%s is larger than the %d bytes this gate reads.', $label, MAX_DOT_BYTES));
}

$parser = new DotLayerGraphParser(tokenizeDot($source, $label), $source, $label);
$parser->parse();

if ($parser->nodes === []) {
    usageError(sprintf(
        '%s holds no layer at all — a vacuous graph proves nothing; check the `paths:` and `layers:` of the deptrac configuration.',
        $label
    ));
}

// Integer ids in name order: a numeric layer name ("1") would otherwise turn into
// an int array key, and a sorted id order makes the report deterministic.
$names = array_map(strval(...), array_keys($parser->nodes));
sort($names, \SORT_STRING);
$ids = array_flip($names);

$successors = array_fill(0, count($names), []);
$edgeCount  = 0;

foreach ($parser->edges as $from => $targets) {
    foreach (array_keys($targets) as $to) {
        $successors[$ids[(string) $from]][] = $ids[(string) $to];
        ++$edgeCount;
    }
}

foreach ($successors as $id => $targets) {
    sort($targets);
    $successors[$id] = $targets;
}

$components = findCyclicComponents($successors);

if ($components === []) {
    fwrite(\STDOUT, sprintf(
        "check-deptrac-cycles: OK — %d layer(s), %d layer dependency(ies), no cycle.\n",
        count($names),
        $edgeCount
    ));

    exit(0);
}

fwrite(\STDERR, sprintf(
    "check-deptrac-cycles: %d layer cycle(s) in %s — the layer graph must be acyclic:\n",
    count($components),
    $label
));

foreach ($components as $component) {
    $members = array_flip($component);
    $layers  = [];
    $inside  = [];

    foreach ($component as $id) {
        $layers[] = safeReportValue($names[$id]);

        foreach ($successors[$id] as $target) {
            if (isset($members[$target])) {
                $inside[] = safeReportValue($names[$id]) . ' -> ' . safeReportValue($names[$target]);
            }
        }
    }

    fwrite(\STDERR, sprintf("  - %s\n      via %s\n", implode(', ', $layers), implode(', ', $inside)));
}

exit(1);
