<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Lang;

/**
 * WEB-3b / C-SEC-5.b: the project-trust prompts this server has put — one per
 * pending grant target per untrusted-or-drifted root, for as long as the
 * process lives.
 *
 * WHY A REGISTRY AND NOT A TURN QUESTION. A tool-permission ask blocks a
 * running turn, rides the host's pendingAsks, times out with the feed's ask
 * timer and is swept `cancelled` when its turn ends. A trust prompt is none of
 * those things: nothing waits on it, it must survive every turn boundary, and
 * answering it writes the USER tier (a project that could write its own trust
 * list would grant itself — the `permission.respond` refusal this class mirrors
 * verbatim in kind). So the prompts live here, keyed by the canonical root and
 * the {@see TrustTarget} the launch census is about, and `context.trust`
 * answers them — `permission.respond` answering a trust id is an
 * `ask_not_found` by construction, which is the honest answer, not a leak into
 * the gate.
 *
 * THE ONCE LAW — PER (ROOT, TARGET). {@see discover()} is called from every
 * `context.sources` measurement and, since C-SEC-4, from every
 * `session.subscribe` (the cheap launch verdict, so prompts reach a watcher
 * before the panel mounts); the first sighting of a pending target mints that
 * target's ask and broadcasts it server-scope (the BackgroundEvents precedent:
 * an ask outside any turn still rides `permission.asked`), every later
 * sighting — reloads, other sessions, reconnects — re-reads the same record.
 * C-SEC-5.b widened the key from root alone to root plus target because the
 * census (5.a) found a root can owe more than one decision at once: the
 * `.mcp.json` grant, a drifted MCP pin re-approval, hooks, command shell
 * forms, and the project settings layer each open their own prompt, and
 * answering one must never silence the others. The answer settles its own
 * record for the process; a rejection is a decision, not a question to be
 * nagged about.
 */
final class TrustAsks
{
    /** @var array<string, array<string, array<string, mixed>>> canonical root => target value => prompt record */
    private static array $byRoot = [];

    /** @var array<string, array{root: string, target: string}> askId => the (root, target) its prompt is about */
    private static array $byAsk = [];

    private function __construct()
    {
    }

    /**
     * Mint the prompts the launch census says this root owes — every pending
     * target from {@see Bootstrap::pendingProjectTrust()}, announced through
     * $broadcast exactly once each. This is the one discovery loop both wire
     * doors (the `session.subscribe` peek and the full `context.sources`
     * measurement) call, so neither can fall behind the census.
     *
     * @param \Closure(array<string, mixed>): void $broadcast
     */
    public static function discoverPending(string $root, \Closure $broadcast): void
    {
        foreach (Bootstrap::pendingProjectTrust($root) as $target) {
            self::discover($root, $target, $broadcast);
        }
    }

    /**
     * The prompt for ($root, $target), minting and announcing it on first
     * sighting. The reason sentence is the target's own
     * `server.trust.ask.*` row — every target has had one since the 5.a
     * census, and the settings row is the one that says out loud what the
     * grant widens (the project's settings layer is dropped until trusted).
     *
     * @param \Closure(array<string, mixed>): void $broadcast receives the fresh record exactly once
     *
     * @return array<string, mixed> the prompt record (the `TrustPrompt` shape)
     */
    public static function discover(string $root, TrustTarget $target, \Closure $broadcast): array
    {
        $existing = self::$byRoot[$root][$target->value] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        $record = [
            'askId' => 'trust-' . \substr(\bin2hex(\random_bytes(8)), 0, 9),
            'kind' => 'trust',
            'target' => $target->value,
            'root' => $root,
            'reason' => Lang::t($target->askKey(), ['root' => $root]),
            'status' => 'asked',
            'persisted' => false,
        ];
        self::$byRoot[$root][$target->value] = $record;
        self::$byAsk[$record['askId']] = ['root' => $root, 'target' => $target->value];
        $broadcast($record);

        return $record;
    }

    /**
     * Every prompt this process has put about $root, minted or settled, in
     * {@see TrustTarget::cases()} order — the answer surface restates the
     * truth a measurement found (a session grant that expires with the
     * process, a rejection that must not nag, a pin re-approval still open
     * beside a granted `.mcp.json`), without minting anything new.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $root): array
    {
        $rows = [];
        foreach (TrustTarget::cases() as $target) {
            $record = self::$byRoot[$root][$target->value] ?? null;
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    /**
     * Settle $askId — one record, never the root's whole shelf: each pending
     * target keeps its own prompt open until it is answered (or its verdict
     * changes at next launch). The record only ever moves from `asked` once;
     * every later answer is the same refusal `permission.respond` gives a
     * settled question, and a project-tier remember is the same refusal it
     * gives a project-tier rule.
     *
     * C-SEC-4: $sessionRoot is the workspace root of the session answering.
     * The registry is per-process statics and a gateway-routed prompt is
     * minted inside its own workspace host, so a correctly routed answer
     * always names the record's root; a mismatch is the misroute the gateway
     * must never let settle a different root's prompt, answered as
     * `ask_not_found` — the honest answer from where it came.
     *
     * C-SEC-5.b: a user-tier grant goes through the census's own writer door,
     * {@see Bootstrap::grantProjectTrust()}, so each target lands in the list
     * its {@see TrustTarget::configKey()} names — the MCP door (whose pin
     * re-approval re-records the refused fingerprints) for `mcp` and
     * `mcp-pin`, the settings writer for hooks, commands and settings.
     * $writer is the answering session's workspace service; null falls back
     * to the launch's own. A write that does not land is
     * `trust_write_failed`: the record stays `asked`, because nothing was
     * persisted and the answer is repeatable once the config is writable.
     *
     * @param 'grant'|'reject' $decision
     * @param 'session'|'user'|'project'|null $remember
     *
     * @return array<string, mixed> the settled record
     *
     * @throws RpcError remember_refused / ask_not_found / already_resolved / trust_write_failed
     */
    public static function settle(string $askId, string $decision, ?string $remember, string $sessionRoot, ?SettingsWriter $writer = null): array
    {
        if ($remember === 'project') {
            throw RpcError::of(ErrorCode::Forbidden, 'trust is user-tier only; a project cannot be told to trust itself', 'remember_refused');
        }

        $owner = self::$byAsk[$askId] ?? null;
        if ($owner === null || $owner['root'] !== $sessionRoot) {
            throw RpcError::notFound(\sprintf('no trust prompt %s', $askId), 'ask_not_found');
        }

        $root = $owner['root'];
        $record = self::$byRoot[$root][$owner['target']];
        if ($record['status'] !== 'asked') {
            throw RpcError::of(ErrorCode::Conflict, 'this trust prompt was already answered', 'already_resolved', ['resolved' => ['status' => $record['status']]]);
        }

        $granted = $decision === 'grant';
        $persisted = false;
        if ($granted && $remember === 'user') {
            // The one writer per door: `mcp` lists the root in
            // trustedProjectMcp and records the server fingerprints (MCP-5);
            // `mcp-pin` rides the same door to re-record drifted pins; the
            // other three write their own trustedProject* list. Every grant
            // applies at next launch — the trust keys are ApplyMode::Frozen,
            // and saying so honestly is this method's job.
            // C-SEC-4: honesty continues past the door — `writeUserConfig`
            // fails SILENTLY on an unwritable config, so the list-not-updated
            // verdict (or a named error) is refused HERE rather than settled
            // as a grant whose persistence was a lie.
            $target = TrustTarget::from($owner['target']);
            $report = Bootstrap::grantProjectTrust($target, $root, $writer);
            if ($report['error'] !== null || $report['granted'] !== true) {
                throw RpcError::of(
                    ErrorCode::Conflict,
                    Lang::t('server.trust.write_failed'),
                    'trust_write_failed',
                    ['error' => $report['error'] ?? ('the ' . $target->configKey() . ' list was not updated')],
                );
            }
            $persisted = $report['persisted'] === true;
        }

        return self::$byRoot[$root][$owner['target']] = [
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
