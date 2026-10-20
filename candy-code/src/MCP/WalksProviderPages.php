<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * MCP-4 (audit re-run F-2): the ONE cursor-walk and parsed-envelope gate for
 * the paginated provider rosters (resources/list, prompts/list).
 *
 * Before this hoist the loop was minted three times — verbatim in
 * HttpMcpServer and SseMcpServer, and word-for-word identical in its CONTROL
 * flow in StdioMcpServer — over a transport call and two refusal wordings
 * that are the only things genuinely per-transport. Drift in one copy (an
 * off-by-one, a forgotten rows defence) would have silently diverged how the
 * three transports answer the same pagination question.
 *
 * The law this trait keeps:
 * - every page is gated through `$page` BEFORE its rows are trusted;
 * - a non-array container or non-array row is dropped, never warned on
 *   (the parseTools lesson, measured on the tools path);
 * - a cursor that comes back twice is a server lying about its pagination —
 *   refuse by name, mid-walk;
 * - a cursor still issued at the page wall is a KNOWN-TRUNCATED roster —
 *   refuse, because missing entries are invisible downstream (ISS-093(b)).
 *
 * The two refusal wordings ride in as closures so each transport keeps its
 * exact shipped message bytes: HTTP/SSE speak `$method`-first, Stdio embeds
 * the server name first. StdioMcpServer additionally SHADOWS this trait's
 * {@see requireProviderResult()} with its own McpMessage-shaped gate (PHP
 * precedence: a method declared in the class wins over the trait's) — the
 * stdio reply is a typed envelope, not a parsed array, and its refusal family
 * names the server; see the docblock on that method.
 */
trait WalksProviderPages
{
    /**
     * Walk a paginated provider list to its end. `$page` receives the cursor
     * params (`null` on the first page) and returns the already-gated result
     * map; the refusal closures receive the repeating cursor and its page
     * number, and nothing for the page-wall refusal.
     *
     * @param callable(?array<string,mixed>): array<mixed> $page
     * @param callable(string, int): string $loopedRefusal
     * @param callable(): string $truncatedRefusal
     * @return list<array<mixed>>
     */
    private function walkProviderPages(string $key, int $maxPages, callable $page, callable $loopedRefusal, callable $truncatedRefusal): array
    {
        $entries = [];
        $seenCursors = [];
        $params = null;

        for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
            $result = $page($params);

            $rows = $result[$key] ?? [];

            if (!is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $entries[] = $row;
                }
            }

            $cursor = $result['nextCursor'] ?? null;

            if (!is_string($cursor) || $cursor === '') {
                return $entries;
            }

            if (isset($seenCursors[$cursor])) {
                throw new \RuntimeException($loopedRefusal($cursor, $pageNo));
            }
            $seenCursors[$cursor] = true;
            $params = ['cursor' => $cursor];
        }

        throw new \RuntimeException($truncatedRefusal());
    }

    /**
     * The one gate every provider page and every read/get answer passes, in
     * the wording family `requireToolsListPage()` established for the tools
     * roster (HTTP/SSE envelope shape; Stdio shadows this with its own gate).
     *
     * @param array<mixed>|null $data
     * @return array<mixed>
     */
    private function requireProviderResult(string $method, ?array $data): array
    {
        if ($data === null) {
            throw new \RuntimeException($method . ' returned an invalid response');
        }

        if (isset($data['error'])) {
            throw new \RuntimeException($method . ' refused' . self::describeError($data['error']));
        }

        if (!array_key_exists('result', $data)) {
            throw new \RuntimeException($method . ' returned no result');
        }

        $result = $data['result'];

        if (!is_array($result)) {
            throw new \RuntimeException($method . ' returned a malformed result');
        }

        return $result;
    }
}
