<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Permissions\PermissionReply;

/**
 * The user's answer to the {@see PermissionRequestMsg} currently blocking a
 * turn (crush_feat.md §1 E2).
 *
 * Delivered as a Msg rather than read straight off a keystroke so the answer
 * can also come from a palette action, a test, or a non-interactive
 * front-end — {@see Chat} only ever sees "the decision", never the key that
 * produced it.
 */
final class PermissionReplyMsg implements Msg
{
    public function __construct(
        public readonly PermissionReply $reply,
    ) {}
}
