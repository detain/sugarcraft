<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Glob implements Tool
{
    public function __construct(
        private ?string $root = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
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
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Find every provider test file", not "globs *.php").',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
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

        // Prepend nested instruction file content for each matched file
        $output = '';
        foreach ($files ?: [] as $file) {
            $nestedContent = $this->instructionLoader?->loadForPath($file);
            if ($nestedContent !== null) {
                $output .= $nestedContent . "\n";
            }
            $output .= $file . "\n";
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $output,
            isError: false,
        );
    }
}
