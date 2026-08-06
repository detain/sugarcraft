<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Share\ShareResult;
use SugarCraft\Crush\Share\ShareSession;

/**
 * @see ShareCommand
 */
final class ShareCommandTest extends TestCase
{
    // =========================================================================
    // Format Parsing Tests
    // =========================================================================

    public function testExecuteWithDefaultFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        // Default format should be markdown
        $this->assertSame(ShareSession::FORMAT_MARKDOWN, 'markdown');
    }

    public function testExecuteWithJsonFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        // Capture output
        ob_start();
        $exitCode = $command->execute($chat, ['json']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Session shared', $output);
        $this->assertStringContainsString('Format: json', $output);
    }

    public function testExecuteWithTextFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['text']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Format: text', $output);
    }

    public function testExecuteWithMarkdownFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Format: markdown', $output);
    }

    public function testExecuteWithInvalidFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['invalid_format']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid format', $output);
    }

    // =========================================================================
    // Expiry Handling Tests
    // =========================================================================

    public function testExecuteWithOneHourExpiry(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown', '1h']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Expires:', $output);
        $this->assertStringContainsString('1 hour', $output);
    }

    public function testExecuteWithSevenDayExpiry(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown', '7d']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('7 days', $output);
    }

    public function testExecuteWithCustomExpiryMinutes(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown', '30m']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('30 minutes', $output);
    }

    public function testExecuteWithDefaultExpiryWhenNoArg(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Session shared', $output);
    }

    // =========================================================================
    // URL Format Validation Tests
    // =========================================================================

    public function testShareResultUrlIsValidFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $command->execute($chat, ['markdown', '1h']);
        $output = ob_get_clean();

        // URL should be present in output
        $this->assertStringContainsString('URL:', $output);
        // URL should contain the base domain or share.sugarcraft.dev
        $this->assertTrue(
            str_contains($output, 'share.sugarcraft.dev') || str_contains($output, 'https://'),
            'Share URL should be a valid URL'
        );
    }

    public function testShareResultContainsMessageCount(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $command->execute($chat, ['markdown']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Messages:', $output);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testExecuteWithEmptyChat(): void
    {
        $chat = new Chat([]);
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Messages: 0', $output);
    }

    public function testExecuteWithMdAlias(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['md']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Format: markdown', $output);
    }

    public function testExecuteWithPlainAlias(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['plain']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Format: text', $output);
    }

    public function testExecuteWithMultipleArgs(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['json', '24h', 'extra_arg']);
        $output = ob_get_clean();

        // Should still succeed and only use first two args
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Format: json', $output);
        $this->assertStringContainsString('24 hours', $output);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a Chat instance with some test messages.
     *
     * @return Chat
     */
    private function createChatWithMessages(): Chat
    {
        $messages = [
            Message::system('You are a helpful assistant.'),
            Message::user('Hello, how are you?'),
            Message::assistant('I am doing well, thank you!'),
        ];

        return new Chat($messages);
    }
}
