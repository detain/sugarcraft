<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Vt\Hyperlink\Hyperlink;

/**
 * OSC handler for the vcr renderer path.
 *
 * Implements candy-ansi's {@see \SugarCraft\Ansi\Parser\OscHandler}.
 * Stores the window title and — since ESC-2 — the active OSC 8 hyperlink,
 * forwarding it to the {@see CsiHandlerImpl} pen so cells printed while a
 * link is open carry it (the emulator's `Handler\OscHandler` +
 * `ScreenHandler::$currentHyperlink` pair, mirrored on this side).
 */
final class OscHandlerImpl implements OscHandler
{
    private string $lastTitle = '';

    /**
     * Last OSC 8 link seen ('' URI closed it → null). Kept even without a
     * sink so standalone use (tests, adapters) can still introspect it.
     */
    private ?Hyperlink $currentHyperlink = null;

    /**
     * @param CsiHandlerImpl|null $sink grid pen fed with every link change;
     *                                  null keeps the handler introspection-only.
     */
    public function __construct(
        private readonly ?CsiHandlerImpl $sink = null,
    ) {
    }

    public function title(string $title): void
    {
        $this->lastTitle = $title;
    }

    /**
     * OSC 8 open/close. The candy-ansi parser contract is that an empty
     * `$uri` CLOSES the current link (xterm ctlseqs: `OSC 8 ; params ; URI`,
     * "an empty URI ... terminates the current hyperlink"), so the pen (and
     * every cell printed afterwards) returns to unlinked.
     *
     * The URI is attacker-controlled program output — store/whitelist
     * downstream before display, same rule as the emulator's OSC 8 path.
     */
    public function hyperlink(string $uri, string $id): void
    {
        $this->currentHyperlink = $uri === '' ? null : new Hyperlink($id, $uri);
        $this->sink?->setHyperlink($this->currentHyperlink);
    }

    public function lastTitle(): string
    {
        return $this->lastTitle;
    }

    /** The link currently open on this handler, or null after a close. */
    public function currentHyperlink(): ?Hyperlink
    {
        return $this->currentHyperlink;
    }
}
