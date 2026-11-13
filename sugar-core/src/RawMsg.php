<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Sentinel returned by {@see Cmd::raw()}. The Program
 * intercepts it and writes the bytes verbatim to its output stream
 * without disturbing the renderer's diff state. Useful as an escape
 * hatch for terminal features the framework doesn't wrap (e.g. iTerm2
 * inline images, OSC 7 cwd reporting). The sentinel never reaches the
 * model: the dispatch arm writes the bytes and returns, so a consumer
 * that branches on RawMsg inside `update()` sees nothing — that claim
 * was the stale intercept text (sugar-core-038).
 */
final class RawMsg implements Msg
{
    public function __construct(public readonly string $bytes)
    {
    }
}
