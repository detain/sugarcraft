<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Key-press event. The default `KeyMsg` shape — emitted for every
 * legacy CSI/SS3/printable key. Under the Kitty progressive-keyboard
 * protocol the parser still emits `KeyPressMsg` for press events,
 * but additionally distinguishes {@see KeyReleaseMsg} (event type 3)
 * and {@see KeyRepeatMsg} (event type 2) so handlers can pattern
 * match via `instanceof`.
 *
 * Existing `instanceof KeyMsg` checks keep working unchanged.
 */
final class KeyPressMsg extends KeyMsg
{
}
