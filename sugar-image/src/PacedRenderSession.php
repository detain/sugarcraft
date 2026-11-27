<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * One paced, resumable pump over an incremental encode.
 *
 * The session is the unit of fairness: {@see advanceSlice()} consumes rows
 * from the generator until the slice budget runs out, then returns, keeping
 * every byte and the generator's cursor, and the next call resumes exactly
 * where the last stopped. The budget is ONE of two kinds, never both:
 *
 * - a finite whole-rows cap: the slice plan is a pure function of the row
 *   stream and the cap (max(1, ceil(rows / cap)) slices of exactly cap rows
 *   each, bar the last — an empty stream still takes the one slice that
 *   discovers it is empty). The clock is never read, so a slow host — a coverage
 *   run, a loaded CI box — lands the identical plan as a fast one;
 * - the unbounded cap (PHP_INT_MAX): a fresh {@see Deadline} per slice paces
 *   the pump on the wall clock.
 *
 * Mixing the two (a row cap that a deadline could still cut short) made
 * the "deterministic" plan depend on how fast each row encoded, so the same
 * input split into different slices from one run to the next.
 *
 * The clock still paces a capped pump, just never through the plan:
 * {@see advanceUntil()} stops mid-slice once its pause expires and the next
 * call resumes the same slice, so {@see PacedAsyncRenderer} yields the loop
 * on time while every slice keeps exactly cap rows.
 *
 * The deadline bounds EACH SLICE, never the whole render — it is a fairness
 * device, not a request timeout, and cannot truncate a slow-but-legitimate
 * encode (the no-blanket-timeout rule). No slice can stall the pipeline forever: each advance starts
 * a fresh budget, and each advance consumes at least one row whenever rows
 * remain (a single row whose encode alone overshoots the slice still
 * lands IN that slice — expiry is checked after consuming a row — and the
 * next slice resumes after it).
 *
 * Zero timers: nothing here arms anything on an event loop. The pump advances
 * only when someone calls advanceSlice(); {@see PacedAsyncRenderer} drives
 * it from futureTick callbacks (loop queue, not clock), and tests drive it
 * directly and count slices instead of watching walls.
 *
 * Mirrors no upstream — SugarCraft addition. The resumable session object
 * is the plumbing that lets PacedAsyncRenderer's slice budget survive
 * yields.
 */
final class PacedRenderSession
{
    private string $out = '';

    private bool $done = false;

    private int $sliceCount = 0;

    private int $rowsConsumed = 0;

    /** Rows still owed to the open slice; 0 = no slice open. */
    private int $sliceLeft = 0;

    /** Output offset where the open slice began. */
    private int $sliceMark = 0;

    /** The open slice's clock budget — armed only under the unbounded cap. */
    private ?Deadline $sliceDeadline = null;

    /**
     * @param \Generator<int,string> $rows         Byte chunks from an
     *                                             {@see Renderer\IncrementalRenderer::renderRows()}.
     * @param int                    $sliceMs      Whole-millisecond budget per slice;
     *                                             closes a slice only when $rowsPerSlice
     *                                             is unbounded (PHP_INT_MAX).
     * @param int                    $rowsPerSlice Rows consumed per slice. A finite
     *                                             value is the determinism cap: it alone
     *                                             decides the slice plan and the clock is
     *                                             never consulted. PHP_INT_MAX hands the
     *                                             slice boundary to the clock.
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

        $this->run(null);

        return substr($this->out, $this->sliceMark);
    }

    /**
     * Run rows of the open slice until it closes or $pause expires.
     *
     * The loop-yield seam for {@see PacedAsyncRenderer}: under a finite row
     * cap the pause may stop the pump mid-slice, and the next call (or
     * {@see advanceSlice()}) resumes the SAME slice, so the clock decides
     * only when the caller yields, never where a slice boundary falls. At
     * least one row is consumed per call whenever rows remain.
     *
     * @return bool True when the slice closed (and was counted), false when
     *              the pause expired first.
     */
    public function advanceUntil(Deadline $pause): bool
    {
        if ($this->done) {
            return true;
        }

        return $this->run($pause);
    }

    private function run(?Deadline $pause): bool
    {
        if ($this->sliceLeft === 0) {
            $this->sliceLeft = $this->rowsPerSlice;
            $this->sliceMark = strlen($this->out);
            // A finite row cap is the whole plan; a slice deadline beside it
            // would let encode speed move slice boundaries between otherwise
            // identical runs. Only the unbounded cap closes slices on the clock.
            $this->sliceDeadline = $this->rowsPerSlice === PHP_INT_MAX ? Deadline::in($this->sliceMs) : null;
        }

        $closed = false;
        while ($this->rows->valid()) {
            $this->out .= (string) $this->rows->current();
            $this->rows->next();
            --$this->sliceLeft;
            ++$this->rowsConsumed;
            if ($this->sliceLeft === 0 || $this->sliceDeadline?->isExpired()) {
                $closed = true;
                break;
            }
            if ($pause?->isExpired()) {
                break;
            }
        }

        if (!$this->rows->valid()) {
            // Exhaustion is completion: release the producer (and, for a
            // wrapped push stream, its suspended fiber's frame stack) rather
            // than waiting on GC.
            $this->done = true;
            $closed = true;
        }

        if ($closed) {
            ++$this->sliceCount;
            $this->sliceLeft = 0;
            $this->sliceDeadline = null;
        }

        return $closed;
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
