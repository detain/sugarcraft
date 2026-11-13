<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * Recording OSC sink: stores the window title and the currently-active
 * OSC 8 hyperlink.
 *
 * No upstream class mirrors this one (finding 004): it is a lib-original
 * ready-made {@see OscHandler} implementation, here as the storage leg of
 * the vcr/renderer wiring. The bare behavior name (finding 029, gate G4)
 * replaces the shape-only `*Impl` suffix; that old FQN stays alive as a
 * deprecated class_alias so shipped consumers do not break.
 */
final class RecordingOscHandler implements OscHandler
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
        // An empty URI closes the hyperlink and BOTH fields reset. A close
        // sequence may
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
