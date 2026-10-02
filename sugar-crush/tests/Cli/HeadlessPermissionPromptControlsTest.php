<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\HeadlessPermissionPrompt;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit R17 (15e lead 6): the headless `[y/N]` prompt encodes the call's
 * arguments with `JSON_UNESCAPED_UNICODE`, which escapes C0 but writes the
 * C1 controls out raw — `\xC2\x9B` is U+009B, which a UTF-8 terminal runs as
 * CSI exactly like `ESC [`. The same flag lets a U+202E override reverse the
 * rest of the line (15b-28). Both prompt surfaces (the tty question and the
 * no-tty refusal) now show them via `Sanitize::visibleControls()`.
 */
final class HeadlessPermissionPromptControlsTest extends TestCase
{
    /** @var list<resource> */
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        $this->streams = [];
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function surfaces(): iterable
    {
        yield 'tty question' => [true];
        yield 'no-tty refusal' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function testAUtf8C1ControlInAnArgumentIsShownNotEmitted(bool $interactive): void
    {
        $text = $this->ask($interactive, ['command' => "safe\u{9b}2Jrm -rf / \u{9d}0;pwn\u{9c}"], 'Allow?');

        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]/', $text, bin2hex($text));
        $this->assertStringContainsString('safe<U+009B>2Jrm -rf / <U+009D>0;pwn<U+009C>', $text);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function testABidiOverrideInAnArgumentIsShownNotObeyed(bool $interactive): void
    {
        $text = $this->ask($interactive, ['command' => "cat \u{202E}hs.txt"], 'Allow?');

        $this->assertStringNotContainsString("\u{202E}", $text);
        $this->assertStringContainsString('cat <U+202E>hs.txt', $text);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function testControlsInTheAskMessageAreShownNotEmitted(bool $interactive): void
    {
        $text = $this->ask($interactive, [], "Allow? \x1b[2J\u{9b}6n");

        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]/', $text);
        $this->assertStringContainsString('why:  Allow? ^[[2J<U+009B>6n', $text);
    }

    public function testReadableUnicodeIsStillReadable(): void
    {
        $text = $this->ask(true, ['command' => 'cat 文件/é.txt'], 'Allow?');

        $this->assertStringContainsString('{"command":"cat 文件/é.txt"}', $text);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function ask(bool $interactive, array $args, string $why): string
    {
        $in = $this->memoryStream($interactive ? "n\n" : '');
        $err = $this->memoryStream('');
        $prompt = (new HeadlessPermissionPrompt(PermissionMode::Default, $in, $err, $interactive))->approver();

        $this->assertFalse($prompt(new ToolCall('c1', 'Bash', $args), HookResult::ask($why)));

        return (string) \stream_get_contents($err, -1, 0);
    }

    /** @return resource */
    private function memoryStream(string $contents)
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        if ($contents !== '') {
            \fwrite($stream, $contents);
            \rewind($stream);
        }
        $this->streams[] = $stream;

        return $stream;
    }
}
