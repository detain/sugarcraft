<?php

declare(strict_types=1);

namespace SugarCraft\Query\Explain;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * MySQL EXPLAIN provider.
 *
 * Uses `EXPLAIN FORMAT=JSON` to get detailed query execution plan
 * and parses it into a tree structure compatible with ExplainView.
 */
final class MysqlExplainProvider implements ExplainProviderInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Execute EXPLAIN FORMAT=JSON and return parsed tree structure.
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

        $result = $this->db->query("EXPLAIN FORMAT=JSON {$sql}");

        if ($result === []) {
            return [];
        }

        return $this->parseJsonExplain($result);
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Parse EXPLAIN FORMAT=JSON output into flat detail rows.
     *
     * @param list<array<string,mixed>> $result Raw JSON explain result
     * @return list<array{detail:string}>
     */
    private function parseJsonExplain(array $result): array
    {
        $rows = [];

        foreach ($result as $row) {
            // EXPLAIN FORMAT=JSON returns a single JSON column
            foreach ($row as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $data = json_decode($value, true);
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
     * @param array<string,mixed> $node JSON tree node
     * @return list<array{detail:string}>
     */
    private function flattenJsonTree(array $node): array
    {
        $rows = [];
        $prefix = $node['query_block'] ?? null;

        if ($prefix !== null && is_array($prefix)) {
            $rows[] = ['detail' => $this->formatQueryBlock($prefix)];
            $rows = array_merge($rows, $this->extractOperations($prefix));
        }

        return $rows;
    }

    /**
     * Format query block summary as detail string.
     *
     * @param array<string,mixed> $block Query block data
     * @return string Formatted detail
     */
    private function formatQueryBlock(array $block): string
    {
        $selectId = $block['select_id'] ?? '?';
        $cost = $block['cost_info']['evaluated_cost'] ?? 'unknown';

        return "QUERY BLOCK #{$selectId} (cost: {$cost})";
    }

    /**
     * Extract operations from query block into detail rows.
     *
     * @param array<string,mixed> $block Query block
     * @return list<array{detail:string}>
     */
    private function extractOperations(array $block): array
    {
        $rows = [];

        foreach ($block as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            // Handle nested operations (joins, scans, etc.)
            if (in_array($key, ['join_conditions', 'access_type', 'used_columns'], true)) {
                continue;
            }

            if (is_array($value) && isset($value[0])) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $rows[] = ['detail' => $this->formatOperation($key, $item)];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Format a single operation as detail string.
     *
     * @param string $type Operation type
     * @param array<string,mixed> $data Operation data
     * @return string Formatted detail
     */
    private function formatOperation(string $type, array $data): string
    {
        $typeLabel = ucfirst($type);
        $table = $data['table'] ?? 'unknown';
        $accessType = $data['access_type'] ?? 'ALL';
        $key = $data['key'] ?? null;
        $keyPart = $data['key_part'] ?? null;

        $detail = "{$typeLabel}: {$table} ({$accessType})";

        if ($key !== null) {
            $detail .= " USING {$key}";
        }

        if ($keyPart !== null) {
            if (is_array($keyPart)) {
                $detail .= ' (' . implode(', ', $keyPart) . ')';
            } else {
                $detail .= " ({$keyPart})";
            }
        }

        return $detail;
    }
}
