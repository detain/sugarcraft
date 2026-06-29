<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Grep implements Tool
{
    public function __construct(
        private ?string $root = null,
    ) {}

    public function name(): string
    {
        return 'Grep';
    }
    public function description(): string
    {
        return 'Search for a pattern in files';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'pattern' => ['type' => 'string', 'description' => 'The regex pattern to search for'],
            'path' => ['type' => 'string', 'description' => 'Directory path to search in'],
            'include' => ['type' => 'string', 'description' => 'File pattern to match (e.g., *.php)'],
        ],
        'required' => ['pattern', 'path'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';
        $include = $args['include'] ?? '*';

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

        $output = [];
        $cmd = 'grep -rn';
        if ($include !== '*') {
            $cmd .= ' --include=' . escapeshellarg($include);
        }
        $cmd .= ' ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path);
        exec($cmd, $output, $exitCode);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $output),
            isError: $exitCode !== 0,
        );
    }
}
