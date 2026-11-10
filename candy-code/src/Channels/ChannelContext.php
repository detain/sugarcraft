<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use React\EventLoop\LoopInterface;

/**
 * What a channel is allowed to know about the launch it runs in.
 *
 * D6 (owner ruling, deferred per-session tool policy) needs this object to
 * carry identity WITHOUT carrying policy: the registry keys channels by
 * bundle+name and delegates every decision to the channel instance through
 * exactly this context. A later policy layer can add a per-session rule
 * scope here — or hand channels a scoped child context per session — with
 * no reshape of the registry, because no rule state lives in the registry
 * itself (spec §10.2, ruling D6).
 *
 * Immutable value object; constructed once per channel at boot.
 */
final class ChannelContext
{
    /**
     * @param \Closure(string): void $log one line per event; never a secret
     */
    private function __construct(
        public readonly string $bundle,
        public readonly string $channel,
        public readonly ?string $root,
        public readonly LoopInterface $loop,
        public readonly \Closure $log,
        public readonly ?ChannelInboundLog $inbound = null,
    ) {
    }

    /**
     * @param \Closure(string): void|null $log
     *
     * $inbound is the spec §9.5 Track B durable ledger: a channel that
     * accepts inbound from outside hands each event to the ledger BEFORE
     * dispatching it and acks after, so a crash between the two replays
     * instead of losing (or double-acting — the ledger dedupes). Null when
     * the launch runs no channel boot, which is the byte-identical pre-§9.5
     * shape.
     */
    public static function for(
        string $bundle,
        string $channel,
        ?string $root,
        LoopInterface $loop,
        ?\Closure $log = null,
        ?ChannelInboundLog $inbound = null,
    ): self {
        return new self(
            $bundle,
            $channel,
            $root,
            $loop,
            $log ?? static function (string $line): void {
            },
            $inbound,
        );
    }
}
