<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * OSC handler for the vcr renderer path.
 *
 * Stores the window title and the currently-active OSC 8 hyperlink.
 *
 * Mirrors charmbracelet/x/ansi.OscHandler
 */
final class OscHandlerImpl implements OscHandler
{
    private string $lastTitle = '';

    private string $hyperlinkUri = '';

    private string $hyperlinkId = '';

    public function title(string $title): void
    {
        $this->lastTitle = $title;
    }

    public function hyperlink(string $uri, string $id): void
    {
        // An empty URI closes the current hyperlink, so both fields reset.
        $this->hyperlinkUri = $uri;
        $this->hyperlinkId = $id;
    }

    public function lastTitle(): string
    {
        return $this->lastTitle;
    }

    /**
     * URI of the currently-open hyperlink, or '' when none is open.
     */
    public function hyperlinkUri(): string
    {
        return $this->hyperlinkUri;
    }

    /**
     * `id=` value of the currently-open hyperlink, or '' when none/unset.
     */
    public function hyperlinkId(): string
    {
        return $this->hyperlinkId;
    }
}
