<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    public function execute(array $args): ToolResult;
}
