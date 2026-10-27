<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

final readonly class MenuSelectedMsg
{
    /**
     * @param ?string $action the chosen node's kit id — `cmd:<name>` or
     *                        `pane:<value>` — so dispatch names the command
     *                        directly instead of reverse-lookup by label.
     *                        Null keeps the label-lookup path alive for the
     *                        tests and message senders that predate the kit.
     */
    public function __construct(
        public string $menu,
        public string $item,
        public ?string $action = null,
    ) {}
}
