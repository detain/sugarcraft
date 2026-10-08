<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ctr;

use SugarCraft\Top\Collect\ContainerCollector;
use SugarCraft\Top\Collect\ContainerSnapshot;

/**
 * The ctr box's SampledMsg payload: the containers plus the collector
 * carrying their cpu baselines to the next sample.
 */
final class CtrSample
{
    public function __construct(
        public readonly ContainerSnapshot $snapshot,
        public readonly ContainerCollector $collector,
    ) {
    }
}
