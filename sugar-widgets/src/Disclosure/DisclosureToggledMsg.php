<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Disclosure;

use SugarCraft\Core\Msg;

/**
 * Sent through a Cmd when a key opens or closes a {@see Disclosure}, either
 * standalone or as an item of a {@see CollapsibleList}. Only a real change
 * of state sends it: opening an item that is already open returns a null
 * Cmd, so a host can treat every message as an edge.
 *
 * `$index` is the item's position in the list that toggled it, or null for
 * a standalone Disclosure.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no disclosure
 * component, so there is no upstream Mirrors citation.
 */
final class DisclosureToggledMsg implements Msg
{
    public function __construct(
        public readonly string $title,
        public readonly bool $open,
        public readonly ?int $index = null,
    ) {
    }
}
