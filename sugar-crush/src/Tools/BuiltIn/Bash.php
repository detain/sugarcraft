<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class Bash implements Tool
{
    public function __construct(
        private ?string $root = null,
    ) {}

    public function name(): string
    {
        return 'Bash';
    }
    public function description(): string
    {
        return 'Execute a bash command';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
        ],
        'required' => ['command'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $command = $args['command'] ?? '';
        $output = [];
        $exitCode = 0;
        $cwd = $this->root ?? null;
        // Mirrors charmbracelet/bubbletea.*.Exec.
        // Use bash -c to interpret shell syntax; escapeshellarg prevents command injection.
        if ($cwd !== null) {
            $cmd = "bash -c " . escapeshellarg("cd " . escapeshellarg($cwd) . " && " . $command);
        } else {
            $cmd = "bash -c " . escapeshellarg($command);
        }
        exec($cmd, $output, $exitCode);
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $output),
            isError: $exitCode !== 0,
        );
    }
}
