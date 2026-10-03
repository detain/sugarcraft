<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents\Live;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\ToolSummary;

/**
 * The one-line phrase a live agent line shows for a tool call (Appendix P
 * §4.1): the model's description first, else the tool's primary argument.
 */
final class ToolSummaryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function calls(): array
    {
        return [
            'description wins' => ['Grep', ['description' => 'Find the login route', 'pattern' => 'Login'], 'Find the login route'],
            'Read path' => ['Read', ['file_path' => 'src/Session/Store.php'], 'src/Session/Store.php'],
            'Edit path' => ['Edit', ['file_path' => 'a.php', 'old_string' => 'x', 'new_string' => 'y'], 'a.php'],
            'Write path' => ['Write', ['file_path' => 'b.php', 'content' => '<?php'], 'b.php'],
            'Grep pattern and path' => ['Grep', ['pattern' => 'LoginController', 'path' => 'routes/'], '"LoginController" routes/'],
            'Grep pattern alone' => ['Grep', ['pattern' => 'TODO'], '"TODO"'],
            'Glob pattern' => ['Glob', ['pattern' => 'src/**/*.php', 'path' => '.'], 'src/**/*.php'],
            'Bash first line' => ['Bash', ['command' => "\ncomposer install\nvendor/bin/phpunit"], 'composer install'],
            'WebFetch host and path' => ['WebFetch', ['url' => 'https://example.com/docs/page?q=1'], 'example.com/docs/page'],
            'WebSearch query' => ['WebSearch', ['query' => 'PHP 8.3 release'], '"PHP 8.3 release"'],
            'MCP tool' => ['mcp__github__create_issue', ['title' => 'x'], 'github/create_issue'],
            'unknown tool' => ['Lsp', ['op' => 'hover'], ''],
            'blank description falls through' => ['Read', ['description' => "  \n ", 'file_path' => 'c.php'], 'c.php'],
            'whitespace flattened' => ['Bash', ['description' => "run\tthe\n\ntests"], 'run the tests'],
            'non-string args ignored' => ['Read', ['file_path' => ['a']], ''],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('calls')]
    public function testSummaries(string $tool, array $args, string $expected): void
    {
        $this->assertSame($expected, ToolSummary::of($tool, $args));
    }

    public function testALongSummaryIsClippedWithinTheCeilingOnACodepointBoundary(): void
    {
        $summary = ToolSummary::of('Bash', ['command' => str_repeat('é', 200)]);

        $this->assertLessThanOrEqual(ToolSummary::MAX_BYTES, strlen($summary));
        $this->assertStringEndsWith('…', $summary);
        $this->assertSame(1, preg_match('//u', $summary));
    }
}
