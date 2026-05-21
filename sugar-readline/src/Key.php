<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Symbolic key names accepted by every prompt's handleKey().
 *
 * Constants — not an enum — so callers can interop with raw strings
 * (e.g. when forwarding from a parser that already produces names).
 */
final class Key
{
    public const Up        = 'up';
    public const Down      = 'down';
    public const Left      = 'left';
    public const Right     = 'right';
    public const Home      = 'home';
    public const End       = 'end';
    public const PageUp    = 'pageup';
    public const PageDown  = 'pagedown';
    public const Tab       = 'tab';
    public const Enter     = 'enter';
    public const Backspace = 'backspace';
    public const Delete    = 'delete';
    public const Space     = 'space';
    public const Escape    = 'esc';
    public const CtrlC     = 'ctrl_c';
    public const CtrlU     = 'ctrl_u';
    public const CtrlK     = 'ctrl_k';
    public const CtrlW     = 'ctrl_w';
    public const Undo      = 'undo';
    public const Redo      = 'redo';

    private function __construct() {}
}
