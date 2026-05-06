<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Table;

/**
 * Default in-memory {@see Data} implementation — a 2D string array.
 *
 * Mirrors lipgloss's `StringData`. Use to feed Table from any
 * eagerly-materialised matrix:
 *
 * ```php
 * $data = StringData::fromMatrix([
 *     ['Alice', '32', 'NYC'],
 *     ['Bob',   '41', 'LA'],
 * ]);
 *
 * echo Table::new()
 *     ->headers('Name', 'Age', 'City')
 *     ->data($data)
 *     ->render();
 * ```
 *
 * The factory is forgiving: ragged rows pad to the widest row's
 * column count with empty strings so `at()` never goes out of
 * bounds.
 */
final class StringData implements Data
{
    /**
     * @param list<list<string>> $rows
     */
    public function __construct(private readonly array $rows)
    {}

    /**
     * Build a {@see StringData} from a 2D array. Non-string cell
     * values are coerced via `(string)`. Ragged rows are padded
     * with empty strings to the widest row.
     *
     * @param iterable<iterable<mixed>> $matrix
     */
    public static function fromMatrix(iterable $matrix): self
    {
        $cols = 0;
        $tmp  = [];
        foreach ($matrix as $row) {
            $r = [];
            foreach ($row as $cell) {
                $r[] = (string) $cell;
            }
            $cols = max($cols, count($r));
            $tmp[] = $r;
        }
        // Pad ragged rows to the widest column count.
        $out = [];
        foreach ($tmp as $r) {
            $missing = $cols - count($r);
            if ($missing > 0) {
                $r = array_merge($r, array_fill(0, $missing, ''));
            }
            $out[] = $r;
        }
        return new self($out);
    }

    public function rows(): int { return count($this->rows); }

    public function columns(): int
    {
        return $this->rows === [] ? 0 : count($this->rows[0]);
    }

    public function at(int $row, int $col): string
    {
        return $this->rows[$row][$col] ?? '';
    }

    /** Append a row. Returns a new instance (immutable). */
    public function append(string ...$cells): self
    {
        return new self([...$this->rows, array_values($cells)]);
    }

    /** Empty `StringData` (zero rows, zero columns). */
    public static function empty(): self
    {
        return new self([]);
    }
}
