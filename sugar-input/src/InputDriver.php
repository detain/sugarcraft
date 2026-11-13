<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Contract for a pull-based source of decoded terminal events.
 *
 * Implementations differ in where their bytes come from — a stream resource
 * (Driver\StreamInputDriver) or a signal disposition (Driver\SignalResizeDriver)
 * — and each documents its own construction and lifecycle.
 * The interface promises only that read() is non-blocking and yields at most
 * one Event, or null when none is available.
 *
 * The ReactPHP adapter (Driver\ReactInputDriver) is deliberately NOT an
 * InputDriver: it PUSHES events by emitting them. Every event in the
 * decoder's repertoire can arrive here, including TerminalReplyEvent —
 * replies share the keystroke channel, so hosts that match only concrete
 * key/mouse events should expect unknown Event instances to pass through.
 *
 * This is the pull-based counterpart of the charmbracelet/bubbletea input loop.
 *
 * @see Driver\StreamInputDriver
 * @see Driver\SignalResizeDriver
 */
interface InputDriver
{
    /**
     * Read and return the next input event, or null when none is available
     * (EOF on a stream driver, no pending signal on the resize driver —
     * each implementation documents which).
     *
     * @return Event|null one of KeyEvent|MouseEvent|FocusEvent|PasteEvent|
     *                    ResizeEvent|TerminalReplyEvent, or null
     */
    public function read(): Event|null;
}
