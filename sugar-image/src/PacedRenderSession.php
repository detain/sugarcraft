<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * One paced, resumable pump over an incremental encode (D-05).
 *
 * The session is the unit of fairness: {@see advanceSlice()} consumes rows
 * from the generator until the slice budget runs out — a fresh
 * {@see Deadline} per slice, capped additionally by a whole-rows budget so
 * the pump is deterministic under test. It then returns, keeping every byte
 * and the generator's cursor, and the next call resumes exactly where the
 * last stopped.
 *
 * The deadline bounds EACH SLICE, never the whole render — it is a fairness
 * device, not a request timeout, and cannot truncate a slow-but-legitimate
 * encode (E646). No slice can stall the pipeline forever: each advance starts
 * a fresh budget, and each advance consumes at least one row whenever rows
 * remain (a single row whose encode alone overshoots the slice still
 * lands IN that slice — expiry is checked after consuming a row — and the
 * next slice resumes after it).
 *
 * Zero timers: nothing here arms anything on an event loop. The pump advances
 * only when someone calls advanceSlice(); {@see PacedAsyncRenderer} drives
 * it from futureTick callbacks (loop queue, not clock), and tests drive it
 * directly and count slices instead of watching walls.
 */
final class PacedRenderSession
{
    private string $out = '';

    private bool $done = false;

    private int $sliceCount = 0;

    private int $rowsConsumed = 0;

    /**
     * @param \Generator<int,string> $rows         Byte chunks from an
     *                                             {@see Renderer\IncrementalRenderer::renderRows()}.
     * @param int                    $sliceMs      Whole-millisecond budget per slice.
     * @param int                    $rowsPerSlice Upper bound on rows consumed per
     *                                             slice — the determinism cap that lets
     *                                             tests pin slice counts exactly.
     */
    public function __construct(
        private readonly \Generator $rows,
        private readonly int $sliceMs,
        private readonly int $rowsPerSlice,
    ) {}

    /**
     * Run one slice: pull rows while budget remains, appending their bytes.
     *
     * @return string The bytes appended by THIS slice (may be '' once done).
     */
    public function advanceSlice(): string
    {
        if ($this->done) {
            return '';
        }

        $mark = strlen($this->out);
        $deadline = Deadline::in($this->sliceMs);
        $budget = $this->rowsPerSlice;

        while ($this->rows->valid() && $budget > 0) {
            $this->out .= (string) $this->rows->current();
            $this->rows->next();
            --$budget;
            ++$this->rowsConsumed;
            if ($deadline->isExpired()) {
                break;
            }
        }

        if (!$this->rows->valid()) {
            // Exhaustion is completion: release the producer (and, for a
            // wrapped push stream, its suspended fiber's frame stack) rather
            // than waiting on GC.
            $this->done = true;
        }

        ++$this->sliceCount;

        return substr($this->out, $mark);
    }

    /** All bytes accumulated so far — the full stream once {@see isDone()}. */
    public function output(): string
    {
        return $this->out;
    }

    /** Whether every row has been consumed (idempotent past the last slice). */
    public function isDone(): bool
    {
        return $this->done;
    }

    /** Slices run so far — the deterministic progress counter. */
    public function sliceCount(): int
    {
        return $this->sliceCount;
    }

    /** Rows pulled from the generator so far. */
    public function rowsConsumed(): int
    {
        return $this->rowsConsumed;
    }
}
