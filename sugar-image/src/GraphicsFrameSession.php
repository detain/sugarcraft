<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Image\Renderer\Renderer;

/**
 * Presents a sequence of frames on one terminal without leaking images.
 *
 * Each {@see present()} draws the next frame under an image id from a
 * two-slot double buffer (`imageId`, `imageId + 1`, alternating), then retires
 * the frame it replaces. On an id-addressable backend (Kitty, see
 * {@see Renderer::supportsIdDelete()}) the new frame lands before the old one
 * is deleted, so the screen never shows an empty image cell. Other backends
 * get the delete-before-draw their own {@see Renderer::present()} implements.
 *
 * The session is stateful: the frame counter advances on every present, which
 * is what makes the id rotation work. {@see withImageId()} returns a fresh
 * session at frame zero, the same way every other `with*()` in this lib does.
 *
 * Mirrors the double-buffer parity chafa uses for its placement ids
 * (tools/chafa/chafa.c:833), as {@see AnimationDriver} already does for
 * animations. This class is the shared form for callers that are not a
 * Model, such as a video player drawing decoded frames.
 */
final class GraphicsFrameSession
{
    /** Frames presented so far; drives the id rotation. */
    private int $frames = 0;

    private function __construct(
        public readonly Renderer $renderer,
        public readonly int $imageId,
    ) {}

    /**
     * Start a session on `$renderer` using image ids 1 and 2.
     */
    public static function new(Renderer $renderer): self
    {
        return new self($renderer, 1);
    }

    /**
     * A fresh session at frame zero whose two slots are `$imageId` and
     * `$imageId + 1`.
     *
     * @throws \InvalidArgumentException when $imageId is not positive
     */
    public function withImageId(int $imageId): self
    {
        if ($imageId <= 0) {
            throw new \InvalidArgumentException("image id must be positive, got {$imageId}");
        }

        return new self($this->renderer, $imageId);
    }

    /**
     * Draw `$frame` and retire the previous one. Advances the session.
     *
     * The first frame has nothing to replace, so it is drawn with no delete.
     *
     * @throws \InvalidArgumentException as {@see Renderer::render()} does for bad dimensions
     */
    public function present(ImageSource $frame, int $width, ?int $height = null): string
    {
        $slot   = $this->frames % 2;
        $newId  = (string) ($this->imageId + $slot);
        $prevId = $this->frames === 0 ? null : (string) ($this->imageId + (1 - $slot));

        $bytes = $this->renderer->present($frame, $width, $height, $newId, $prevId);
        $this->frames++;

        return $bytes;
    }

    /**
     * Frames presented on this session so far.
     */
    public function frameCount(): int
    {
        return $this->frames;
    }
}
