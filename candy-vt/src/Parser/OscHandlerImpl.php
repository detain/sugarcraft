<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

/**
 * OSC handler for the vcr renderer path.
 *
 * Stores window title; hyperlink support deferred to v2.
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
