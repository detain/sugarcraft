<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Marks the start of a bracketed-paste region. Emitted by
 * {@see \SugarCraft\Core\InputReader} as soon as the `CSI 200 ~`
 * start marker is seen — *before* any of the pasted bytes are
 * collected.
 *
 * Use to flip a "paste in progress" flag (e.g. show a spinner, or
 * suppress per-keystroke validation) until the matching
 * {@see PasteEndMsg} arrives. The full pasted content is still
 * delivered as a {@see PasteMsg} when the paste terminates.
 */
final class PasteStartMsg implements Msg
{
}
