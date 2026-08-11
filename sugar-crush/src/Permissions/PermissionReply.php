<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * A user's answer to a blocking permission prompt (crush_feat.md §1 E2).
 *
 * The three replies opencode's permission service offers, and the reason
 * this is not just a bool: `Once` and `Always` both permit the paused call,
 * but only `Always` outlives it, granting every later call of the same tool
 * in this session without re-prompting.
 */
enum PermissionReply: string
{
    /** Permit the paused call, and only that call. */
    case Once = 'once';

    /** Permit the paused call and every later call of the same tool this session. */
    case Always = 'always';

    /** Refuse the paused call; the turn ends without running it. */
    case Reject = 'reject';

    /**
     * True when this reply lets the paused tool call run.
     *
     * An allow-list of the two permitting replies rather than
     * `!== Reject`, for the same reason {@see \SugarCraft\Crush\Hooks\HookResult::permitsExecution()}
     * is: a reply added later must not widen permission just by not being a
     * rejection.
     */
    public function permits(): bool
    {
        return $this === self::Once || $this === self::Always;
    }
}
