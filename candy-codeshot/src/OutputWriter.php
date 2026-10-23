<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

/**
 * Interface for chunked output writers.
 *
 * Standalone helpers for callers that want to consume a rendered
 * screenshot in pieces. The bundled renderers do not use them:
 * {@see SvgRenderer::render()} and {@see PngRenderer::render()} each
 * return the complete byte string in one buffer.
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
