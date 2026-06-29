<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Receives OSC (Operating System Command) dispatches from the parser.
 *
 * Empty shell for now — Phase 1c fills in the implementations.
 * OSC sequences are terminated by BEL (0x07) or ST (ESC \).
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
