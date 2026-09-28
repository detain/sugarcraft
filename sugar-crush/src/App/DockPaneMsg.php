<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Message carrying a `/pane` verb to the shell — the command twin of the
 * {@see \SugarCraft\Crush\Tui\PaneDragController} drop and of the keyboard
 * dock/undock arm. The verb travels as the `left`/`right`/`toggle` word the
 * user typed because `Chat` has no reason to know candy-layout's enum;
 * {@see App::applyDockCommand()} parses it at the boundary and refuses
 * anything else.
 *
 * `paneName` null means "whatever pane currently holds focus", the same
 * subject the mouse gestures take; the focus-not-dockable case is answered
 * with a status line rather than silence.
 */
final readonly class DockPaneMsg implements Msg
{
    public function __construct(public string $action, public ?string $paneName = null) {}
}
