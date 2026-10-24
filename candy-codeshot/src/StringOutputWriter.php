<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

/**
 * Output writer that accumulates all chunks into a string.
 * Use when the chunks must be reassembled into a single string.
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
    public function result(): string
    {
        return $this->buffer;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::result()} instead. REMOVED-IN: 2.0.0.
     */
    public function getResult(): string { return $this->result(); }
}
