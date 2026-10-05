<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The hard stop of ONE delegated run (roadmap P-E1, Appendix P §5.5): the
 * turn child's view of `agent_cancel{agentId, callId}`, and the two halves of
 * acting on it — the SIGTERM-then-SIGKILL escalation the turn child applies to
 * a concurrent member, and the graceful stop the member arms in itself.
 *
 * WHY IT IS A SECOND SEAM BESIDE {@see ToolCancelRequests}. `cancel_tool` is
 * Esc's "stop this call now": the reap loop kills the member's tree outright.
 * The Agent View's second cancel press is the escalation of a soft cancel the
 * run has not acted on yet, and it should still leave the run RESUMABLE where
 * it can: so the member is asked first (SIGTERM, which the member turns into
 * the same stop a lone Task makes at its next tool start or step — see
 * {@see armGracefulStop()}), and killed only if it has not gone within
 * {@see GRACE_SECONDS}. That ordering is the whole difference, and it needs
 * the reap loop to tell the two requests apart.
 *
 * A LONE Task runs inside the turn child itself, which no signal can stop
 * without stopping the turn. Its request is handed on to
 * {@see ToolCancelRequests} by call id ({@see takeNewCallIds()}), so it stops
 * itself at its next tool start or step, exactly as Esc's `cancel_tool` makes
 * it — the hardest stop a run in this process can have.
 *
 * OWNED BY ONE PROCESS, like {@see ToolCancelRequests}: every answer outside
 * the process that listened is "nothing requested". Requests are LATCHED for
 * the rest of the turn.
 */
final class AgentCancelRequests
{
    /** How long a member has to stop by itself after SIGTERM before its tree is SIGKILLed. */
    public const GRACE_SECONDS = 2.0;

    /** What a member that went on the SIGTERM itself, leaving no result of its own, reports. */
    public const STOPPED = 'Stopped by the user (hard cancel from the Agent View).';

    /** What a member that had to be killed reports, to the model and on its row. */
    public const KILLED = 'Stopped by the user (hard cancel from the Agent View): the sub-agent did not stop within 2 s of SIGTERM, so its process tree was killed.';

    /** What a member stopped before it ever started reports. */
    public const NEVER_STARTED = 'Stopped by the user (hard cancel from the Agent View) before it started.';

    /** Pull the channel at most this often; the reap loop asks on every poll pass. */
    private const PULL_INTERVAL_SECONDS = 0.05;

    /** @var (\Closure(): list<array<string, mixed>>)|null */
    private static ?\Closure $source = null;

    private static int $ownerPid = 0;

    private static float $lastPull = 0.0;

    /** @var array<string, string> callId => agentId, every request seen this turn */
    private static array $requested = [];

    /** @var list<string> call ids not yet handed to {@see ToolCancelRequests} */
    private static array $fresh = [];

    /** The member process {@see armGracefulStop()} armed, and whether its SIGTERM has come. */
    private static int $armedPid = 0;

    private static bool $terminated = false;

    /**
     * Name where this process's requests come from — the turn child's
     * channel ({@see \SugarCraft\Crush\Backend\ChildChannel::takeAgentCancels()})
     * — and forget any earlier turn's.
     *
     * @param \Closure(): list<array<string, mixed>> $source the `{agentId, callId}` requests since its last call
     */
    public static function listen(\Closure $source): void
    {
        self::$source = $source;
        self::$ownerPid = (int) getmypid();
        self::$lastPull = 0.0;
        self::$requested = [];
        self::$fresh = [];
    }

    /** Stop listening (the turn is over, or a test is done). */
    public static function forget(): void
    {
        self::$source = null;
        self::$ownerPid = 0;
        self::$lastPull = 0.0;
        self::$requested = [];
        self::$fresh = [];
        self::$armedPid = 0;
        self::$terminated = false;
    }

    /**
     * The agent id the user hard-stopped under the Task call $callId, or null
     * when none was — always null outside the listening process.
     */
    public static function forCall(string $callId): ?string
    {
        if ($callId === '' || !self::pull()) {
            return null;
        }

        return self::$requested[$callId] ?? null;
    }

    /**
     * The call ids requested since the last call, each handed out once — the
     * turn child feeds them to {@see ToolCancelRequests} so a lone Task stops
     * at its next boundary.
     *
     * @return list<string>
     */
    public static function takeNewCallIds(): array
    {
        if (!self::pull()) {
            return [];
        }
        $ids = self::$fresh;
        self::$fresh = [];

        return $ids;
    }

    /**
     * Ask member $pid to stop: SIGTERM to it and to everything it started.
     *
     * The member itself turns the signal into a graceful stop
     * ({@see armGracefulStop()}); a command it is running (a Bash, an MCP
     * child, a nested member) takes the default and goes, which is what lets
     * the member reach its next tool start or step soon. A process group
     * another member leads (a `setsid`'d command) gets it as a group, so a
     * backgrounded job of that command goes too. NEVER this process's own
     * group: the members are forks of the turn child and share its group —
     * and the TUI's.
     *
     * Non-blocking; the reap loop SIGKILLs the tree when the member is still
     * there {@see GRACE_SECONDS} later.
     */
    public static function terminate(int $pid): void
    {
        if ($pid <= 0 || !\function_exists('posix_kill') || $pid === (int) getmypid()) {
            return;
        }

        $own = \function_exists('posix_getpgrp') ? \posix_getpgrp() : 0;
        $descendants = ProcessTree::descendants($pid);
        $groups = [];
        foreach ($descendants as $member) {
            $stat = ProcessTree::stat($member);
            if ($stat !== null && $stat['pgid'] > 1 && $stat['pgid'] !== $own) {
                $groups[$stat['pgid']] = true;
            }
        }

        // 15 as a literal: SIGTERM is ext-pcntl's constant (see ProcessReaper).
        foreach (\array_keys($groups) as $group) {
            @\posix_kill(-$group, 15);
        }
        foreach ([$pid, ...$descendants] as $member) {
            @\posix_kill($member, 15);
        }
    }

    /**
     * In a concurrent member, just after its fork: make SIGTERM a request to
     * stop the run of call $callId rather than the end of the process.
     *
     * The handler only latches; {@see ToolCancelRequests} — re-listened here,
     * owned by this process — then answers true for $callId, and the Task run
     * stops itself at its next tool start or step, settles cancelled and
     * writes its result, resume id included, as any finished member does. A
     * process forked BELOW this one inherits the handler; it is not the run,
     * so it takes SIGTERM's default (re-raised) and goes.
     *
     * Without ext-pcntl's signal functions this is a no-op: SIGTERM ends the
     * member at once, and the reap loop settles it as killed.
     */
    public static function armGracefulStop(string $callId): void
    {
        if ($callId === '' || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        self::$armedPid = (int) getmypid();
        self::$terminated = false;
        $armed = self::$armedPid;

        \pcntl_async_signals(true);
        \pcntl_signal(15, static function () use ($armed): void {
            if ((int) getmypid() !== $armed) {
                \pcntl_signal(15, \SIG_DFL);
                @\posix_kill((int) getmypid(), 15);

                return;
            }
            self::$terminated = true;
        });

        ToolCancelRequests::listen(static fn (): array => self::$terminated && (int) getmypid() === $armed ? [$callId] : []);
    }

    /** Whether the member armed in this process has been asked to stop by SIGTERM. */
    public static function terminationRequested(): bool
    {
        return self::$terminated && self::$armedPid === (int) getmypid();
    }

    /** @return bool false outside the listening process */
    private static function pull(): bool
    {
        if (self::$source === null || (int) getmypid() !== self::$ownerPid) {
            return false;
        }

        $now = microtime(true);
        if ($now - self::$lastPull < self::PULL_INTERVAL_SECONDS) {
            return true;
        }
        self::$lastPull = $now;

        foreach ((self::$source)() as $request) {
            if (!\is_array($request)) {
                continue;
            }
            $agentId = $request['agentId'] ?? null;
            $callId = $request['callId'] ?? null;
            if (!\is_string($agentId) || $agentId === '' || !\is_string($callId) || $callId === '' || isset(self::$requested[$callId])) {
                continue;
            }
            self::$requested[$callId] = $agentId;
            self::$fresh[] = $callId;
        }

        return true;
    }
}
