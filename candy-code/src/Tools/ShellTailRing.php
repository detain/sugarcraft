<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

use SugarCraft\Code\Events\ToolProgress;

/**
 * The ring-buffer host tail behind a running tool's {@see ToolProgress}
 * frames (WEB-4, plan_comparison).
 *
 * IT HOLDS A TAIL, NOT A TRANSCRIPT. Every captured chunk is appended and the
 * oldest bytes beyond `$tailBudget` fall out — the frame is the last N bytes
 * of output, replaced wholesale each emission. That is what makes the event
 * safely ephemeral: a dropped frame costs the client one repaint, because the
 * next frame already contains everything worth showing.
 *
 * THE EMISSION IS FRAME-RATE CAPPED, the rule {@see TokenDelta} learned the
 * other way: a chatty build must not stampede the socket and the reducer with
 * one frame per 8 KiB chunk. The first frame goes out as soon as there is
 * anything to show — the whole point of the event is the early silence-breaker
 * — and later ones no more than once per `$frameIntervalSeconds`. Bytes that
 * arrive between frames are not lost: {@see tick()} flushes a held tail at
 * the trailing edge of the interval — fired from the capture's wait beat, so
 * the quiet end of a run lands its last lines without a further chunk — and
 * {@see finish()} still carries whatever is pending at exit.
 *
 * `$clock` is the test seam (the production default is the wall clock), so the
 * cadence pins never sleep.
 */
final class ShellTailRing
{
    private string $tail = '';

    private int $droppedBytes = 0;

    private int $seq = 0;

    private bool $dirty = false;

    private float $lastEmittedAt = 0.0;

    /**
     * @param \Closure(ToolProgress): void $emit the turn's event channel
     * @param ?\Closure(): float           $clock now in seconds (test seam)
     */
    public function __construct(
        private readonly string $callId,
        private readonly string $toolName,
        private readonly \Closure $emit,
        private readonly int $tailBudget,
        private readonly float $frameIntervalSeconds,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** One captured chunk: keep its tail, and emit a frame when the cadence allows. */
    public function onChunk(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        $this->tail .= $chunk;
        $this->clamp();
        $this->dirty = true;

        $now = $this->now();
        if ($this->seq > 0 && $now - $this->lastEmittedAt < $this->frameIntervalSeconds) {
            return;
        }
        $this->push($now);
    }

    /** The run is over: land a final frame when bytes arrived since the last one. */
    public function finish(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->push($this->now());
    }

    /**
     * The capture's wait beat (C-WEB-7): land a held tail once the interval
     * since the last frame has elapsed, so the last lines before a long
     * silence reach the client at the trailing edge instead of at exit.
     * Waits on the same cadence as a chunk would — an early beat is silent —
     * and does nothing at all while nothing is pending.
     */
    public function tick(): void
    {
        if (!$this->dirty) {
            return;
        }
        $now = $this->now();
        if ($now - $this->lastEmittedAt < $this->frameIntervalSeconds) {
            return;
        }
        $this->push($now);
    }

    private function push(float $now): void
    {
        $this->seq++;
        $this->lastEmittedAt = $now;
        $this->dirty = false;
        ($this->emit)(new ToolProgress(
            $this->callId,
            $this->toolName,
            $this->tail,
            $this->seq,
            $this->droppedBytes > 0,
            $this->tailBudget,
        ));
    }

    /**
     * Keep the newest `$tailBudget` bytes. The cut lands on a byte boundary
     * like every other truncation here (the 256 KiB contract counts bytes), so
     * a leading continuation byte — the torn half of a multi-byte character —
     * is dropped too and counts against the same budget, keeping the frame
     * valid UTF-8 for the browser's <pre>.
     */
    private function clamp(): void
    {
        $budget = max(0, $this->tailBudget);
        if (\strlen($this->tail) > $budget) {
            $excess = \strlen($this->tail) - $budget;
            $this->tail = \substr($this->tail, $excess);
            $this->droppedBytes += $excess;
        }
        while ($this->tail !== '' && (\ord($this->tail[0]) & 0xC0) === 0x80) {
            $this->tail = \substr($this->tail, 1);
            $this->droppedBytes++;
        }
    }

    private function now(): float
    {
        $clock = $this->clock;

        return $clock !== null ? (float) $clock() : \microtime(true);
    }
}
