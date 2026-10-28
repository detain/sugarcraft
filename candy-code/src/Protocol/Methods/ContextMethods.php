<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
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
 * reports a pending project-trust verdict is where that verdict is DISCOVERED
 * over the wire, so the first sighting mints that target's prompt once per
 * (root, target) ({@see TrustAsks}) — the discovery is a notification raised
 * by a read, the same posture as the launch notices the CLI prints while
 * answering any question (C-SEC-4 added the same discovery to
 * `session.subscribe`, so prompts reach a watcher without the panel having to
 * mount first); answering still takes a write, `context.trust`, under the
 * Approve scope. C-SEC-5.b widened the answer from the one MCP verdict to the
 * whole 5.a launch census: every pending target (`.mcp.json`, a drifted pin
 * re-approval, hooks, command shell forms, the project settings layer) mints
 * its own row of the `trusts` list, and a grant persists through the census's
 * own writer door, `Bootstrap::grantProjectTrust()`.
 */
final class ContextMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('context.sources', Scope::Read, 'What the session\'s context is built from: instruction files, memory notes, skills, declared MCP servers, refused paths, the token breakdown, and this root\'s pending project-trust prompts.', self::sources(...)));
        $registry->add(MethodSpec::new('context.trust', Scope::Approve, 'Answer one project-trust prompt (permission.asked with kind "trust") by its askId: grant or reject; a user-tier remember writes the answering prompt\'s target list (trustedProjectMcp for an mcp or mcp-pin row, the matching trustedProject* key for the others) in your own config, a project-tier one is refused.', self::trust(...)));
    }

    /** @return array<string, mixed> */
    private static function sources(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $answer = $host->contextSources();
        $root = $host->workspace()->root;

        // C-SEC-5.b: the 5.a launch census replaces the single MCP verdict.
        // Every pending target mints (once) and rides the answer as its own
        // row; settled rows restate their truth so a session grant that
        // expires with the process, or a rejection that must not nag, still
        // shows on the panel.
        TrustAsks::discoverPending($root, static function (array $record) use ($call, $sessionId): void {
            $call->server->broadcast(EventEnvelope::server(EventType::PERMISSION_ASKED, ['sessionId' => $sessionId] + $record));
        });
        $answer['trusts'] = TrustAsks::records($root);

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
        $host = $call->host($sessionId);
        $root = $host->workspace()->root;
        $askId = $params->string('askId', 16);
        $decision = $params->enum('decision', ['grant', 'reject']);
        $remember = $params->has('remember') ? $params->enum('remember', ['session', 'user', 'project']) : null;

        // C-SEC-5.b: the answering session's own settings writer is the door
        // non-MCP grants land in (the SettingsMethods precedent — the same
        // service the live settings surface uses); settle() falls back to the
        // launch's writer when a workspace carries none, and the MCP door
        // never consults it.
        $service = $host->workspace()->service(SettingsWriter::class);
        $record = TrustAsks::settle($askId, $decision, $remember, $root, $service instanceof SettingsWriter ? $service : null);

        return [
            'askId' => $record['askId'],
            'status' => $record['status'],
            'persisted' => $record['persisted'],
        ];
    }
}
