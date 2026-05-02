<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Internal sentinel returned by {@see Cmd::println()}. The Program
 * intercepts it and writes the line above the program's region —
 * useful for non-alt-screen "inline" programs that need to log
 * messages without disturbing the rendered view.
 *
 * Mirrors Bubble Tea v2's `tea.Println` Cmd.
 *
 * @internal
 */
final class PrintMsg implements Msg
{
    public function __construct(public readonly string $text) {}
}
