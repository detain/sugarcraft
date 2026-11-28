<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Raw line tail of a file through a fixed byte window:
 * `[$lines, $offset] = LogTailWindow::new($path)->lines(100)`.
 *
 * {@see LogReader::tail()} answers in parsed records and reads back as far as
 * it takes to find them. This answers in raw lines and never reads more than
 * the window, which is what a log surface that hands lines on unparsed (a
 * server's `logs` verb, an admin RPC) wants: a bounded read whatever the
 * file holds, and the offset to resume from.
 *
 * The rules, kept exactly as candy-code's server log tail has them so that
 * tail can move onto this class with no change in what it answers:
 *
 * - Only the last {@see $windowBytes} bytes are read. When the file is
 *   longer than the window, the first line of the window is dropped, since
 *   it may be the tail end of a line that starts before the window. It is
 *   dropped even when the window happens to start on a line boundary: one
 *   byte more would be needed to tell, and the window is the bound.
 * - Blank and whitespace-only lines are lines: they are returned and count
 *   toward $n. Trailing newlines at the end of the file are trimmed first,
 *   so the empty "line" after the last newline, and any run of newlines at
 *   the very end, is not one.
 * - A line keeps its bytes as written: a carriage return before the newline
 *   stays on the line, and nothing is parsed or stripped.
 * - A final line still missing its newline (a write in flight) is returned
 *   like any other.
 * - The offset is the file's size, read from the same handle the window was
 *   read through, so it is the byte just past what this read saw.
 * - A path that cannot be opened (missing, unreadable, or not a regular
 *   file) answers no lines and offset 0, never an error: a log nobody has
 *   written yet is an answer.
 * - A symlink at the path is refused the same way, even one pointing at a
 *   regular file: the shape is checked with lstat, never stat, so the tail
 *   is never followed out of the directory that holds the log.
 *
 * Immutable: every with*() returns a new instance through mutate().
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogTailWindow
{
    /** Default window: how far back from the end of the file {@see lines()} reads. */
    public const WINDOW_BYTES = 256 * 1024;

    private function __construct(
        public string $path,
        public int $windowBytes,
    ) {
    }

    /** A tail of $path through the default {@see WINDOW_BYTES} window. */
    public static function new(string $path): self
    {
        return new self($path, self::WINDOW_BYTES);
    }

    public function withPath(string $path): self
    {
        return $this->mutate(['path' => $path]);
    }

    /**
     * The most bytes {@see lines()} reads from the end of the file.
     *
     * @throws \InvalidArgumentException When $bytes is zero or less.
     */
    public function withWindowBytes(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException(
                Lang::t('viewer.not_positive', ['name' => 'windowBytes', 'value' => (string) $bytes]),
            );
        }

        return $this->mutate(['windowBytes' => $bytes]);
    }

    /**
     * The last $n lines inside the window, oldest first, and the byte offset
     * the read stopped at (the file's size). $n = 0 answers no lines but
     * still the offset.
     *
     * @return array{0: list<string>, 1: int}
     *
     * @throws \InvalidArgumentException When $n is negative.
     */
    public function lines(int $n): array
    {
        if ($n < 0) {
            throw new \InvalidArgumentException(Lang::t('reader.negative_count', ['count' => (string) $n]));
        }

        $handle = $this->open();
        if ($handle === null) {
            return [[], 0];
        }

        try {
            $size = (int) (\fstat($handle)['size'] ?? 0);
            $from = \max(0, $size - $this->windowBytes);
            \fseek($handle, $from);
            $text = $size > $from ? (string) \stream_get_contents($handle, $size - $from) : '';
        } finally {
            \fclose($handle);
        }

        $lines = \explode("\n", \rtrim($text, "\n"));
        if ($from > 0) {
            \array_shift($lines);
        }
        if ($lines === [''] || $n === 0) {
            return [[], $size];
        }

        return [\array_slice($lines, -$n), $size];
    }

    /**
     * A read handle on a regular file at the path, or null. The shape is
     * checked with lstat before opening, so a symlink at the path is refused
     * rather than followed, and a fifo already there is never opened (opening
     * one blocks until a writer comes). It is checked again on the handle, so
     * a directory, or a symlink to one, swapped in between the check and the
     * open is caught too. A symlink to a regular file, or a fifo, swapped in
     * inside that window is not: PHP's fopen() has no O_NOFOLLOW, and it
     * blocks on a fifo before the handle check runs. That residual race is
     * accepted, since it needs a racing writer in a directory the same user
     * already owns.
     *
     * @return resource|null
     */
    private function open(): mixed
    {
        \clearstatcache(true, $this->path);
        $stat = @\lstat($this->path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }
        $handle = @\fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }
        $mode = (int) (\fstat($handle)['mode'] ?? 0);
        if (($mode & 0o170000) !== 0o100000) {
            \fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...\array_merge(\get_object_vars($this), $changes));
    }
}
