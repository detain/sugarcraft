<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

/**
 * One live-render preview frame for an in-flight image generation, carried on
 * the same event channel as the tool lifecycle frames (W2.4, plan_crush_media).
 *
 * STRICTLY DISPLAY-ONLY. This frame MUST NEVER reach the session store or the
 * transcript - the bytes ride PreviewSlots → Renderer → pixels and die there
 * (crush_media.md §8.6-5 privacy law). It is ephemeral by construction like
 * TokenDelta: a lost frame costs one repaint, nothing more.
 *
 * Emitted only when the render loop's polling actually supplied a preview
 * image (ProgressLoop's wants-preview gate); absent previews never fabricate
 * this event, so terminals without image display see zero difference.
 */
final class MediaProgress
{
    public function __construct(
        /** The running tool call this frame belongs to - the PreviewSlots key. */
        public readonly string $toolCallId,
        /** Base64-encoded PNG of the progressive diffusion preview, null when the poll carried none. */
        public readonly ?string $frameB64,
        /** Diffusion progress 0..1 when the server reported it. */
        public readonly ?float $progress,
        /** Seconds remaining when the server estimated it. */
        public readonly ?float $eta,
    ) {
    }
}
