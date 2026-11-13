<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Ui;

use SugarCraft\Minesweeper\Lang;

/**
 * Custom difficulty form — lets the player specify rows, columns, and
 * mine count for a custom game instead of a preset {@see Difficulty}.
 *
 * Validation enforces:
 *   - Minimum 2 rows and 2 columns
 *   - Maximum 50 rows and 50 columns
 *   - At least 1 mine, at most (rows × cols) − 9 (leaving a safe 3×3 on first click)
 *
 * Note on the advertised minimum: boards of fewer than 10 cells (2×2 … 3×3)
 * satisfy the side-length checks yet can never satisfy "1 … rows×cols−9
 * mines", so msw-18/-020 refuses them with a dedicated minimum-area message
 * naming the 10-cell floor — never the mine-count blame, which for a derived
 * default (the CLI omits mines) would cite a count the player never typed.
 * The CLI door and this engine cap stay distinct by design (G4c ruling):
 * Board's own invariant is the looser ≤ w·h−1, and the −9 safe-zone cap is
 * this form's product rule.
 */
final class CustomDifficulty
{
    public readonly int $rows;
    public readonly int $cols;
    public readonly int $mines;

    private function __construct(int $rows, int $cols, int $mines)
    {
        $this->rows = $rows;
        $this->cols = $cols;
        $this->mines = $mines;
    }

    /**
     * Build with defaults appropriate for a new custom game.
     */
    public static function defaults(): self
    {
        return new self(9, 9, 10);
    }

    /**
     * Build from raw input values. Throws on validation failure.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromInput(int $rows, int $cols, int $mines): self
    {
        if ($rows < 2) {
            throw new \InvalidArgumentException(Lang::t('custom.min_rows'));
        }
        if ($cols < 2) {
            throw new \InvalidArgumentException(Lang::t('custom.min_cols'));
        }
        if ($rows > 50) {
            throw new \InvalidArgumentException(Lang::t('custom.max_rows'));
        }
        if ($cols > 50) {
            throw new \InvalidArgumentException(Lang::t('custom.max_cols'));
        }
        // msw-18/-020: the honest floor, stated as its own rule before any
        // mine logic — a sub-10-cell board cannot host a safe 3×3 plus a
        // mine, no matter what mine count (typed or derived) is offered.
        if ($rows * $cols < 10) {
            throw new \InvalidArgumentException(Lang::t('custom.min_area'));
        }
        $maxMines = $rows * $cols - 9;
        if ($mines < 1) {
            throw new \InvalidArgumentException(Lang::t('custom.min_mines'));
        }
        if ($mines > $maxMines) {
            throw new \InvalidArgumentException(Lang::t('custom.max_mines'));
        }
        return new self($rows, $cols, $mines);
    }

    /**
     * Return a display label for the current settings.
     */
    public function label(): string
    {
        return "{$this->rows}×{$this->cols} · {$this->mines} mines";
    }
}
