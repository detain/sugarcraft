<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Image\Renderer\IncrementalRenderer;

/**
 * Async renderer that pumps an {@see IncrementalRenderer} against a slice
 * budget.
 *
 * The problem this replaces: {@see SyncAsyncRenderer} is a futureTick
 * wrapper around the blocking one-shot encode — scheduling only, a large
 * image still occupies the whole loop for the entire render, with no
 * progress, no interleaving, and no budget. Here the encode is row-lazy from
 * the first slice: each tick's {@see PacedRenderSession::advanceUntil()}
 * consumes rows until the slice budget or the tick's pause is spent (never
 * {@see PacedRenderSession::advanceSlice()}, which would hold the loop for a
 * whole capped slice), the loop is yielded through
 * {@see Loop::futureTick()} (a queue hand-off, not a timer — this class
 * arms nothing on the clock, so no LoopPin timer margin is consumed), and
 * the following tick resumes exactly where the bytes and the generator
 * cursor were left. Other watchers run in between; a TUI driving this can
 * paint partial progress or abandon the generator to cancel.
 *
 * A slice ends on either a finite row cap or a wall-clock deadline, never
 * both: with a finite $rowsPerSlice the cap alone ends each slice, so the
 * slice plan never moves with encode speed; only the unbounded cap
 * (PHP_INT_MAX) closes slices on the clock. $sliceMs still paces the loop
 * in both cases: each tick pumps through
 * {@see PacedRenderSession::advanceUntil()} and yields once its $sliceMs is
 * spent, mid-slice if need be, and the next tick resumes that same slice.
 *
 * The no-blanket-timeout rule, which must survive edits: the deadline
 * bounds EACH SLICE (or each tick's share of one), never the whole render —
 * so it is a fairness device, not a timeout, and cannot truncate a
 * slow-but-legitimate encode.
 * There is no request-wide ceiling anywhere in this class by design.
 *
 * Cancellation is by abandonment (the port's prescription): drop the
 * promise and the session, the generator dies, and the encode dies with it —
 * a push stream half-delivered is a truncated transmission, the consumer's
 * destination contract, never a correctness hazard for the promise itself.
 *
 * Mirrors no upstream pacing — SugarCraft addition. The slice-budget pump
 * discipline (work-effort per tick, never a wall-clock deadline) is this
 * fleet's own lesson; the async tier it drives rides AsyncRenderer
 * (x/mosaic concept).
 */
final class PacedAsyncRenderer implements AsyncRenderer
{
    /** 8 ms ≈ one 120 Hz frame — long enough to batch small rows, short enough to feel live. */
    public const DEFAULT_SLICE_MS = 8;

    /**
     * @param IncrementalRenderer $renderer     The row-lazy encode source.
     * @param int                 $sliceMs      Millisecond budget per tick (>= 1): when
     *                                          renderAsync() yields the loop; it closes
     *                                          slices only under the unbounded row cap.
     * @param int                 $rowsPerSlice Determinism cap on rows per slice
     *                                          (>= 1): a finite cap alone decides the
     *                                          slice plan; PHP_INT_MAX = clock decides.
     */
    public function __construct(
        private readonly IncrementalRenderer $renderer,
        private readonly int $sliceMs = self::DEFAULT_SLICE_MS,
        private readonly int $rowsPerSlice = PHP_INT_MAX,
    ) {
        if ($sliceMs < 1) {
            throw new \InvalidArgumentException(Lang::t('paced.slice_ms', ['ms' => $sliceMs]));
        }
        if ($rowsPerSlice < 1) {
            throw new \InvalidArgumentException(Lang::t('paced.rows_per_slice', ['rows' => $rowsPerSlice]));
        }
    }

    /**
     * Render asynchronously in paced slices, resolving with the full bytes.
     *
     * The first tick runs inline before this method returns (progress
     * immediately observable, matching the documented pump shape), bounded by
     * sliceMs: it ends at the slice boundary or the clock, whichever comes
     * first, so under a finite row cap it may encode fewer rows than one
     * slice. Later ticks re-arm on futureTick, so the promise needs the loop
     * whenever the encode spans more than one tick.
     */
    public function renderAsync(ImageSource $image, int $width, ?int $height = null): PromiseInterface
    {
        $deferred = new Deferred();
        $session = $this->session($image, $width, $height);

        $pump = function () use ($session, $deferred, &$pump): void {
            try {
                // Yield on the slice boundary or on the clock, whichever is
                // first: the clock paces the loop, the cap keeps the plan.
                $session->advanceUntil(Deadline::in($this->sliceMs));
                if ($session->isDone()) {
                    $deferred->resolve($session->output());
                    return;
                }
                Loop::futureTick($pump);
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        };

        $pump();

        return $deferred->promise();
    }

    /**
     * Open a step-driven pump for the same encode — the test/integration
     * surface: call {@see PacedRenderSession::advanceSlice()} at your own
     * cadence (no loop, no wall-clock assumption) and observe progress.
     */
    public function session(ImageSource $image, int $width, ?int $height = null): PacedRenderSession
    {
        return new PacedRenderSession(
            $this->renderer->renderRows($image, $width, $height),
            $this->sliceMs,
            $this->rowsPerSlice,
        );
    }

    /** The wrapped row source. */
    public function renderer(): IncrementalRenderer
    {
        return $this->renderer;
    }

    /** Per-slice millisecond budget. */
    public function sliceMs(): int
    {
        return $this->sliceMs;
    }

    /** Per-slice row cap (PHP_INT_MAX = unbounded, clock decides). */
    public function rowsPerSlice(): int
    {
        return $this->rowsPerSlice;
    }
}
