<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Receives OSC (Operating System Command) dispatches from the parser.
 *
 * OSC sequences are terminated by BEL (0x07) or ST (ESC \). This is NOT a
 * stub: the bridge is live in both directions. {@see HandlerAdapter} parses
 * OSC 8 (hyperlink, `id=` included) and OSC 0/1/2 (title) out of the raw
 * dispatch payload and calls these two methods, and {@see OscHandlerImpl}
 * — shipping in this library — implements them with real storage,
 * including the empty-URI close path that resets both fields. sugar-vterm
 * carries its own implementer on the emulator side.
 *
 * Mirrors charmbracelet/x/ansi.OscHandler
 */
interface OscHandler
{
    /**
     * Set the window title.
     */
    public function title(string $title): void;

    /**
     * Open or close a hyperlink.
     *
     * @param string $uri  the URI; empty string closes the hyperlink
     * @param string $id   optional id parameter from OSC 8;params
     */
    public function hyperlink(string $uri, string $id): void;
}
