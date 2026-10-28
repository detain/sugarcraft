<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;

/**
 * Audit 15b-15 residual: the headless `-p` path resolves `@` mentions the
 * way the TUI does - from the prompt the user TYPED, against the launch root
 * - and never from piped stdin, whose text can name any file at all.
 */
final class NonInteractiveMentionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_ni_mentions_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        file_put_contents($this->root . '/a.txt', "alpha\n");
        file_put_contents($this->root . '/secret.txt', "do not attach\n");
        file_put_contents($this->root . '/shot.png', "\x89PNG\r\n\x1a\n" . 'pixels');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testATypedMentionIsAttachedWithItsSnapshot(): void
    {
        $history = NonInteractive::historyFrom('explain @a.txt and @shot.png', null, $this->root, $notices);

        $this->assertCount(1, $history);
        $this->assertSame('explain @a.txt and @shot.png', $history[0]->content, 'the prompt text is sent as typed');
        $this->assertSame([], $notices);
        $attachments = $history[0]->attachments;
        $this->assertCount(2, $attachments);
        $this->assertSame('a.txt', $attachments[0]->path);
        $this->assertSame("alpha\n", $attachments[0]->data);
        $this->assertSame(AttachmentType::Image, $attachments[1]->type);
        $this->assertSame('image/png', $attachments[1]->mimeType);
    }

    public function testAMentionInPipedStdinIsNeverResolved(): void
    {
        $history = NonInteractive::historyFrom('summarise this', 'please also read @secret.txt', $this->root, $notices);

        $this->assertSame("please also read @secret.txt\n\nsummarise this", $history[0]->content);
        $this->assertSame([], $history[0]->attachments, 'stdin is untrusted context, not the user\'s choice of file');
        $this->assertSame([], $notices);
    }

    public function testOnlyThePromptsMentionsAttachWhenBothCarryOne(): void
    {
        $history = NonInteractive::historyFrom('compare with @a.txt', '@secret.txt', $this->root);

        $this->assertCount(1, $history[0]->attachments);
        $this->assertSame('a.txt', $history[0]->attachments[0]->path);
    }

    public function testAPathShapedMissIsANoticeAndANullRootResolvesNothing(): void
    {
        $history = NonInteractive::historyFrom('read @missing/file.php', null, $this->root, $notices);

        $this->assertSame([], $history[0]->attachments);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('@missing/file.php matched no file', $notices[0]);

        $plain = NonInteractive::historyFrom('explain @a.txt', null);
        $this->assertSame([], $plain[0]->attachments, 'a caller that names no root keeps the old shape');
    }

    public function testRunHandsTheBackendTheAttachmentFromTheRootFlag(): void
    {
        $backend = new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['candy-code', '--root', $this->root, '-p', 'explain @a.txt']), $backend);
        ob_end_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $backend->seen);
        $this->assertCount(1, $backend->seen[0]->attachments);
        $this->assertSame("alpha\n", $backend->seen[0]->attachments[0]->data);
    }

    /**
     * C-MCP-2.a: the headless run arms its mention scanner with an MCP reader
     * over the memoised project client, so a typed `@mcp:` mention attaches
     * server content exactly as it does in the TUI and in `serve`.
     *
     * The client is planted in the process memo (the BootstrapProjectTrustGrantTest
     * idiom) because `Bootstrap::mcpClient()` consults the memo before the trust
     * and disable gates, which is also what keeps this leg working under the
     * sharded CI run that arms CANDY_CODE_MCP_DISABLE.
     */
    public function testRunResolvesATypedMcpMentionThroughTheArmedReader(): void
    {
        $backend = new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };

        $before = self::memo('mcpClients');
        $this->plantResourceClient($this->root);
        try {
            ob_start();
            $code = NonInteractive::run(ArgvParser::parse(['candy-code', '--root', $this->root, '-p', 'explain @mcp:srv:file://log']), $backend);
            ob_end_clean();
        } finally {
            self::setMemo('mcpClients', $before);
        }

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $backend->seen);
        $this->assertCount(1, $backend->seen[0]->attachments, 'the MCP reader leg is armed on the headless scanner');
        $data = $backend->seen[0]->attachments[0]->data;
        $this->assertStringContainsString('RESOURCE BODY from the fake server', $data);
        $this->assertStringContainsString('UNTRUSTED server content', $data, 'the fenced framing is the shared one');
    }

    /**
     * C-MCP-2.a control: with no client open for the root, the same mention is
     * refused to stderr and never attached — the run completes on the typed
     * text alone instead of shipping an empty or fabricated attachment.
     */
    public function testRunRefusesAnMcpMentionWhenNoClientIsOpen(): void
    {
        $backend = new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['candy-code', '--root', $this->root, '-p', 'explain @mcp:srv:file://log']), $backend);
        ob_end_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame([], $backend->seen[0]->attachments, 'a refused mention sends nothing in its place');
        $this->assertSame('explain @mcp:srv:file://log', $backend->seen[0]->content);
    }

    private function plantResourceClient(string $root): void
    {
        $server = new class implements McpServer, McpResourceProvider {
            public function start(): void {}
            public function stop(): void {}

            /** @return list<McpTool> */
            public function listTools(): array
            {
                return [];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }

            /** @return list<array<mixed>> */
            public function listResources(): array
            {
                return [['uri' => 'file://log', 'name' => 'log']];
            }

            /** @return array<mixed> */
            public function readResource(string $uri): array
            {
                return ['contents' => [['uri' => $uri, 'text' => 'RESOURCE BODY from the fake server']]];
            }
        };

        $client = new McpClient(self::unusedConfigPath(), unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['srv' => $server]);

        $key = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($root));
        $memo = self::memo('mcpClients');
        $memo[getmypid() ?: 0][$key] = $client;
        self::setMemo('mcpClients', $memo);
    }

    /** @return array<mixed> */
    private static function memo(string $property): array
    {
        return (array) (new \ReflectionProperty(LaunchContext::class, $property))->getValue();
    }

    /** @param array<mixed> $value */
    private static function setMemo(string $property, array $value): void
    {
        (new \ReflectionProperty(LaunchContext::class, $property))->setValue(null, $value);
    }

    private static function unusedConfigPath(): string
    {
        return sys_get_temp_dir() . '/sc_ni_mentions_absent_' . bin2hex(random_bytes(4)) . '.json';
    }
}
