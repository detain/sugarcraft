<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

/**
 * One in-flight `client_call` as the SERVE PARENT holds it.
 *
 * The twin of {@see PendingAsk}, deliberately one class apart: an ask settles
 * to a permission verdict that is broadcast to every watching client, while a
 * client call settles to a tool result aimed at one conversation. They share
 * the socket vocabulary, not the answer type — the same reason a relay seam
 * sits beside the approver seam instead of inside it
 * ({@see \SugarCraft\Code\Tools\RelaysClientToolCalls}).
 *
 * Like PendingAsk this class is DELIBERATELY NOT IMMUTABLE: the settle-once
 * rule is not a property of a value, it is a fact about a handle onto a live
 * set. Two paths can reach the same pending call — the client's answer
 * arriving over the socket, and the deadline timer or the turn's teardown
 * firing first — and the one that settles it must be the one whose write
 * reaches the child; every later path must read the already-chosen answer, not
 * fork a second `client_result` frame onto a socket the child matches by
 * callId. The `$settle` closure carries the frame back DOWN the channel; the
 * parent-side loop owns when it is built.
 *
 * The `callId` is a hash of the identity triple (call id, proxy name,
 * arguments), the same construction {@see PendingAsk::askId()} uses for asks:
 * the child computes it before writing, the parent recomputes it from the
 * frame to prove the payload was not altered in transit, and a client can
 * only answer a call whose exact arguments it was shown.
 */
final class PendingClientCall
{
    // The bounds are the descriptor's own, read at the wire door rather than
    // re-declared: an announced timeout outside ClientToolSpec's 1..600 is not
    // a number this loop installs a timer for, whatever spelling it arrives in.
    public const MAX_DEADLINE_SECONDS = \SugarCraft\Code\Host\ClientTools\ClientToolSpec::MAX_TIMEOUT_SECONDS;

    public const MIN_DEADLINE_SECONDS = \SugarCraft\Code\Host\ClientTools\ClientToolSpec::MIN_TIMEOUT_SECONDS;

    /**
     * Derive the wire id of a call from the triple that identifies it.
     *
     * @param array<string, mixed> $arguments
     */
    public static function callId(string $toolCallId, string $name, array $arguments): string
    {
        return substr(hash('sha256', serialize([$toolCallId, $name, $arguments])), 0, 16);
    }

    /**
     * The child→parent frame shape, built in one place so the child's writer
     * and the parent's reader cannot drift apart.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function describe(string $callId, string $toolCallId, string $name, array $arguments, int $deadlineSeconds): array
    {
        return [
            'kind' => ChildChannel::CLIENT_CALL,
            'callId' => $callId,
            'toolCallId' => $toolCallId,
            'name' => $name,
            'arguments' => $arguments,
            'deadlineSeconds' => $deadlineSeconds,
        ];
    }

    /**
     * The parent→child answer frame, likewise owned by the settled handle's
     * own semantics rather than by whichever code path answers it.
     *
     * @return array<string, mixed>
     */
    public static function resultFrame(string $callId, string $content, bool $isError): array
    {
        return [
            'kind' => ChildChannel::CLIENT_RESULT,
            'callId' => $callId,
            'content' => $content,
            'isError' => $isError,
        ];
    }

    private ?array $resolution = null;

    /**
     * @param array<string, mixed> $arguments
     * @param \Closure(self): void $settle
     */
    public function __construct(
        public readonly string $callId,
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly array $arguments,
        public readonly int $deadlineSeconds,
        private readonly \Closure $settle,
    ) {}

    /**
     * Read a `client_call` frame back into the handle the parent holds.
     *
     * Every field is checked BY HAND on purpose: the serializer on this
     * socket may only ever carry arrays, strings and integers (the
     * `allowed_classes => false` law the channel's reader keeps), so a frame
     * that needs more shape than that was forged or corrupted upstream and
     * must refuse, not adapt. A null return makes the loop answer the call as
     * unreadable rather than install a pending that can never be settled.
     *
     * @param array<array-key, mixed> $frame
     */
    public static function fromFrame(array $frame, \Closure $settle): ?self
    {
        $callId = $frame['callId'] ?? null;
        $toolCallId = $frame['toolCallId'] ?? null;
        $name = $frame['name'] ?? null;
        $arguments = $frame['arguments'] ?? null;
        $deadline = $frame['deadlineSeconds'] ?? null;

        if (!is_string($callId) || preg_match('/\A[0-9a-f]{16}\z/', $callId) !== 1) {
            return null;
        }

        if (!is_string($toolCallId) || !is_string($name) || !is_array($arguments)) {
            return null;
        }

        // A deadline outside the declared bounds is not a number this loop
        // should install a timer for: clamp-to-edge would silently honor a
        // registration that asked for "forever" or "zero", so the frame reads
        // as exactly what it is — unreadable — and the child gets the loud
        // error instead of a quiet reinterpretation.
        if (!is_int($deadline)
            || $deadline < self::MIN_DEADLINE_SECONDS
            || $deadline > self::MAX_DEADLINE_SECONDS
        ) {
            return null;
        }

        return new self($callId, $toolCallId, $name, $arguments, $deadline, $settle);
    }

    /**
     * Settle with the client's answer (or the error that stands in for one).
     * First caller wins; later ones are no-ops returning false.
     */
    public function resolve(string $content, bool $isError): bool
    {
        return $this->settleWith(['content' => $content, 'isError' => $isError]);
    }

    /** Answer as an error result carrying `$reason` as the model-facing text. */
    public function cancel(string $reason): bool
    {
        return $this->resolve($reason, true);
    }

    public function isSettled(): bool
    {
        return $this->resolution !== null;
    }

    public function content(): string
    {
        return (string) ($this->resolution['content'] ?? '');
    }

    public function isError(): bool
    {
        return (bool) ($this->resolution['isError'] ?? true);
    }

    /** @param array{content: string, isError: bool} $resolution */
    private function settleWith(array $resolution): bool
    {
        if ($this->resolution !== null) {
            return false;
        }

        $this->resolution = $resolution;
        ($this->settle)($this);

        return true;
    }
}
