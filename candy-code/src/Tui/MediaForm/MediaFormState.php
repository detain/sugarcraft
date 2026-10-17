<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * Where the media form's focus sits: which section (tab) is up, which row
 * inside it is highlighted, and whether a field on that row holds the
 * keyboard.
 *
 * Immutable bookkeeping in the {@see \SugarCraft\Code\Palette\PaletteState}
 * shape — every `with*()` returns a new state and clamps instead of throwing,
 * because focus positions arrive from clicks and wheel notches whose ranges
 * the caller cannot be trusted to have pre-checked. The form itself
 * ({@see MediaForm}) holds the draft and the open field; this holds only
 * position.
 */
final class MediaFormState
{
    /**
     * @param int $section index into the visible sections list
     * @param int $row highlighted row inside that section
     * @param bool $editing whether a field on the highlighted row owns keys
     */
    public function __construct(
        public readonly int $section = 0,
        public readonly int $row = 0,
        public readonly bool $editing = false,
    ) {
    }

    /** Focus clamped into `[0, sections-1] x [0, rows-1]` of one section. */
    public static function forSection(int $section, int $rows, int $row = 0): self
    {
        return new self(
            max(0, $section),
            $rows > 0 ? min(max(0, $row), $rows - 1) : 0,
        );
    }

    public function withSection(int $section, int $rows): self
    {
        return new self(
            max(0, $section),
            $rows > 0 ? min($this->row, $rows - 1) : 0,
            false,
        );
    }

    public function withRow(int $row, int $rows): self
    {
        return new self($this->section, $rows > 0 ? min(max(0, $row), $rows - 1) : 0, $this->editing);
    }

    public function withEditing(bool $editing): self
    {
        return new self($this->section, $this->row, $editing);
    }
}
