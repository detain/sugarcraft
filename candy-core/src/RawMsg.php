<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Internal sentinel returned by {@see Cmd::raw()}. The Program
 * intercepts it and writes the bytes verbatim to its output stream
 * without disturbing the renderer's diff state. Useful as an escape
 * hatch for terminal features the framework doesn't wrap (e.g. iTerm2
 * inline images, OSC 7 cwd reporting).
 *
 * @internal
 */
final class RawMsg implements Msg
{
    public function __construct(public readonly string $bytes) {}
}
