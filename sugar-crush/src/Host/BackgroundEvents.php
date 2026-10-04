<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;

/**
 * A host's view of its workspace's background (`/bg`) sessions as events
 * (roadmap O-4b, Appendix O §4.6): `bg.started`, `bg.status` and
 * `bg.completed`, handed to whoever the host says ({@see new()}'s $emit — the
 * server broadcasts them to every client).
 *
 * BOOT RE-ADOPTS ({@see boot()}). A `/bg` daemon outlives whatever spawned it,
 * so a server that starts may find daemons an earlier server — or a TUI of the
 * same project — left running. {@see BackgroundSupervisor::reconnect()} adopts
 * them from the per-uid session index (roadmap 4.3-3); this is the server's
 * caller of it, the TUI's being `Bootstrap::chat()`. Each adopted session is
 * recorded at {@see BackgroundSupervisor::ADOPTED_STATUS}, a status no session
 * has, so the first {@see poll()} always reports it — including the answer of
 * one that finished while nothing was watching.
 *
 * ONE PATH TO AN EVENT: A STATUS DIFF. {@see poll()} ticks the supervisor (that
 * is what settles a daemon that has exited and flips a silent one to
 * `stalled`), then compares each session's status with the last one it
 * reported — the same diff `Chat::pumpBackgroundSessions()` runs for the TUI.
 * Not the supervisor's listener: the workspace's supervisor is built before
 * any host exists, and {@see BackgroundSupervisor::withListener()} answers a
 * CLONE whose session table would part from the one `bg.*` methods read. A
 * diff also cannot double-report: whatever moved a session — a tick, a
 * `bg.stop`, a reap — the next poll sees the new status exactly once.
 *
 * A session stays known after it settles, so `bg.list` still lists it and
 * `bg.output` / `bg.inject` can reach its answer; the supervisor itself only
 * enumerates active ones.
 *
 * MUTABLE ON PURPOSE, like the server context that holds it: it is the live
 * record of what one host has already told its clients.
 */
final class BackgroundEvents
{
    /** A session this host spawned, or one it re-adopted at boot. */
    public const STARTED = 'bg.started';

    /** A known session's status changed. */
    public const STATUS = 'bg.status';

    /** A session settled: completed, failed, stopped or timed out. */
    public const COMPLETED = 'bg.completed';

    /**
     * How often a host polls — the TUI's own background poll interval, so a
     * session settles as promptly in a browser as in a terminal.
     */
    public const POLL_SECONDS = 2.0;

    /** @var array<string, string> bgId => the status last reported */
    private array $reported = [];

    /** @var array<string, true> the sessions {@see boot()} re-adopted */
    private array $adopted = [];

    private bool $booted = false;

    /**
     * @param \Closure(string, array<string, mixed>): void $emit
     */
    private function __construct(
        private readonly BackgroundSupervisor $supervisor,
        private readonly ?string $root,
        private readonly \Closure $emit,
    ) {
    }

    /**
     * @param string|null $root the project whose orphaned sessions {@see boot()} adopts; null adopts none
     * @param \Closure(string, array<string, mixed>): void $emit given each event's type and data
     */
    public static function new(BackgroundSupervisor $supervisor, ?string $root, \Closure $emit): self
    {
        return new self($supervisor, $root, $emit);
    }

    public function supervisor(): BackgroundSupervisor
    {
        return $this->supervisor;
    }

    /**
     * Re-adopt the daemons an earlier process left running for this root.
     * Once per host: a second call adopts nothing.
     *
     * @return list<string> the adopted session ids
     */
    public function boot(): array
    {
        if ($this->booted) {
            return [];
        }
        $this->booted = true;

        $adopted = [];
        foreach (\array_keys($this->supervisor->reconnect($this->root)) as $id) {
            $id = (string) $id;
            $adopted[] = $id;
            $this->adopted[$id] = true;
            $this->reported[$id] ??= BackgroundSupervisor::ADOPTED_STATUS;
        }

        return $adopted;
    }

    /** Whether {@see boot()} has run. */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Report a session this host just spawned now, rather than at the next
     * poll. A session already known is left to the poll.
     */
    public function track(BackgroundSession $session): void
    {
        if (!isset($this->reported[$session->id])) {
            $this->report($session, null);
        }
    }

    /**
     * One poll: tick the supervisor, then report every session whose status
     * moved since the last report.
     *
     * @return int how many events were emitted
     */
    public function poll(): int
    {
        if ($this->reported === [] && !$this->supervisor->hasActiveSessions()) {
            return 0;
        }
        if ($this->supervisor->hasActiveSessions()) {
            $this->supervisor->tick();
        }

        $emitted = 0;
        foreach ($this->sessions() as $session) {
            if (($this->reported[$session->id] ?? null) !== $session->status->value) {
                $emitted += $this->report($session, $this->reported[$session->id] ?? null);
            }
        }

        return $emitted;
    }

    /**
     * Every session this host knows: the supervisor's active ones and the
     * settled ones already reported, oldest first (ids begin with their
     * spawn time).
     *
     * @return array<string, BackgroundSession>
     */
    public function sessions(): array
    {
        $sessions = $this->supervisor->getActiveSessions();
        foreach (\array_keys($this->reported) as $id) {
            $id = (string) $id;
            if (!isset($sessions[$id])) {
                $session = $this->supervisor->getSession($id);
                if ($session !== null) {
                    $sessions[$id] = $session;
                }
            }
        }
        \ksort($sessions, \SORT_STRING);

        return $sessions;
    }

    /** @return array<string, string> bgId => the status last reported ({@see BackgroundSupervisor::ADOPTED_STATUS} until the first poll) */
    public function reported(): array
    {
        return $this->reported;
    }

    /**
     * A session as the protocol shows it — in `bg.list`, `bg.spawn`'s answer
     * and the `bg.started` / `bg.completed` events. Never the output itself:
     * that can be large, and `bg.output` reads it from an offset.
     *
     * @return array<string, mixed>
     */
    public function summary(BackgroundSession $session): array
    {
        return [
            'bgId' => $session->id,
            'name' => $session->name,
            'task' => $session->task,
            'status' => $session->status->value,
            'agent' => $session->agent->name,
            'model' => $session->agent->model,
            'tags' => \array_values(\array_map('strval', $session->tags)),
            'workingDirectory' => $session->workingDirectory,
            'createdAt' => $session->createdAt->format(\DATE_ATOM),
            'completedAt' => $session->completedAt?->format(\DATE_ATOM),
            'tokensUsed' => $session->tokensUsed,
            'costUsd' => $session->costUsd,
            'error' => $session->error,
            'outputBytes' => \strlen($session->output),
            'forkedSessionId' => $session->forkedSessionId(),
            'adopted' => isset($this->adopted[$session->id]),
        ];
    }

    /** @return int how many events were emitted */
    private function report(BackgroundSession $session, ?string $previous): int
    {
        $status = $session->status->value;
        $this->reported[$session->id] = $status;

        if ($previous === null || $previous === BackgroundSupervisor::ADOPTED_STATUS) {
            ($this->emit)(self::STARTED, $this->summary($session));
        } else {
            ($this->emit)(self::STATUS, ['bgId' => $session->id, 'status' => $status, 'previous' => $previous]);
        }
        if (!$session->isSettled()) {
            return 1;
        }
        ($this->emit)(self::COMPLETED, $this->summary($session));

        return 2;
    }
}
