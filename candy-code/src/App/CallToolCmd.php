<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

/**
 * Command to call a tool.
 */
final readonly class CallToolCmd implements Cmd
{
    public function __construct(public string $toolName, public array $args) {}
}
