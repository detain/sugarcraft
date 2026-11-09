<?php

declare(strict_types=1);

namespace ChannelBridge;

/**
 * What the Bridge may ask of any platform: three verbs (a text chunk, an
 * approval prompt, a retired approval prompt) and a typing nudge. The
 * reply "address" is an opaque `ref` array the adapter chose when it
 * reported the inbound (a Discord channel id; a Teams
 * conversationReference) — the Bridge stores it verbatim and hands it
 * back, never reading it.
 *
 * The shared composition law (§9.4): all text arrives ALREADY chunked by
 * the OutboundChunker under the adapter's own declared limits; the
 * adapter still owns platform dialect (markdown flavor, link handling).
 */
interface ChannelInterface
{
    public function name(): string;

    /** The chunk ceiling this platform tolerates (§7.7). */
    public function textChunkLimit(): int;

    /** The line ceiling that rides with it. */
    public function maxLinesPerMessage(): int;

    /** Deliver one already-chunked piece; resolve with a platform-visible id. */
    public function deliver(array $ref, string $chunk): \React\Promise\PromiseInterface;

    /**
     * Present an approval ask. The prompt carries a callable-free answer
     * contract: when a human answers, the adapter calls back into the
     * Bridge via the closure it was handed at construction.
     *
     * @param array<string,mixed> $ask the permission.requested data
     */
    public function promptApproval(array $ref, string $sessionId, array $ask): void;

    /** Retire a presented approval prompt once answered (§7.5 single-use). */
    public function retireApproval(string $askId, string $line): void;

    /** Best-effort typing indicator while the turn runs; may no-op. */
    public function typing(array $ref): void;
}
