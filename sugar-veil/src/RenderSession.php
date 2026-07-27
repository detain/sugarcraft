<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Diff\DiffEncoder;

/**
 * Mutable render-session state for diff-based frame emission.
 *
 * Holds the four frame-cache fields that Veil::composite() mutates on
 * subsequent same-dimension calls. This object is intentionally mutable —
 * it is the mutable companion to the immutable Veil, allowing Veil itself
 * to remain truly readonly while still preserving diff state across frames.
 *
 * NOT for export outside the library.
 */
final class RenderSession
{
    /** @var Buffer|null Lazily-built previous frame buffer for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var string|null Previous full composite output (string), kept so the diff buffer can be built lazily on frame 2 */
    private ?string $previousOutput = null;

    /** @var int|null Previous output width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous output height for resize detection */
    private ?int $prevHeight = null;

    /**
     * Flag set to true by rememberFull() (which clears previousFrame) so that
     * the NEXT shouldEmitFull call returns true even when dimensions match —
     * the diff buffer was just invalidated and the next composite must emit full.
     * Cleared by the next shouldEmitFull call so it only affects one call.
     */
    private bool $justClearedFrame = false;

    /** @var bool Tracks whether diff() was called since last rememberFull() */
    private bool $diffWasCalled = false;

    public function __construct()
    {
    }

    /**
     * Returns true when there is no prior output or the dimensions differ,
     * meaning the next composite must emit a full frame.
     */
    public function shouldEmitFull(int $width, int $height): bool
    {
        // justClearedFrame: rememberFull() cleared the diff buffer → next call must be full
        if ($this->justClearedFrame && $this->previousOutput !== null) {
            $this->justClearedFrame = false;

            return true;
        }

        // If returning true due to dimension change, clear diffWasCalled since we
        // took the full path and diff state is no longer relevant.
        if ($this->previousOutput !== null
            && ($this->prevWidth !== $width || $this->prevHeight !== $height)
        ) {
            $this->diffWasCalled = false;

            return true;
        }

        return $this->previousOutput === null
            || $this->prevWidth !== $width
            || $this->prevHeight !== $height;
    }

    /**
     * Remember a full-frame output string and clear the diff buffer
     * (used when emitting a full frame or after a resize).
     */
    public function rememberFull(string $output, int $width, int $height): void
    {
        $this->previousOutput = $output;
        $this->prevWidth = $width;
        $this->prevHeight = $height;
        $this->previousFrame = null;
        // Only set justClearedFrame if dimensions MATCH and diff was called since
        // last rememberFull. When dimensions changed, shouldEmitFull returns true
        // due to dimension check (no need for justClearedFrame). When dimensions
        // match, justClearedFrame forces full emission to clear diff state.
        $sameDimensions = ($this->prevWidth === $width && $this->prevHeight === $height);
        $this->justClearedFrame = $this->diffWasCalled && $sameDimensions;
        $this->diffWasCalled = false;
    }

    /**
     * Build diff delta between the given full output and the remembered previous output.
     *
     * @param string   $output        Current full-frame output string
     * @param int      $width         Frame width in cells
     * @param int      $height        Frame height in rows
     * @param callable $bufferFactory Factory to build a Buffer from (output, width, height)
     * @return string Encoded diff bytes, or empty string if no changes
     */
    public function diff(string $output, int $width, int $height, callable $bufferFactory): string
    {
        $this->diffWasCalled = true;

        // First diff call with no prior output: return empty diff but prime
        // previousFrame so the NEXT call can diff properly.
        if ($this->previousOutput === null) {
            $this->previousFrame = $bufferFactory('', $width, $height);
            $this->previousOutput = $output;
            $this->prevWidth = $width;
            $this->prevHeight = $height;

            return '';
        }

        $prev = $this->previousFrame ??= $bufferFactory($this->previousOutput, $width, $height);
        $current = $bufferFactory($output, $width, $height);
        $ops = $current->diff($prev);
        $this->previousFrame = $current;
        $this->previousOutput = $output;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    /**
     * Reset all session state, forcing the next composite to emit a full frame.
     *
     * @see release() for an alias useful when discarding a session
     */
    public function reset(): void
    {
        $this->previousFrame = null;
        $this->previousOutput = null;
        $this->prevWidth = null;
        $this->prevHeight = null;
    }

    /**
     * Release all session state, preventing memory growth in long-running apps.
     *
     * This is an alias for reset(). Call this when you are done using the
     * RenderSession and want to ensure all held references are cleared.
     * In long-running TUI applications with many frame transitions, calling
     * release() periodically (e.g., every ~1000 frames) prevents unbounded
     * memory accumulation from the diff buffer cache.
     *
     * @see reset()
     */
    public function release(): void
    {
        $this->reset();
    }
}
