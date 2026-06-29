<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Immutable value object representing a file's unified diff with an
 * interactive hunk cursor.
 *
 * @param list<string> $lines       Raw output of `git diff --no-color -- <path>`
 * @param int          $hunkCursor Index of the currently selected hunk (for Space to stage)
 * @param string       $path       The file path this diff belongs to
 */
final readonly class DiffViewer
{
    /**
     * @param list<string> $lines Raw diff lines (including hunk headers)
     * @param list<int>    $hunkStarts Byte-offsets in $lines where each hunk begins
     * @param list<string> $header Lines before the first hunk header (diff --git / --- / +++)
     */
    public function __construct(
        public array $lines,
        public int $hunkCursor,
        public string $path,
        public array $hunkStarts,
        public array $header,
    ) {}

    /**
     * Build a DiffViewer from raw `git diff --no-color -- <path>` output.
     *
     * Hunk headers look like:
     *   @@ -1,3 +1,4 @@ optional context
     */
    public static function fromRawDiff(string $path, array $lines): self
    {
        $hunkStarts = [];
        $header = [];
        $inHeader = true;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '@@')) {
                $inHeader = false;
                $hunkStarts[] = $i;
            } elseif ($inHeader) {
                $header[] = $line;
            }
        }
        // Default cursor to first hunk, or 0 if no hunks
        $cursor = $hunkStarts[0] ?? 0;

        return new self(lines: $lines, hunkCursor: $cursor, path: $path, hunkStarts: $hunkStarts, header: $header);
    }

    /** Returns the hunk lines around hunkCursor, for rendering and staging. */
    public function currentHunkLines(): array
    {
        if ($this->hunkStarts === []) {
            return $this->lines;
        }

        $start = $this->hunkCursor;
        $end = count($this->lines) - 1;

        // Find the next hunk start after current
        $hunkIdx = array_search($this->hunkCursor, $this->hunkStarts, true);
        if ($hunkIdx !== false && $hunkIdx + 1 < count($this->hunkStarts)) {
            $end = $this->hunkStarts[$hunkIdx + 1] - 1;
        }

        return array_slice($this->lines, $start, $end - $start + 1);
    }

    /** Returns the raw patch for just the current hunk (for git apply --cached). */
    public function currentHunkPatch(): string
    {
        $header = implode("\n", $this->header);
        $hunk = implode("\n", $this->currentHunkLines());
        return $header === '' ? $hunk . "\n" : $header . "\n" . $hunk . "\n";
    }

    public function withHunkCursor(int $cursor): self
    {
        $max = count($this->hunkStarts) - 1;
        $newIdx = max(0, min($cursor, $max >= 0 ? $max : 0));
        $lineIdx = ($max >= 0 && isset($this->hunkStarts[$newIdx]))
            ? $this->hunkStarts[$newIdx]
            : 0;
        return new self(
            lines: $this->lines,
            hunkCursor: $lineIdx,
            path: $this->path,
            hunkStarts: $this->hunkStarts,
            header: $this->header,
        );
    }

    /** Number of hunks in this diff. */
    public function hunkCount(): int
    {
        return count($this->hunkStarts);
    }
}
