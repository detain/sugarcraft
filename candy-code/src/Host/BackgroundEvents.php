<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Background\BackgroundPermissionRelay;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Background\ScheduleSpawner;
use SugarCraft\Code\Agents\AgentManager;

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

    /** @var array<string, string> settled relayed askId => the reply word, `cancelled`, or `timed_out` */
    private array $answeredAsks = [];

    /**
     * @var array<string, string> relayed askId => the token of the asking the
     * memos currently describe (F2). The askId is a content hash, so an
     * identical replayed call re-uses it; when the file's newest line for an
     * id carries a different token, that is a NEW question wearing a known
     * id, and the memos are purged so it announces afresh — whose answer may
     * then only be a row echoing the new token.
     */
    private array $askTokens = [];

    /** The `answeredAsks` marker for a question its session settled without answering. */
    private const CANCELLED_REPLY = 'cancelled';

    /**
     * The `answeredAsks` marker for a question its daemon closed by running
     * out of its window (F4). Like `cancelled` it refuses late answers as
     * `unknown` — the poll is over — but it is not echoed on the wire: the
     * settled event carries the same `cancelled: true` shape the schema
     * knows, because a window that expired is exactly "settled without an
     * answer". The marker exists so `permission.respond` cannot mistake it
     * for an answerable question.
     */
    private const TIMED_OUT_REPLY = 'timed_out';

    private bool $booted = false;

    /**
     * @param \Closure(string, array<string, mixed>): void $emit
     */
    private function __construct(
        private readonly BackgroundSupervisor $supervisor,
        private readonly ?string $root,
        private readonly \Closure $emit,
        private readonly ?ScheduleRegistry $schedules = null,
        private readonly ?AgentManager $agentManager = null,
    ) {
    }

    /**
     * @param string|null $root the project whose orphaned sessions {@see boot()} adopts; null adopts none
     * @param \Closure(string, array<string, mixed>): void $emit given each event's type and data
     * @param ScheduleRegistry|null $schedules the AUT-1 roster this host fires
     *        persisted rows from on every poll (the row lock arbitrates with
     *        any other host); null runs no clock, as before
     */
    public static function new(
        BackgroundSupervisor $supervisor,
        ?string $root,
        \Closure $emit,
        ?ScheduleRegistry $schedules = null,
        ?AgentManager $agentManager = null,
    ): self {
        return new self($supervisor, $root, $emit, $schedules, $agentManager);
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
        // AUT-1 FIRST: the clock runs even while nothing is being watched —
        // an idle serve with a due row must fire, and the session its fire
        // spawns turns this poll's own diff into `bg.started`.
        $this->pumpSchedules();
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
     * Evaluate the persisted AUT-1 rows once (the serve half of the clock):
     * reload if the file moved, fire due rows behind their per-row locks, and
     * retire fired markers whose daemons settled. A fired session is already
     * in the active set when {@see poll()} diffs it, so it announces itself as
     * an ordinary `bg.started` — no schedule-specific event type exists, and
     * none is minted here (the Protocol/ fence stays whole).
     *
     * Spawn REFUSALS are quiet on this side by necessity: there is no
     * transcript to announce to. `/schedules list` on the serve host reads
     * the same roster, and the row keeps its un-advanced state only across a
     * locked skip — an error'd row advances and will retry next period.
     */
    private function pumpSchedules(): void
    {
        if ($this->schedules === null) {
            return;
        }
        $this->schedules->reloadIfChanged();
        $this->schedules->fireDue(
            time(),
            ScheduleSpawner::closure($this->supervisor, $this->agentManager, $this->root ?? ''),
        );
        foreach ($this->schedules->firedNames() as $name) {
            $sessionId = $this->schedules->busySession($name);
            $session = $sessionId === null ? null : $this->supervisor->getSession($sessionId);
            if ($session === null || $session->isSettled()) {
                $this->schedules->clearFired($name);
            }
        }
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
            // S1 (docs-fix-1023): the spend figure's companion bit, on the
            // SAME present-only-when-true law {@see SessionSnapshot::shape()}
            // states for the transcript snapshot — a priced row serialises
            // byte-for-byte as before, and a client reads
            // `row['spendUnpriced'] ?? false` tolerantly. Without it the
            // web's background panel could never mark a bg cost as the
            // lower bound it is.
            ...($session->spendUnpriced ? ['spendUnpriced' => true] : []),
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
     * The answer file is consulted BEFORE a question is announced (F3): a
     * server that restarts finds its memos empty, and without that read it
     * would re-broadcast a question a human already answered — whose answer
     * a client could then post again, a duplicate row on a record already
     * settled. The row that names the asking (id AND token) settles it in
     * the memo silently, exactly as the answer did on the server that lived
     * through it. And the ask file is read for closures (F4): a question its
     * daemon timed out gets its one `permission.settled` here, so no client
     * is left holding a question nobody is polling any more.
     *
     * @return int how many ask events (`permission.asked` / `permission.settled`) were emitted
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
                $token = (string) ($ask['askToken'] ?? '');
                if (($this->askTokens[$askId] ?? '') !== $token) {
                    // F2: a re-issued asking is a new question, whatever the
                    // memos think they already know about its id.
                    unset($this->asks[$askId], $this->answeredAsks[$askId]);
                    $this->askTokens[$askId] = $token;
                }
                if (isset($this->asks[$askId]) || isset($this->answeredAsks[$askId])) {
                    continue;
                }
                $filed = $this->fileAnswer($session->id, $askId, $token);
                if ($filed !== null) {
                    $this->answeredAsks[$askId] = $filed;
                    continue;
                }
                $this->asks[$askId] = $session->id;
                $emitted += $this->emitAsk($session->id, $ask);
            }
            foreach ($this->supervisor->permissionSettledAskIds($session->id) as $askId) {
                if (($this->asks[$askId] ?? null) !== $session->id || isset($this->answeredAsks[$askId])) {
                    continue;
                }
                unset($this->asks[$askId]);
                $this->answeredAsks[$askId] = self::TIMED_OUT_REPLY;
                ($this->emit)(EventType::PERMISSION_SETTLED, [
                    'sessionId' => $session->id,
                    'bgId' => $session->id,
                    'askId' => $askId,
                    'cancelled' => true,
                ]);
                ++$emitted;
            }
        }

        return $emitted;
    }

    /**
     * The reply a recorded answer row gives to exactly this asking — the
     * row's `askToken` echoing the open ask line's — or null when the file
     * holds no answer to it. This is F3's memory and F2's stale-row door in
     * one read; both sides of a mixed-version channel match on the same
     * tokenless pairing the daemon itself polls on.
     */
    private function fileAnswer(string $sessionId, string $askId, string $askToken): ?string
    {
        foreach ($this->supervisor->permissionAnswers($sessionId) as $row) {
            if (($row['askId'] ?? null) === $askId && (string) ($row['askToken'] ?? '') === $askToken) {
                return (string) $row['reply'];
            }
        }

        return null;
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
                $askId = (string) $ask['askId'];
                if (isset($this->answeredAsks[$askId])) {
                    continue;
                }
                // F3: an answer on the record closes the question for this
                // read too, before any poll has seeded the memo — a restart
                // must not list an answered question as pending. (A settled
                // question never reaches here: the open-row reader already
                // dropped it on its settle line, F4.)
                if ($this->fileAnswer($session->id, $askId, (string) ($ask['askToken'] ?? '')) !== null) {
                    continue;
                }
                $items[] = ['sessionId' => $session->id, 'bgId' => $session->id, ...$this->relayRow($ask)];
            }
        }

        return $items;
    }

    /**
     * Hand the daemon's server the human's answer to one relayed question
     * (ISS-004). Answers the first valid one for a given asking; the answer
     * file is the record, so a second answer to the same asking reports
     * `already` with the first reply attached rather than overwriting
     * anything — and so does a RESTARTED server that never saw the question,
     * reading the record instead of trusting empty memos (F3). A re-issued
     * identical call is a NEW asking with a fresh token: its memos were
     * purged by that token (F2), and a row answering its predecessor cannot
     * settle it here either, because this method only ever answers what the
     * ask file still holds open.
     *
     * The reply word travels as the human answered it, EXCEPT `always`: a
     * relayed question lands once-strength in the daemon (there is no
     * channel to grow a remembered grant inside it), and since F5 the row,
     * the settled event and the result all echo `once` — including when the
     * `always` arrived as the remember-coercion of a `once`. What the wire
     * reports is what the gate applied.
     *
     * @return array{status: string, reply?: string} status is `answered`,
     *         `already` (with the winning `reply`), or `unknown`
     */
    public function answerAsk(string $bgId, string $askId, string $reply, string $note = ''): array
    {
        if ($reply === BackgroundPermissionRelay::REPLY_ALWAYS) {
            $reply = BackgroundPermissionRelay::REPLY_ONCE;
        }
        $settled = $this->answeredAsks[$askId] ?? null;
        if ($settled === self::CANCELLED_REPLY || $settled === self::TIMED_OUT_REPLY) {
            // Dead questions answer nothing: cancelled died with its session,
            // timed-out with its window — nobody is polling an answer any more.
            return ['status' => 'unknown'];
        }
        $session = $this->supervisor->getSession($bgId);
        if ($session === null || $session->isSettled()) {
            return ['status' => 'unknown'];
        }
        $openToken = null;
        foreach ($this->supervisor->permissionAsks($bgId) as $row) {
            if (($row['askId'] ?? null) === $askId) {
                $openToken = (string) ($row['askToken'] ?? '');
                break;
            }
        }
        if ($openToken === null) {
            // The ask file no longer holds this question open — it was never
            // asked, its session's channel is gone, or a settle line closed
            // it (F4). An append now reaches no reader, whichever of the
            // memos happens to still name it.
            return ['status' => 'unknown'];
        }
        if (($this->askTokens[$askId] ?? '') !== $openToken) {
            // F2: the memos speak for an earlier asking of this id. A fresh
            // asking answers anew, whatever the old answer was.
            unset($this->asks[$askId], $this->answeredAsks[$askId]);
            $this->askTokens[$askId] = $openToken;
            $settled = null;
        }
        if ($settled !== null) {
            return ['status' => 'already', 'reply' => $settled];
        }
        $filed = $this->fileAnswer($bgId, $askId, $openToken);
        if ($filed !== null) {
            // F3: answered before this server looked — the record IS the
            // memo, and re-answering would only append a duplicate row on a
            // question its human has already settled.
            $this->answeredAsks[$askId] = $filed;

            return ['status' => 'already', 'reply' => $filed];
        }
        $this->asks[$askId] = $bgId;
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
