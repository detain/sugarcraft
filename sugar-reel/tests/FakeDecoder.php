<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A Decoder that yields a fixed sequence of synthetic RgbFrame objects.
 *
 * Used in tests to provide deterministic frame sequences without any
 * real video file or external process.
 */
final class FakeDecoder implements Decoder
{
    /** @var list<RgbFrame> */
    private array $frames;

    private int $index = 0;

    private bool $opened = false;

    private bool $everOpened = false;

    /**
     * @param list<RgbFrame> $frames Sequence of frames to yield on each next() call
     */
    public function __construct(array $frames)
    {
        $this->frames = $frames;
    }

    /**
     * @inheritDoc
     *
     * The $mode and $startSec parameters are accepted for interface compatibility
     * but ignored — FakeDecoder always replays its predetermined frame sequence
     * from the start.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->opened = true;
        $this->everOpened = true;
        $this->index = 0;
    }

    public function next(): ?RgbFrame
    {
        // Auto-open on first use so tests don't need to call open() explicitly.
        // But do NOT re-open after close() — close() truly closes the decoder.
        if (!$this->opened) {
            if (!$this->everOpened) {
                $this->opened = true;
                $this->everOpened = true;
            } else {
                // Was opened previously, then closed — stay closed.
                return null;
            }
        }
        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
        $this->opened = false;
        $this->everOpened = true; // Mark as "was opened then closed"
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * Reset the frame index so next() starts from the beginning again.
     */
    public function reset(): void
    {
        $this->index = 0;
    }

    /**
     * Return the number of frames in this decoder.
     */
    public function count(): int
    {
        return count($this->frames);
    }

    /**
     * Return true if close() has been called.
     */
    public function isClosed(): bool
    {
        return !$this->opened;
    }
}
