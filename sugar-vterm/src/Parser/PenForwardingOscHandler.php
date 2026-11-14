<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Parser;

use SugarCraft\AnsiParser\Parser\OscHandler;
use SugarCraft\Vterm\Hyperlink\Hyperlink;

/**
 * Pen-forwarding OSC handler for the vcr renderer path.
 *
 * Implements sugar-ansi-parser's {@see \SugarCraft\AnsiParser\Parser\OscHandler}.
 * Stores the window title and — since ESC-2 — the active OSC 8 hyperlink,
 * forwarding it to the {@see CsiHandlerImpl} pen so cells printed while a
 * link is open carry it (the emulator's `Handler\OscHandler` +
 * `ScreenHandler::$currentHyperlink` pair, mirrored on this side).
 *
 * Shipped as `OscHandlerImpl` until 2026-11-13 (sap-19 vterm-leg): the
 * behavior name replaces the shape-only `*Impl` suffix, and the old FQN
 * stays alive as a deprecated class_alias so shipped consumers do not
 * break. NOT a copy of sugar-ansi-parser's RecordingOscHandler — the pen
 * sink and typed Hyperlink objects are vterm behavior the lib must not
 * absorb (the drift fold ruling the parser-leg disclosed).
 */
final class PenForwardingOscHandler implements OscHandler
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
     * OSC 8 open/close. The sugar-ansi-parser parser contract is that an empty
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
