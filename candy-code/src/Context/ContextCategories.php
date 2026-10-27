<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Util\TokenEstimate;

/**
 * The same measured tokens, attributed by category — the Claude Code-style
 * `By category` block `/context` opens with (roadmap P-CTX.a).
 *
 * The per-layer rows answer "how big is each prompt section"; this answers
 * "who to bill" — the harness's own voice, the MCP bridges, the custom-agent
 * roster, the repository's instructions, memory, skills, the conversation —
 * over EXACTLY the same tokens, never a second measurement. The summing law
 * that makes the two readings interchangeable: on a fully measured breakdown,
 * every category except the autocompact buffer and free space sums to
 * {@see ContextBreakdown::totalTokens()}, and content + buffer + free space
 * is the window (or free space is 0, because the {@see ContextCategory}
 * mapping leaves no measured byte unattributed).
 *
 * WHAT IS ESTIMATED AND WHAT IS SPLIT EXACTLY. The tool half splits exactly:
 * {@see TokenEstimate::ofToolSchemas()} prices per tool and sums, so
 * built-in + MCP is the same arithmetic as the whole. The agent-roster half
 * carves from the tool-guidance SECTION row, which was estimated as a whole —
 * a substring of estimated text costs at most the text that contains it
 * (weights are per-character sums under a single ceil), so the carve leaves
 * System prompt non-negative and the two rows still sum to the section total.
 *
 * WHAT IS UNKNOWN STAYS NULL, like the breakdown itself: a command backend
 * that reports neither prompt layers nor tools gets "not measured" category
 * rows, never an invented zero. Two zeroes ARE measured absences though:
 * the autocompact buffer of a session whose compaction is OFF is a real 0
 * (the window reserves nothing), and a tool list with no bound roster really
 * spends nothing on custom agents. And the MCP server instructions row says
 * what is true of this build: server instructions are never sent at all, so
 * nothing is measured — and nothing is silently missing either.
 */
final readonly class ContextCategories
{
    /**
     * @param list<array{category: ContextCategory, tokens: ?int, sub: list<array{label: string, tokens: ?int, bytes: ?int, personal: bool, note: ?string}>}> $rows
     *        one row per {@see ContextCategory} case, in case order; `sub`
     *        rows carry a server/file/message name plus its tokens, or its
     *        bytes alone (instruction documents are priced by size only —
     *        bodies never reach this surface), or a `note` that replaces the
     *        token column with the reason there is no token figure
     */
    private function __construct(public array $rows)
    {
    }

    /**
     * Attribute a measured breakdown. Reads only fields the breakdown itself
     * measured ({@see ContextBreakdown::measure()}); it never re-walks the
     * history or re-prices a schema, so the block and the per-layer rows
     * cannot drift from each other.
     */
    public static function from(ContextBreakdown $b): self
    {
        $sections = $b->sections;
        $agents = $b->agentsTokens;

        if ($sections === null) {
            $system = $memory = $project = $repo = $skills = null;
        } else {
            $system = $memory = $project = $repo = $skills = 0;
            foreach ($sections as $row) {
                switch (ContextCategory::forSectionLabel($row['label'])) {
                    case ContextCategory::SystemPrompt:
                        $system += $row['tokens'];
                        break;
                    case ContextCategory::MemoryFiles:
                        $memory += $row['tokens'];
                        break;
                    case ContextCategory::ProjectMemory:
                        $project += $row['tokens'];
                        break;
                    case ContextCategory::RepositoryMap:
                        $repo += $row['tokens'];
                        break;
                    default:
                        // ContextCategory::Skills — the mapping's two skill
                        // labels are the only ones left in its match arm.
                        $skills += $row['tokens'];
                }
            }
            if ($agents !== null) {
                // The roster rides the tool-guidance section but is billed to
                // its own category (P-CTX.a) — subtracting here, from the same
                // sections total, keeps the two rows summing to exactly what
                // the layers measured.
                $system = max(0, $system - $agents);
            }
        }

        $mcp = $b->mcpToolTokens;
        $builtins = $b->toolTokens === null || $mcp === null ? null : $b->toolTokens - $mcp;

        $serversTotal = $b->mcpServers === null ? 0 : array_sum(array_column($b->mcpServers, 'tokens'));
        $mcpSub = [];
        foreach ($b->mcpServers ?? [] as $server) {
            $mcpSub[] = self::sub($server['name'], $server['tokens']);
        }
        if ($mcp !== null) {
            $mcpSub[] = self::sub('mcp_list/mcp_call', max(0, $mcp - $serversTotal));
            $mcpSub[] = self::sub(
                Lang::t('cmd.context.category.mcp.instructions'),
                null,
                note: Lang::t('cmd.context.category.mcp.not-sent'),
            );
        }

        $messages = max(0, $b->historyTokens - $b->prunedTokens());
        $messageSub = [];
        foreach ($b->largest as $row) {
            $messageSub[] = self::sub('#' . $row['index'] . ' ' . $row['role'], $row['tokens']);
        }

        $fileSub = [];
        foreach ($b->instructionFiles['files'] ?? [] as $file) {
            $fileSub[] = self::sub($file['path'], null, bytes: $file['bytes'], personal: $file['personal']);
        }

        $content = ($system ?? 0) + ($builtins ?? 0) + ($mcp ?? 0) + ($agents ?? 0)
            + ($memory ?? 0) + ($project ?? 0) + ($repo ?? 0) + ($skills ?? 0) + $messages;
        $buffer = self::bufferTokens($b);
        $free = max(0, $b->window - $content - ($buffer ?? 0));

        return new self([
            ['category' => ContextCategory::SystemPrompt, 'tokens' => $system, 'sub' => []],
            ['category' => ContextCategory::SystemTools, 'tokens' => $builtins, 'sub' => []],
            ['category' => ContextCategory::McpTools, 'tokens' => $mcp, 'sub' => $mcpSub],
            ['category' => ContextCategory::CustomAgents, 'tokens' => $agents, 'sub' => []],
            ['category' => ContextCategory::MemoryFiles, 'tokens' => $memory, 'sub' => $fileSub],
            ['category' => ContextCategory::ProjectMemory, 'tokens' => $project, 'sub' => []],
            ['category' => ContextCategory::RepositoryMap, 'tokens' => $repo, 'sub' => []],
            ['category' => ContextCategory::Skills, 'tokens' => $skills, 'sub' => []],
            ['category' => ContextCategory::Messages, 'tokens' => $messages, 'sub' => $messageSub],
            ['category' => ContextCategory::AutocompactBuffer, 'tokens' => $buffer, 'sub' => []],
            ['category' => ContextCategory::FreeSpace, 'tokens' => $free, 'sub' => []],
        ]);
    }

    /**
     * Split a priced tool list into the MCP and custom-agent shares — the
     * measurement {@see ContextBreakdown::measure()} stores so every surface
     * reads the same attribution off one pass over the same list the request
     * ships.
     *
     * Bridges are recognised by their wire name: {@see
     * \SugarCraft\Code\MCP\McpToolBridge::wireNameFor()} sanitises each
     * segment so it can never carry a `__`, which makes the first `__` after
     * the `mcp__` prefix an unambiguous server/tool cut. The two dispatchers
     * ride the MCP category too — they exist only because servers exist —
     * but belong to no single server, so they land in the block's dispatch
     * sub-row rather than a per-server one.
     *
     * @param iterable<Tool> $tools
     *
     * @return array{mcpCount: int, mcpTokens: int, builtinCount: int, builtinTokens: int, servers: list<array{name: string, count: int, tokens: int}>, dispatchTokens: int, agentsTokens: int}
     */
    public static function splitTools(iterable $tools): array
    {
        $mcpCount = $mcpTokens = $builtinCount = $builtinTokens = $dispatchTokens = 0;
        $agentsTokens = 0;
        $servers = [];

        foreach ($tools as $tool) {
            $name = $tool->name();
            $tokens = TokenEstimate::ofToolSchema($name, $tool->description(), $tool->inputSchema());

            if ($tool instanceof McpListTool || $tool instanceof McpCallTool) {
                $mcpCount++;
                $mcpTokens += $tokens;
                $dispatchTokens += $tokens;
            } elseif (($server = self::mcpServerOf($name)) !== null) {
                $mcpCount++;
                $mcpTokens += $tokens;
                $key = (string) $server;
                $servers[$key] ??= ['name' => $key, 'count' => 0, 'tokens' => 0];
                $servers[$key]['count']++;
                $servers[$key]['tokens'] += $tokens;
            } else {
                $builtinCount++;
                $builtinTokens += $tokens;
            }

            if ($tool instanceof TaskTool) {
                // The roster is the session's own agent list spliced into the
                // spawn doctrine (ISS-032); an unbound Task renders the
                // doctrine alone and spends 0 here — the fence never opens.
                $guidance = $tool->promptGuidance();
                $open = strpos($guidance, TaskTool::AGENTS_OPEN);
                if ($open !== false) {
                    $agentsTokens += TokenEstimate::ofText(substr($guidance, $open));
                }
            }
        }

        return [
            'mcpCount' => $mcpCount,
            'mcpTokens' => $mcpTokens,
            'builtinCount' => $builtinCount,
            'builtinTokens' => $builtinTokens,
            'servers' => array_values($servers),
            'dispatchTokens' => $dispatchTokens,
            'agentsTokens' => $agentsTokens,
        ];
    }

    /** The server behind a bridge wire name, null for anything that is not one. */
    private static function mcpServerOf(string $name): ?string
    {
        if (!str_starts_with($name, 'mcp__')) {
            return null;
        }
        $rest = substr($name, 5);

        $pos = strpos($rest, '__');

        return $pos === false || $pos === 0 ? $rest : substr($rest, 0, $pos);
    }

    /**
     * The reserved room between where background compaction fires and the
     * window's end — 0 when compaction is off (nothing reserves), null when
     * the session reports no compaction config at all.
     */
    public static function bufferTokens(ContextBreakdown $b): ?int
    {
        $config = $b->compactorConfig;
        if ($config === null) {
            return null;
        }
        if (!$config->autoCompacts()) {
            return 0;
        }

        return max(0, $b->window - $config->backgroundCompactionTokenThreshold($b->window));
    }

    /** Every billed category except the reservation and what is left — the totalTokens() half. */
    public function contentTokens(): int
    {
        $sum = 0;
        foreach ($this->rows as $row) {
            if ($row['category'] === ContextCategory::AutocompactBuffer || $row['category'] === ContextCategory::FreeSpace) {
                continue;
            }
            $sum += $row['tokens'] ?? 0;
        }

        return $sum;
    }

    /** The tokens of one category's row, null when it was not measured. */
    public function tokensOf(ContextCategory $category): ?int
    {
        foreach ($this->rows as $row) {
            if ($row['category'] === $category) {
                return $row['tokens'];
            }
        }

        return null;
    }

    /**
     * One bar segment per row, in row order, apportioned by the largest
     * remainder method so the segments sum to EXACTLY $cells whenever any
     * row carries tokens (all-zero rows get an empty bar rather than a
     * fabricated first segment). Null rows weigh 0 — an unmeasured category
     * claims no space it cannot account for.
     *
     * @return list<string>
     */
    public function bar(int $cells): array
    {
        $cells = max(0, $cells);
        $weights = array_map(static fn (array $row): int => $row['tokens'] ?? 0, $this->rows);
        $total = array_sum($weights);
        if ($cells === 0 || $total === 0) {
            return array_fill(0, count($weights), '');
        }

        $base = [];
        $fractions = [];
        $used = 0;
        foreach ($weights as $i => $weight) {
            $exact = $weight * $cells / $total;
            $base[$i] = (int) floor($exact);
            $used += $base[$i];
            $fractions[$i] = $exact - $base[$i];
        }

        // Spare cells to the largest fractions; row order breaks a tie. The
        // spares never exceed the row count: their sum is a sum of fractions.
        $order = array_keys($weights);
        usort($order, static fn (int $a, int $b): int => [$fractions[$b], $a] <=> [$fractions[$a], $b]);
        for ($k = 0, $spare = $cells - $used; $spare > 0; $spare--, $k++) {
            $base[$order[$k]]++;
        }

        return array_map(static fn (int $n): string => str_repeat('█', max(0, $n)), $base);
    }

    /**
     * @return array{label: string, tokens: ?int, bytes: ?int, personal: bool, note: ?string}
     */
    private static function sub(string $label, ?int $tokens, ?int $bytes = null, bool $personal = false, ?string $note = null): array
    {
        return ['label' => $label, 'tokens' => $tokens, 'bytes' => $bytes, 'personal' => $personal, 'note' => $note];
    }
}
