<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\JsonLines;

/**
 * Incremental newline splitter for JSON-Lines / NDJSON streams.
 *
 * Feed it whatever a non-blocking read returned; it hands back every COMPLETE
 * line and retains the unterminated tail for the next chunk. It splits and
 * nothing else — decoding, blank-line skipping, "\r" trimming, SSE `data:`
 * and header handling stay with the caller, because those are per-protocol
 * policy and three call-site families already disagree on them.
 *
 * WHY THE SEARCH NEVER RE-READS THE TAIL. Only the incoming chunk is searched
 * for "\n" (the retained partial is known newline-free), so a 64 MiB frame
 * arriving in 4 KiB reads costs O(n), not the O(n^2) of re-scanning the
 * buffer per chunk.
 *
 * WHY THE CAP DROPS INSTEAD OF THROWING. `feed()` returns the lines it
 * completed; throwing would lose the ones completed in the same chunk before
 * the oversized one. An over-cap line is discarded whole (through its
 * terminating newline, even across later chunks — half a frame never parses)
 * and counted; a caller whose policy is "refuse" reads {@see takeOversized()}
 * after each feed and throws, a caller whose policy is "drop" ignores it.
 */
final class Reader
{
    private string $buffer = '';

    /** True while discarding the rest of an over-cap line. */
    private bool $skipping = false;

    private int $oversized = 0;

    /** @param int|null $maxLineBytes longest line (without its newline) kept; null = unbounded */
    public function __construct(private readonly ?int $maxLineBytes = null)
    {
        if ($maxLineBytes !== null && $maxLineBytes < 1) {
            throw new \InvalidArgumentException('maxLineBytes must be at least 1 or null');
        }
    }

    /**
     * Append a chunk; return the complete lines it finished, in order,
     * WITHOUT their "\n". Empty lines are returned as ''.
     *
     * @return list<string>
     */
    public function feed(string $chunk): array
    {
        $lines = [];
        $offset = 0;
        $length = strlen($chunk);

        while ($offset < $length) {
            $newline = strpos($chunk, "\n", $offset);

            if ($this->skipping) {
                if ($newline === false) {
                    return $lines;
                }
                $this->skipping = false;
                $offset = $newline + 1;
                continue;
            }

            if ($newline === false) {
                $this->buffer .= substr($chunk, $offset);
                $this->enforceCap();

                return $lines;
            }

            $line = substr($chunk, $offset, $newline - $offset);
            $offset = $newline + 1;
            if ($this->buffer !== '') {
                $line = $this->buffer . $line;
                $this->buffer = '';
            }
            if ($this->maxLineBytes !== null && strlen($line) > $this->maxLineBytes) {
                $this->oversized++;
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The retained unterminated tail, or '' — what an EOF-is-a-frame caller
     * reads before {@see reset()}; an EOF-is-an-error caller reports it.
     */
    public function partial(): string
    {
        return $this->buffer;
    }

    /** Bytes currently retained. */
    public function pendingBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Over-cap lines discarded since the last call, then reset to zero.
     */
    public function takeOversized(): int
    {
        $count = $this->oversized;
        $this->oversized = 0;

        return $count;
    }

    /** Forget the retained tail, any skip in progress, and the oversized count. */
    public function reset(): void
    {
        $this->buffer = '';
        $this->skipping = false;
        $this->oversized = 0;
    }

    private function enforceCap(): void
    {
        if ($this->maxLineBytes !== null && strlen($this->buffer) > $this->maxLineBytes) {
            $this->buffer = '';
            $this->skipping = true;
            $this->oversized++;
        }
    }
}
