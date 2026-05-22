<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Screenshot <path> directive — captures a single frame to disk.
 * Deferred: v2 scope.
 */
final readonly class ScreenshotDirective implements Directive
{
    public function __construct(
        public string $path,
    ) {
    }
}
