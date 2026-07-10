<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use SugarCraft\Ansi\Parser\OscHandler;

/**
 * OSC handler for the vcr renderer path.
 *
 * Implements candy-ansi's {@see \SugarCraft\Ansi\Parser\OscHandler}.
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
