<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

final readonly class MenuSelectedMsg
{
    public function __construct(
        public string $menu,
        public string $item,
    ) {}
}
