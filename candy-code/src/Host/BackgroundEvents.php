<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Sessions\BackgroundSession;
use SugarCraft\Code\Sessions\BackgroundSupervisor;

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

    /** @var array<string, string> relayed askId => the bgId that asked, while open (ISS-004) */
    private array $asks = [];

    /** @var array<string, string> settled relayed askId => the reply word, or `cancelled` */
    private array $answeredAsks = [];

    /** The `answeredAsks` marker for a question its session settled without answering. */
    private const CANCELLED_REPLY = 'cancelled';

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
        $emitted += $this->pollAsks();

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
        $emitted = 2 + $this->cancelAsksFor($session->id);

        return $emitted;
    }

    /**
     * The relayed permission questions of the active sessions (ISS-004).
     *
     * A daemon with no relay never creates the file, so the scan costs one
     * stat per session per poll for every deployment that does not use it.
     * Discovery is content-based, not timer-based: a question written and
     * answered between two polls still lands in `permission.pending` for a
     * client that looks, and a client that was watching gets both events at
     * the next poll.
     *
     * @return int how many `permission.asked` events were emitted
     */
    public function pollAsks(): int
    {
        $emitted = 0;
        foreach ($this->sessions() as $session) {
            if ($session->isSettled()) {
                continue;
            }
            foreach ($this->supervisor->permissionAsks($session->id) as $ask) {
                $askId = (string) $ask['askId'];
                if (isset($this->asks[$askId]) || isset($this->answeredAsks[$askId])) {
                    continue;
                }
                $this->asks[$askId] = $session->id;
                $emitted += $this->emitAsk($session->id, $ask);
            }
        }

        return $emitted;
    }

    /**
     * Every relayed question still open across the active sessions, in the
     * shape `permission.pending` lists them — read from the files, so it is
     * correct between polls, not only at them.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingAsks(): array
    {
        $items = [];
        foreach ($this->sessions() as $session) {
            if ($session->isSettled()) {
                continue;
            }
            foreach ($this->supervisor->permissionAsks($session->id) as $ask) {
                if (isset($this->answeredAsks[$ask['askId']])) {
                    continue;
                }
                $items[] = ['sessionId' => $session->id, 'bgId' => $session->id, ...$this->relayRow($ask)];
            }
        }

        return $items;
    }

    /**
     * Hand the daemon's server the human's answer to one relayed question
     * (ISS-004). Answers the first valid one; the answer file is the record,
     * so a second answer to the same question reports `already` with the
     * first reply attached rather than overwriting anything.
     *
     * @return array{status: string, reply?: string} status is `answered`,
     *         `already` (with the winning `reply`), or `unknown`
     */
    public function answerAsk(string $bgId, string $askId, string $reply, string $note = ''): array
    {
        $settled = $this->answeredAsks[$askId] ?? null;
        if ($settled !== null && $settled !== self::CANCELLED_REPLY) {
            return ['status' => 'already', 'reply' => $settled];
        }
        if ($settled === self::CANCELLED_REPLY) {
            // The question died with its session; there is nothing to answer.
            return ['status' => 'unknown'];
        }
        $session = $this->supervisor->getSession($bgId);
        if ($session === null || $session->isSettled() || !isset($this->asks[$askId]) || $this->asks[$askId] !== $bgId) {
            // Not tracked from a poll yet: accept it straight off the file if
            // an active session really did ask — a client that read
            // `permission.pending` between two polls must be able to answer.
            if ($session === null || $session->isSettled() || !in_array($askId, array_column($this->supervisor->permissionAsks($bgId), 'askId'), true)) {
                return ['status' => 'unknown'];
            }
            $this->asks[$askId] = $bgId;
        }
        if (!$this->supervisor->answerPermissionAsk($bgId, $askId, $reply, $note)) {
            return ['status' => 'unknown'];
        }

        unset($this->asks[$askId]);
        $this->answeredAsks[$askId] = $reply;
        ($this->emit)(EventType::PERMISSION_SETTLED, [
            'sessionId' => $bgId,
            'bgId' => $bgId,
            'askId' => $askId,
            'reply' => $reply,
            'note' => ChildChannel::clipNote($note),
        ]);

        return ['status' => 'answered', 'reply' => $reply];
    }

    /** @return int how many `permission.settled` (cancelled) events were emitted */
    private function cancelAsksFor(string $bgId): int
    {
        $emitted = 0;
        foreach ($this->asks as $askId => $ownerId) {
            if ($ownerId !== $bgId) {
                continue;
            }
            unset($this->asks[$askId]);
            $this->answeredAsks[$askId] = self::CANCELLED_REPLY;
            ($this->emit)(EventType::PERMISSION_SETTLED, [
                'sessionId' => $bgId,
                'bgId' => $bgId,
                'askId' => $askId,
                'cancelled' => true,
            ]);
            ++$emitted;
        }

        return $emitted;
    }

    /**
     * A relayed ask row as the wire sees it: an empty argument map must ride
     * as `{}`, not `[]` — the same object shape {@see SessionFeed::askSummary()}
     * gives a hosted session's question, so one client renders both.
     *
     * @param array<string, mixed> $ask
     * @return array<string, mixed>
     */
    private function relayRow(array $ask): array
    {
        $arguments = $ask['arguments'] ?? [];
        $ask['arguments'] = $arguments === [] ? new \stdClass() : $arguments;

        return $ask;
    }

    /**
     * One relayed question as the broadcast that announces it: the session-scope
     * ask data with the background id wearing the `sessionId` name, so every
     * client can answer it with `permission.respond` exactly as it would an
     * open session's question.
     *
     * @param array<string, mixed> $ask
     */
    private function emitAsk(string $bgId, array $ask): int
    {
        ($this->emit)(EventType::PERMISSION_ASKED, ['sessionId' => $bgId, 'bgId' => $bgId, ...$this->relayRow($ask)]);

        return 1;
    }
}
