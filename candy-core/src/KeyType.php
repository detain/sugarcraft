<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Logical key types emitted by the input parser. {@see KeyType::Char} carries
 * the actual rune in the {@see Msg\KeyMsg::$rune} field; all other cases are
 * named keys whose rune is irrelevant.
 */
enum KeyType: string
{
    case Char      = 'char';
    case Up        = 'up';
    case Down      = 'down';
    case Left      = 'left';
    case Right     = 'right';
    case Enter     = 'enter';
    case Escape    = 'escape';
    case Tab       = 'tab';
    case Backspace = 'backspace';
    case Space     = 'space';
    case Delete    = 'delete';
    case Home      = 'home';
    case End       = 'end';
    case PageUp    = 'pageup';
    case PageDown  = 'pagedown';
}
