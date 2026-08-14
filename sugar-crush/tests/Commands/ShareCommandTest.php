<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Message;

/**
 * @see ShareCommand
 *
 * No real /share upload backend exists yet. ShareUploader::upload() always
 * throws, so ShareCommand must report that honestly and must never print a
 * fabricated success URL or hash.
 */
final class ShareCommandTest extends TestCase
{
    /** @var array<string, string|false> Original values of every variable a test overrode. */
    private array $envBackup = [];

    // =========================================================================
    // Honest failure path
    // =========================================================================

    public function testExecuteReportsNotImplemented(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not yet implemented', $output);
        $this->assertStringContainsString('No data was uploaded', $output);
    }

    public function testExecuteNeverFabricatesUrlOrHash(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $command->execute($chat, ['markdown', '1h']);
        $output = ob_get_clean();

        // The old behaviour claimed success and printed a signed-looking
        // URL even though nothing was ever uploaded. None of that may
        // appear anywhere in the output now.
        $this->assertStringNotContainsString('Session shared successfully', $output);
        $this->assertStringNotContainsString('URL:', $output);
        $this->assertStringNotContainsString('share.sugarcraft.dev', $output);
        $this->assertStringNotContainsString('https://', $output);
        $this->assertStringNotContainsString('Expires:', $output);
    }

    /**
     * @dataProvider formatProvider
     */
    public function testExecuteReportsNotImplementedForEveryFormat(string $formatArg): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, [$formatArg]);
        $output = ob_get_clean();

        // The fabrication bug was independent of format; the honest
        // failure path must be too.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not yet implemented', $output);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function formatProvider(): array
    {
        return [
            'markdown' => ['markdown'],
            'md alias' => ['md'],
            'json' => ['json'],
            'text' => ['text'],
            'plain alias' => ['plain'],
        ];
    }

    public function testExecuteWithEmptyChatAlsoReportsNotImplemented(): void
    {
        $chat = new Chat([]);
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not yet implemented', $output);
    }

    public function testExecuteWithCustomExpiryStillReportsNotImplemented(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['markdown', '30m']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not yet implemented', $output);
    }

    // =========================================================================
    // Format Parsing Tests (unaffected by the upload path)
    // =========================================================================

    public function testExecuteWithInvalidFormat(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['invalid_format']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid format', $output);
        // A format error is a different failure than "not implemented" —
        // must not be conflated with the upload failure message.
        $this->assertStringNotContainsString('not yet implemented', $output);
    }

    public function testExecuteWithMultipleArgsStillParsesButReportsNotImplemented(): void
    {
        $chat = $this->createChatWithMessages();
        $command = new ShareCommand();

        ob_start();
        $exitCode = $command->execute($chat, ['json', '24h', 'extra_arg']);
        $output = ob_get_clean();

        // Should still parse args correctly and only use the first two,
        // but the honest failure path still applies.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not yet implemented', $output);
    }

    // =========================================================================
    // Upload base URL: SUGARCRUSH_SHARE_UPLOAD_URL, with the pre-rename
    // SUGAR_CRUSH_SHARE_UPLOAD_URL honoured for one release
    // (crush_code.md Phase 4 item 4).
    // =========================================================================

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $original) {
            $original === false ? putenv($name) : putenv($name . '=' . $original);
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    public function testUploadBaseUrlDefaultsWhenNeitherVariableIsSet(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', null);
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', null);

        $this->assertSame('https://share.sugarcraft.dev', $this->uploadBaseUrl());
    }

    public function testCanonicalVariableSetsTheUploadBaseUrl(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', 'https://canonical.example');

        $this->assertSame('https://canonical.example', $this->uploadBaseUrl());
    }

    /**
     * The compat shim: an operator who has pointed /share at a private host
     * must not silently start addressing the public default on the release
     * that renames the variable.
     */
    public function testLegacyUnderscoredVariableIsStillHonoured(): void
    {
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://legacy.example', $this->uploadBaseUrl());
    }

    public function testCanonicalVariableWinsWhenBothAreSet(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', 'https://canonical.example');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://canonical.example', $this->uploadBaseUrl());
    }

    /**
     * An exported-but-empty canonical name is "unset", not "override with the
     * empty string" — a `/share` pointed at "" would be worse than either the
     * legacy value or the default.
     */
    public function testAnEmptyCanonicalVariableFallsThroughToTheLegacyName(): void
    {
        $this->setEnv('SUGARCRUSH_SHARE_UPLOAD_URL', '');
        $this->setEnv('SUGAR_CRUSH_SHARE_UPLOAD_URL', 'https://legacy.example');

        $this->assertSame('https://legacy.example', $this->uploadBaseUrl());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * The resolved upload base URL. Read directly because the only production
     * caller hands it to ShareUploader, which always throws while no upload
     * backend exists — so the value never reaches any observable output.
     */
    private function uploadBaseUrl(): string
    {
        $method = new \ReflectionMethod(ShareCommand::class, 'getUploadBaseUrl');

        return (string) $method->invoke(new ShareCommand());
    }

    /**
     * Set (or, with a null value, unset) an environment variable, recording
     * its original value for tearDown().
     */
    private function setEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }

        $value === null ? putenv($name) : putenv($name . '=' . $value);
    }

    /**
     * Create a Chat instance with some test messages.
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
