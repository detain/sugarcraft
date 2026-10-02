<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;

/**
 * Audit 15a A22: both command backends json-encoded Chat's history rows with
 * no invalid-UTF-8 handling, and those rows are touched by neither the
 * tool-result scrub nor the loader scrub. One Latin-1 byte (`caf\xe9`) made
 * `json_encode` return false, so every later turn - the row is replayed each
 * time - answered `_[error: failed to encode history]_`. The history must now
 * reach the command's stdin as valid JSON, the bad byte as U+FFFD.
 */
final class CommandBackendInvalidUtf8HistoryTest extends TestCase
{
    private string $capture = '';

    protected function setUp(): void
    {
        $this->capture = sys_get_temp_dir() . '/a22_stdin_' . uniqid((string) getmypid(), true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->capture)) {
            unlink($this->capture);
        }
    }

    /** @return list<Message> */
    private static function latin1History(): array
    {
        return [Message::user("caf\xe9 menu"), Message::assistant('noted')];
    }

    /** A command that saves its stdin to $capture and replies `ok`. */
    private function capturingCommand(): array
    {
        return ['sh', '-c', 'cat > "$0"; echo ok', $this->capture];
    }

    private function assertCapturedHistoryIsValidUtf8(): void
    {
        $this->assertFileExists($this->capture);
        $stdin = (string) file_get_contents($this->capture);

        $this->assertTrue(mb_check_encoding($stdin, 'UTF-8'), 'stdin must be valid UTF-8');
        $decoded = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame("caf\u{FFFD} menu", $decoded[0]['content']);
        $this->assertSame('noted', $decoded[1]['content']);
    }

    public function testEncodeHistorySubstitutesInvalidBytesInsteadOfFailing(): void
    {
        $payload = CommandBackend::encodeHistory(self::latin1History());

        $this->assertNotNull($payload, 'one invalid byte must not make the whole history unencodable');
        $this->assertSame("caf\u{FFFD} menu", json_decode($payload, true)[0]['content']);
    }

    public function testEncodeHistoryKeepsValidTextByteIdentical(): void
    {
        $this->assertSame(
            '[{"role":"user","content":"café/über"}]',
            CommandBackend::encodeHistory([Message::user('café/über')]),
            'valid UTF-8 and slashes still go out unescaped',
        );
    }

    public function testCommandBackendSendsAnInvalidUtf8HistoryAsValidJson(): void
    {
        $reply = (new CommandBackend($this->capturingCommand()))->complete(self::latin1History());

        $this->assertSame('ok', $reply->content);
        $this->assertCapturedHistoryIsValidUtf8();
    }

    public function testStreamingCommandBackendSendsAnInvalidUtf8HistoryAsValidJson(): void
    {
        $reply = (new StreamingCommandBackend($this->capturingCommand()))->complete(self::latin1History(), null);

        $this->assertStringNotContainsString('failed to encode history', $reply->content);
        $this->assertSame('ok', trim($reply->content));
        $this->assertCapturedHistoryIsValidUtf8();
    }
}
