<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

final class GpuSnapshot
{
    /**
     * @param list<GpuDevice> $devices empty when no GPU could be queried
     */
    public function __construct(
        public readonly array $devices,
    ) {
    }

    public function available(): bool
    {
        return $this->devices !== [];
    }
}
