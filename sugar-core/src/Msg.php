<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Marker interface for messages flowing through the {@see Program} loop.
 *
 * A Msg is any event the user's {@see Model::update()} should react to —
 * keypresses, window-size changes, ticks, custom application messages, etc.
 * Implementations are typically immutable readonly value objects.
 *
 * @property-read \SugarCraft\Core\KeyType $type KeyMsg type (KeyType enum value)
 * @property-read string $rune KeyMsg character (empty for named keys)
 * @property-read bool $alt Alt modifier flag
 * @property-read bool $ctrl Ctrl modifier flag
 * @property-read bool $shift Shift modifier flag
 * @property-read \SugarCraft\Core\MouseButton $button MouseMsg button
 * @property-read \SugarCraft\Core\MouseAction $action MouseMsg action
 * @property-read int $x MouseMsg X coordinate (1-based)
 * @property-read int $y MouseMsg Y coordinate (1-based)
 * @property-read int $cols WindowSizeMsg columns
 * @property-read int $rows WindowSizeMsg rows
 * @property-read string $content PasteMsg/ClipboardMsg text content
 * @property-read string $selection ClipboardMsg selection key
 * @property-read int $flags KeyboardEnhancementsMsg bitfield
 * @property-read int $state State for stateful messages
 * @property-read int $r ColorMsg red component
 * @property-read int $g ColorMsg green component
 * @property-read int $b ColorMsg blue component
 *
 * @method string string() Human-readable label for KeyMsg (e.g. "ctrl+c")
 * @method string text() KeyMsg text content (alias for $rune)
 * @method \SugarCraft\Core\KeyType code() KeyMsg key code (alias for $type)
 * @method \SugarCraft\Core\Modifiers modifiers() KeyMsg bundled modifier flags
 * @method bool has(int $mask) KeyboardEnhancementsMsg check if flag is set
 */
interface Msg
{
}
