<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\Promise\PromiseInterface;

/**
 * Strategy interface for asynchronous rendering.
 *
 * Implement this to provide alternate async backends (e.g. a worker pool
 * or pcntl_fork-based process per render).
 */
interface AsyncRenderer
{
    /**
     * Render the image asynchronously and resolve with ANSI bytes.
     *
     * @return PromiseInterface<string>  Resolves with encoded bytes on success.
     */
    public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface;
}
