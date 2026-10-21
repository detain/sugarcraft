<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use React\Promise\Deferred;
use SugarCraft\Code\App\PermissionRequestMsg;

/**
 * The prepared half of raising a permission prompt: what
 * {@see PermissionPrompt::armAsk()} decides an ask goes up.
 *
 * Three values, no behaviour: the (possibly label-prepended) request the modal
 * will show, the {@see Deferred} whose promise suspends the turn, and the
 * terminal-notification title built from the same origin lookup. W5-A split:
 * the decision moved out of Chat with the generation guard that owns it, the
 * `mutate()`/`Cmd` plumbing that must return a Chat stayed, and this object is
 * the seam between them.
 */
final class ArmedPrompt
{
    public function __construct(
        public readonly PermissionRequestMsg $ask,
        public readonly Deferred $deferred,
        public readonly string $notifyTitle,
    ) {
    }
}
