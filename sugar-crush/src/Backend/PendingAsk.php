<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The reply handle for one open ASK raised inside a running turn (Appendix O
 * §5.1, decision D1) — what a {@see \SugarCraft\Crush\Events\PermissionAsked}
 * hands its receiver so a LATER answer can reach the process that is blocked
 * on the question.
 *
 * Deliberately not immutable, and the one class in this namespace that is
 * not: it is a handle onto a single settle-once fact, in the same family as
 * React's `Deferred`. The question's description is public `readonly`; the
 * settlement is private and moves exactly once, from open to replied or to
 * cancelled. Every later {@see reply()} or {@see cancel()} is a no-op that
 * answers false, which is what makes "the turn was torn down while the modal
 * was open, then the user pressed y" harmless — no frame is written to a
 * socket that is already closed, and nothing throws into the UI.
 *
 * WHO SETTLES IT. The `$settle` closure belongs to whoever raised the ask:
 * {@see EngineBackend::completeInteractive()}'s parent writes the
 * `ask_reply` frame and re-arms the idle ceiling, the pcntl-less fallback
 * just records the answer for the in-process approver to read. This class
 * only guarantees the closure is called once, with the clipped note.
 */
final class PendingAsk
{
    private ?PermissionResolved $resolution = null;

    /**
     * @param array<string, mixed>                  $arguments   the call's arguments AFTER any
     *                                                           hook rewrite — what will run
     * @param list<string>                          $suggestions the {@see PermissionReply} values on offer
     * @param array<string, string>                 $alwaysScope what an `always` would cover
     *                                                           (e.g. `['tool' => 'Bash']`);
     *                                                           empty when `always` is not on offer
     * @param \Closure(PermissionResolved): void     $settle
     */
    public function __construct(
        public readonly string $askId,
        public readonly string $toolCallId,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly string $reason,
        public readonly string $source,
        public readonly string $mode,
        public readonly array $suggestions,
        public readonly array $alwaysScope,
        private readonly \Closure $settle,
    ) {
    }

    /**
     * The D1 ask id: the first 16 hex digits of a hash over the call's id,
     * tool and (rewritten) arguments.
     *
     * Content-derived rather than a counter so that both ends — and, later, a
     * server client replaying the event log — name the same question the same
     * way without sharing any state. The arguments are part of it because the
     * id has to change when a hook rewrite changes what is being asked about.
     *
     * @param array<string, mixed> $arguments
     */
    public static function askId(string $toolCallId, string $tool, array $arguments): string
    {
        return substr(hash('sha256', serialize([$toolCallId, $tool, $arguments])), 0, 16);
    }

    /**
     * The `ask` frame for $call, which {@see \SugarCraft\Crush\Runtime::settleAsk()}
     * has ALREADY rebuilt with the question's rewrite applied — so the
     * arguments here are what will execute, never the model's proposal.
     *
     * `always` is offered only for a question the permission gate asked
     * alone: that is the one question that is the same question for the same
     * call ({@see HookResult::askedOnlyBy()} on why a user hook's "confirm
     * before touching prod" must be put every time), and exactly the rule
     * {@see PermissionReply::Always} already documents.
     *
     * @return array<string, mixed>
     */
    public static function describe(ToolCall $call, HookResult $ask, string $mode): array
    {
        $gateOnly = $ask->askedOnlyBy(PermissionGateHook::NAME);
        $askers = $ask->askedBy;

        return [
            'kind' => ChildChannel::ASK,
            'askId' => self::askId($call->id(), $call->name(), $call->arguments()),
            'toolCallId' => $call->id(),
            'tool' => $call->name(),
            'arguments' => $call->arguments(),
            'reason' => $ask->message,
            'source' => $gateOnly ? 'gate' : 'hook:' . ($askers === [] ? 'unknown' : implode(',', $askers)),
            'mode' => $mode,
            'suggestions' => $gateOnly
                ? [PermissionReply::Once->value, PermissionReply::Always->value, PermissionReply::Reject->value]
                : [PermissionReply::Once->value, PermissionReply::Reject->value],
            'alwaysScope' => $gateOnly ? ['tool' => $call->name()] : [],
        ];
    }

    /**
     * Rebuild the handle from an `ask` frame, or null when the frame is not a
     * shape {@see describe()} writes. The frame was unserialized with
     * `allowed_classes => false`, so every field is checked rather than
     * trusted; an out-of-shape `suggestions` entry is dropped, and a list
     * that ends up without `reject` gets it back — refusing must always be on
     * offer.
     *
     * @param array<string, mixed>               $frame
     * @param \Closure(PermissionResolved): void $settle
     */
    public static function fromFrame(array $frame, \Closure $settle): ?self
    {
        $askId = $frame['askId'] ?? null;
        $toolCallId = $frame['toolCallId'] ?? null;
        $tool = $frame['tool'] ?? null;
        $arguments = $frame['arguments'] ?? null;
        if (!is_string($askId) || preg_match('/\A[0-9a-f]{16}\z/', $askId) !== 1
            || !is_string($toolCallId) || !is_string($tool) || $tool === '' || !is_array($arguments)) {
            return null;
        }

        $suggestions = [];
        foreach ((array) ($frame['suggestions'] ?? []) as $value) {
            if (is_string($value) && PermissionReply::tryFrom($value) !== null && !in_array($value, $suggestions, true)) {
                $suggestions[] = $value;
            }
        }
        if (!in_array(PermissionReply::Reject->value, $suggestions, true)) {
            $suggestions[] = PermissionReply::Reject->value;
        }

        $alwaysScope = [];
        foreach ((array) ($frame['alwaysScope'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $alwaysScope[$key] = $value;
            }
        }

        $string = static fn (mixed $v): string => is_string($v) ? $v : '';

        return new self(
            $askId,
            $toolCallId,
            $tool,
            $arguments,
            $string($frame['reason'] ?? null),
            $string($frame['source'] ?? null),
            $string($frame['mode'] ?? null),
            $suggestions,
            $alwaysScope,
            $settle,
        );
    }

    /**
     * Whether $reply is one of the answers this question offers.
     */
    public function offers(PermissionReply $reply): bool
    {
        return in_array($reply->value, $this->suggestions, true);
    }

    /**
     * Answer the question. An `always` on a question that does not offer it
     * settles as `once`: the caller gets the call it approved, and nothing
     * is remembered for a question that must be put every time.
     *
     * @param ?string $note on a reject, feedback for the model; clipped to
     *                      {@see ChildChannel::MAX_NOTE_BYTES}
     *
     * @return bool false when the question was already settled — answered
     *              before, or cancelled with the turn — and nothing was sent
     */
    public function reply(PermissionReply $reply, ?string $note = null): bool
    {
        if ($reply === PermissionReply::Always && !$this->offers($reply)) {
            $reply = PermissionReply::Once;
        }

        return $this->settleWith(PermissionResolved::replied($this->askId, $reply, ChildChannel::clipNote($note ?? '')));
    }

    /**
     * Settle the question as unanswered: the turn is going away under it.
     *
     * @return bool false when it was already settled
     */
    public function cancel(string $reason = ''): bool
    {
        return $this->settleWith(PermissionResolved::cancelled($this->askId, ChildChannel::clipNote($reason)));
    }

    public function isSettled(): bool
    {
        return $this->resolution !== null;
    }

    /**
     * How the question was settled, or null while it is still open.
     */
    public function resolution(): ?PermissionResolved
    {
        return $this->resolution;
    }

    private function settleWith(PermissionResolved $resolution): bool
    {
        if ($this->resolution !== null) {
            return false;
        }
        $this->resolution = $resolution;
        ($this->settle)($resolution);

        return true;
    }
}
