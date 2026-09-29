<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

/**
 * Marker for a payload that should fold into the current Type group rather
 * than emitting its own directive line. Internal to {@see Decompiler}.
 *
 * @internal
 */
final readonly class DecompilerTypeChunk
{
    public function __construct(public string $text)
    {
    }
}
