<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Immutable value object representing a file's unified diff with an
 * interactive hunk cursor.
 *
 * @param list<string> $lines       Raw output of `git diff --no-color -- <path>`
 * @param int          $hunkCursor  Line index where the selected hunk starts
 *                                  (always one of $hunkStarts, or 0 when the
 *                                  diff carries no hunks at all — Space stages
 *                                  it; see hunkIndex() for the 0-based position)
 * @param string       $path       The file path this diff belongs to
 *
 * Mirrors jesseduffield/lazygit pkg/gui/patches — hunk cursor with per-hunk stage over unified diff. */
final readonly class DiffViewer
{
    /**
     * @param list<string> $lines Raw diff lines (including hunk headers)
     * @param list<int>    $hunkStarts Line indices in $lines where each hunk begins
     * @param list<string> $header Lines before the first hunk header (diff --git / --- / +++)
     * @param bool         $cached Which side this diff shows: true = the index
     *                             (`--cached`), so Space must UNstage; false =
     *                             the worktree (candy-git-034 — the viewer used
     *                             to show the staged side but could only stage).
     * @param int          $scrollOffset First visible line of the window; the
     *                             display anchor, separate from the hunk
     *                             cursor (candy-git-045: hunk-only navigation
     *                             stranded any hunk taller than the screen).
     * @param bool         $readOnly The commit-details view (candy-git-015):
     *                             bytes for the log pane's Enter overlay that
     *                             must never be staged — Space is inert on it.
     * @param string       $commitSha The commit this display belongs to,
     *                             empty on file diffs; the revert/reset/v
     *                             prefill reads it INSTEAD of asking the user
     *                             to retype what is on screen.
     * @param bool         $partialTail The capture hit the 4 MiB ceiling and
     *                             lost everything past it (candy-git-040):
     *                             the LAST hunk is beheaded and must not be
     *                             staged; earlier hunks are complete bytes.
     */
    public function __construct(
        public array $lines,
        public int $hunkCursor,
        public string $path,
        public array $hunkStarts,
        public array $header,
        public bool $cached = false,
        public int $scrollOffset = 0,
        public bool $readOnly = false,
        public string $commitSha = '',
        public bool $partialTail = false,
    ) {}

    /**
     * Build the read-only commit display from `git show --stat --format=full
     * --patch --no-color` output (candy-git-015). The @@ scan still runs: the
     * patch half of the blob carries hunks, and j/k navigation stays honest
     * about what is on screen — but readOnly locks the staging lane shut.
     *
     * @param list<string> $lines
     */
    public static function fromCommitDetails(string $sha, array $lines): self
    {
        $hunkStarts = [];
        $header = [];
        $inHeader = true;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '@@') === true) {
                $inHeader = false;
                $hunkStarts[] = $i;
            } elseif ($inHeader === true) {
                $header[] = $line;
            }
        }
        $cursor = $hunkStarts[0] ?? 0;

        return new self(
            lines: $lines,
            hunkCursor: $cursor,
            path: $sha,
            hunkStarts: $hunkStarts,
            header: $header,
            readOnly: true,
            commitSha: $sha,
        );
    }

    /**
     * Build a DiffViewer from raw `git diff --no-color -- <path>` output.
     *
     * Hunk headers look like:
     *   @@ -1,3 +1,4 @@ optional context
     */
    public static function fromRawDiff(string $path, array $lines, bool $cached = false): self
    {
        // candy-git-040: a head-truncated capture ends with the notice line.
        // It is metadata, not diff content - keep it out of the display
        // lines, but REMEMBER it: the old code parsed the beheaded tail hunk
        // as if whole and handed a partial patch to git apply.
        $partialTail = false;
        if ($lines !== [] && $lines[count($lines) - 1] === Process::CAPTURE_NOTICE) {
            array_pop($lines);
            $partialTail = true;
        }

        $hunkStarts = [];
        $header = [];
        $inHeader = true;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '@@') === TRUE) {
                $inHeader = false;
                $hunkStarts[] = $i;
            } elseif ($inHeader === TRUE) {
                $header[] = $line;
            }
        }
        // Default cursor to first hunk, or 0 if no hunks
        $cursor = $hunkStarts[0] ?? 0;

        return new self(lines: $lines, hunkCursor: $cursor, path: $path, hunkStarts: $hunkStarts, header: $header, cached: $cached, partialTail: $partialTail);
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

    /**
     * The raw patch for just the current hunk (for git apply --cached).
     *
     * Contract (candy-git-073): a diff with no hunks has no patchable
     * selection, so the result is the empty string. The old behavior
     * prepended the header to currentHunkLines() — which falls back to
     * EVERY line when hunkStarts is empty — emitting each header line
     * twice in a string destined for git apply --cached and the undo
     * payload. Production survived only because git rejects the
     * malformed blob with an opaque error; the guard in
     * App::stageCurrentHunk now refuses it cleanly before that.
     */
    public function currentHunkPatch(): string
    {
        if ($this->hunkStarts === []) {
            return '';
        }

        $header = implode("\n", $this->header);
        $hunk = implode("\n", $this->currentHunkLines());
        return $header === '' ? $hunk . "\n" : $header . "\n" . $hunk . "\n";
    }

    /** 0-based position of the selected hunk within $hunkStarts. */
    public function hunkIndex(): int
    {
        $idx = array_search($this->hunkCursor, $this->hunkStarts, true);

        return $idx === false ? 0 : $idx;
    }

    /** Line index in $lines where the selected hunk starts. */
    public function currentHunkLine(): int
    {
        return $this->hunkCursor;
    }

    /**
     * Select the hunk at 0-based position $index in $hunkStarts (clamped).
     * candy-git-059: this parameter is a HUNK INDEX; the stored cursor stays
     * the line index it selects, which is what the windowing needs.
     */
    public function withHunkIndex(int $index): self
    {
        if ($this->hunkStarts === []) {
            return new self(lines: $this->lines, hunkCursor: 0, path: $this->path, hunkStarts: [], header: $this->header, cached: $this->cached, scrollOffset: 0, readOnly: $this->readOnly, commitSha: $this->commitSha, partialTail: $this->partialTail);
        }
        $max = count($this->hunkStarts) - 1;
        $newIdx = max(0, min($index, $max >= 0 ? $max : 0));
        $lineIdx = ($max >= 0 && isset($this->hunkStarts[$newIdx]))
            ? $this->hunkStarts[$newIdx]
            : 0;
        return new self(
            lines: $this->lines,
            hunkCursor: $lineIdx,
            path: $this->path,
            hunkStarts: $this->hunkStarts,
            header: $this->header,
            cached: $this->cached,
            readOnly: $this->readOnly,
            commitSha: $this->commitSha,
            partialTail: $this->partialTail,
            // Jumping to a hunk brings it into view (candy-git-045: the
            // scroll window follows selection; scrolling never moves
            // selection).
            scrollOffset: $lineIdx,
        );
    }

    /** First visible line of the window (clamped to the diff body). candy-git-045. */
    public function withScrollOffset(int $offset): self
    {
        $max = max(0, count($this->lines) - 1);

        return new self(
            lines: $this->lines,
            hunkCursor: $this->hunkCursor,
            path: $this->path,
            hunkStarts: $this->hunkStarts,
            header: $this->header,
            cached: $this->cached,
            readOnly: $this->readOnly,
            commitSha: $this->commitSha,
            partialTail: $this->partialTail,
            scrollOffset: max(0, min($offset, $max)),
        );
    }

    /** Scroll the window by $delta lines without touching hunk selection. */
    public function scrollBy(int $delta): self
    {
        return $this->withScrollOffset($this->scrollOffset + $delta);
    }

    /** Number of hunks in this diff. */
    public function hunkCount(): int
    {
        return count($this->hunkStarts);
    }
}
