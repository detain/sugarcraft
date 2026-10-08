<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Source\Source;

/**
 * One panel's data sample, produced off the update path by the Cmd its
 * {@see \SugarCraft\Top\Panel\Panel::collect()} returned, routed back to
 * that panel only.
 */
final class SampledMsg implements Msg
{
    public function __construct(
        public readonly string $box,
        public readonly object $snapshot,
        public readonly Source $next,
    ) {
    }
}
