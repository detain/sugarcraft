<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dormant twin of {@see BlurMsg}: nothing on sugar-core's live input path
 * ever emits this class. Terminal focus-out (ESC [O while `CSI ?1004h` is
 * enabled) surfaces as {@see BlurMsg} from InputReader — handle that
 * instead; an `instanceof FocusLostMsg` branch is dead code in live
 * programs. The class remains because external replay and test code still
 * constructs it.
 */
final class FocusLostMsg implements Msg
{
}
