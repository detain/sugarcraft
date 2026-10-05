<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

/**
 * One thing that happened in a session, in the shape a host broadcasts it
 * (roadmap O-2f, Appendix O §6.4 envelope / §6.5 catalogue).
 *
 * WHY A VALUE AND NOT AN ARRAY. Every producer — {@see TurnRunner} for the
 * turn's own lifecycle, {@see TranscriptProjector} for the rows a tool event
 * becomes — and every consumer — the {@see EventLog} that makes a durable one
 * replayable, and later the protocol layer that frames it for a socket —
 * agree on one set of fields here instead of on a convention about array
 * keys. The `type` strings are Appendix O §6.5's, verbatim, because that
 * catalogue is what a web client will switch on.
 *
 * DURABLE OR EPHEMERAL IS DECIDED BY THE TYPE, not by the caller: §6.5 marks
 * each type D or E, and {@see DURABLE_TYPES} is that column. A durable event
 * is written to the session's {@see EventLog} before anyone hears it and so
 * carries the `seq` the log gave it; an ephemeral one (a token delta, a step
 * tick) is live only, never carries a seq, and a reader never advances its
 * cursor on one. Every ephemeral stream is bracketed by durable events —
 * `turn.started` … `turn.completed` — which is the invariant that lets a
 * client that dropped the deltas repair the text from the durable
 * `assistant.completed`.
 *
 * ROWS ARE NAMED BY IDENTITY, NOT POSITION. An event about a transcript row
 * carries that row's `messageId`/`ref` from
 * {@see TranscriptStore::identify()} — the id the row is saved under — so a
 * replay, a compaction rewrite or a `/rewind` cannot make an event point at
 * the wrong row the way an index would.
 *
 * Immutable: {@see withSeq()} is how the log stamps the number it allocated.
 */
final class SessionEvent
{
    public const TURN_STARTED = 'turn.started';
    public const TURN_COMPLETED = 'turn.completed';
    public const TURN_STEP = 'turn.step';
    public const MESSAGE_CREATED = 'message.created';
    public const ASSISTANT_DELTA = 'assistant.delta';
    public const REASONING_DELTA = 'reasoning.delta';
    public const ASSISTANT_COMPLETED = 'assistant.completed';
    public const TOOL_STARTED = 'tool.started';
    public const TOOL_FINISHED = 'tool.finished';
    public const PERMISSION_REQUESTED = 'permission.requested';
    public const PERMISSION_RESOLVED = 'permission.resolved';
    public const SUBAGENT_STARTED = 'subagent.started';
    public const SUBAGENT_PROGRESS = 'subagent.progress';
    public const SUBAGENT_FINISHED = 'subagent.finished';
    public const USAGE_UPDATED = 'usage.updated';
    public const SPEND_CAP_BREACHED = 'spend_cap.breached';
    public const SESSION_STATUS = 'session.status';
    public const TURN_QUEUED = 'turn.queued';
    public const TURN_DEQUEUED = 'turn.dequeued';
    public const TURN_STEERED = 'turn.steered';
    public const COMPACTION_COMPLETED = 'compaction.completed';
    public const TODO_UPDATED = 'todo.updated';

    /**
     * The types Appendix O §6.5 marks durable (D) among those this package
     * produces; every other type is ephemeral.
     */
    public const DURABLE_TYPES = [
        self::MESSAGE_CREATED,
        self::TURN_STARTED,
        self::TURN_COMPLETED,
        self::ASSISTANT_COMPLETED,
        self::TOOL_STARTED,
        self::TOOL_FINISHED,
        self::PERMISSION_REQUESTED,
        self::PERMISSION_RESOLVED,
        self::SUBAGENT_STARTED,
        self::SUBAGENT_FINISHED,
        self::USAGE_UPDATED,
        self::SPEND_CAP_BREACHED,
        self::SESSION_STATUS,
        self::TURN_QUEUED,
        self::TURN_DEQUEUED,
        self::TURN_STEERED,
        self::COMPACTION_COMPLETED,
        self::TODO_UPDATED,
    ];

    /** `turn.completed`'s `stopReason` values (Appendix O §6.5). */
    public const STOP_END_TURN = 'end_turn';
    public const STOP_MAX_STEPS = 'max_steps';
    public const STOP_LENGTH = 'length';
    public const STOP_CANCELLED = 'cancelled';
    public const STOP_SPEND_CAP = 'spend_cap';
    public const STOP_ERROR = 'error';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly ?string $sessionId,
        public readonly ?string $turnId,
        public readonly int $ts,
        public readonly ?int $seq,
    ) {
    }

    /**
     * An event of $type. Whether it is durable follows from the type
     * ({@see isDurable()}).
     *
     * @param array<string, mixed> $data the event's `data`; JSON-encodable
     * @param int|null $tsMs milliseconds since the epoch; null is now
     *
     * @throws \InvalidArgumentException on an empty type
     */
    public static function new(string $type, array $data = [], ?string $sessionId = null, ?string $turnId = null, ?int $tsMs = null): self
    {
        if (trim($type) === '') {
            throw new \InvalidArgumentException('A session event needs a non-empty type.');
        }

        return new self($type, $data, $sessionId, $turnId, $tsMs ?? (int) floor(microtime(true) * 1000), null);
    }

    /** Whether $type is one Appendix O §6.5 marks durable. */
    public static function typeIsDurable(string $type): bool
    {
        return \in_array($type, self::DURABLE_TYPES, true);
    }

    /** Whether this event is written to the session's log before it is heard. */
    public function isDurable(): bool
    {
        return self::typeIsDurable($this->type);
    }

    /**
     * A copy stamped with the seq the {@see EventLog} allocated for it.
     *
     * @throws \LogicException on an ephemeral event, which never carries one
     */
    public function withSeq(int $seq): self
    {
        if (!$this->isDurable()) {
            throw new \LogicException(sprintf('An ephemeral "%s" event never carries a seq.', $this->type));
        }

        return new self($this->type, $this->data, $this->sessionId, $this->turnId, $this->ts, $seq);
    }

    /**
     * The payload a durable event is logged with: its `data`, plus the
     * `turnId` the envelope would carry, so a replayed row says which turn it
     * belonged to without a second column.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->turnId === null ? $this->data : ['turnId' => $this->turnId, ...$this->data];
    }

    /**
     * The Appendix O §6.4 envelope. `seq` is present only on a durable event
     * the log has numbered; `turnId` only inside a turn.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'sessionId' => $this->sessionId,
            'seq' => $this->seq,
            'type' => $this->type,
            'ts' => $this->ts,
            'turnId' => $this->turnId,
            'durable' => $this->isDurable(),
            'data' => $this->data,
        ], static fn (mixed $value, string $key): bool => $value !== null || $key === 'sessionId', ARRAY_FILTER_USE_BOTH);
    }
}
