<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Usage;

/**
 * Landing Msg for one prompt-cache keep-warm ping (C-CTX-5.d,
 * `promptCache.keepWarm`).
 *
 * {@see Chat} dispatches the ping on the session engine through the same
 * frozen-prefix route the automatic fold uses, and the settled call lands
 * here: billed like any other call under the `warm` source label, and
 * counted by {@see \SugarCraft\Code\Backend\CacheHealthWatch} as the warm
 * hit it was meant to be. `$usage` is null when the call failed — the
 * landing then only releases the in-flight latch, because a call that never
 * answered warmed nothing and billed nothing.
 *
 * Carries the asking session's key rather than trusting the Chat to still
 * be the one that sent the ping, mirroring how the fold's landing identifies
 * its transcript: a reply that arrives after a session switch must not be
 * booked onto the wrong ledger.
 */
final class KeepWarmPingedMsg implements Msg
{
    public function __construct(
        public readonly string $sessionId,
        public readonly ?Usage $usage = null,
    ) {
    }
}
