<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Acp;

use SugarCraft\Crush\Host\SessionEvent;

/**
 * A `turn.completed` stop reason (Appendix O §6.5) as the Agent Client
 * Protocol's `session/prompt` answer names it (roadmap 5.9-1).
 *
 * ACP has five: `end_turn`, `max_tokens`, `max_turn_requests`, `refusal`,
 * `cancelled`. Ours has six, and the two that have no exact twin are mapped
 * by what the editor should do next rather than by name:
 *
 * - `spend_cap` → `max_turn_requests`: the turn stopped on a limit the user
 *   set, the same thing the step budget is to an editor — prompting again is
 *   how to go on, once the cap allows it;
 * - `error` has no stop reason at all: the prompt is answered with a
 *   JSON-RPC error carrying the failure ({@see isError()}), which is how an
 *   ACP client shows a turn that broke rather than ended.
 */
final class StopReasonMap
{
    public const END_TURN = 'end_turn';
    public const MAX_TOKENS = 'max_tokens';
    public const MAX_TURN_REQUESTS = 'max_turn_requests';
    public const REFUSAL = 'refusal';
    public const CANCELLED = 'cancelled';

    private function __construct()
    {
    }

    /** The ACP stop reason for $stopReason; an unknown one ended the turn. */
    public static function toAcp(?string $stopReason): string
    {
        return match ($stopReason) {
            SessionEvent::STOP_LENGTH => self::MAX_TOKENS,
            SessionEvent::STOP_MAX_STEPS, SessionEvent::STOP_SPEND_CAP => self::MAX_TURN_REQUESTS,
            SessionEvent::STOP_CANCELLED => self::CANCELLED,
            default => self::END_TURN,
        };
    }

    /** Whether $stopReason is a failure the prompt answers with an error. */
    public static function isError(?string $stopReason): bool
    {
        return $stopReason === SessionEvent::STOP_ERROR;
    }
}
