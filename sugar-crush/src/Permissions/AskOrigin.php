<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Message;

/**
 * Which delegated run a permission question came from (roadmap P-E2,
 * Appendix P §5.4): a sub-agent's ask is shown in the parent's modal AND
 * inline in that agent's view, and both need to know whose it is.
 *
 * WHERE IT IS KNOWN. A parallel Task member asks over its own
 * {@see \SugarCraft\Crush\Support\PermissionAskRelay} (roadmap 1.C-5); the
 * process reading that relay — the turn child's reap loop — knows which
 * member it is, so it names the origin around the question it puts on
 * ({@see during()}), and {@see PendingAsk::describe()} writes it into the
 * `ask` frame as `origin`. A lone Task runs inside the turn child and asks
 * through the turn's own channel, so its question carries no origin; the
 * parent still recognises it ({@see locate()}): a question about a call the
 * main turn never made, while exactly one delegated run of the turn is going.
 *
 * Display data, never authority: nothing about the answer depends on it.
 * Every field is clipped, because a run's name comes from an agent preset.
 */
final class AskOrigin
{
    /** Bytes kept of each field. */
    public const MAX_FIELD_BYTES = 256;

    private static ?self $current = null;

    public function __construct(
        public readonly string $agentId,
        public readonly string $agentName = '',
        public readonly string $parentCallId = '',
    ) {
    }

    /** @return array{agentId: string, agentName: string, parentCallId: string} */
    public function toArray(): array
    {
        return ['agentId' => $this->agentId, 'agentName' => $this->agentName, 'parentCallId' => $this->parentCallId];
    }

    /**
     * The origin an `ask` frame carries, or null when it carries none (or
     * one this build cannot read — the frame crossed a process boundary).
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!\is_array($raw)) {
            return null;
        }
        $field = static fn (mixed $v): string => \is_string($v) ? mb_strcut($v, 0, self::MAX_FIELD_BYTES, 'UTF-8') : '';
        $origin = new self($field($raw['agentId'] ?? null), $field($raw['agentName'] ?? null), $field($raw['parentCallId'] ?? null));

        return $origin->agentId === '' && $origin->parentCallId === '' ? null : $origin;
    }

    /** The origin named around the question being put right now in this process, if any. */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Run $put with $origin named as the origin of any question it raises,
     * then restore whatever was named before.
     *
     * @template T
     *
     * @param \Closure(): T $put
     *
     * @return T
     */
    public static function during(?self $origin, \Closure $put): mixed
    {
        $before = self::$current;
        self::$current = $origin;
        try {
            return $put();
        } finally {
            self::$current = $before;
        }
    }

    /**
     * The delegated run $ask belongs to, as the parent's live registry knows
     * it, or null when it is the main turn's own question (or no run can be
     * named).
     *
     * An origin the frame carries is looked up by agent id, then by the Task
     * call it runs under. A question without one is a sub-agent's only when
     * the call it asks about is not one of the main turn's running calls,
     * and it is put down to a run only when exactly one is going under those
     * calls — never guessed between two.
     *
     * @param list<Message> $history the main transcript (its running calls are its pending rows)
     */
    public static function locate(PendingAsk $ask, array $history, AgentLiveRegistry $registry): ?AgentLiveState
    {
        $origin = $ask->origin;
        if ($origin !== null) {
            $state = $origin->agentId === '' ? null : $registry->get($origin->agentId);
            if ($state !== null) {
                return $state;
            }
            if ($origin->parentCallId === '') {
                return null;
            }
            $going = array_values(array_filter(
                $registry->forCall($origin->parentCallId),
                static fn (AgentLiveState $s): bool => !$s->isFinished(),
            ));

            return \count($going) === 1 ? $going[0] : null;
        }

        $pending = [];
        foreach ($history as $message) {
            if ($message->pendingToolCallId !== null) {
                $pending[$message->pendingToolCallId] = true;
            }
        }
        if ($pending === [] || isset($pending[$ask->toolCallId])) {
            return null;
        }

        $going = [];
        foreach (array_keys($pending) as $callId) {
            foreach ($registry->forCall((string) $callId) as $state) {
                if (!$state->isFinished()) {
                    $going[] = $state;
                }
            }
        }

        return \count($going) === 1 ? $going[0] : null;
    }

    /**
     * The modal's line naming the run — `Asked by sub-agent explore (find
     * the login handler)` — for {@see \SugarCraft\Crush\Chat}'s prompt.
     */
    public static function label(AgentLiveState $state): string
    {
        $name = $state->name !== '' ? $state->name : $state->id;
        $description = trim($state->description);

        return 'Asked by sub-agent ' . $name . ($description === '' ? '' : ' (' . $description . ')');
    }
}
