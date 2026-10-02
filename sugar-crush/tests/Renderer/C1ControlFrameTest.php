<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Audit 15b-08: no UTF-8-encoded C1 control may reach the frame from any row.
 *
 * `\xC2\x9B` is well-formed UTF-8 for U+009B, which xterm decodes and runs as
 * CSI — so model or tool text carrying `U+009B 2 J` clears the user's screen
 * without one ESC byte. Every row kind that paints model-, tool- or
 * user-authored text is driven here with a CSI and a full OSC
 * (`U+009D 0;x U+009C`) payload; the frame must hold no `\xC2[\x80-\x9F]`
 * pair at all (the chrome itself never emits one).
 */
final class C1ControlFrameTest extends TestCase
{
    private const CSI = "\u{9b}2J";
    private const OSC = "\u{9d}0;x\u{9c}";
    private const C1_PAIR = '/\xC2[\x80-\x9F]/';

    /**
     * @return array<string, array{0: \Closure(string): Chat}>
     */
    public static function rowKinds(): array
    {
        $chat = static fn (array $history): Chat => new Chat(history: $history);

        return [
            'user' => [static fn (string $p): Chat => $chat([Message::user("user {$p} text", 0)])],
            'system' => [static fn (string $p): Chat => $chat([Message::system("system {$p} text", 0)])],
            'assistant markdown' => [static fn (string $p): Chat => $chat([
                Message::assistant("# Head {$p}\n\npara {$p}\n\n`code {$p}`\n\n```\nfence {$p}\n```\n\n| a | b |\n|---|---|\n| {$p} | c |", 0),
            ])],
            'assistant reasoning (expanded)' => [static function (string $p) use ($chat): Chat {
                $reasoning = "thinking {$p}\nmore";
                return $chat([Message::assistant('done', 0, reasoning: $reasoning)])
                    ->toggleToolOutput(Renderer::thoughtKey($reasoning));
            }],
            'running tool placeholder' => [static fn (string $p): Chat => $chat([
                Message::toolRunning(new ToolCall('bash', ['command' => "ls {$p}", 'description' => "list {$p} files"], 'call_1')),
            ])],
            'tool name' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::ok("tool{$p}", 'ok', 'call_1')]),
            ])],
            'tool description' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([
                    ToolResult::ok('bash', 'ok', 'call_1')->withDescription("describe {$p} it"),
                ]),
            ])],
            // A collapsed SUCCESS body is hidden behind "N lines hidden"; an
            // error body is always painted (clipped), so it is the collapsed case.
            'collapsed tool error' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::error('bash', "fail {$p} here", 'call_1')]),
            ])],
            'expanded tool result' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::ok('grep', "alpha {$p}\nbeta {$p}", 'call_1')]),
            ])->toggleToolOutput('call_1')],
            'expanded tool error' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::error('bash', "boom {$p}\nagain {$p}", 'call_1')]),
            ])->toggleToolOutput('call_1')],
            'expanded shell args' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([
                    ToolResult::ok('bash', 'ok', 'call_1')->withArguments(['command' => "echo {$p}", 'timeout' => 30]),
                ]),
            ])->toggleToolOutput('call_1')],
            'expanded non-shell args' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([
                    ToolResult::ok('write', 'ok', 'call_1')->withArguments(['path' => "src/{$p}.php", 'content' => "body {$p}"]),
                ]),
            ])->toggleToolOutput('call_1')],
            'tool diff' => [static fn (string $p): Chat => $chat([
                Message::assistant('')->withToolResults([
                    new ToolResult(
                        name: 'edit',
                        result: 'edited',
                        id: 'call_1',
                        diff: "--- a/f.txt\n+++ b/f.txt\n@@ -1 +1 @@\n-old {$p}\n+new {$p}\n",
                    ),
                ]),
            ])->toggleToolOutput('call_1')],
        ];
    }

    /**
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('rowKinds')]
    public function testCsiCodepointNeverReachesTheFrame(\Closure $build): void
    {
        $this->assertCleanFrame(Renderer::render($build(self::CSI)));
    }

    /**
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('rowKinds')]
    public function testOscCodepointNeverReachesTheFrame(\Closure $build): void
    {
        $this->assertCleanFrame(Renderer::render($build(self::OSC)));
    }

    /**
     * Guards the guard: every fixture must actually paint its payload's inert
     * tail, or a row that is simply not rendered would pass vacuously.
     *
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('rowKinds')]
    public function testThePayloadTailIsPaintedSoTheRowIsReallyExercised(\Closure $build): void
    {
        $this->assertStringContainsString('2J', Renderer::render($build(self::CSI)));
        $this->assertStringContainsString('0;x', Renderer::render($build(self::OSC)));
    }

    private function assertCleanFrame(string $frame): void
    {
        $this->assertDoesNotMatchRegularExpression(
            self::C1_PAIR,
            $frame,
            'UTF-8 C1 control reached the frame near: '
                . (preg_match(self::C1_PAIR, $frame, $m, PREG_OFFSET_CAPTURE) === 1
                    ? bin2hex(substr($frame, max(0, $m[0][1] - 16), 40))
                    : ''),
        );
    }
}
