<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * A user's answer to a blocking permission prompt (crush_feat.md §1 E2).
 *
 * The three replies opencode's permission service offers, and the reason
 * this is not just a bool: `Once` and `Always` both permit the paused call,
 * but only `Always` outlives it, granting the same call (same tool, same
 * arguments) for the rest of this session without re-prompting — and only
 * when the permission gate was the sole hook that asked; a user hook's ask is
 * never grantable, so there `Always` acts as `Once` (audit F-P9,
 * {@see \SugarCraft\Crush\Chat::permissionGrantKey()}).
 */
enum PermissionReply: string
{
    /** Permit the paused call, and only that call. */
    case Once = 'once';

    /** Permit the paused call and, for a gate-only ask, the same call again this session. */
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
