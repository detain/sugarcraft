<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Glob implements Tool
{
    public function __construct(
        private ?string $root = null,
    ) {}

    public function name(): string
    {
        return 'Glob';
    }
    public function description(): string
    {
        return 'Find files matching a glob pattern';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'pattern' => ['type' => 'string', 'description' => 'The glob pattern to match (e.g., **/*.php)'],
            'path' => ['type' => 'string', 'description' => 'Base directory path'],
        ],
        'required' => ['pattern', 'path'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';

        if ($pattern === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern cannot be empty',
                isError: true,
            );
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolveDir($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        $fullPattern = rtrim($path, '/') . '/' . $pattern;
        $files = glob($fullPattern);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $files ?: []),
            isError: false,
        );
    }
}
