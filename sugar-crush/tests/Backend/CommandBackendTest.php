<?php

declare(strict_types=1);

namespace CandyCore\Crush\Tests\Backend;

use CandyCore\Crush\Backend\CommandBackend;
use CandyCore\Crush\Message;
use PHPUnit\Framework\TestCase;

final class CommandBackendTest extends TestCase
{
    public function testCommandReceivesHistoryAsJsonOnStdin(): void
    {
        // `cat` echoes whatever it gets on stdin, which is the
        // JSON-encoded history. The reply will therefore be the
        // JSON itself — letting us assert the wire format.
        $backend = new CommandBackend(['cat']);
        $reply = $backend->complete([
            Message::user('hi'),
            Message::assistant('hello back'),
        ]);
        $decoded = json_decode($reply->content, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('user',       $decoded[0]['role']);
        $this->assertSame('hi',         $decoded[0]['content']);
        $this->assertSame('assistant',  $decoded[1]['role']);
        $this->assertSame('hello back', $decoded[1]['content']);
    }

    public function testNonZeroExitReportedAsErrorMessage(): void
    {
        $backend = new CommandBackend(['false']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('exited 1', $reply->content);
    }

    public function testMissingCommandReportedGracefully(): void
    {
        $backend = new CommandBackend(['/nonexistent/command/path']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('error', strtolower($reply->content),
            'a non-existent command should produce an "[error: ...]" message, not crash');
    }
}
