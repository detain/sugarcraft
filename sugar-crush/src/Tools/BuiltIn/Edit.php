<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool
{
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    public function name(): string
    {
        return 'Edit';
    }
    public function description(): string
    {
        return 'Edit a file by replacing text';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to file to edit'],
            'old_string' => ['type' => 'string', 'description' => 'The text to replace'],
            'new_string' => ['type' => 'string', 'description' => 'The replacement text'],
            'replace_all' => ['type' => 'bool', 'description' => 'Replace all occurrences'],
        ],
        'required' => ['file_path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';
        $oldString = $args['old_string'] ?? '';
        $newString = $args['new_string'] ?? '';
        $replaceAll = $args['replace_all'] ?? false;

        if ($oldString === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: old_string cannot be empty',
                isError: true,
            );
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        }

        if (!file_exists($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: file not found: $path",
                isError: true,
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error reading file: $path",
                isError: true,
            );
        }

        $count = substr_count($content, $oldString);
        if ($count > 1 && !$replaceAll) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: old_string is not unique ($count matches); include more context",
                isError: true,
            );
        }

        $newContent = str_replace($oldString, $newString, $content);
        $result = file_put_contents($path, $newContent);

        if ($result === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error writing file: $path",
                isError: true,
            );
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: "File updated: $path",
            isError: false,
        );
    }
}
