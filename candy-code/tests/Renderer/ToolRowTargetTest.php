<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tui\ToolRowTarget;

/**
 * #4: a tool row says what ran. With no model `description` the row shows the
 * literal target (`$ ls -la` for a shell); a Bash row carries its exit code and
 * wall time; the model's own description still wins when it sent one.
 */
final class ToolRowTargetTest extends TestCase
{
    public function testTheTargetIsTheCommandForBashAndThePathOrPatternForFileTools(): void
    {
        self::assertSame('$ ls -la', ToolRowTarget::of('Bash', ['command' => 'ls -la']));
        self::assertSame('src/a.php', ToolRowTarget::of('Read', ['file_path' => 'src/a.php']));
        self::assertSame('src/a.php', ToolRowTarget::of('Edit', ['file_path' => 'src/a.php']));
        self::assertSame('TODO', ToolRowTarget::of('Grep', ['pattern' => 'TODO']));
        self::assertSame('*.php', ToolRowTarget::of('Glob', ['pattern' => '*.php']));
    }

    public function testAToolItDoesNotKnowOrAnEmptyArgumentHasNoTarget(): void
    {
        self::assertNull(ToolRowTarget::of('Todo', ['todos' => []]));
        self::assertNull(ToolRowTarget::of('Bash', ['command' => '   ']));
        self::assertNull(ToolRowTarget::of('Bash', []));
    }

    public function testABashRowWithNoDescriptionShowsTheLiteralCommand(): void
    {
        $plain = $this->plain($this->frame(self::bash(arguments: ['command' => 'ls -la'], exitCode: 0)));

        self::assertStringContainsString('$ ls -la', $plain);
        self::assertStringNotContainsString('bash(command:', $plain, 'the mechanical dump is only the fallback');
    }

    public function testAModelDescriptionStillWinsOverTheLiteralCommand(): void
    {
        $result = self::bash(
            arguments: ['command' => 'ls -la', 'description' => 'List files here'],
            exitCode: 0,
        )->withDescription('List files here');

        $plain = $this->plain($this->frame(Message::assistant('')->withToolResults([$result])));

        self::assertStringContainsString('List files here', $plain);
        self::assertStringNotContainsString('$ ls -la', $plain);
    }

    public function testABashRowShowsItsExitCodeAndDuration(): void
    {
        $plain = $this->plain($this->frame(self::bash(arguments: ['command' => 'false'], exitCode: 3, durationMs: 1234)));

        self::assertStringContainsString(Lang::t('tui.tool.exit_code', ['code' => 3]), $plain);
        self::assertStringContainsString('1.2s', $plain);
    }

    public function testASubSecondDurationIsShownInMilliseconds(): void
    {
        $plain = $this->plain($this->frame(self::bash(arguments: ['command' => 'true'], exitCode: 0, durationMs: 42)));

        self::assertStringContainsString('42ms', $plain);
    }

    public function testANonShellRowCarriesNoExitSegment(): void
    {
        $read = new ToolResult('Read', 'contents', id: 'c_read', arguments: ['file_path' => 'a.txt'], durationMs: 5);
        $plain = $this->plain($this->frame(Message::assistant('')->withToolResults([$read])));

        self::assertStringNotContainsString(Lang::t('tui.tool.exit_code', ['code' => 0]), $plain);
        self::assertStringNotContainsString('5ms', $plain);
    }

    public function testTheExitCodeCrossesTheEngineAdapterBothWays(): void
    {
        $original = self::bash(arguments: ['command' => 'exit 7'], exitCode: 7);

        self::assertSame(7, $original->toEngineResult()->exitCode());
        self::assertSame(7, ToolResult::fromEngineResult($original->toEngineResult(), 'Bash')->exitCode);
    }

    private static function bash(array $arguments, ?int $exitCode, ?int $durationMs = null): ToolResult
    {
        return new ToolResult(
            'Bash',
            'output',
            id: 'c_bash',
            durationMs: $durationMs,
            arguments: $arguments,
            exitCode: $exitCode,
        );
    }

    private function frame(Message|ToolResult $message): string
    {
        $history = $message instanceof ToolResult
            ? [Message::assistant('')->withToolResults([$message])]
            : [$message];

        return Renderer::render(new Chat(history: $history, rows: 40, cols: 100));
    }

    private function plain(string $frame): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $frame);
    }
}
