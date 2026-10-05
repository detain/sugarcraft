<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Switch the session's permission mode (roadmap 5.7-1, decision D8): what
 * `Alt+M` sends to {@see Chat}, and the door any other surface uses to do the
 * same — a menu row, a `PlanExit` approval (roadmap 5.7-2).
 *
 * `$mode` null is the TOGGLE: into `plan` from any other mode, and out of
 * `plan` back to the mode it was entered from
 * ({@see \SugarCraft\Crush\Permissions\PermissionGate::toggledFrom()}), or
 * `default` when the session started in `plan`. A named mode is switched to
 * exactly.
 *
 * Chat applies it between turns only: a running turn keeps the gate it forked
 * with, so a switch mid-turn is refused with a notice rather than half-applied.
 */
final readonly class PermissionModeToggledMsg implements Msg
{
    public function __construct(
        public ?PermissionMode $mode = null,
        /** How `/permissions` names where the switch came from; null: `Alt+M`'s. */
        public ?string $source = null,
    ) {}
}
