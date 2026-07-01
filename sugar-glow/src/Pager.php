<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

/**
 * Streaming pager that reads input in chunks and yields lines.
 *
 * Used for large files where loading the entire content into memory is impractical.
 *
 * Note: Empty streams yield no chunks. A consumer calling iterator_to_array($pager)
 * on an empty stream will receive an empty array with no indication of whether
 * the stream was genuinely empty vs. seekable-and-closed vs. other edge case.
 */
final class Pager implements \IteratorAggregate
{
    /** @var \Generator<array<string>> */
    private \Generator $generator;

    /**
     * @param resource $stream    A readable stream (e.g. fopen('file.txt', 'r'))
     * @param int      $chunkSize  Lines per chunk yield (default 100)
     */
    public function __construct($stream, int $chunkSize = 100)
    {
        $this->generator = (function () use ($stream, $chunkSize): \Generator {
            $buffer = [];
            $lineNum = 0;
            while (($line = fgets($stream)) !== false) {
                $buffer[] = $line;
                $lineNum++;
                if ($lineNum % $chunkSize === 0) {
                    yield $buffer;
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                yield $buffer;
            }
        })();
    }

    public function getIterator(): \Generator
    {
        return $this->generator;
    }
}
