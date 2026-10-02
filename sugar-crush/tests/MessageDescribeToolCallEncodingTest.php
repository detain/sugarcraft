<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolCall;

/**
 * Audit 15b-27: `Message::describeToolCall()` encoded each argument with a
 * bare `json_encode()`, which returns `false` on one invalid UTF-8 byte — and
 * `false` concatenates as '', so `["command" => "caf\xe9"]` was described as
 * `bash(command: )` and the permission modal built from that description asked
 * the user to approve a command it did not show.
 */
final class MessageDescribeToolCallEncodingTest extends TestCase
{
    public function testAnInvalidByteIsSubstitutedNotBlanked(): void
    {
        $described = Message::describeToolCall(new ToolCall('bash', ['command' => "caf\xe9 && rm x"], 'c1'));

        $this->assertSame("bash(command: \"caf\u{FFFD} && rm x\")", $described);
        $this->assertTrue(mb_check_encoding($described, 'UTF-8'));
    }

    public function testANonStringArgumentWithAnInvalidByteIsNotBlankedEither(): void
    {
        $described = Message::describeToolCall(new ToolCall('edit', ['edits' => ['old' => "caf\xe9"]], 'c1'));

        $this->assertStringContainsString("caf\u{FFFD}", $described);
    }

    public function testAZeroValueIsShownNotDropped(): void
    {
        $this->assertSame('read(limit: "0")', Message::describeToolCall(new ToolCall('read', ['limit' => 0], 'c1')));
    }

    public function testUnicodeIsReadableButControlsAndBidiStayEscaped(): void
    {
        $described = Message::describeToolCall(
            new ToolCall('bash', ['command' => "cat 文件 \u{9b}2J \u{202E}hs.txt \u{200B}x"], 'c1'),
        );

        $this->assertStringContainsString('文件', $described);
        $this->assertStringContainsString('\u009b2J', $described);
        $this->assertStringContainsString('\u202ehs.txt', $described);
        $this->assertStringContainsString('\u200bx', $described);
        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]|\xE2\x80[\x8B\xAE]/', $described);
    }

    public function testThePermissionModalShowsTheCommand(): void
    {
        $method = new \ReflectionMethod(Renderer::class, 'wrapPermissionText');
        $shown = (string) $method->invoke(
            null,
            Message::describeToolCall(new ToolCall('bash', ['command' => "caf\xe9"], 'c1')),
            60,
        );

        $this->assertStringContainsString("command: \"caf\u{FFFD}\"", $shown);
    }
}
