<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

/**
 * One plugin-contributed channel, in its lifecycle shape (code-chat P1,
 * spec §10.2/§10.3).
 *
 * The lifecycle is the spec's four moves: the loader brings the class into
 * this process under the trust gate (load), {@see ChannelRegistry::register()}
 * accepts or refuses it by name (register), serve booting starts it with a
 * {@see ChannelContext} (start), and shutdown disposes every started channel
 * in reverse (dispose). A channel that never passed load never reaches the
 * registry; a channel refused at register never starts.
 *
 * Every payload crossing this boundary is a JSON-serializable array — the
 * payload law of spec §10.1 (no closures, no live objects, which is what
 * buys a future out-of-process tier for free). Capability is declared by
 * which extra interface the class implements: {@see SubscribesToSessionEvents}
 * for the in-process event feed (GAP-3), {@see AcceptsHttpRequests} for an
 * HTTP webhook route under `/channels/`. A plain ChannelInterface is a
 * long-lived transport (a Discord or Teams client) that dials out itself.
 *
 * Timers a channel arms must ride {@see ChannelContext}->loop and be unwired
 * in dispose() — the house LoopPin/SharedLoopResidue law the spec §10.2
 * names, and the difference between a clean serve stop and a hanging one.
 */
interface ChannelInterface
{
    /**
     * The channel's name, unique across ALL accepted channels and the
     * reserved built-in namespace. The registry enforces this at register
     * time and refuses collisions (ruling 2c): a plugin channel may never
     * shadow a built-in or another accepted bundle. Names are matched
     * exactly, never case-folded, and must be a single safe path segment —
     * the name is also the webhook route slug under `/channels/<name>`.
     */
    public function name(): string;

    /**
     * Bring the channel up. Called once, at serve boot, after the trust
     * gate and collision door have both passed. Throwing refuses the
     * channel without unrolling the launch: the registry records the
     * refusal and keeps every other channel running.
     */
    public function start(ChannelContext $context): void;

    /**
     * Symmetric teardown (spec §10.2): close sockets, cancel every timer
     * armed through the context loop, release anything held. Called once
     * per started channel at shutdown, in reverse start order. A throw
     * here is logged and swallowed by the registry — one bad channel may
     * not strand the others' teardown.
     */
    public function dispose(): void;
}
