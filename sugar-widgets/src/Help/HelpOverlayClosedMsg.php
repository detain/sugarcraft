<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Help;

use SugarCraft\Core\Msg;

/**
 * Sent through a Cmd when a {@see HelpOverlay} closes itself on its close
 * binding, so the host can drop the overlay layer and restore focus.
 */
final class HelpOverlayClosedMsg implements Msg
{
}
