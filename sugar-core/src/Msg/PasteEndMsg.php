<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Marks the end of a bracketed-paste region. Emitted *just before*
 * the {@see PasteMsg} that carries the collected content (the remainder,
 * for a paste big enough to have been surfaced in chunks), so
 * models that flipped state on {@see PasteStartMsg} can settle back
 * before they see the data. Also emitted when a paste whose end marker
 * was lost is closed after {@see \SugarCraft\Core\InputReader::PASTE_IDLE_TIMEOUT}
 * seconds of input silence.
 */
final class PasteEndMsg implements Msg
{
}
