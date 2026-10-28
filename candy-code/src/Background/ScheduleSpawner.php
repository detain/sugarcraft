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
 * session's (the dontAsk floor from {@see Schedule}; bypass-class spellings
 * never reach this line — {@see Schedule::fromArray()} refuses them at load,
 * C-AGT-7.a P3). The row's optional `timeoutSeconds` rides too (P4): absent,
 * the run takes spawnSession's own 3600s session default; present, it raises
 * that ONE row's ceiling. Both are daemon-run bounds and mode grants — neither
 * puts a timeout on a model request (E646).
 *
 * A HEARTBEAT row (C-AGT-5.c) spawns with two additions, both visible here
 * and nowhere else: {@see taskFor()} appends the reply-exactly-HEARTBEAT_OK
 * instruction to the prompt, and {@see tagsFor()} marks the session with
 * {@see Schedule::HEARTBEAT_TAG}. The stored row keeps the human's bare
 * prompt; the pump applies the silence contract from the TAG, so what a
 * running check's answer means cannot change under its feet.
 */
final class ScheduleSpawner
{
    private function __construct()
    {
    }

    /**
     * The {@see ScheduleRegistry::fireDue()} closure for one host.
     *
     * `$root` is the HOST's project directory, and it is only a FALLBACK since
     * C-AGT-1: a persisted row carries its own bound `root`, and `fireDue()`
     * hands a row to this closure only when that root is already this host's.
     * What is left to the host's directory is an ephemeral `/loop` row, which
     * has no binding of its own because it cannot outlive the session that
     * made it.
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
            // The row's own binding wins wherever it exists (a realpath'd
            // absolute directory); the host's directory answers only for the
            // unbound half, and '.' keeps the pre-existing meaning of "wherever
            // this process happens to be".
            $directory = $row->root ?? $root;

            $spawn = [
                'name' => self::sessionName($row),
                'agent' => $agent,
                'task' => self::taskFor($row),
                'workingDirectory' => $directory === null || $directory === '' ? '.' : $directory,
                'tags' => self::tagsFor($row),
                'permissionMode' => $row->permissionMode,
            ];
            // C-AGT-7.a P4: null adds NO key, so the default keeps living
            // exactly where it always has — spawnSession's own signature —
            // and a row that names a bound raises only its own runs. Omitting
            // the key rather than passing null is deliberate: the parameter is
            // `int`, and re-spelling 3600 here would mint a second source of
            // truth for it. (Whole-array spread because PHP forbids unpacking
            // after named arguments.)
            if ($row->timeoutSeconds !== null) {
                $spawn['timeoutSeconds'] = $row->timeoutSeconds;
            }

            return $supervisor->spawnSession(...$spawn)->id;
        };
    }

    /** The daemon name a row's runs appear under: prefixed so `/bg list` reads as cron, not chat. */
    public static function sessionName(Schedule $row): string
    {
        return 'schedule:' . $row->name;
    }

    /**
     * The task the daemon runs: the row's prompt, plus — for a heartbeat row
     * only (C-AGT-5.c) — the instruction that gives the run its silence
     * contract. The suffix rides the SPAWN, never the stored row, so
     * `/schedules list` shows the human's prompt, and a row flipped to task
     * on disk mid-flight cannot de-instruct an already-spawned heartbeat
     * (the tag, not the row, is what the pump consults).
     */
    public static function taskFor(Schedule $row): string
    {
        if ($row->kind !== Schedule::KIND_HEARTBEAT) {
            return $row->prompt;
        }

        return $row->prompt . ' When nothing needs attention, reply exactly ' . Schedule::HEARTBEAT_OK . '.';
    }

    /**
     * The session tags: the ordinary pair, plus {@see Schedule::HEARTBEAT_TAG}
     * on a heartbeat fire — the mark Chat's pump reads to apply the silence
     * contract, carried on the session so a mid-flight row edit cannot change
     * what an already-running check's answer means.
     *
     * @return list<string>
     */
    public static function tagsFor(Schedule $row): array
    {
        $tags = ['schedule', 'schedule:' . $row->name];
        if ($row->kind === Schedule::KIND_HEARTBEAT) {
            $tags[] = Schedule::HEARTBEAT_TAG;
        }

        return $tags;
    }

    /** Whether a settled daemon is a heartbeat fire (the tag — see {@see tagsFor()}). */
    public static function isHeartbeat(BackgroundSession $session): bool
    {
        return \in_array(Schedule::HEARTBEAT_TAG, $session->tags ?? [], true);
    }
}
