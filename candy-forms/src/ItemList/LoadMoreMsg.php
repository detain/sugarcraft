<?php

declare(strict_types=1);

namespace SugarCraft\Forms\ItemList;

use SugarCraft\Core\Msg;

/**
 * Delivered to the host when an {@see ItemList} cursor ARRIVES on the last
 * visible item while {@see ItemList::hasMore()} is true — the list has run
 * out of loaded entries but the host said more pages exist.
 *
 * Edge-triggered, once per arrival: resting on the last item does not
 * re-fire on every keystroke, and leaving and returning fires again.
 * SugarCraft extension (E736 5.15) — upstream Bubbles `list.go` carries no
 * pagination cursor, so hosts watch for this message themselves.
 */
final class LoadMoreMsg implements Msg
{
}
