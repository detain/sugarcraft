<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Marker interface for all input events produced by EscapeDecoder.
 *
 * Mirrors charmbracelet/bubbletea's `tea.Msg` — the empty interface every
 * message type (KeyMsg, MouseMsg, WindowSizeMsg, ...) satisfies.
 */
interface Event
{
}
