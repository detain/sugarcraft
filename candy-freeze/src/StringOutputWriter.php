<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Output writer that accumulates all chunks into a string.
 * Use for cases where the entire output is needed as a single string.
 */
final class StringOutputWriter implements OutputWriter
{
    private string $buffer = '';

    public function write(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    public function flush(): void
    {
        // No-op: string accumulation is already in memory.
    }

    /**
     * Return the accumulated output as a string.
     */
    public function getResult(): string
    {
        return $this->buffer;
    }
}
