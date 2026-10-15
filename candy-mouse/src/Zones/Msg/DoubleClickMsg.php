<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Zone\Zone;

/**
 * Emitted when a second click occurs inside the same zone within the
 * click interval (default 500ms).
 *
 * Mirrors bubblezone's zone double-click event.
 */
final class DoubleClickMsg implements Msg
{
    public function __construct(
        public readonly Zone $zone,
    ) {}
}
