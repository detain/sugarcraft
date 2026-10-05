<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\BuiltIn\AskUserTool;
use SugarCraft\Crush\Tools\BuiltIn\PlanExitTool;
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
    /** How {@see source()} names a question the tool put itself. */
    public const TOOL_SOURCE_PREFIX = 'tool:';

    /** The tools whose call IS a question to the user (roadmap 5.7-2). */
    public const QUESTION_TOOLS = [AskUserTool::NAME, PlanExitTool::NAME];

    private ?PermissionResolved $resolution = null;

    /**
     * @param array<string, mixed>                  $arguments   the call's arguments AFTER any
     *                                                           hook rewrite — what will run
     * @param list<string>                          $suggestions the {@see PermissionReply} values on offer
     * @param array<string, string>                 $alwaysScope what an `always` would cover
     *                                                           (e.g. `['tool' => 'Bash']`);
     *                                                           empty when `always` is not on offer
     * @param \Closure(PermissionResolved): void     $settle
     * @param ?\SugarCraft\Crush\Permissions\AskOrigin $origin the delegated run that asked
     *                                                           (roadmap P-E2), when the
     *                                                           process that put the question
     *                                                           knew it; display only
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
        public readonly ?\SugarCraft\Crush\Permissions\AskOrigin $origin = null,
        /**
         * Why this question is put every time and `always` cannot remember it
         * (a hook asked, or the gate flagged a security finding), for the
         * modal's "this always asks (…)" line; '' when `always` is offered,
         * or for the model's own questions ({@see question()}).
         */
        public readonly string $alwaysAsks = '',
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
    public static function describe(ToolCall $call, HookResult $ask, string $mode, ?string $projectRoot = null): array
    {
        $gateOnly = $ask->isRememberable();
        $scope = [];
        if ($gateOnly) {
            $scope = ['tool' => $call->name()];
            $patterns = \SugarCraft\Crush\Permissions\SessionPermissionMemo::patternsFor($call->name(), $call->arguments(), $projectRoot);
            if ($patterns !== []) {
                // What `always` will remember, for a client to show (the web
                // card's label) — the same pattern the TUI's `a` row names.
                $scope['pattern'] = $patterns[\count($patterns) - 1];
            }
        }
        $alwaysAsks = $gateOnly ? '' : self::alwaysAsksReason($call->name(), $ask);

        return [
            'kind' => ChildChannel::ASK,
            'askId' => self::askId($call->id(), $call->name(), $call->arguments()),
            'toolCallId' => $call->id(),
            'tool' => $call->name(),
            'arguments' => $call->arguments(),
            'reason' => $ask->message,
            'source' => self::source($call, $ask),
            'mode' => $mode,
            'suggestions' => $gateOnly
                ? [PermissionReply::Once->value, PermissionReply::Always->value, PermissionReply::Reject->value]
                : [PermissionReply::Once->value, PermissionReply::Reject->value],
            'alwaysScope' => $scope,
            // P-E2: which delegated run asked, when this process was told
            // (a relayed member's question); absent for the turn's own.
        ] + ($alwaysAsks === '' ? [] : ['alwaysAsks' => $alwaysAsks])
            + (($origin = \SugarCraft\Crush\Permissions\AskOrigin::current()) === null ? [] : ['origin' => $origin->toArray()]);
    }

    /**
     * Why a question `always` cannot remember is put every time, in words
     * for the modal — '' for the model's own questions, which say what they
     * are themselves.
     */
    public static function alwaysAsksReason(string $tool, HookResult $ask): string
    {
        if ($ask->askEveryTime !== null) {
            return $ask->askEveryTime;
        }
        $hooks = array_values(array_diff($ask->askedBy, [PermissionGateHook::NAME]));
        if ($hooks !== []) {
            return \SugarCraft\Crush\Lang::t('chat.permission.always_asks.hook', ['hooks' => implode(', ', $hooks)]);
        }

        return in_array($tool, self::QUESTION_TOOLS, true) ? '' : \SugarCraft\Crush\Lang::t('chat.permission.always_asks.unattributed');
    }

    /**
     * Who put the question: `gate` for the permission gate alone,
     * `hook:<names>` when hooks asked, and `tool:<name>` for an ask no hook
     * raised — the call's own question, which is what `AskUser` and `PlanExit`
     * put through {@see \SugarCraft\Crush\Tools\RelaysPermissionAsks}
     * (roadmap 5.7-2). That last one used to read `hook:unknown`, naming a
     * hook that did not exist.
     */
    public static function source(ToolCall $call, HookResult $ask): string
    {
        if ($ask->askedOnlyBy(PermissionGateHook::NAME)) {
            return 'gate';
        }

        return $ask->askedBy === []
            ? self::TOOL_SOURCE_PREFIX . $call->name()
            : 'hook:' . implode(',', $ask->askedBy);
    }

    /**
     * The question tool whose own question this is — `AskUser` or `PlanExit`
     * — or null for a permission question about a call. Read off the
     * {@see source()} the asking process wrote, so a hook's ask about an
     * `AskUser` call stays a permission question.
     */
    public function question(): ?string
    {
        return \in_array($this->tool, self::QUESTION_TOOLS, true) && $this->source === self::TOOL_SOURCE_PREFIX . $this->tool
            ? $this->tool
            : null;
    }

    /**
     * The choices an `AskUser` question offers, in order (option 1 is the
     * recommended one) — what the modal's number keys pick. Empty for any
     * other ask, or one whose arguments carry none.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        if ($this->question() !== AskUserTool::NAME || !\is_array($this->arguments['options'] ?? null)) {
            return [];
        }

        $choices = [];
        foreach ($this->arguments['options'] as $option) {
            if (\is_string($option) && $option !== '') {
                $choices[] = $option;
            }
        }

        return \array_slice($choices, 0, AskUserTool::MAX_OPTIONS);
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
            \SugarCraft\Crush\Permissions\AskOrigin::fromArray($frame['origin'] ?? null),
            mb_strcut($string($frame['alwaysAsks'] ?? null), 0, 512, 'UTF-8'),
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
     * @param ?string $note on a reject, feedback for the model; on a
     *                      permitting reply to a {@see question()}, the
     *                      user's answer (a choice's number picks it);
     *                      clipped to {@see ChildChannel::MAX_NOTE_BYTES}
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
