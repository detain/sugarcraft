<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A mode-aware fake Decoder that regenerates its frames at the open()-time
 * geometry: each frame is cellsW × (cellsH * mode->rowsPerCell()) pixels.
 *
 * Used to prove the Player rebuilds the decoder on a mode switch — re-opening
 * at a new mode produces frames at the new pixel height (HalfBlock 2×, the
 * 1-row modes 1×). The fixed-frame {@see FakeDecoder} cannot exercise this, so
 * this is a separate class (FakeDecoder is depended on by many tests).
 */
final class GeometryFakeDecoder implements Decoder
{
    /** @var list<RgbFrame> */
    private array $frames = [];

    private int $index = 0;

    private int $w = 0;

    private int $frameH = 0;

    public function __construct(private int $count = 4)
    {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->w = $cellsW;
        $this->frameH = $cellsH * ($mode?->rowsPerCell() ?? 2);
        $this->index = 0;

        // Regenerate every frame at the new geometry. Deterministic non-empty
        // bytes of the exact w*h*3 length the renderer expects.
        $this->frames = [];
        $bytes = str_repeat("\x80\x40\x20", $this->w * $this->frameH);
        for ($i = 0; $i < $this->count; $i++) {
            $this->frames[] = new RgbFrame($bytes, $this->w, $this->frameH);
        }
    }

    public function next(): ?RgbFrame
    {
        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
        $this->index = 0;
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }
}
