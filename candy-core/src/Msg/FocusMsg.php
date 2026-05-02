<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/** Emitted when the terminal regains focus (CSI 1004 must be enabled). */
final class FocusMsg implements Msg
{
}
