<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Mouse\Zones\Zone;

/**
 * Emitted when a second click occurs inside the same zone within the
 * click interval (default 500ms).
 *
 * Extends bubblezone — upstream ships no multi-click machinery and no
 * such event.
 */
final class DoubleClickMsg implements Msg
{
    public function __construct(
        public readonly Zone $zone,
    ) {}
}
