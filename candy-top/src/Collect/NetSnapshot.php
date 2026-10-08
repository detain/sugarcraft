<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Every interface seen in /proc/net/dev plus the one btop's policy picked
 * for the net panel.
 */
final class NetSnapshot
{
    /**
     * @param array<string, NetInterface> $interfaces keyed by name, /proc/net/dev order
     */
    public function __construct(
        public readonly array $interfaces,
        public readonly ?string $selectedName,
    ) {
    }

    public function selected(): ?NetInterface
    {
        return $this->selectedName === null ? null : ($this->interfaces[$this->selectedName] ?? null);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->interfaces);
    }
}
