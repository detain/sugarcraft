<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\ReportsPromptSections;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Memory\MemoryEntry;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Protocol\Methods\MemoryMethods;

/**
 * WEB-3a: the structured half of `/context` — one pure-data builder that
 * answers `context.sources` AND measures the `/context` panel's breakdown, so
 * the two surfaces can never fork the measurement (VIS-2a's single-writer
 * law, extended to the wire).
 *
 * WHAT IT IS NOT. No second estimate of anything: the history figure is the
 * caller's (the status bar's, carrying the session's calibration), the
 * instruction documents are the loader's own record, memory rows are the
 * `memory.list` item shape, and the MCP rows are `Bootstrap::mcpServerInventory()`
 * — the read-never-run census, with its `detail` field DROPPED: an http
 * entry's detail is a URL, and `server.hello` deliberately publishes only a
 * sha256 prefix of SD hosts, so the same restraint applies here.
 *
 * The trust-prompt half (WEB-3b, roadmap W5 — since C-SEC-5.b the `trusts`
 * list of the launch census) is NOT here: this answers the read surface only.
 */
final class ContextSources
{
    private function __construct()
    {
    }

    /**
     * The session breakdown `/context` reports and `context.sources` rides:
     * prompt layers from a backend that assembles its own prompt, tool schemas
     * from an engine backend, the ledger's pruning, and the cache-break watch.
     *
     * $historyTokens overrides the context's own figure — a {@see SessionHost}
     * passes its calibrated estimate (the status bar's), while a driver that
     * only has the context's number passes null and gets that.
     *
     * @param array{files: list<array{path: string, bytes: int, personal: bool}>, refused: list<array{path: string, reason: string}>}|null $instructionFiles
     */
    public static function measure(
        CommandContext $context,
        ?array $instructionFiles,
        ?int $historyTokens = null,
    ): ContextBreakdown {
        $backend = $context->backend;
        $sections = null;
        if ($backend instanceof ReportsPromptSections) {
            try {
                $sections = $backend->promptSectionSizes();
            } catch (\Throwable) {
                // A layer that fails to build here fails the next turn too and
                // is reported there; this read-only panel just says "not measured".
                $sections = null;
            }
        }
        // P-CTX.a: price what the NEXT request carries — the shaped turn list
        // (promptTools()), the same list the section rows were built with,
        // not the unbound launch list tools() answers with.
        $tools = $backend instanceof EngineBackend ? $backend->promptTools() : null;
        $compactorConfig = $backend instanceof EngineBackend ? $backend->compactorConfig() : null;

        $breakdown = ContextBreakdown::measure(
            $context->history,
            $sections,
            $tools,
            (int) $context->contextTokenLimit,
            $historyTokens ?? (int) $context->contextTokens,
            instructionFiles: $instructionFiles,
            compactorConfig: $compactorConfig,
        )
            // Roadmap 5.6 remainder: what the session's ledger prunes out.
            ->withPruning($context->contextLedger(), $context->history);
        if ($backend instanceof EngineBackend) {
            // Roadmap 3.B-5: the session's prompt-cache breaks, off the
            // watch every clone of the backend shares.
            $breakdown = $breakdown->withCacheBreaks($backend->cacheBreaks(), $backend->lastCacheBreak());
        }

        return $breakdown;
    }

    /**
     * The instruction documents this root's prompt splices, as names and
     * sizes only (roadmap VIS-2a): {@see Bootstrap::instructionLoader()} is
     * the memoized instance the system prompt itself is built from, so the
     * panel reads the loader's own record. A loader that cannot be built
     * leaves the figure null — the unknown-honesty rule every other part of
     * the panel follows.
     *
     * @return array{files: list<array{path: string, bytes: int, personal: bool}>, refused: list<array{path: string, reason: string}>}|null
     */
    public static function priceInstructionFiles(?string $root): ?array
    {
        try {
            $loader = Bootstrap::instructionLoader($root);
            $files = [];
            foreach ($loader->loadDocuments() as $doc) {
                $files[] = ['path' => $doc['path'], 'bytes' => $doc['bytes'], 'personal' => false];
            }
            foreach ($loader->loadPersonal() as $doc) {
                $files[] = ['path' => $doc['path'], 'bytes' => $doc['bytes'], 'personal' => true];
            }
            $refused = [];
            foreach ($loader->refusedPaths() as $path => $reason) {
                $refused[] = ['path' => (string) $path, 'reason' => (string) $reason];
            }

            return ['files' => $files, 'refused' => $refused];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The whole `context.sources` answer. The session half rides $breakdown
     * (null when no session is on screen — every session figure is then
     * honestly absent rather than zero); the workspace half is read from
     * $workspace. $mcpInventory is injectable so tests pin the shaping
     * without a live `.mcp.json`; null runs the read-never-run census —
     * C-MCP-4.a feeds it the FULL nine-key
     * {@see \SugarCraft\Code\Cli\McpDecision::mcpServerInventory()} shape,
     * so both tiers' rows reach the wire.
     *
     * MCP-3 (dormant-wired here): user-tier rows append after the project
     * rows, each badged with its `tier`; a name the project tier declares
     * never repeats as a user row because the census already shadows it
     * (the launch's precedence, mirrored on the listing). $mcpLiveness is
     * injectable for the same reason as $mcpInventory; null reads
     * {@see Bootstrap::mcpLivenessSnapshot()} — MEMO ONLY, never a launch,
     * so a process holding no client answers null on every row and a
     * declared-but-not-started server answers a row with null liveness.
     *
     * @param array{files: list<array{path: string, bytes: int, personal: bool}>, refused: list<array{path: string, reason: string}>}|null $instructionFiles
     * @param array{status: string, path: string, servers: list<array{name: string, type: string, detail: string}>, skipped: list<string>, error: string|null, userStatus: string, userPath: string, userServers: list<array{name: string, type: string, detail: string}>, userSkipped: list<string>, userError: string|null}|null $mcpInventory
     * @param array<string, array{transport: string, up: bool|null, tools: int}>|null $mcpLiveness
     *
     * @return array<string, mixed>
     */
    public static function answer(
        ?WorkspaceContext $workspace,
        ?ContextBreakdown $breakdown,
        ?array $instructionFiles,
        ?array $mcpInventory = null,
        ?array $mcpLiveness = null,
    ): array {
        $files = $instructionFiles === null ? null : array_map(
            static fn (array $file): array => [
                'path' => $file['path'],
                'bytes' => $file['bytes'],
                'tier' => $file['personal'] ? 'personal' : 'project',
            ],
            $instructionFiles['files'],
        );
        $untrusted = $instructionFiles === null ? [] : $instructionFiles['refused'];
        $mcp = $mcpInventory ?? Bootstrap::mcpServerInventory($workspace?->root);
        $liveness = $mcpLiveness ?? Bootstrap::mcpLivenessSnapshot($workspace?->root);

        // C-MCP-4.a: both tiers ride ONE row builder, so the wire cannot
        // drift into saying different things about the two files it read —
        // the same law the census itself carries (mcpConfigRows). The
        // liveness map is keyed by server name across both tiers; the
        // shadowing makes the keys disjoint, so one lookup serves both.
        $mcpRow = static function (array $server, string $tier) use ($liveness): array {
            $live = $liveness[$server['name']] ?? null;

            return [
                'name' => $server['name'],
                'transport' => $server['type'],
                // The census only lists servers when the file is trusted,
                // so every row here is a trusted entry; the file-level
                // verdict rides `status`/`userStatus` above.
                'trust' => 'trusted',
                'tier' => $tier,
                'liveness' => $live === null ? null : ['up' => $live['up'], 'tools' => $live['tools']],
            ];
        };
        $mcpRows = [];
        foreach ($mcp['servers'] as $server) {
            $mcpRows[] = $mcpRow($server, 'project');
        }
        foreach ($mcp['userServers'] as $server) {
            $mcpRows[] = $mcpRow($server, 'user');
        }

        return [
            'instructionFiles' => $files,
            'untrusted' => $untrusted,
            'memorySections' => self::memorySections($workspace),
            'skills' => array_map(static fn (\SugarCraft\Code\Skills\Skill $skill): array => [
                'name' => $skill->name,
                'tier' => $skill->origin->value,
                'enabled' => !($workspace?->skills?->isDisabled($skill->name) ?? false),
            ], $workspace?->skills?->all() ?? []),
            'mcp' => [
                'status' => $mcp['status'],
                'error' => $mcp['error'],
                'skipped' => $mcp['skipped'],
                'userStatus' => $mcp['userStatus'],
                'userError' => $mcp['userError'],
                'userSkipped' => $mcp['userSkipped'],
                'servers' => $mcpRows,
            ],
            'breakdown' => $breakdown === null ? null : self::serialize($breakdown),
        ];
    }

    /**
     * Every note of both stores across all scopes — the `memory.list` grouping
     * rule (repo rows lead, home rows follow) widened to the full listing. A
     * workspace with no memory store answers empty, not an error: the panel
     * shows the other five sections either way.
     *
     * @return list<array<string, mixed>>
     */
    private static function memorySections(?WorkspaceContext $workspace): array
    {
        $store = $workspace?->memoryStore;
        if ($store === null) {
            return [];
        }
        $writer = MemoryWriter::new($store, $workspace?->root ?? '');
        $items = [];
        $append = static function (?MemoryStore $reader, string $source) use (&$items): void {
            if ($reader === null) {
                return;
            }
            foreach (MemoryMethods::SCOPES as $scope) {
                foreach ($reader->list($scope) as $entry) {
                    $items[] = $entry->toArray() + ['source' => $source];
                }
            }
        };
        $append($writer->repository(), MemoryMethods::SOURCE_REPO);
        $append($writer->home(), MemoryMethods::SOURCE_HOME);

        return $items;
    }

    /**
     * The breakdown as a plain array — the value object's own fields, one
     * serialization, schema-checked by ContextSourcesTest through the
     * fixtures a real session produces.
     *
     * P-CTX.f adds the category attribution (roadmap P-CTX.a) to the wire:
     * `categories` rides {@see ContextCategories::from()} — the SAME tokens
     * the per-layer rows already serialize, just billed to who owns them —
     * with each row's `share` the whole-percent of the window (the rounding
     * law every other percentage on this surface follows). `buffer` is the
     * autocompact reservation lifted to the top level for the meter panels,
     * and `mcpServers` the bridge grouping the breakdown measured in its one
     * pass over the priced tool list. Nothing here re-measures: unknown parts
     * stay null, and the per-server / per-document / per-message sub-rows
     * carry exactly the fields {@see ContextCategories} shaped — bytes for the
     * documents priced by size, the not-sent note for server instructions.
     *
     * @return array<string, mixed>
     */
    private static function serialize(ContextBreakdown $b): array
    {
        $categories = [];
        foreach (ContextCategories::from($b)->rows as $row) {
            $tokens = $row['tokens'];
            $categories[] = [
                'key' => $row['category']->value,
                'label' => $row['category']->label(),
                'tokens' => $tokens,
                'share' => $tokens === null || $b->window <= 0
                    ? null
                    : (int) round($tokens * 100 / $b->window),
                'sub' => array_map(static fn (array $sub): array => [
                    'label' => $sub['label'],
                    'tokens' => $sub['tokens'],
                    'bytes' => $sub['bytes'],
                    'personal' => $sub['personal'],
                    'note' => $sub['note'],
                ], $row['sub']),
            ];
        }

        return [
            'window' => $b->window,
            'sections' => $b->sections,
            'toolCount' => $b->toolCount,
            'toolTokens' => $b->toolTokens,
            'historyTokens' => $b->historyTokens,
            'historyMessages' => $b->historyMessages,
            'uiOnlyRows' => $b->uiOnlyRows,
            'largest' => $b->largest,
            'lastCache' => $b->lastCache,
            'sessionCache' => $b->sessionCache,
            'pruning' => $b->pruning,
            'cacheBreaks' => $b->cacheBreaks,
            'categories' => $categories,
            'buffer' => ContextCategories::bufferTokens($b),
            'mcpServers' => $b->mcpServers,
        ];
    }
}
