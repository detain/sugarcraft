<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Boxer;

use SugarCraft\Dashboard\Foundation\Item;

/**
 * Blank stand-in content for a declared leaf that no one has edited yet.
 *
 * Factory-built trees (`Boxer::vertical(Node::leaf('0'), ...)`) declare
 * addresses long before the host supplies models; seeding each declared
 * leaf with this placeholder keeps `editLeaf()` — the documented primary
 * path — usable straight away instead of throwing NotFoundError on the
 * address the factory itself announced (finding sugar-dashboard-033).
 * It renders the empty string, so an untouched slot is simply blank
 * space in the layout, and the render-time size accounting stays honest.
 *
 * @internal Never construct this outside the Boxer seeding path; hosts
 *     replace it through {@see Boxer::editLeaf()}.
 */
final class PlaceholderItem implements Item
{
    public function render(): string
    {
        return '';
    }
}
