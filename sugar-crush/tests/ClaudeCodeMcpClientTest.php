<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;
use PHPUnit\Framework\TestCase;

final class ClaudeCodeMcpClientTest extends TestCase
{
    /**
     * The rename itself (crush_code.md Phase 2 item 1), asserted rather than
     * grepped.
     *
     * The plan called the two `McpClient` classes duplicates. They were not:
     * `SugarCraft\Crush\McpClient` (this class, stdio JSON-RPC to the
     * `claude` binary) and {@see \SugarCraft\Crush\MCP\McpClient} (Guzzle
     * HTTP over configured servers) sit in different namespaces, so nothing was
     * ever broken and no PSR-4 rule was violated. What DID collide was the two
     * TEST files' basename, which is why this file is now named after the class
     * it drives. Both halves are pinned: the new FQN resolves, the old one does
     * NOT — including through the autoloader, since a `class_alias` or a
     * re-added `src/McpClient.php` would each make the old name resolve again
     * and each would put the collision straight back.
     */
    public function testTheRenamedClassResolvesAtItsNewFqnAndTheOldNameIsGone(): void
    {
        $this->assertTrue(class_exists(ClaudeCodeMcpClient::class, true));
        $this->assertSame('SugarCraft\\Crush\\ClaudeCodeMcpClient', ClaudeCodeMcpClient::class);

        $this->assertFalse(
            class_exists('SugarCraft\\Crush\\McpClient', true),
            'the pre-rename FQN must not resolve — not via an alias, and not via a re-added src/McpClient.php',
        );

        // The sibling is untouched and is still a DIFFERENT class, which is the
        // fact that made "duplicates" the wrong word for these two.
        $this->assertTrue(class_exists(\SugarCraft\Crush\MCP\McpClient::class, true));
        $this->assertNotSame(
            (new \ReflectionClass(ClaudeCodeMcpClient::class))->getShortName(),
            (new \ReflectionClass(\SugarCraft\Crush\MCP\McpClient::class))->getShortName(),
            'the whole point of the rename is that the two short names differ',
        );
    }

    /**
     * The dormancy the class docblock claims, measured here instead of
     * asserted in prose: no file under `src/`, `bin/` or `examples/` REFERENCES
     * this class in code. It spawns a process with no path containment and no
     * permission gate, so "nothing reaches it" is the property that makes that
     * survivable — and the day someone wires it up (Phase 2 item 2) this test
     * reds and points at the two gates that have to come with the wiring.
     *
     * CODE, NOT PROSE, and the distinction is load-bearing. This test used to
     * be a `str_contains()` over the raw bytes of every file, which matched a
     * `{@see}` in a doc comment exactly as readily as a `new` — so the day
     * anyone cross-referenced this class from a neighbouring docblock the test
     * would have gone red claiming the class was "reached from" a file that
     * merely mentioned it, which is the mis-attribution this whole rename exists
     * to stop. Comments are now separated with {@see \token_get_all()}: a
     * mention inside a `T_COMMENT`/`T_DOC_COMMENT` cannot reach anything, so it
     * is collected and REPORTED rather than failed on. A name inside a string
     * literal still counts as code — `class_exists('…')` and a container binding
     * are both real reachability.
     */
    public function testNothingInSrcBinOrExamplesReachesThisDormantSeam(): void
    {
        $lib = \dirname(__DIR__);
        $callers = [];
        $mentionedInProse = [];

        foreach (['src', 'bin', 'examples'] as $dir) {
            $path = $lib . '/' . $dir;
            if (!\is_dir($path)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            ) as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = \substr((string) $file->getPathname(), \strlen($lib) + 1);
                if ($relative === 'src/ClaudeCodeMcpClient.php') {
                    continue; // the declaration is not a call site
                }
                $source = (string) \file_get_contents((string) $file->getPathname());
                if (!\str_contains($source, 'ClaudeCodeMcpClient')) {
                    continue;
                }
                if (self::namesTheClassOutsideAComment($source)) {
                    $callers[] = $relative;
                } else {
                    $mentionedInProse[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $callers,
            'ClaudeCodeMcpClient is named in CODE by: ' . \implode(', ', $callers)
                . ' — it spawns a process with no ContainedPath anchor and no PermissionGate, so wiring it'
                . ' up has to bring both gates along (crush_code.md Phase 2 item 2).'
                . ($mentionedInProse === []
                    ? ''
                    : ' (Doc-comment mentions, which reach nothing and are not the failure: '
                        . \implode(', ', $mentionedInProse) . '.)'),
        );
    }

    /**
     * True when the class name appears anywhere in $source that is not a
     * comment.
     *
     * A file that does not parse as PHP yields a single `T_INLINE_HTML` token
     * carrying the whole text; that counts as prose, which is the right answer
     * for a `SKILL.md` or a YAML workflow under the scanned directories.
     */
    private static function namesTheClassOutsideAComment(string $source): bool
    {
        foreach (\token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue; // single-character token; cannot contain a class name
            }
            [$id, $text] = $token;
            if ($id === \T_COMMENT || $id === \T_DOC_COMMENT || $id === \T_INLINE_HTML) {
                continue;
            }
            if (\str_contains($text, 'ClaudeCodeMcpClient')) {
                return true;
            }
        }

        return false;
    }

    public function testConstruction(): void
    {
        $client = new ClaudeCodeMcpClient('claude', ['--mcp'], ['capabilities' => ['tools' => true]]);

        $this->assertSame('claude', $client->command);
        $this->assertSame(['--mcp'], $client->args);
        $this->assertSame(['capabilities' => ['tools' => true]], $client->initialOptions);
        $this->assertFalse($client->isConnected());
    }

    public function testForClaudeCodeDefaults(): void
    {
        $client = ClaudeCodeMcpClient::forClaudeCode();

        $this->assertSame('claude', $client->command);
        $this->assertSame(['--mcp'], $client->args);
        $this->assertFalse($client->isConnected());
    }

    public function testForClaudeCodeWithOptions(): void
    {
        $options = ['timeout' => 30];
        $client = ClaudeCodeMcpClient::forClaudeCode($options);

        $this->assertSame($options, $client->initialOptions);
    }

    public function testConnectThrowsWhenProcessFails(): void
    {
        $client = new ClaudeCodeMcpClient('nonexistent-command-xyz', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testDisconnectWhenNotConnectedIsNoOp(): void
    {
        $client = new ClaudeCodeMcpClient();
        $client->disconnect(); // should not throw
        $this->assertFalse($client->isConnected());
    }

    public function testCallToolThrowsWhenNotConnected(): void
    {
        $client = new ClaudeCodeMcpClient();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->callTool('test_tool');
    }

    public function testListToolsThrowsWhenNotConnected(): void
    {
        $client = new ClaudeCodeMcpClient();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->listTools();
    }

    public function testSendMessageThrowsWhenNotConnected(): void
    {
        $client = new ClaudeCodeMcpClient();
        $msg = McpMessage::notification('test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->sendMessage($msg);
    }

    public function testReadMessagesWhenNotConnectedReturnsEmpty(): void
    {
        $client = new ClaudeCodeMcpClient();
        $this->assertSame([], $client->readMessages());
    }

    public function testForClaudeCodeSetsCorrectCommandAndArgs(): void
    {
        $client = ClaudeCodeMcpClient::forClaudeCode(['protocolVersion' => '2024-11-05']);

        $this->assertSame('claude', $client->command);
        $this->assertCount(1, $client->args);
        $this->assertSame('--mcp', $client->args[0]);
        $this->assertNotNull($client->initialOptions);
        $this->assertSame('2024-11-05', $client->initialOptions['protocolVersion']);
    }

    public function testInitialOptionsDefaults(): void
    {
        $client = new ClaudeCodeMcpClient();
        $this->assertNull($client->initialOptions);

        $client2 = new ClaudeCodeMcpClient('claude');
        $this->assertNull($client2->initialOptions);
    }

    public function testConnectWithEmptyCommandThrows(): void
    {
        $client = new ClaudeCodeMcpClient('', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testConnectWithNonexistentAbsolutePathThrows(): void
    {
        // A path with separator that doesn't exist as a file
        $client = new ClaudeCodeMcpClient('/nonexistent/directory/command', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testConnectWithPathSeparatorButNotExecutableThrows(): void
    {
        // Create a file that exists but is not executable
        $tempFile = sys_get_temp_dir() . '/nonexec_' . uniqid((string) getmypid(), true);
        file_put_contents($tempFile, '#!/bin/bash\necho test');
        chmod($tempFile, 0644); // not executable

        try {
            $client = new ClaudeCodeMcpClient($tempFile, [], null);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to spawn MCP process');

            $client->connect();
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * A stdio MCP server must outlive the handshake. `cat` is the smallest
     * command that does: it holds its stdin open until EOF, so disconnect()'s
     * fclose() is what ends it — no timeout, no orphan, no scheduling race.
     *
     * The obvious choices are `true` and `echo`, and both are wrong here.
     * They exit immediately, so connect()'s handshake fwrite() races the
     * kernel closing the pipe's read end. Lose that race and the write gets
     * EPIPE — "fwrite(): Write of 52 bytes failed with errno=32 Broken pipe" —
     * which phpunit.xml's failOnWarning="true" turns into a failure, and
     * sendMessage() then throws so the isConnected() assertions never run.
     * Win it and everything passes. That is the whole flake.
     */
    private const LIVE_SERVER = 'cat';

    public function testConnectReturnsEarlyWhenAlreadyConnected(): void
    {
        $client = new ClaudeCodeMcpClient(self::LIVE_SERVER);
        $client->connect();
        $this->assertTrue($client->isConnected());

        // The early return is observable two ways: it answers [] rather than
        // readMessages(), and it leaves the child alone. Assert the second —
        // a respawn would replace the process handle even if both calls
        // happened to return an empty array.
        $handle = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'process');
        $before = $handle->getValue($client);

        $this->assertSame([], $client->connect());
        $this->assertSame($before, $handle->getValue($client));

        $client->disconnect();
    }

    public function testDestructorDisconnects(): void
    {
        // __destruct() delegates to disconnect(); this pins that disconnect()
        // cleans up without error while the child is still running.
        $client = new ClaudeCodeMcpClient(self::LIVE_SERVER);
        $client->connect();
        $this->assertTrue($client->isConnected());
        // Explicitly disconnect to avoid relying on GC timing
        $client->disconnect();
        $this->assertFalse($client->isConnected());
    }

    public function testConnectSucceedsWithExistingExecutable(): void
    {
        $client = new ClaudeCodeMcpClient(self::LIVE_SERVER);
        $result = $client->connect();
        $this->assertTrue($client->isConnected());
        $this->assertIsArray($result);
        $client->disconnect();
    }

    public function testResolveExecutableFindsCommandInPath(): void
    {
        // A command with no path separator is resolved against PATH.
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR, self::LIVE_SERVER);

        $client = new ClaudeCodeMcpClient(self::LIVE_SERVER, ['-u']);
        $client->connect();
        $this->assertTrue($client->isConnected());
        $client->disconnect();
    }
}
