<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\Scope;

/**
 * `context.sources` (WEB-3a): the `/context` panel's data as structure —
 * the instruction documents the prompt splices, the memory notes of both
 * stores, the skills inventory, the declared MCP servers, the paths the
 * loader refused, and the token breakdown. Read-only, nothing dialed,
 * nothing run: every section is a read of state the host already holds
 * ({@see \SugarCraft\Code\Host\ContextSources}).
 *
 * WHY A SESSION CALL. The breakdown is per-session — history, pruning
 * ledger and cache breaks belong to the host, and its calibrated history
 * estimate lives there, not in a recomputable global. A client wanting the
 * workspace halves without a session can read `memory.list`, the skills
 * prompt listing and `candy-code mcp list` surfaces it already has.
 *
 * The trust prompt (WEB-3b) is NOT here by ruling: this method answers the
 * read surface; the write round-trip rides W5.
 */
final class ContextMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('context.sources', Scope::Read, 'What the session\'s context is built from: instruction files, memory notes, skills, declared MCP servers, refused paths, and the token breakdown.', self::sources(...)));
    }

    /** @return array<string, mixed> */
    private static function sources(CallContext $call, Params $params): array
    {
        return $call->host(SessionMethods::sessionId($params))->contextSources();
    }
}
