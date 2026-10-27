<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use SugarCraft\Core\Util\Width;

/**
 * What one window held on screen: how far the transcript was scrolled, the
 * unsent draft text, and where the caret sat inside it.
 *
 * A plain value object with normalised edges — a negative scroll offset
 * clamps to 0 and a caret beyond the draft's end clamps to it — so a
 * restored viewport can be trusted without re-checking it. Scroll 0 is the
 * BOTTOM of the transcript (the follow-live position), which is what
 * {@see WindowViewports::restore()} answers for a window it never saw.
 */
final class WindowViewport
{
    public readonly int $scrollOffset;

    public readonly int $cursor;

    public function __construct(
        int $scrollOffset = 0,
        public readonly string $draft = '',
        int $cursor = 0,
    ) {
        $this->scrollOffset = max(0, $scrollOffset);
        $this->cursor = min(max(0, $cursor), Width::string($draft));
    }

    /** The state of a window nobody has opened yet: pinned to the bottom, empty. */
    public static function bottom(): self
    {
        return new self();
    }

    /** The same viewport scrolled to $offset cells above the bottom. */
    public function withScrollOffset(int $offset): self
    {
        return new self($offset, $this->draft, $this->cursor);
    }

    /** The same viewport holding $draft with the caret at $cursor. */
    public function withDraft(string $draft, int $cursor): self
    {
        return new self($this->scrollOffset, $draft, $cursor);
    }
}
