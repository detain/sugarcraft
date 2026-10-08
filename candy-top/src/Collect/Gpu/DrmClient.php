<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\Sentinel;

/**
 * One DRM client (one open of a /dev/dri node, `drm-client-id`) as one
 * fdinfo scan saw it, with its engine use already turned into rates.
 *
 * `engines` maps an engine class (render, video, copy, compute, gfx,
 * dec, enc, rcs, vcs, ...) to percent busy over the interval since the
 * previous scan, already divided by `drm-engine-capacity-*`. On the
 * first sighting of a client `rated` is false and `engines` empty — a rate
 * needs two reads.
 * `memory` is device-local resident bytes (vram / local regions),
 * UNMEASURED_INT when the driver lists no such region (an iGPU).
 */
final class DrmClient
{
    /**
     * @param array<string, float> $engines
     */
    public function __construct(
        public readonly int $pid,
        public readonly string $pdev,
        public readonly string $clientId,
        public readonly string $driver,
        public readonly array $engines,
        public readonly int $memory = Sentinel::UNMEASURED_INT,
        public readonly bool $rated = false,
    ) {
    }

    /** True once the client has rates (seen in two consecutive scans). */
    public function measured(): bool
    {
        return $this->rated;
    }
}
