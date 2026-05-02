<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/** Emitted when the terminal loses focus (CSI 1004 must be enabled). */
final class BlurMsg implements Msg
{
}
