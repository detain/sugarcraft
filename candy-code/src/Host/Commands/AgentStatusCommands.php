<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\CapabilityCard;
use SugarCraft\Code\Agents\CapabilityCardStore;
use SugarCraft\Code\Agents\Live\AgentInbox;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\AgentRunStatus;

/**
 * The command-output half of the agent run status surface (roadmap A3): a
 * session's delegated runs rendered as an indented parent → children tree.
 *
 * The lineage itself is {@see AgentRunStatus::tree()}; this class only prints
 * it for `agents tree` (and any other host surface that wants the text). It is
 * deliberately command output and nothing else — no pane, no TUI state, no
 * live geometry — so it works over ACP, `-p` and the host command surface
 * exactly as written. Rows mirror the `Subagents` tool's line shape (`- <run>
 * · agent "<name>" · <status>`), with indent standing in for the launcher
 * column a flat list has to spell out.
 *
 * UNTRUSTED ON READ: the agent name, description and status of a row are card
 * file contents, so each crosses
 * {@see \SugarCraft\Code\Host\Commands\PermissionsCommand::reportField()} —
 * the same one-transcript-line filter the permissions report uses — before it
 * reaches the screen. Run ids and parents are already ID_PATTERN-gated by the
 * card reader.
 *
 * The prose is literal English like the messaging tools' output, not a locale
 * key: these strings carry run data mid-sentence and belong with the tool
 * surface that shares their shape (SubagentsTool precedent).
 */
final class AgentStatusCommands
{
    /** The formatter is a namespace of statics; there is nothing to hold. */
    private function __construct()
    {
    }

    /**
     * Render the session's run tree; every line one physical transcript row.
     *
     * A null scope means the caller has no session id to look runs up under —
     * answered as text, never as a failure, because `agents tree` is a
     * question, not a mutation.
     *
     * @param  string|null $scope  the session whose delegated runs to render
     * @param  string|null $root   the cards tree, defaults to {@see AgentInbox::defaultRoot()}
     */
    public static function tree(?string $scope, ?string $root = null): string
    {
        if ($scope === null || $scope === '') {
            return 'No run cards to show: this session has no id to look its delegated runs up under.';
        }

        $rows = AgentRunStatus::tree($scope, AgentRunCards::MAIN, $root);
        if ($rows === []) {
            return 'No delegated agent runs are on record for this session.';
        }

        $lines = ['Agent run tree, oldest first (indent marks the launcher):'];
        foreach ($rows as $row) {
            $card = $row['card'];
            $line = str_repeat('  ', $row['depth']) . sprintf(
                '- %s · agent "%s" · %s',
                $card['runId'],
                PermissionsCommand::reportField((string) $card['agent']),
                PermissionsCommand::reportField(AgentRunCards::statusOf($card)),
            );

            if ($card['description'] !== '') {
                $line .= ' · "' . PermissionsCommand::reportField((string) $card['description']) . '"';
            }

            if ($row['orphan']) {
                $line .= ' · LAUNCHED BY ' . PermissionsCommand::reportField((string) $card['parent'])
                    . ', WHICH HAS NO CARD HERE';
            }

            if ($row['cycle']) {
                $line .= ' · PARENT CYCLE (forged or corrupt lineage)';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The discoverable capability manifests (RS-AGENT-C4 v1): publish this
     * session's roster into the home store, then list what is discoverable —
     * including other sessions' buckets. V1 discovery is LOCAL and
     * CROSS-SESSION only, never network; every card row below is data, not
     * permission, and nothing on this path acts on it.
     *
     * Publishing is skipped when the caller disallows it (read-only
     * surfaces) or holds no session id; discovery never needs either, so a
     * null scope still answers with everything on record. A stale row is a
     * manifest in THIS session's bucket that the live roster no longer
     * names — surfaced and labelled, because a vanished capability is
     * itself the news.
     *
     * @param list<Agent> $roster the caller's live agents, published as cards
     */
    public static function capabilities(
        ?string $scope,
        array $roster,
        ?string $nameFilter,
        bool $asJson,
        bool $allowPublish,
        ?string $root = null,
    ): string {
        $liveNames = [];
        foreach ($roster as $agent) {
            $liveNames[] = $agent->name;
        }

        if ($allowPublish && $scope !== null && $scope !== '' && $roster !== []) {
            $now = time();
            $cards = [];
            foreach ($roster as $agent) {
                $cards[] = CapabilityCard::fromAgent($agent, $now);
            }
            CapabilityCardStore::publish($scope, $cards, $root);
        }

        $rows = CapabilityCardStore::discover($scope, $liveNames, $root);
        if ($nameFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['name'] === $nameFilter,
            ));
        }

        if ($asJson) {
            $manifests = [];
            foreach ($rows as $row) {
                $manifests[] = [
                    'session' => $row['session'],
                    'current' => $row['current'],
                    'stale' => $row['stale'],
                    'card' => $row['card']->toArray(),
                ];
            }
            $json = json_encode(
                ['version' => CapabilityCard::SCHEMA_VERSION, 'scope' => $scope, 'agents' => $manifests],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if ($json === false) {
                return 'Could not encode the capability roster; refusing to print a partial manifest.';
            }

            return $json;
        }

        if ($rows === []) {
            return 'No capability manifests are on record yet. `agents capabilities` publishes this '
                . "session's roster and lists everything discoverable.";
        }

        $lines = ['Discoverable agent capabilities (a manifest is data, not permission):'];
        foreach ($rows as $row) {
            $line = '- ' . PermissionsCommand::reportField($row['name'])
                . ' · ' . PermissionsCommand::reportField($row['card']->summary());
            if (!$row['current']) {
                $line .= ' · from session ' . PermissionsCommand::reportField($row['session']);
            }
            if ($row['stale']) {
                $line .= " · STALE (this session's current roster no longer names it)";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
