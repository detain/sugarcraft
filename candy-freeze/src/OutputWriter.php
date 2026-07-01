<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Interface for streaming output writers.
 *
 * Enables streaming output for large screenshots without buffering
 * the entire rendered result in memory.
 */
interface OutputWriter
{
    /**
     * Write a chunk of output.
     */
    public function write(string $chunk): void;

    /**
     * Flush any buffered output.
     */
    public function flush(): void;
}
