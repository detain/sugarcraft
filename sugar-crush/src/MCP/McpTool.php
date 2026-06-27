<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

final readonly class McpTool
{
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public string $serverName,
    ) {}

    public static function fromArray(array $data, string $serverName): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            inputSchema: $data['inputSchema'] ?? [],
            serverName: $serverName,
        );
    }
}
