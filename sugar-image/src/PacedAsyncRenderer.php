<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Image\Renderer\IncrementalRenderer;

/**
 * Async renderer that pumps an {@see IncrementalRenderer} against a slice
 * budget (D-05).
 *
 * The problem this replaces: {@see SyncAsyncRenderer} is a futureTick
 * wrapper around the blocking one-shot encode — scheduling only, a large
 * image still occupies the whole loop for the entire render, with no
 * progress, no interleaving, and no budget. Here the encode is row-lazy from
 * the first slice: each {@see PacedRenderSession::advanceSlice()} consumes
 * rows until the budget is spent, the loop is yielded through
 * {@see Loop::futureTick()} (a queue hand-off, not a timer — this class
 * arms nothing on the clock, so no LoopPin timer margin is consumed), and
 * the following tick resumes exactly where the bytes and the generator
 * cursor were left. Other watchers run in between; a TUI driving this can
 * paint partial progress or abandon the generator to cancel.
 *
 * E646, the sentence that must survive edits: the deadline bounds EACH
 * SLICE, never the whole render — so it is a fairness device, not a
 * timeout, and cannot truncate a slow-but-legitimate encode. There is no
 * request-wide ceiling anywhere in this class by design.
 *
 * Cancellation is by abandonment (the survey's prescription): drop the
 * promise and the session, the generator dies, and the encode dies with it —
 * a push stream half-delivered is a truncated transmission, the consumer's
 * destination contract, never a correctness hazard for the promise itself.
 */
final class PacedAsyncRenderer implements AsyncRenderer
{
    /** 8 ms ≈ one 120 Hz frame — long enough to batch small rows, short enough to feel live. */
    public const DEFAULT_SLICE_MS = 8;

    /**
     * @param IncrementalRenderer $renderer     The row-lazy encode source.
     * @param int                 $sliceMs      Millisecond budget per slice (>= 1).
     * @param int                 $rowsPerSlice Determinism cap on rows per slice
     *                                          (>= 1); PHP_INT_MAX = clock decides.
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
     * The first slice runs inline before this method returns (progress
     * immediately observable, matching the survey's pump), later slices
     * re-arm on futureTick, so the promise needs the loop only when the
     * encode spans more than one slice.
     */
    public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface
    {
        $deferred = new Deferred();
        $session = $this->session($image, $width, $height);

        $pump = function () use ($session, $deferred, &$pump): void {
            try {
                $session->advanceSlice();
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
