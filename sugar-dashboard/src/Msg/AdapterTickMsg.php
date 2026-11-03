<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Msg;

use SugarCraft\Core\Msg;

/**
 * Refresh tick scheduled by the legacy-module adapter's subscription.
 *
 * The adapter only advances its wrapped plugin on this message (and on
 * resizes); keeping it a dedicated type is what lets unrelated traffic
 * — keystrokes above all — pass through without triggering a blocking
 * plugin round-trip.
 */
final class AdapterTickMsg implements Msg
{
}
