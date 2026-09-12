<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Palette;

/**
 * Immutable state for the Ctrl+P command palette. `mode` selects which item
 * list is being browsed ('root' → {@see PaletteAction::all()}, 'providers'
 * → provider names, 'themes' → theme names) - this class carries only the
 * mode tag, not the items themselves, so it stays a plain data tuple; the
 * mode's actual item list is resolved on demand by
 * {@see \SugarCraft\Crush\Chat::paletteMatches()}.
 *
 * `queryCursor` (E3) is the caret's flat CODE-POINT offset inside `query` -
 * deliberately the same shape {@see \SugarCraft\Crush\Chat::inputCursorOffset()}
 * publishes for the draft, so both text fields state a caret the same way and
 * {@see \SugarCraft\Crush\Renderer} splices both carets through one rule.
 * It is clamped into `query` here rather than trusted, but NOT snapped to a
 * grapheme-cluster boundary: segmentation is the writer's job (Chat's palette
 * keys keep every caret on a boundary), and the renderer defends the paint
 * independently.
 */
final class PaletteState
{
    public function __construct(
        public readonly string $mode,
        public readonly string $query,
        public readonly int $selectedIndex,
        public readonly int $queryCursor = 0,
    ) {}

    public static function root(): self
    {
        return new self('root', '', 0, 0);
    }

    /**
     * Replace the query text and move the caret with it. A NULL `$queryCursor`
     * parks the caret at the end - the shape every whole-replacement writer
     * wants, because appending and typing-at-the-caret are the same keystroke
     * stream once the caret starts at the end.
     */
    public function withQuery(string $query, ?int $queryCursor = null): self
    {
        return new self(
            $this->mode,
            $query,
            0,
            self::clampInto($query, $queryCursor ?? mb_strlen($query, 'UTF-8')),
        );
    }

    /** Pure caret move: same text, row selection kept. */
    public function withQueryCursor(int $queryCursor): self
    {
        return new self(
            $this->mode,
            $this->query,
            $this->selectedIndex,
            self::clampInto($this->query, $queryCursor),
        );
    }

    public function withSelectedIndex(int $selectedIndex): self
    {
        return new self($this->mode, $this->query, $selectedIndex, $this->queryCursor);
    }

    public function withMode(string $mode): self
    {
        return new self($mode, '', 0, 0);
    }

    private static function clampInto(string $query, int $cursor): int
    {
        return max(0, min($cursor, mb_strlen($query, 'UTF-8')));
    }
}
