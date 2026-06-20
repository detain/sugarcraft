<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class Edit implements Tool
{
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
        ],
        'required' => ['file_path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';
        $oldString = $args['old_string'] ?? '';
        $newString = $args['new_string'] ?? '';

        if ($oldString === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: old_string cannot be empty',
                isError: true,
            );
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

        // str_replace replaces ALL occurrences; consider str_replace(..., 1) if first-match only is needed.
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
