<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A deterministic, child-free sugar-reel Decoder for crush's player tests
 * (crush-media W5.4). Cycles a fixed frame list forever so ticks, seeks and
 * teardown are observable without ffmpeg — and without ever being mistaken
 * for the library's own FakeDecoder: this is the crush-side consumer
 * fixture, deliberately its own class (façade/duplication law).
 */
final class VideoPlayerScriptedDecoder implements Decoder
{
    /** @var list<RgbFrame> */
    private array $frames;

    private int $index = 0;

    private int $vpOpenCount = 0;

    private int $vpCloseCount = 0;

    private int $vpReopenCount = 0;

    private ?float $vpLastReopenStartSec = null;

    private bool $closed = false;

    /**
     * @param list<RgbFrame> $frames sequence replayed cyclically on next()
     */
    public function __construct(array $frames)
    {
        $this->frames = array_values($frames);
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->vpOpenCount++;
        $this->index = 0;
        $this->closed = false;
    }

    public function getIterator(): \Generator
    {
        // Bounded so a stray foreach terminates: two full cycles at most.
        $limit = count($this->frames) * 2;

        for ($step = 0; $step < $limit && !$this->closed; $step++) {
            $frame = $this->next();

            if ($frame === null) {
                return;
            }

            yield $frame;
        }
    }

    public function next(): ?RgbFrame
    {
        if ($this->closed || $this->frames === []) {
            return null;
        }

        $frame = $this->frames[$this->index % count($this->frames)];
        $this->index++;

        return $frame;
    }

    public function close(): void
    {
        $this->vpCloseCount++;
        $this->closed = true;
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->vpReopenCount++;
        $this->vpLastReopenStartSec = $startSec;
        $this->index = (int) floor($startSec * $fps);
        $this->closed = false;
    }

    public function reopensInPlace(): bool
    {
        return true;
    }

    public function vpOpenCount(): int
    {
        return $this->vpOpenCount;
    }

    public function vpCloseCount(): int
    {
        return $this->vpCloseCount;
    }

    public function vpReopenCount(): int
    {
        return $this->vpReopenCount;
    }

    public function vpLastReopenStartSec(): ?float
    {
        return $this->vpLastReopenStartSec;
    }
}
