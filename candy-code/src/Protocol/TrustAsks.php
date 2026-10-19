<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use SugarCraft\Code\Cli\Bootstrap;

/**
 * WEB-3b: the project-trust prompts this server has put — one per untrusted
 * root, for as long as the process lives.
 *
 * WHY A REGISTRY AND NOT A TURN QUESTION. A tool-permission ask blocks a
 * running turn, rides the host's pendingAsks, times out with the feed's ask
 * timer and is swept `cancelled` when its turn ends. A trust prompt is none of
 * those things: nothing waits on it, it must survive every turn boundary, and
 * answering it writes the USER tier (a project that could write its own trust
 * list would grant itself — the `permission.respond` refusal this class mirrors
 * verbatim in kind). So the prompts live here, keyed by the canonical root the
 * w4i census verdict is about, and `context.trust` answers them —
 * `permission.respond` answering a trust id is an `ask_not_found` by
 * construction, which is the honest answer, not a leak into the gate.
 *
 * THE ONCE LAW. {@see discover()} is called from every `context.sources`
 * measurement; the first untrusted sighting mints the ask and broadcasts it
 * server-scope (the BackgroundEvents precedent: an ask outside any turn still
 * rides `permission.asked`), every later sighting — reloads, other sessions,
 * reconnects — re-reads the same record. The answer settles it for the
 * process; a rejection is a decision, not a question to be nagged about.
 */
final class TrustAsks
{
    /** The one grantable target today: the project `.mcp.json` census verdict. */
    public const TARGET_MCP = 'mcp';

    /** @var array<string, array<string, mixed>> canonical root => the prompt record */
    private static array $byRoot = [];

    /** @var array<string, string> askId => the root its prompt is about */
    private static array $byAsk = [];

    private function __construct()
    {
    }

    /**
     * The prompt for $root, minting and announcing it on first sighting.
     *
     * @param \Closure(array<string, mixed>): void $broadcast receives the fresh record exactly once
     *
     * @return array<string, mixed> the prompt record (the `TrustPrompt` shape)
     */
    public static function discover(string $root, string $reason, \Closure $broadcast): array
    {
        $existing = self::$byRoot[$root] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        $record = [
            'askId' => 'trust-' . \substr(\bin2hex(\random_bytes(8)), 0, 9),
            'kind' => 'trust',
            'target' => self::TARGET_MCP,
            'root' => $root,
            'reason' => $reason,
            'status' => 'asked',
            'persisted' => false,
        ];
        self::$byRoot[$root] = $record;
        self::$byAsk[$record['askId']] = $root;
        $broadcast($record);

        return $record;
    }

    /**
     * The prompt $root already has, without minting one — the answer surface
     * reads this when the verdict is no longer grantable-but-asked (trusted,
     * absent, outside-tree, disabled) so a settled grant still shows its truth.
     *
     * @return array<string, mixed>|null
     */
    public static function record(string $root): ?array
    {
        return self::$byRoot[$root] ?? null;
    }

    /**
     * Settle $askId. The record only ever moves from `asked` once; every
     * later answer is the same refusal `permission.respond` gives a settled
     * question, and a project-tier remember is the same refusal it gives a
     * project-tier rule.
     *
     * @param 'grant'|'reject' $decision
     * @param 'session'|'user'|'project'|null $remember
     *
     * @return array<string, mixed> the settled record
     *
     * @throws RpcError remember_refused / ask_not_found / already_resolved
     */
    public static function settle(string $askId, string $decision, ?string $remember): array
    {
        if ($remember === 'project') {
            throw RpcError::of(ErrorCode::Forbidden, 'trust is user-tier only; a project cannot be told to trust itself', 'remember_refused');
        }

        $root = self::$byAsk[$askId] ?? null;
        if ($root === null) {
            throw RpcError::notFound(\sprintf('no trust prompt %s', $askId), 'ask_not_found');
        }

        $record = self::$byRoot[$root];
        if ($record['status'] !== 'asked') {
            throw RpcError::of(ErrorCode::Conflict, 'this trust prompt was already answered', 'already_resolved', ['resolved' => ['status' => $record['status']]]);
        }

        $granted = $decision === 'grant';
        $persisted = false;
        if ($granted && $remember === 'user') {
            // The one writer: lists the root in trustedProjectMcp through the
            // user config door and records the server fingerprints (MCP-5).
            // The grant applies at next launch — the trust keys are
            // ApplyMode::Frozen, and saying so honestly is this method's job.
            $report = Bootstrap::trustProjectMcp($root);
            $persisted = $report['error'] === null && $report['granted'] === true;
        }

        return self::$byRoot[$root] = [
            ...$record,
            'status' => $granted ? 'granted' : 'rejected',
            'persisted' => $persisted,
        ];
    }

    /** Test seam: the process-global registry, as every static here owns one lifetime. */
    public static function resetForTesting(): void
    {
        self::$byRoot = [];
        self::$byAsk = [];
    }
}
