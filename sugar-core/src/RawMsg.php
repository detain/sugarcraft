<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Sentinel returned by {@see Cmd::raw()}. The Program
 * intercepts it and writes the bytes verbatim to its output stream
 * without disturbing the renderer's diff state. Useful as an escape
 * hatch for terminal features the framework doesn't wrap (e.g. iTerm2
 * inline images, OSC 7 cwd reporting) — user code pattern-matches on
 * this class in `update()` exactly like any other framework message.
 */
final class RawMsg implements Msg
{
    public function __construct(public readonly string $bytes)
    {
    }
}
