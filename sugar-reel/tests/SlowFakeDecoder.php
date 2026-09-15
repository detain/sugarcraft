<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A decoder that spends a fixed slice of wall-clock time on every next(), so a
 * bounded catch-up loop (Player's frameBudgetMs) reliably runs out of budget part
 * way through a large skip. Stands in for "ffmpeg is slower than the frame clock"
 * without needing a real video file.
 *
 * @internal
 */
final class SlowFakeDecoder implements Decoder
{
    private int $index = 0;

    /**
     * @param list<RgbFrame> $frames
     * @param float          $perFrameSeconds wall-clock spent producing each frame
     */
    public function __construct(
        private readonly array $frames,
        private readonly float $perFrameSeconds = 0.002,
    ) {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->index = 0;
    }

    public function next(): ?RgbFrame
    {
        if ($this->index >= count($this->frames)) {
            return null;
        }
        usleep((int) round($this->perFrameSeconds * 1_000_000));

        return $this->frames[$this->index++];
    }

    public function close(): void
    {
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->index = 0;
    }

    public function reopensInPlace(): bool
    {
        return true;
    }
}
