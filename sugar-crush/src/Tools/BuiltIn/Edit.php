<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool
{
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /** @var array<string, bool> */
    private array $sessionCache;

    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
    ) {
        $this->sessionCache = [];
    }

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

        if ($this->worktreeJail !== null) {
            $path = $this->worktreeJail->jailPath($path);
            if (!$this->worktreeJail->isAllowed($path)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
        } elseif ($this->root !== null) {
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

        // Inject nested instruction file if this edit touched a lib directory
        $injectedContent = '';
        if ($this->instructionLoader !== null) {
            $nested = $this->instructionLoader->loadForPath($path, $this->sessionCache);
            if ($nested !== null) {
                $injectedContent = $nested . "\n\n";
            }
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $injectedContent . "File updated: $path",
            isError: false,
        );
    }
}
