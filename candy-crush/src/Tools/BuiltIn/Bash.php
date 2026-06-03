declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class Bash implements Tool
{
    public function name(): string => 'Bash';
    public function description(): string => 'Execute a bash command';
    public function inputSchema(): array => [
        'type' => 'object',
        'properties' => [
            'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
        ],
        'required' => ['command'],
    ];

    public function execute(array $args): ToolResult
    {
        $command = $args['command'] ?? '';
        $output = [];
        $exitCode = 0;
        // Mirrors charmbracelet/bubbletea.*.Exec.
        // Use bash -c to interpret shell syntax; escapeshellarg prevents command injection.
        exec("bash -c " . escapeshellarg($command), $output, $exitCode);
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $output),
            isError: $exitCode !== 0,
        );
    }
}
