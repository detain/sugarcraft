<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 2.8 through the real settle path: what the MODEL receives when a
 * tool's output is over budget, and that it can get the rest back.
 *
 * Driven through {@see Runtime}'s private `executeToolCalls()` — the same seam
 * {@see \SugarCraft\Crush\Tests\RuntimeToolResultUtf8Test} uses — so the
 * PostToolUse chain, the session adoption, the window cap and the UI event all
 * run exactly as in a turn.
 */
final class SpillToFileTest extends TestCase
{
    private string $sandbox;
    private string $root;

    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(sys_get_temp_dir()) . '/sc_rt_spill_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->root = $this->sandbox . '/workspace';
        self::assertTrue(mkdir($this->root, 0o755, true));
        ToolOutputSpill::useDirectoryForTesting($this->sandbox . '/store');
    }

    protected function tearDown(): void
    {
        ToolOutputSpill::useDirectoryForTesting(null);
        self::removeTree($this->sandbox);
    }

    public function testAnOversizedGrepResultIsSavedPerSessionAndReadBack(): void
    {
        $lines = [];
        for ($i = 1; $i <= 20000; $i++) {
            $lines[] = "build step $i ok" . ($i === 20000 ? ' FINAL VERDICT: 3 tests failed' : '');
        }
        file_put_contents($this->root . '/build.log', implode("\n", $lines) . "\n");

        $call = new ToolCall('call_grep', 'Grep', ['pattern' => 'build step', 'path' => '.', 'description' => 'x']);
        $tools = [new Grep($this->root), new Read($this->root)];

        [$messages, $finished] = $this->dispatch([$call], $tools, 200_000, 'sess_spill_grep');

        $content = $messages[0]->content();
        self::assertLessThanOrEqual(65_536, strlen($content));
        self::assertStringContainsString('build.log:1:build step 1 ok', $content);
        self::assertStringContainsString('FINAL VERDICT: 3 tests failed', $content, 'the tail of the output is part of the preview');
        self::assertStringContainsString('... [middle omitted; it is in the saved file named below]', $content);
        self::assertStringContainsString('This result is PARTIAL', $content);
        self::assertSame(1, preg_match('/\.\.\. \[saved: the (\d+) bytes this tool captured are in (\S+) — Read it/', $content, $m));
        self::assertSame($this->sandbox . '/store/s-sess_spill_grep', \dirname($m[2]), 'the pointer the model reads is not session-scoped');
        self::assertStringContainsString("build.log:12345:build step 12345 ok\n", (string) file_get_contents($m[2]), 'the middle the preview left out is in the file');
        self::assertSame($content, $finished[0]->result->content(), 'the UI renders what the model reads');

        // And the model's own next call gets it back.
        $read = new ToolCall('call_read', 'Read', ['file_path' => $m[2], 'offset' => 12345, 'limit' => 1]);
        [$again] = $this->dispatch([$read], $tools, 200_000, 'sess_spill_grep');
        self::assertStringContainsString('build step 12345 ok', $again[0]->content());
    }

    public function testAToolWithNoCapIsBoundedToAShareOfASmallWindow(): void
    {
        $output = '';
        for ($i = 1; $i <= 3000; $i++) {
            $output .= "result row $i\n";
        }
        $call = new ToolCall('call_wide', 'wide_tool', []);

        [$messages] = $this->dispatch([$call], [$this->fixedTool('wide_tool', $output)], 8192, 'sess_small');

        $content = $messages[0]->content();
        self::assertLessThanOrEqual(intdiv(8192 * ToolOutputSpill::WINDOW_SHARE_PERCENT, 100) * 3, strlen($content));
        self::assertStringStartsWith('result row 1', $content);
        self::assertStringContainsString('result row 3000', $content);
        self::assertSame(1, preg_match('/are in (\S+) — Read it/', $content, $m));
        self::assertSame($output, file_get_contents($m[1]));
    }

    public function testTheSameResultUnderALargeWindowIsUntouched(): void
    {
        $output = str_repeat("result row\n", 3000);
        $call = new ToolCall('call_wide', 'wide_tool', []);

        [$messages] = $this->dispatch([$call], [$this->fixedTool('wide_tool', $output)], 200_000, 'sess_big');

        self::assertSame($output, $messages[0]->content());
    }

    public function testInvisibleUnicodeTagCharactersAreStrippedAndAnnounced(): void
    {
        $smuggled = "visible text\u{E0049}\u{E0047}\u{E004E}\u{E004F}\u{E0052}\u{E0045} done";
        $call = new ToolCall('call_tags', 'tag_tool', []);

        [$messages, $finished] = $this->dispatch([$call], [$this->fixedTool('tag_tool', $smuggled)], 200_000, 'sess_tags');

        $content = $messages[0]->content();
        self::assertStringStartsWith('visible text done', $content);
        self::assertStringNotContainsString("\u{E0049}", $content);
        self::assertStringContainsString('[unicode: 6 invisible Unicode tag character(s)', $content);
        self::assertSame($content, $finished[0]->result->content());
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool>     $tools
     * @return array{0: list<\SugarCraft\Crush\Messages\ToolResultMessage>, 1: list<ToolFinished>}
     */
    private function dispatch(array $calls, array $tools, int $window, string $sessionId): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('spill-stub');
        $provider->method('contextWindow')->willReturn($window);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools)->withSessionId($sessionId);

        $finished = [];
        $onEvent = static function (object $event) use (&$finished): void {
            if ($event instanceof ToolFinished) {
                $finished[] = $event;
            }
        };

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');
        $messages = array_values(iterator_to_array($method->invoke($runtime, $calls, $app, $onEvent), false));

        return [$messages, $finished];
    }

    private function fixedTool(string $name, string $output): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn('fixed output');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturn(new ToolResult(toolCallId: $name, content: $output));

        return $tool;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
