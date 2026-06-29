<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Read implements Tool
{
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    public function name(): string
    {
        return 'Read';
    }
    public function description(): string
    {
        return 'Read contents of a file';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
        ],
        'required' => ['file_path'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';

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

        set_error_handler(static function (int $errno, string $errstr) use ($path): bool {
            throw new \RuntimeException("Error reading file {$path}: {$errstr}");
        });
        try {
            clearstatcache(true, $path);
            $size = @filesize($path);
            if ($size !== false && $size > $this->maxBytes) {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    throw new \RuntimeException("Error reading file {$path}");
                }
                $content = fread($handle, $this->maxBytes);
                fclose($handle);
                if ($content === false) {
                    throw new \RuntimeException("Error reading file {$path}");
                }
                $content .= "\n... [truncated]";
            } else {
                $content = file_get_contents($path);
            }
            restore_error_handler();
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $content,
                isError: false,
            );
        } catch (\Throwable $e) {
            restore_error_handler();
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $e->getMessage(),
                isError: true,
            );
        }
    }
}
