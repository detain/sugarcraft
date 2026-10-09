<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * The static facts read once per session: `mc info` (the BMC itself),
 * `fru print 0` (what the box is) and `lan print <channel>` (where the BMC
 * listens). Serials are kept for the detail view only — the panel never
 * shows them unless asked to.
 */
final class IpmiInfo
{
    /**
     * @param array<string, string> $serials chassis | board | product | asset => serial
     */
    public function __construct(
        public readonly string $product = '',
        public readonly string $manufacturer = '',
        public readonly string $board = '',
        public readonly string $bmcVendor = '',
        public readonly string $firmware = '',
        public readonly string $ipmiVersion = '',
        public readonly ?int $manufacturerId = null,
        public readonly string $bmcIp = '',
        public readonly string $ipSource = '',
        public readonly ?int $channel = null,
        public readonly array $serials = [],
    ) {
    }

    /** The name of the machine: the FRU product, else the board, else ''. */
    public function machine(): string
    {
        return $this->product !== '' ? $this->product : $this->board;
    }
}
