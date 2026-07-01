<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A Decoder that wraps a fixed frame list and records how many times close()
 * is called.
 *
 * Used by the F21 regression test to prove a backward seek closes the OLD
 * decoder (instead of leaking the underlying ffmpeg process) before building
 * a fresh one.
 */
final class SpyDecoder implements Decoder
{
    public int $closeCount = 0;

    /** @var list<RgbFrame> */
    private array $frames;

    private int $index = 0;

    /**
     * @param list<RgbFrame> $frames Sequence of frames to yield on each next() call
     */
    public function __construct(array $frames)
    {
        $this->frames = $frames;
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->index = 0;
    }

    public function next(): ?RgbFrame
    {
        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * @inheritDoc
     *
     * Resets the index (same as open()).
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->open($source, $cellsW, $cellsH, $fps, $mode, $startSec);
    }
}
