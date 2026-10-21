<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Host\Commands\BackgroundCommand;

/**
 * The one way a due {@see Schedule} becomes a running daemon (AUT-1).
 *
 * Both hosts — the TUI's Chat pump and the serve-side
 * {@see \SugarCraft\Code\Host\BackgroundEvents} — build their fire closure
 * here, so a schedule cannot run differently depending on which process's
 * clock caught it. The spawn rides {@see BackgroundSupervisor::spawnSession()}
 * exactly like `/bg` does: the SAME accounted fork site (the child-lifetime
 * census needs no new roster row because no NEW proc_open appears), and the
 * resulting session is a normal `bg.*` citizen — listable, stoppable, and
 * reported by every host's status diff.
 *
 * The agent is the roster's default, then the first native, then the stand-in
 * ({@see BackgroundCommand::rosterAgent()} — the ISS-087 chain, shared rather
 * than copied), with the row's pinned provider substituted when it names one.
 * The row's RECORDED permission mode rides through: a scheduled run answers
 * to the consent state of the moment it was scheduled, never the live
 * session's (the dontAsk floor from {@see Schedule}).
 */
final class ScheduleSpawner
{
    private function __construct()
    {
    }

    /**
     * The {@see ScheduleRegistry::fireDue()} closure for one host.
     *
     * @return \Closure(Schedule, int): ?string  the spawned session id; throws
     *         to report a refused spawn (the registry turns that into an
     *         error result — the advance it already made is NOT rolled back,
     *         so a broken row announces once per period, never per tick)
     */
    public static function closure(BackgroundSupervisor $supervisor, ?AgentManager $agentManager, ?string $root): \Closure
    {
        return static function (Schedule $row) use ($supervisor, $agentManager, $root): ?string {
            $agent = BackgroundCommand::rosterAgent($agentManager);
            if ($row->provider !== null && $row->provider !== $agent->provider) {
                // Derived, never mutated in place: the roster instance is
                // shared with the live session's sub-agent dispatch.
                $agent = Agent::fromArray(array_merge($agent->toArray(), ['provider' => $row->provider]));
            }

            return $supervisor->spawnSession(
                name: self::sessionName($row),
                agent: $agent,
                task: $row->prompt,
                workingDirectory: $root === null || $root === '' ? '.' : $root,
                tags: ['schedule', 'schedule:' . $row->name],
                permissionMode: $row->permissionMode,
            )->id;
        };
    }

    /** The daemon name a row's runs appear under: prefixed so `/bg list` reads as cron, not chat. */
    public static function sessionName(Schedule $row): string
    {
        return 'schedule:' . $row->name;
    }
}
