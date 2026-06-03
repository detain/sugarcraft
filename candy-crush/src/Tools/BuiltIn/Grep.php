declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class Grep implements Tool
{
    public function name(): string => 'Grep';
    public function description(): string => 'Search for a pattern in files';
    public function inputSchema(): array => [
        'type' => 'object',
        'properties' => [
            'pattern' => ['type' => 'string', 'description' => 'The regex pattern to search for'],
            'path' => ['type' => 'string', 'description' => 'Directory path to search in'],
            'include' => ['type' => 'string', 'description' => 'File pattern to match (e.g., *.php)'],
        ],
        'required' => ['pattern', 'path'],
    ];

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

        if (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        $output = [];
        $includeFlag = $include !== '*' ? "--include=$include" : '';
        $command = "grep -rn $includeFlag " . escapeshellarg($pattern) . " " . escapeshellarg($path);
        exec($command, $output, $exitCode);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $output),
            isError: $exitCode !== 0,
        );
    }
}
