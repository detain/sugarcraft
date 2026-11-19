<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use React\EventLoop\LoopInterface;
use SugarCraft\Plugins\Host\HostServices;
use SugarCraft\Plugins\PluginContext;

/**
 * The host's factory for the context a channel starts with (PL-1c-i, plan 4.2).
 *
 * The value a channel receives is the library's {@see PluginContext}: identity,
 * the loop, the log, the settings section frozen at launch and the state
 * directory. This class only builds it, so the registry's call shape is
 * unchanged.
 *
 * $inbound is accepted for the call shape and deliberately not carried. The
 * durable inbound ledger is host-only (plan default g): a channel reaches it
 * only through {@see ChannelRegistry::inboundLog()}, never through its context.
 */
final class ChannelContext
{
    /**
     * @param \Closure(string): void|null $log null discards every line
     * @param array<string, mixed>       $config the channel's settings section, frozen at launch
     * @param HostServices|null          $host the in-process host calls this channel runs against (PL-5d)
     */
    public static function for(
        string $bundle,
        string $channel,
        ?string $root,
        LoopInterface $loop,
        ?\Closure $log = null,
        ?ChannelInboundLog $inbound = null,
        array $config = [],
        ?string $stateDir = null,
        ?HostServices $host = null,
    ): PluginContext {
        return PluginContext::for($bundle, $channel, $root, $loop, $log, $config, $stateDir, $host);
    }
}
