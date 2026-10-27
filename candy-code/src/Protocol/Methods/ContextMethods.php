<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\TrustAsks;

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
 * WEB-3b joined the measurement with the trust prompt: the same answer that
 * reports an untrusted project `.mcp.json` verdict is where that verdict is
 * DISCOVERED over the wire, so the first sighting mints the prompt once per
 * root ({@see TrustAsks}) — the discovery is a notification raised by a read,
 * the same posture as the launch notices the CLI prints while answering any
 * question (C-SEC-4 added the same discovery to `session.subscribe`, so the
 * prompt reaches a watcher without the panel having to mount first);
 * answering still takes a write, `context.trust`, under the
 * Approve scope, and the one grantable verdict (MCP) persists through the
 * one user-tier writer `Bootstrap::trustProjectMcp()` already owns.
 */
final class ContextMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('context.sources', Scope::Read, 'What the session\'s context is built from: instruction files, memory notes, skills, declared MCP servers, refused paths, and the token breakdown.', self::sources(...)));
        $registry->add(MethodSpec::new('context.trust', Scope::Approve, 'Answer a project-trust prompt (permission.asked with kind "trust"): grant or reject; a user-tier remember writes your own trustedProjectMcp list, a project-tier one is refused.', self::trust(...)));
    }

    /** @return array<string, mixed> */
    private static function sources(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $answer = $host->contextSources();
        $root = $host->workspace()->root;

        if (($answer['mcp']['status'] ?? null) === Bootstrap::MCP_UNTRUSTED) {
            $answer['trust'] = TrustAsks::discover(
                $root,
                Lang::t('server.trust.ask.mcp', ['root' => $root]),
                static function (array $record) use ($call, $sessionId): void {
                    $call->server->broadcast(EventEnvelope::server(EventType::PERMISSION_ASKED, ['sessionId' => $sessionId] + $record));
                },
            );
        } else {
            // Nothing to ask — but a prompt this root already settled still
            // tells the panel the truth (a session grant that expires with
            // the process, a rejection that must not nag).
            $answer['trust'] = TrustAsks::record($root);
        }

        return $answer;
    }

    /** @return array<string, mixed> */
    private static function trust(CallContext $call, Params $params): array
    {
        // C-SEC-4: the sessionId routes the answer to the process that minted
        // the prompt (a gateway's workspace host holds its own TrustAsks
        // statics) and roots it: settle() refuses a prompt from any other
        // root, so a misroute can never answer a different project's question.
        $sessionId = SessionMethods::sessionId($params);
        $root = $call->host($sessionId)->workspace()->root;
        $askId = $params->string('askId', 16);
        $decision = $params->enum('decision', ['grant', 'reject']);
        $remember = $params->has('remember') ? $params->enum('remember', ['session', 'user', 'project']) : null;

        $record = TrustAsks::settle($askId, $decision, $remember, $root);

        return [
            'askId' => $record['askId'],
            'status' => $record['status'],
            'persisted' => $record['persisted'],
        ];
    }
}
