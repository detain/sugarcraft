<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Key-press event under the Kitty progressive-keyboard protocol
 * (event type 1 — the default when a `CSI ... u` sequence carries no
 * event field). Legacy CSI/SS3/printable keys do NOT arrive as this
 * class: the parser hands them over as plain {@see KeyMsg}. That is
 * the verified home for the legacy path (finding sugar-core-041; the
 * only construction site is InputReader's kitty decoder) — the old
 * docblock claimed emission for every key and misdirected handlers
 * into instanceof branches that never matched.
 *
 * Kitty release (event type 3) and repeat (event type 2) are
 * distinguished as {@see KeyReleaseMsg} / {@see KeyRepeatMsg} so
 * handlers can pattern match via `instanceof`. Existing
 * `instanceof KeyMsg` checks keep working unchanged.
 */
final class KeyPressMsg extends KeyMsg
{
}
