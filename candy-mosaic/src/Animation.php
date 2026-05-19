<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * An ordered sequence of frames with per-frame delays.
 *
 * Frame-source-agnostic: the constructor takes `list<ImageSource>`,
 * which means a GIF decoder, an MP4 frame extractor, a procedural
 * generator, or a live video feed adapter can all produce an
 * Animation without coupling to any specific frame type.
 *
 * Mirrors no upstream — there is no equivalent class in
 * charmbracelet/x/mosaic. The driver layer (see {@see AnimationDriver})
 * is the SugarCraft addition that turns this passive value object into
 * a running animation through {@see Renderer\Renderer}.
 */
final class Animation
{
    /**
     * @param list<ImageSource> $frames   Ordered frames, non-empty
     * @param list<int>        $delaysMs  Per-frame delay in milliseconds, same length as $frames
     */
    public function __construct(
        public readonly array $frames,
        public readonly array $delaysMs,
    ) {
        if ($this->frames === []) {
            throw new \InvalidArgumentException(Lang::t('animation.empty'));
        }
        if (count($this->frames) !== count($this->delaysMs)) {
            throw new \InvalidArgumentException(Lang::t('animation.delay_count_mismatch', [
                'frameCount'  => count($this->frames),
                'delayCount'  => count($this->delaysMs),
            ]));
        }
    }

    /**
     * Convenience factory: same delay for every frame.
     *
     * @param list<ImageSource> $frames
     */
    public static function fixed(array $frames, int $delayMs): self
    {
        return new self($frames, array_fill(0, count($frames), $delayMs));
    }

    public function frameCount(): int
    {
        return count($this->frames);
    }

    public function totalDurationMs(): int
    {
        return array_sum($this->delaysMs);
    }

    /**
     * Replace a single frame at the given index.
     *
     * @throws \OutOfRangeException  if $index is outside [0, frameCount)
     */
    public function withFrame(int $index, ImageSource $frame, int $delayMs): self
    {
        if ($index < 0 || $index >= count($this->frames)) {
            throw new \OutOfRangeException(Lang::t('animation.index_out_of_range', ['index' => $index]));
        }

        $frames  = $this->frames;
        $delaysMs = $this->delaysMs;
        $frames[$index]   = $frame;
        $delaysMs[$index] = $delayMs;

        return $this->mutate(frames: $frames, delaysMs: $delaysMs);
    }

    /**
     * Private clone-and-replace helper.
     *
     * Mirrors the canonical `with*()` pattern in candy-sprinkles/src/Style.php:
     * each named parameter defaults to null and falls back to the existing
     * field when omitted. Keeps `with*()` callers terse without giving up
     * immutability.
     */
    private function mutate(?array $frames = null, ?array $delaysMs = null): self
    {
        return new self(
            frames:   $frames   ?? $this->frames,
            delaysMs: $delaysMs ?? $this->delaysMs,
        );
    }
}
