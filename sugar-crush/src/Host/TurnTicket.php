<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

/**
 * What became of one submission (roadmap O-2g, Appendix O §4.3 and §6.6).
 *
 * ADMISSION, NOT OUTCOME. Appendix O §6.6 separates the answer to "did the
 * host take this?" from the durable record of what the turn later did
 * (OpenClaw's `runStarted`/`messageSeq` split): a ticket says how the
 * submission was admitted the moment it was made, and the turn's own story
 * arrives afterwards as {@see SessionEvent}s keyed by {@see $turnId}.
 *
 * One of:
 * - {@see STARTED}: a turn was dispatched for it; {@see $turnId} names it and
 *   {@see $messageId} the user row it sent, when the session persists.
 * - {@see QUEUED}: a turn was running, so it waits to go out after it;
 *   {@see $position} is its 1-based place in the queue.
 * - {@see STEERED}: it was handed to the running turn, which reads it at its
 *   next step boundary; {@see $steerId} is the turn's id for it. Like the TUI,
 *   it is also held as a follow-up, sent as the next prompt if the turn ends
 *   before reading it.
 * - {@see PENDING}: it is parked behind work that left the host's thread (a
 *   forked turn-hook chain or command-file expansion) and goes on once that
 *   answers.
 * - {@see REFUSED}: nothing was sent; {@see $reason} is the sentence a user
 *   reads, the same one the TUI writes into its transcript.
 */
final class TurnTicket
{
    public const STARTED = 'started';
    public const QUEUED = 'queued';
    public const STEERED = 'steered';
    public const PENDING = 'pending';
    public const REFUSED = 'refused';

    private const ADMISSIONS = [self::STARTED, self::QUEUED, self::STEERED, self::PENDING, self::REFUSED];

    private function __construct(
        public readonly string $admitted,
        public readonly ?string $turnId,
        public readonly ?string $messageId,
        public readonly ?string $steerId,
        public readonly ?int $position,
        public readonly ?string $reason,
        public readonly ?string $idempotencyKey,
    ) {
    }

    /**
     * A ticket admitted as $admitted (one of the class constants).
     *
     * @throws \InvalidArgumentException for an admission this class does not define
     */
    public static function new(string $admitted): self
    {
        if (!\in_array($admitted, self::ADMISSIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown admission "%s"; expected one of %s.',
                $admitted,
                implode(', ', self::ADMISSIONS),
            ));
        }

        return new self($admitted, null, null, null, null, null, null);
    }

    /** A started ticket for turn $turnId, sending user row $messageId when known. */
    public static function started(?string $turnId, ?string $messageId = null): self
    {
        return self::new(self::STARTED)->mutate(['turnId' => $turnId, 'messageId' => $messageId]);
    }

    /** A queued ticket at 1-based $position. */
    public static function queued(int $position): self
    {
        return self::new(self::QUEUED)->mutate(['position' => $position]);
    }

    /** A ticket steered into turn $turnId as $steerId. */
    public static function steered(?string $turnId, string $steerId, int $position): self
    {
        return self::new(self::STEERED)->mutate(['turnId' => $turnId, 'steerId' => $steerId, 'position' => $position]);
    }

    /** A ticket parked behind off-thread work. */
    public static function pending(): self
    {
        return self::new(self::PENDING);
    }

    /** A refused ticket, with the sentence that says why. */
    public static function refused(string $reason): self
    {
        return self::new(self::REFUSED)->mutate(['reason' => $reason]);
    }

    /** A copy carrying the sender's idempotency key back. */
    public function withIdempotencyKey(?string $key): self
    {
        return $this->mutate(['idempotencyKey' => $key]);
    }

    /** Whether anything will reach the model for this submission (now or later). */
    public function isAdmitted(): bool
    {
        return $this->admitted !== self::REFUSED;
    }

    /**
     * The wire shape (Appendix O §6.6's `admitted` field and its companions);
     * absent fields are omitted.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'admitted' => $this->admitted,
            'turnId' => $this->turnId,
            'messageId' => $this->messageId,
            'steerId' => $this->steerId,
            'position' => $this->position,
            'reason' => $this->reason,
            'idempotencyKey' => $this->idempotencyKey,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'admitted' => $this->admitted,
            'turnId' => $this->turnId,
            'messageId' => $this->messageId,
            'steerId' => $this->steerId,
            'position' => $this->position,
            'reason' => $this->reason,
            'idempotencyKey' => $this->idempotencyKey,
        ], $changes));
    }
}
