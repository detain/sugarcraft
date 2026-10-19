<?php

declare(strict_types=1);

namespace SugarCraft\Code\Events;

/**
 * One live-tail frame for a running tool call, carried on the same event
 * channel as the tool lifecycle frames (WEB-4, plan_comparison).
 *
 * EPHEMERAL BY CONSTRUCTION, like {@see MediaProgress} and {@see TokenDelta}:
 * the frame is never written to the session log and never replayed — a late
 * subscriber is covered by the settled `tool.finished` row, which keeps its
 * 256 KiB contract byte-for-byte. A lost frame costs one repaint, nothing more.
 *
 * THE TAIL REPLACES, IT NEVER APPENDS. Each frame carries the ring-buffer's
 * current tail of at most `tailBudget` bytes, so a client that drops a frame
 * (or subscribes mid-run) still lands on the freshest view with no gap to
 * stitch — unlike the delta types, there is no offset contract here.
 */
final class ToolProgress
{
    public function __construct(
        /** The running tool call this frame belongs to — the client's row key. */
        public readonly string $toolCallId,
        /** The tool's name, so a client can render the row without the started frame. */
        public readonly string $toolName,
        /** The bounded host-side tail of the output so far; replaces any earlier frame's tail. */
        public readonly string $tail,
        /** Frames of this call so far, from 1 — a client keeps the highest it has seen. */
        public readonly int $seq,
        /** True once bytes fell out of the ring: the tail is no longer the whole output. */
        public readonly bool $truncated,
        /** The byte budget `tail` obeys, so a client can label a clipped view honestly. */
        public readonly int $tailBudget,
    ) {
    }
}
