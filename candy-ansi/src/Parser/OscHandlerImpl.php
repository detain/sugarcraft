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
        // C3 (lane A3a): an empty URI closes the hyperlink and BOTH fields
        // reset — as the doc above always promised. A close sequence may
        // still carry params ('ESC]8;id=x;ST' parses to hyperlink('', 'x')),
        // so the id argument is deliberately dropped on the close path
        // instead of being stored as a stale lineage marker.
        if ($uri === '') {
            $this->hyperlinkUri = '';
            $this->hyperlinkId = '';

            return;
        }

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
