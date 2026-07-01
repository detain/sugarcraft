<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Model;
use SugarCraft\Mosaic\Renderer\Renderer;

/**
 * Drives an {@see Animation} onto a {@see Renderer} using the Model
 * contract so it can be embedded in a Program or composed into a parent
 * Model via {@see \SugarCraft\Core\Composite}.
 *
 * Frame timing uses {@see Cmd::tick()}; per-frame delete-and-redraw
 * uses the {@see Renderer::delete()} API added in step 07.12.
 *
 * For renderers that support targeted deletion (Kitty, iTerm2),
 * `view()` emits `delete($id) . render($frame)` so the screen shows
 * only the current frame. For renderers that do not (Sixel, HalfBlock,
 * QuarterBlock, Chafa), `Renderer::delete()` returns '' and the
 * existing cell grid is naturally overwritten on the next render —
 * this is correct per step 07.12.
 *
 * Mirrors no upstream — this is a SugarCraft addition.
 */
final class AnimationDriver implements Model
{
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
            $this->animation->delaysMs[$this->index] / 1000.0,
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

        $nextIndex = ($this->index + 1) % $this->animation->frameCount();

        return [
            $this->withIndex($nextIndex),
            Cmd::tick(
                $this->animation->delaysMs[$nextIndex] / 1000.0,
                static fn(): Msg => new FrameTickMsg(),
            ),
        ];
    }

    /**
     * Emit delete + render for the current frame.
     *
     * @return string
     */
    public function view(): string
    {
        $delete = $this->renderer->delete((string) $this->imageId);
        $frame  = $this->animation->frames[$this->index];
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
     * Change the image id used for targeted delete+redraw on Kitty/iTerm2.
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
