<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel;

/**
 * Base class for all SSH channel-level messages.
 *
 * Channel messages correspond to SSH channel requests as defined in
 * RFC 4254: pty-req, window-change, shell, exec, signal, env, break.
 * They are dispatched through a {@see ChannelHandler} rather than
 * handled inline in transport code.
 */
abstract class ChannelMsg
{
}
