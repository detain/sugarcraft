<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class WebFetch implements Tool
{
    public function name(): string
    {
        return 'WebFetch';
    }
    public function description(): string
    {
        return 'Fetch content from a URL';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
        ],
        'required' => ['url'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $url = $args['url'] ?? '';

        if ($url === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: url cannot be empty',
                isError: true,
            );
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: url must start with http:// or https://',
                isError: true,
            );
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error fetching URL: $url",
                isError: true,
            );
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $content,
            isError: false,
        );
    }
}
