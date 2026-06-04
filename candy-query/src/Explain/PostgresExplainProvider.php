<?php

declare(strict_types=1);

namespace SugarCraft\Query\Explain;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * PostgreSQL EXPLAIN provider.
 *
 * Uses `EXPLAIN (FORMAT JSON)` to get detailed query execution plan
 * and parses it into a tree structure compatible with ExplainView.
 */
final class PostgresExplainProvider implements ExplainProviderInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Execute EXPLAIN (FORMAT JSON) and return parsed tree structure.
     *
     * @param string $sql The SQL query to explain
     * @return list<array{detail:string}>
     */
    public function explain(string $sql): array
    {
        // Guard: empty SQL should not be explained
        if ($sql === '') {
            return [];
        }

        $result = $this->db->query("EXPLAIN (FORMAT JSON) {$sql}");

        if ($result === []) {
            return [];
        }

        return $this->parseJsonExplain($result);
    }

    public function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * Parse EXPLAIN (FORMAT JSON) output into flat detail rows.
     *
     * @param list<array<string,mixed>> $result Raw JSON explain result
     * @return list<array{detail:string}>
     */
    private function parseJsonExplain(array $result): array
    {
        $rows = [];

        foreach ($result as $row) {
            // PostgreSQL returns a single JSONB column
            foreach ($row as $value) {
                if (!is_string($value) && !is_array($value)) {
                    continue;
                }

                $data = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($data)) {
                    continue;
                }

                $rows = array_merge($rows, $this->flattenJsonTree($data));
            }
        }

        return $rows;
    }

    /**
     * Flatten JSON tree into detail strings for ExplainView parsing.
     *
     * @param array<string,mixed> $data Parsed JSON data
     * @return list<array{detail:string}>
     */
    private function flattenJsonTree(array $data): array
    {
        $rows = [];

        // PostgreSQL FORMAT JSON wraps in [ { "Plan": {...}} ]
        $plans = $data[0]['Plan'] ?? $data['Plan'] ?? $data;

        if (is_array($plans)) {
            $rows[] = ['detail' => $this->formatPlanSummary($plans)];
            $rows = array_merge($rows, $this->extractNodes($plans));
        }

        return $rows;
    }

    /**
     * Format plan summary as detail string.
     *
     * @param array<string,mixed> $plan Plan node data
     * @return string Formatted detail
     */
    private function formatPlanSummary(array $plan): string
    {
        $nodeType = $plan['Node Type'] ?? 'unknown';
        $cost = $plan['Total Cost'] ?? '?';
        $rows = $plan['Plan Rows'] ?? '?';

        return "{$nodeType} (cost: {$cost}, rows: {$rows})";
    }

    /**
     * Extract child nodes from plan tree.
     *
     * @param array<string,mixed> $plan Plan node
     * @return list<array{detail:string}>
     */
    private function extractNodes(array $plan): array
    {
        $rows = [];

        $this->collectNodes($plan, $rows, 0);

        return $rows;
    }

    /**
     * Recursively collect all nodes from plan tree.
     *
     * @param array<string,mixed> $node Current node
     * @param list<array{detail:string}> &$rows Accumulator
     * @param int $depth Current depth for indentation context
     */
    private function collectNodes(array $node, array &$rows, int $depth): void
    {
        $nodeType = $node['Node Type'] ?? null;
        $relationName = $node['Relation Name'] ?? null;
        $alias = $node['Alias'] ?? null;
        $actualRows = $node['Actual Rows'] ?? null;
        $actualLoops = $node['Actual Loops'] ?? null;

        $detail = '';

        if ($nodeType !== null) {
            $detail = $nodeType;
        }

        if ($relationName !== null) {
            $detail .= ': ' . $relationName;
            if ($alias !== null && $alias !== $relationName) {
                $detail .= " ({$alias})";
            }
        }

        if ($actualRows !== null) {
            $detail .= " [rows: {$actualRows}";
            if ($actualLoops !== null && $actualLoops > 1) {
                $detail .= ", loops: {$actualLoops}";
            }
            $detail .= ']';
        }

        if ($detail !== '') {
            // Prefix detail with depth indicator so ExplainView can render tree structure
            $depthPrefix = $depth > 0 ? "[d{$depth}] " : '';
            $rows[] = ['detail' => $depthPrefix . $detail];
        }

        // Process child nodes (Plans array in PostgreSQL explain output)
        $children = $node['Plans'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->collectNodes($child, $rows, $depth + 1);
                }
            }
        }
    }
}
