<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Table;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;

/**
 * Stateful diff renderer for Table.
 *
 * Holds the mutable diff state (previousFrame, prevWidth, prevHeight) and
 * produces either a full frame or a delta-encoded frame depending on
 * whether dimensions have changed since the last render.
 *
 * Construct one instance per logical table and call render() each frame
 * to get bandwidth-efficient delta output for stable dimensions.
 *
 * @see Table::render() for pure snapshot rendering (always full frame)
 */
final class TableRenderer
{
    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var int|null Previous output width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous output height for resize detection */
    private ?int $prevHeight = null;

    /**
     * Render the table, emitting a full frame or a delta depending on
     * whether dimensions have changed since the last frame.
     *
     * @return string Full frame or delta-encoded ops
     */
    public function render(Table $table): string
    {
        $lines = $table->buildLines();

        $fullOutput = \implode("\n", $lines);

        // Compute output dimensions from the rendered content.
        $height = \count($lines);
        $width = 0;
        foreach ($lines as $line) {
            $w = \SugarCraft\Core\Util\Width::string($line);
            if ($w > $width) {
                $width = $w;
            }
        }

        // Detect dimension change: reset diff state so we emit a full frame.
        if ($this->prevWidth !== null && ($this->prevWidth !== $width || $this->prevHeight !== $height)) {
            $this->previousFrame = null;
        }
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // First frame or resize: emit full output and store as previousFrame.
        if ($this->previousFrame === null) {
            $this->previousFrame = $this->bufferFromOutput($fullOutput, $width, $height);
            return $fullOutput;
        }

        // Subsequent frames with same dimensions: compute diff and emit delta.
        $currentFrame = $this->bufferFromOutput($fullOutput, $width, $height);
        $ops = $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    /**
     * Reset the diff state, forcing the next render to emit a full frame.
     */
    public function reset(): void
    {
        $this->previousFrame = null;
        $this->prevWidth = null;
        $this->prevHeight = null;
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * NOTE: This uses a per-cell withCellAt loop (O((w·h)²) copy) for
     * correctness. Step 2 of the remediation plan replaces this with
     * bulk fromGrid construction for O(w·h).
     *
     * @param string $output Multi-line string from render()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $buffer = Buffer::new($width, $height);
        $lines = \explode("\n", $output);

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            for ($col = 0; $col < $width; $col++) {
                $char = isset($line[$col]) ? \mb_substr($line, $col, 1) : ' ';
                $cell = Cell::new($char, null, null, 1);
                $buffer = $buffer->withCellAt($col, $row, $cell);
            }
        }

        return $buffer;
    }
}
