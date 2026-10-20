<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Model;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\Renderer;

/**
 * Drives an {@see Animation} onto a {@see Renderer} using the Model
 * contract so it can be embedded in a Program or composed into a parent
 * Model via {@see \SugarCraft\Core\Composite}.
 *
 * Frame timing uses {@see Cmd::tick()}; per-frame redraw uses the
 * {@see Renderer::delete()} API added in step 07.12, emitted in the
 * order that renderer family's delete semantics allow.
 *
 * E-15: delete-before-redraw left a per-frame empty-cell window (visible
 * flicker). The fix is per family, because the one delete() contract has
 * three implementations:
 * - Kitty (stable-id capable): {@see KittyRenderer::renderFrame()} commits
 *   the new frame FIRST, into one of two alternating placement slots
 *   (`imageId`, `imageId + 1` — chafa's
 *   `placement_id + frame_count % 2` double-buffer parity,
 *   tools/chafa/chafa.c:833), and only then the OTHER slot is deleted.
 *   The screen never shows an empty image cell, and because each frame
 *   carries a stable id, delete() finally targets images this driver
 *   actually placed (the interface render() transmits anonymous ids, so
 *   the old delete($id) retired nothing and frames accumulated).
 * - iTerm2/WezTerm: delete() is `OSC 1337;Pop` — it removes the TOPMOST
 *   image and ignores the id. Deleting after the redraw would retire the
 *   frame just committed, so this family keeps delete-then-render; its
 *   empty-cell window is inherent to Pop and fixable only with
 *   id-targeted delete in sugar-core's Ansi (out of this step's scope).
 * - Renderers without targeted deletion (Sixel, HalfBlock, QuarterBlock,
 *   Chafa): `Renderer::delete()` returns '' and the existing cell grid is
 *   naturally overwritten on the next render — order is moot; the emitted
 *   bytes are unchanged (correct per step 07.12).
 *
 * Mirrors no upstream — this is a SugarCraft addition.
 */
final class AnimationDriver implements Model
{
    /**
     * Lower bound, in milliseconds, on the delay between frame ticks.
     *
     * GIF/APNG parity (lane p2, N4): a 0 in an animation header is a
     * SENTINEL, not a duration — the netscape loop extension reads 0 as
     * "loop forever", and decoders read a 0-delay frame as "show as fast as
     * your host allows", which every browser floors to roughly one frame.
     * ApngDecoder::delayMs() returns an honest 0 for `fcTL num=0`; feeding
     * that straight to Cmd::tick(0.0) re-arms the timer on the very next
     * loop iteration, so a crafted animation redraws unthrottled forever —
     * CPU pin plus a terminal byte flood (MAX_FRAMES caps allocation, not
     * the loop rate). The floor keeps the product decision honest per
     * frame: frames with real delays pass through untouched.
     */
    public const MIN_TICK_MS = 10;

    public function __construct(
        public readonly Animation $animation,
        public readonly Renderer $renderer,
        public readonly int $cellWidth,
        public readonly ?int $cellHeight = null,
        public readonly int $index = 0,
        public readonly bool $paused = false,
        public readonly int $imageId = 1,
    ) {}

    /**
     * Return a tick Cmd for the first frame when not paused.
     *
     * @return \Closure(): ?Msg|null
     */
    public function init(): ?\Closure
    {
        if ($this->paused) {
            return null;
        }

        return Cmd::tick(
            max($this->animation->delaysMs[$this->index], self::MIN_TICK_MS) / 1000.0,
            static fn(): Msg => new FrameTickMsg(),
        );
    }

    /**
     * Handle tick messages: advance the frame index and schedule the
     * next tick.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof FrameTickMsg) {
            return [$this, null];
        }

        // Paused: swallow the tick without advancing the frame or scheduling
        // the next one. Without this, a tick still in flight when the driver
        // is paused (via withPaused(true)) would silently resume playback.
        if ($this->paused) {
            return [$this, null];
        }

        $nextIndex = ($this->index + 1) % $this->animation->frameCount();

        return [
            $this->withIndex($nextIndex),
            Cmd::tick(
                max($this->animation->delaysMs[$nextIndex], self::MIN_TICK_MS) / 1000.0,
                static fn(): Msg => new FrameTickMsg(),
            ),
        ];
    }

    /**
     * E-15: Emit the current frame, committing it before any retire byte.
     *
     * Kitty renderers get the double-buffered shape: `renderFrame(new) .
     * delete(other)` — new frame lands first, the other slot is retired
     * after, so there is no empty-cell window. All other renderers keep
     * the pre-E-15 byte stream `delete($imageId) . render($frame)`: for
     * ''-delete backends the order is invisible, and for Pop-based
     * iTerm2/WezTerm deleting after the redraw would retire the frame
     * just committed (see class docblock).
     *
     * @return string
     */
    public function view(): string
    {
        $frame = $this->animation->frames[$this->index];

        if ($this->renderer instanceof KittyRenderer) {
            $newId = $this->imageId + ($this->index % 2);
            $oldId = $this->imageId + (($this->index + 1) % 2);

            return $this->renderer->renderFrame($frame, $this->cellWidth, $this->cellHeight, $newId)
                . $this->renderer->delete((string) $oldId);
        }

        $delete = $this->renderer->delete((string) $this->imageId);
        $render = $this->renderer->render($frame, $this->cellWidth, $this->cellHeight);

        return $delete . $render;
    }

    /**
     * No active subscriptions — this Model is purely tick-driven.
     *
     * Frame advancement is scheduled via Cmd::tick() in init() and update(),
     * not via subscriber callbacks. Returning null here tells the Program
     * loop there is nothing to poll; the Model's own tick handler drives
     * all state changes (frame index, pause/resume).
     */
    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Advance to a specific frame index without changing pause state.
     */
    public function withIndex(int $index): self
    {
        return $this->mutate(index: $index);
    }

    /**
     * Pause or unpause animation playback.
     */
    public function withPaused(bool $paused): self
    {
        return $this->mutate(paused: $paused);
    }

    /**
     * E-15: Change the base of the driver's stable image-id pair.
     *
     * The kitty double-buffer reserves `imageId` and `imageId + 1` as its
     * two placement slots; choose a pair no other kitty image uses.
     */
    public function withImageId(int $imageId): self
    {
        return $this->mutate(imageId: $imageId);
    }

    /**
     * Private clone-and-replace helper.
     */
    private function mutate(
        ?Animation $animation = null,
        ?Renderer $renderer = null,
        ?int $cellWidth = null,
        ?int $cellHeight = null,
        ?int $index = null,
        ?bool $paused = null,
        ?int $imageId = null,
    ): self {
        return new self(
            animation:  $animation   ?? $this->animation,
            renderer:   $renderer    ?? $this->renderer,
            cellWidth:  $cellWidth   ?? $this->cellWidth,
            cellHeight: $cellHeight  ?? $this->cellHeight,
            index:      $index       ?? $this->index,
            paused:     $paused      ?? $this->paused,
            imageId:    $imageId     ?? $this->imageId,
        );
    }
}
