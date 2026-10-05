<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Permissions\PermissionReply;

/**
 * One open session's events, fanned out to the clients subscribed to it
 * (roadmap O-3b, Appendix O §6.4–§6.8).
 *
 * ONE LISTENER PER HOST. The feed hears the host ({@see SessionHost::onEvent()})
 * once, however many clients follow it, and hands each subscriber its copy
 * through that client's {@see \SugarCraft\Crush\Server\Ws\Outbox} — so the
 * turn never waits on a socket. A durable event reached the log before the
 * feed heard it (the runner logs first), which is what makes replay exact.
 *
 * SUBSCRIBE BEFORE SNAPSHOT (§6.8). {@see subscribe()} registers the client
 * FIRST and buffers what it hears while the client is caught up — from a
 * snapshot, or by paging the log up to the seq it was given, a page per loop
 * tick — then flushes the buffer, skipping any seq already sent, and goes
 * live. Nothing is missed in the gap and nothing arrives twice.
 *
 * WHAT ONLY A DRIVER KNOWS is announced here, through the host's own
 * log-then-broadcast path ({@see SessionHost::announce()}): the session's
 * status (`idle` / `busy` / `waiting_permission`), the prompts queued behind
 * a turn and their leaving (`turn.queued` / `turn.dequeued`), a steer handed
 * to the running turn (`turn.steered`). A derived event is announced only
 * after the event that caused it reached every subscriber, so the order a
 * client sees is the order of cause and effect.
 *
 * Also here: the per-question answer timeout (`server.askTimeoutSeconds`),
 * a delta's `partId` / `offset` (so a client can spot a dropped one), the
 * narration tail, and the live sub-agent tree (`agents.subtree`).
 *
 * NARRATION (Appendix O §6.9, roadmap O-6a). A subscriber hears an
 * `assistant.narration` tail at most every 2 s instead of the deltas when it
 * subscribed with `mode: "narration"`, when it said (`client.viewing` with
 * `narrate`) that the session is a glanced-at pane rather than the one in
 * front, or when its outbox is past the soft watermark and the session is not
 * in front. The tail names the byte `offset` it starts at, so a pane brought
 * to the front is handed the tail at once ({@see catchUp()}) and the deltas
 * that follow continue it without a gap.
 *
 * EVERY EVENT IS ALSO OFFERED TO THE SERVER ($onHeard), which turns the few
 * that matter beyond the session's own followers — a question put, a
 * question settled, a status change — into server-scope events every client
 * hears at once (the cross-session approvals drawer, the sidebar).
 *
 * MUTABLE ON PURPOSE: the live state of one session's fan-out.
 */
final class SessionFeed
{
    /** Appendix O §6.9: at most this many subscribers per session. */
    public const MAX_SUBSCRIBERS = 50;

    /** Appendix O §6.9: a narration subscription hears the tail at most this often. */
    public const NARRATION_INTERVAL_SECONDS = 2.0;

    /** Appendix O §6.9: the narration tail's size. */
    public const NARRATION_TAIL_BYTES = 4096;

    public const MODE_FULL = 'full';
    public const MODE_NARRATION = 'narration';

    public const STATUS_IDLE = 'idle';
    public const STATUS_BUSY = 'busy';
    public const STATUS_WAITING = 'waiting_permission';

    private const DELTA_TYPES = [SessionEvent::ASSISTANT_DELTA, SessionEvent::REASONING_DELTA];

    /**
     * @var array<string, array{client: Client, mode: string, live: bool, buffer: list<EventEnvelope>, lastSent: int}>
     */
    private array $subscribers = [];

    private ?\Closure $detach = null;

    private string $status;

    /** @var list<array{queueId: string, text: string}> prompts this feed saw queued */
    private array $queue = [];

    private int $nextQueueId = 0;

    /** @var array<string, TimerInterface> */
    private array $askTimers = [];

    /** @var array<string, array<string, mixed>> sub-agent id => its latest activity */
    private array $subagents = [];

    /** @var array<string, int> delta type => bytes streamed into the current part */
    private array $offsets = [];

    private string $narrationTail = '';

    private ?string $narrationTurnId = null;

    private float $narratedAt = 0.0;

    private bool $hearing = false;

    /** @var list<SessionEvent> events heard while one was being handled */
    private array $deferred = [];

    /**
     * @param (\Closure(SessionEvent): void)|null $onHeard
     */
    private function __construct(
        private readonly SessionHost $host,
        private readonly LoopInterface $loop,
        private readonly float $askTimeoutSeconds,
        private readonly \Closure $clock,
        private readonly ?\Closure $onHeard,
    ) {
        $this->status = $host->isBusy() ? self::STATUS_BUSY : self::STATUS_IDLE;
    }

    /**
     * A feed over $host, listening from now on.
     *
     * @param float $askTimeoutSeconds 0 waits for an answer forever (§6.7)
     * @param (\Closure(): float)|null $clock
     * @param (\Closure(SessionEvent): void)|null $onHeard told every event once its subscribers have it
     */
    public static function attach(SessionHost $host, LoopInterface $loop, float $askTimeoutSeconds = 0.0, ?\Closure $clock = null, ?\Closure $onHeard = null): self
    {
        $feed = new self($host, $loop, \max(0.0, $askTimeoutSeconds), $clock ?? static fn (): float => \microtime(true), $onHeard);
        $feed->detach = $host->onEvent($feed->hear(...));

        return $feed;
    }

    public function host(): SessionHost
    {
        return $this->host;
    }

    public function sessionId(): string
    {
        return $this->host->sessionId();
    }

    /** The session's status as last announced. */
    public function status(): string
    {
        return $this->status;
    }

    /** Stop listening; every subscriber is dropped. */
    public function detach(): void
    {
        if ($this->detach !== null) {
            ($this->detach)();
            $this->detach = null;
        }
        foreach ($this->askTimers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->askTimers = [];
        $this->subscribers = [];
    }

    public function isSubscribed(Client $client): bool
    {
        return isset($this->subscribers[$client->id()]);
    }

    public function subscriberCount(): int
    {
        return \count($this->subscribers);
    }

    /** Whether $client follows this session and hears it as narration right now. */
    public function isNarratedFor(Client $client): bool
    {
        return isset($this->subscribers[$client->id()]) && $this->narrates($client->id());
    }

    /**
     * Hand $client the reply streamed so far — its tail, at the byte offset it
     * starts — now, unthrottled. Called when a pane comes to the front: the
     * deltas that follow continue exactly where the tail ends.
     *
     * @return bool whether there was anything to hand
     */
    public function catchUp(Client $client): bool
    {
        $subscriber = $this->subscribers[$client->id()] ?? null;
        $envelope = $this->narration();
        if ($subscriber === null || !$subscriber['live'] || $envelope === null) {
            return false;
        }
        $client->outbox()->pushEphemeral($envelope->notification());

        return true;
    }

    /**
     * Subscribe $client (Appendix O §6.8) and say how it catches up: from
     * the events after $afterSeq (`{fromSeq, throughSeq}`, then the replay
     * pages and the live stream), or — with no cursor, or one the log can no
     * longer serve — from a snapshot (`{reset: true, snapshot, throughSeq}`).
     * Either way the questions still open ride along (`pendingAsks`), so a
     * client that reconnects mid-question can answer it whatever its cursor.
     *
     * @return array<string, mixed>
     *
     * @throws RpcError busy, when the session already has its maximum of subscribers
     */
    public function subscribe(Client $client, ?int $afterSeq, string $mode = self::MODE_FULL): array
    {
        if (!isset($this->subscribers[$client->id()]) && \count($this->subscribers) >= self::MAX_SUBSCRIBERS) {
            throw RpcError::of(ErrorCode::Busy, \sprintf('session %s already has %d subscribers', $this->sessionId(), self::MAX_SUBSCRIBERS), 'too_many_subscribers');
        }

        // Registered before anything is read: what happens from here on is
        // buffered, and flushed after the catch-up.
        $this->subscribers[$client->id()] = ['client' => $client, 'mode' => $mode, 'live' => false, 'buffer' => [], 'lastSent' => 0];

        $log = $this->host->events();
        $through = $log?->latestSeq($this->sessionId()) ?? 0;
        $pending = \array_map(self::askSummary(...), $this->host->pendingAsks());

        if ($afterSeq === null || $log === null || !$log->isReplayable($this->sessionId(), $afterSeq)) {
            $this->subscribers[$client->id()]['lastSent'] = $through;
            $this->loop->futureTick(fn () => $this->goLive($client->id()));

            return ['reset' => true, 'snapshot' => $this->snapshot(), 'throughSeq' => $through, 'pendingAsks' => $pending];
        }

        $this->subscribers[$client->id()]['lastSent'] = $afterSeq;
        $this->loop->futureTick(fn () => $this->replayPage($client->id(), $afterSeq, $through));

        return ['fromSeq' => $afterSeq + 1, 'throughSeq' => $through, 'pendingAsks' => $pending];
    }

    public function unsubscribe(Client $client): bool
    {
        if (!isset($this->subscribers[$client->id()])) {
            return false;
        }
        unset($this->subscribers[$client->id()]);

        return true;
    }

    /**
     * What a client needs to draw the session from nothing: the host's
     * snapshot plus the facts the protocol keeps (§6.8 "snapshot contents").
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $snapshot = $this->host->snapshot()->toArray();
        $snapshot['status'] = $this->status;
        $snapshot['permissionMode'] = $this->host->permissionMode()?->value;
        $snapshot['pendingAsks'] = \array_map(self::askSummary(...), $this->host->pendingAsks());
        $snapshot['queue'] = $this->queueEntries();
        $snapshot['subagents'] = \array_values($this->subagents);

        return $snapshot;
    }

    /**
     * Note a prompt the host queued behind its running turn, and announce it.
     *
     * @return string the queue id a client names it by (`session.dequeue`)
     */
    public function queued(string $text, ?int $position = null): string
    {
        $queueId = 'q' . ++$this->nextQueueId;
        $this->queue[] = ['queueId' => $queueId, 'text' => $text];
        $this->announce(SessionEvent::TURN_QUEUED, [
            'queueId' => $queueId,
            'text' => $text,
            'position' => $position ?? \count($this->host->queued()),
        ]);

        return $queueId;
    }

    /** Announce a steer handed to the running turn. */
    public function steered(?string $turnId, string $steerId): void
    {
        $this->announce(SessionEvent::TURN_STEERED, \array_filter([
            'turnId' => $turnId,
            'steerId' => $steerId,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The prompts waiting behind the running turn, each with its id.
     *
     * @return list<array{queueId: string, text: string, position: int}>
     */
    public function queueEntries(): array
    {
        $this->reconcileQueue();
        $entries = [];
        foreach ($this->queue as $position => $entry) {
            $entries[] = ['queueId' => $entry['queueId'], 'text' => $entry['text'], 'position' => $position + 1];
        }

        return $entries;
    }

    /**
     * Take the queued prompt $queueId out of the queue.
     *
     * @return bool false when no queued prompt has that id
     */
    public function dequeue(string $queueId): bool
    {
        $this->reconcileQueue();
        foreach ($this->queue as $index => $entry) {
            if ($entry['queueId'] !== $queueId) {
                continue;
            }
            $hostQueue = $this->host->queued();
            $at = \array_search($entry['text'], $hostQueue, true);
            if ($at !== false) {
                $this->host->removeQueued((int) $at);
            }
            $this->reconcileQueue('removed');

            return true;
        }

        return false;
    }

    /**
     * The live sub-agent tree: the latest activity of every delegated run
     * this feed has heard of.
     *
     * @return list<array<string, mixed>>
     */
    public function subagents(): array
    {
        return \array_values($this->subagents);
    }

    /**
     * A question in the shape `permission.requested` carries it.
     *
     * @return array<string, mixed>
     */
    public static function askSummary(PendingAsk $ask): array
    {
        return [
            'askId' => $ask->askId,
            'toolCallId' => $ask->toolCallId,
            'tool' => $ask->tool,
            'arguments' => $ask->arguments === [] ? new \stdClass() : $ask->arguments,
            'reason' => $ask->reason,
            'source' => $ask->source,
            'mode' => $ask->mode,
            'options' => $ask->suggestions,
            'alwaysScope' => $ask->alwaysScope === [] ? new \stdClass() : $ask->alwaysScope,
        ];
    }

    // ── hearing ────────────────────────────────────────────────────────

    /**
     * One event from the host. Re-entrant by deferral: an event announced
     * while another is being handled (a derived status) waits until the first
     * reached every subscriber. Never throws — the runner drops a listener
     * that does.
     */
    private function hear(SessionEvent $event): void
    {
        if ($this->hearing) {
            $this->deferred[] = $event;

            return;
        }

        $this->hearing = true;
        try {
            $this->handle($event);
            $this->offer($event);
            while ($this->deferred !== []) {
                $deferred = \array_shift($this->deferred);
                $this->handle($deferred);
                $this->offer($deferred);
            }
        } catch (\Throwable) {
            // A fan-out fault must never cost the session its listener; the
            // client recovers what it missed by replay.
            $this->deferred = [];
        } finally {
            $this->hearing = false;
        }
    }

    private function handle(SessionEvent $event): void
    {
        $envelope = EventEnvelope::fromSessionEvent($event);
        if (\in_array($event->type, self::DELTA_TYPES, true)) {
            $text = (string) ($event->data['text'] ?? '');
            $offset = $this->offsets[$event->type] ?? 0;
            $this->offsets[$event->type] = $offset + \strlen($text);
            $envelope = $envelope->withData([
                'partId' => ($event->turnId ?? 'turn') . ($event->type === SessionEvent::REASONING_DELTA ? ':reasoning' : ':text'),
                'offset' => $offset,
            ]);
        }

        foreach (\array_keys($this->subscribers) as $clientId) {
            if (!isset($this->subscribers[$clientId])) {
                continue;
            }
            if (!$this->subscribers[$clientId]['live']) {
                $this->subscribers[$clientId]['buffer'][] = $envelope;

                continue;
            }
            $this->deliver($clientId, $envelope);
        }

        $this->derive($event);
    }

    /** Offer $event to the server, which may tell every client about it. */
    private function offer(SessionEvent $event): void
    {
        if ($this->onHeard === null) {
            return;
        }
        try {
            ($this->onHeard)($event);
        } catch (\Throwable) {
            // A broadcast fault is the server's; the session's own followers
            // already have the event.
        }
    }

    /** The status, queue, timers and side tables an event implies. */
    private function derive(SessionEvent $event): void
    {
        switch ($event->type) {
            case SessionEvent::TURN_STARTED:
                $this->offsets = [];
                $this->narrationTail = '';
                $this->narrationTurnId = $event->turnId;
                $this->reconcileQueue();
                $this->setStatus(self::STATUS_BUSY);
                break;

            case SessionEvent::PERMISSION_REQUESTED:
                $this->setStatus(self::STATUS_WAITING);
                $askId = $event->data['askId'] ?? null;
                if (\is_string($askId)) {
                    $this->armAskTimeout($askId);
                }
                break;

            case SessionEvent::PERMISSION_RESOLVED:
                $askId = $event->data['askId'] ?? null;
                if (\is_string($askId) && isset($this->askTimers[$askId])) {
                    $this->loop->cancelTimer($this->askTimers[$askId]);
                    unset($this->askTimers[$askId]);
                }
                if ($this->host->pendingAsks() === [] && $this->status === self::STATUS_WAITING) {
                    $this->setStatus($this->host->isBusy() ? self::STATUS_BUSY : self::STATUS_IDLE);
                }
                break;

            case SessionEvent::TURN_COMPLETED:
                $this->setStatus(self::STATUS_IDLE);
                // The host releases its queue after the turn's end is logged;
                // a queued prompt that goes out then is a new turn.started.
                $this->loop->futureTick(function (): void {
                    $this->reconcileQueue();
                    if ($this->host->isBusy() && $this->status === self::STATUS_IDLE) {
                        $this->setStatus(self::STATUS_BUSY);
                    }
                });
                break;

            case SessionEvent::SUBAGENT_STARTED:
            case SessionEvent::SUBAGENT_PROGRESS:
            case SessionEvent::SUBAGENT_FINISHED:
                $id = $event->data['id'] ?? null;
                if (\is_string($id) || \is_int($id)) {
                    $this->subagents[(string) $id] = $event->data;
                }
                break;

            case SessionEvent::ASSISTANT_DELTA:
                $this->narrate((string) ($event->data['text'] ?? ''), $event->turnId);
                break;

            case SessionEvent::ASSISTANT_COMPLETED:
            case SessionEvent::TOOL_STARTED:
                // A client freezes the text streamed so far into a row at a
                // tool call and lands the reply as one: the next tail starts
                // after either, never repeating what is already a row.
                $this->narrationTail = '';
                break;
        }
    }

    private function setStatus(string $status): void
    {
        if ($status === $this->status) {
            return;
        }
        $this->status = $status;
        $this->announce(SessionEvent::SESSION_STATUS, ['status' => $status]);
    }

    /**
     * Queued prompts that have left the host's queue — sent as a turn, or
     * removed — become `turn.dequeued`, with $reason.
     */
    public function reconcileQueue(string $reason = 'sent'): void
    {
        if ($this->queue === []) {
            return;
        }
        $remaining = $this->host->queued();
        $kept = [];
        $gone = [];
        foreach ($this->queue as $entry) {
            $at = \array_search($entry['text'], $remaining, true);
            if ($at !== false) {
                unset($remaining[$at]);
                $kept[] = $entry;

                continue;
            }
            $gone[] = $entry;
        }
        $this->queue = $kept;
        foreach ($gone as $entry) {
            $this->announce(SessionEvent::TURN_DEQUEUED, ['queueId' => $entry['queueId'], 'text' => $entry['text'], 'reason' => $reason]);
        }
    }

    private function armAskTimeout(string $askId): void
    {
        if ($this->askTimeoutSeconds <= 0.0 || isset($this->askTimers[$askId])) {
            return;
        }
        $seconds = $this->askTimeoutSeconds;
        $this->askTimers[$askId] = $this->loop->addTimer($seconds, function () use ($askId, $seconds): void {
            unset($this->askTimers[$askId]);
            $this->host->answer($askId, PermissionReply::Reject, \sprintf('timed out after %s s', \rtrim(\rtrim(\sprintf('%.3f', $seconds), '0'), '.')));
        });
    }

    /** Keep the reply's tail, and hand it to narration subscribers at most every 2 s. */
    private function narrate(string $text, ?string $turnId): void
    {
        $this->narrationTail = \substr($this->narrationTail . $text, -self::NARRATION_TAIL_BYTES);
        $this->narrationTurnId = $turnId ?? $this->narrationTurnId;
        $now = ($this->clock)();
        if ($now - $this->narratedAt < self::NARRATION_INTERVAL_SECONDS) {
            return;
        }
        $this->narratedAt = $now;
        $envelope = $this->narration();
        if ($envelope === null) {
            return;
        }
        foreach (\array_keys($this->subscribers) as $clientId) {
            if ($this->subscribers[$clientId]['live'] && $this->narrates($clientId)) {
                $this->subscribers[$clientId]['client']->outbox()->pushEphemeral($envelope->notification(), $this->sessionId() . '|narration');
            }
        }
    }

    /**
     * The tail as an `assistant.narration`: at most {@see NARRATION_TAIL_BYTES},
     * starting on a character boundary, with the byte `offset` into the part
     * where it starts. Null when nothing has streamed since the last row.
     */
    private function narration(): ?EventEnvelope
    {
        // The byte cut may have split a character: drop its continuation bytes.
        $tail = (string) \preg_replace('/^[\x80-\xBF]+/', '', $this->narrationTail);
        if ($tail === '') {
            return null;
        }
        $turnId = $this->narrationTurnId;

        return EventEnvelope::ephemeral($this->sessionId(), EventType::ASSISTANT_NARRATION, [
            'partId' => ($turnId ?? 'turn') . ':text',
            'tail' => $tail,
            'offset' => \max(0, ($this->offsets[SessionEvent::ASSISTANT_DELTA] ?? 0) - \strlen($tail)),
        ], $turnId);
    }

    private function announce(string $type, array $data): void
    {
        $this->host->announce(SessionEvent::new($type, $data, $this->sessionId(), $this->host->turnId()));
    }

    // ── delivery ───────────────────────────────────────────────────────

    /** Whether $clientId gets narration instead of deltas right now. */
    private function narrates(string $clientId): bool
    {
        $subscriber = $this->subscribers[$clientId];

        return $subscriber['mode'] === self::MODE_NARRATION
            || $subscriber['client']->wantsNarration($this->sessionId())
            || ($subscriber['client']->outbox()->isAboveSoft() && !$subscriber['client']->isForeground($this->sessionId()));
    }

    private function deliver(string $clientId, EventEnvelope $envelope): void
    {
        $client = $this->subscribers[$clientId]['client'];

        if ($envelope->isNumbered()) {
            if ($envelope->seq <= $this->subscribers[$clientId]['lastSent']) {
                return;
            }
            $this->subscribers[$clientId]['lastSent'] = (int) $envelope->seq;
            $client->send(JsonRpc::encode($envelope->notification()));

            return;
        }

        if ($envelope->durable) {
            // A store that keeps no log numbers nothing; the event must still arrive.
            $client->send(JsonRpc::encode($envelope->notification()));

            return;
        }

        if (\in_array($envelope->type, self::DELTA_TYPES, true)) {
            if ($this->narrates($clientId)) {
                return;
            }
            $client->outbox()->pushEphemeral($envelope->notification(), $this->sessionId() . '|' . $envelope->type . '|' . ($envelope->turnId ?? ''));

            return;
        }

        $client->outbox()->pushEphemeral($envelope->notification());
    }

    /** One replay page (§6.8 step 4), then the next on a later tick, then live. */
    private function replayPage(string $clientId, int $afterSeq, int $throughSeq): void
    {
        if (!isset($this->subscribers[$clientId])) {
            return;
        }
        $log = $this->host->events();
        $rows = $log === null || $afterSeq >= $throughSeq
            ? []
            : $log->store()->sessionEvents($this->sessionId(), $afterSeq, \SugarCraft\Crush\Host\EventLog::PAGE_SIZE, $throughSeq);

        $last = $afterSeq;
        foreach ($rows as $row) {
            $this->deliver($clientId, EventEnvelope::fromLogRow($this->sessionId(), $row));
            $last = $row['seq'];
        }

        if ($rows !== [] && $last < $throughSeq) {
            $this->loop->futureTick(fn () => $this->replayPage($clientId, $last, $throughSeq));

            return;
        }

        $this->goLive($clientId);
    }

    /** §6.8 steps 5–6: flush what was heard meanwhile, deduped by seq, and go live. */
    private function goLive(string $clientId): void
    {
        if (!isset($this->subscribers[$clientId])) {
            return;
        }
        $buffer = $this->subscribers[$clientId]['buffer'];
        $this->subscribers[$clientId]['buffer'] = [];
        $this->subscribers[$clientId]['live'] = true;
        foreach ($buffer as $envelope) {
            if (isset($this->subscribers[$clientId])) {
                $this->deliver($clientId, $envelope);
            }
        }
    }
}
