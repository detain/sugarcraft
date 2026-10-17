<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

/**
 * Key-release event. Only emitted under the Kitty progressive-
 * keyboard protocol's `REPORT_EVENT_TYPES` flag; legacy mode
 * delivers nothing on key release.
 */
final class KeyReleaseMsg extends KeyMsg
{
}
