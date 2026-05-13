<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;

final class NetworkNode
{
    /** @var list<string> */
    public array $connections = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly NetworkShape $shape = NetworkShape::Circle,
        public readonly ?Color $color = null,
        public readonly ?string $icon = null,
    ) {}

    public function withConnection(string $nodeId): self
    {
        $clone = clone $this;
        $clone->connections[] = $nodeId;
        return $clone;
    }
}
