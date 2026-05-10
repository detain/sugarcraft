<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class StreamingCommandBackendTest extends TestCase
{
    public function testStreamingBackendCallsOnTokenForEachLine(): void
    {
        // Create a script that outputs tokens line by line
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'Hello'\necho ' '\necho 'World!'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $tokens = [];
            $onToken = function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertSame(['Hello', ' ', 'World!'], $tokens);
            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('Hello World!', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendWithoutCallback(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'No callback test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('No callback test', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnNonZeroExit(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'partial output'\nexit 1");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertStringContainsString('error', $result->content);
            $this->assertStringContainsString('1', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnMissingCommand(): void
    {
        $backend = new StreamingCommandBackend(['/nonexistent/command/path']);
        $result = $backend->complete([], null);

        $this->assertSame(Role::Assistant, $result->role);
        $this->assertStringContainsString('error', $result->content);
    }

    public function testStreamingBackendPassesHistoryToStdin(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        // Script reads stdin and includes it in output
        file_put_contents($script, "#!/bin/bash\ncat > /dev/null && echo 'received history'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $history = [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
            ];
            $result = $backend->complete($history, null);

            $this->assertSame('received history', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendHandlesMultipleRapidTokens(): void
    {
        // Generate tokens quickly to test buffering - use fewer tokens for stability
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = "token{$i}";
        }
        // Build script: each echo on its own line, properly terminated
        $lines = ["#!/bin/bash"];
        foreach ($tokens as $token) {
            $lines[] = "echo {$token}";
        }
        $lines[] = "true";
        $scriptContent = implode("\n", $lines);

        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, $scriptContent);
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $receivedTokens = [];
            $onToken = function (string $token) use (&$receivedTokens): void {
                $receivedTokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertCount(50, $receivedTokens);
            $this->assertSame(implode('', $tokens), $result->content);
        } finally {
            unlink($script);
        }
    }
}
