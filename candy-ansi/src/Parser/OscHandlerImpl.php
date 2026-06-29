<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * OSC handler for the vcr renderer path.
 *
 * Stores window title; hyperlink support deferred to v2.
 *
 * Mirrors charmbracelet/x/ansi.OscHandler
 */
final class OscHandlerImpl implements OscHandler
{
    private string $lastTitle = '';

    public function title(string $title): void
    {
        $this->lastTitle = $title;
    }

    public function hyperlink(string $uri, string $id): void
    {
    }

    public function lastTitle(): string
    {
        return $this->lastTitle;
    }
}
