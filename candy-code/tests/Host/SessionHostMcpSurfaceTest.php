<?php

/**
 * C-MCP-2.a (plan_comparison §3): the two MCP surfaces the served host used
 * to refuse by construction — `@mcp:` mentions and `/mcp__<server>__<prompt>`
 * commands — now resolve in a served session exactly as they do in the TUI.
 *
 * The fake provider matters: this step threads a lazy MCP-client closure
 * through {@see SessionHost::new()}, so the test plants an in-memory client
 * (the McpPromptCommandsTest idiom — real McpClient over anonymous provider
 * servers, because the behaviors under test live in the client) rather than
 * trusting the process-wide memo. Every assertion below was impossible
 * before the fix: the host built its mention scanner without the MCP reader,
 * merged no prompt rows into its command table, and materialised nothing at
 * submission, so each one is its own red-before-green regression.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpPromptProvider;
use SugarCraft\Code\MCP\McpResourceProvider;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class SessionHostMcpSurfaceTest extends TestCase
{
    use HomeSandboxTrait;

    private const RESOURCE_TEXT = 'RESOURCE BODY from the fake server';

    private const FETCHED_MARK = 'FETCHED BODY target=';

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-host-mcp-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testAServedSubmissionResolvesTheMcpMentionIntoAFencedAttachment(): void
    {
        $host = $this->host(mcpProvider: $this->provider(failPrompt: false));

        $ticket = $host->submit('explain @mcp:srv:file://log');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the mention resolves instead of being refused');
        $attached = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && $m->attachments !== [],
        ));
        self::assertCount(1, $attached, 'exactly the prompted turn carries the attachment');
        $data = (string) $attached[0]->attachments[0]->data;
        self::assertStringContainsString(self::RESOURCE_TEXT, $data, 'the read bytes ride the turn');
        self::assertStringContainsString('UNTRUSTED server content', $data, 'framed as data, never as instructions');
        self::assertStringContainsString('Read from MCP server "srv"', $data);

        $this->drain($host);
        $host->release();
    }

    public function testAServedMcpPromptCommandMaterialisesAtSubmission(): void
    {
        $host = $this->host(mcpProvider: $this->provider(failPrompt: false));

        $ticket = $host->submit('/mcp__srv__review deployment');

        self::assertSame(TurnTicket::PENDING, $ticket->admitted, 'the fetch parks the expansion in a child');
        $this->drain($host);

        $sent = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && \str_contains($m->content, self::FETCHED_MARK),
        ));
        self::assertCount(1, $sent, 'the fetched body reached the transcript');
        self::assertStringContainsString('deployment', $sent[0]->content, 'the positional argument mapped through');

        $host->release();
    }

    public function testADiskRowOfTheSameNameShadowsTheServedMcpPrompt(): void
    {
        $counters = new \ArrayObject();
        $provider = $this->provider(failPrompt: false, counters: $counters);
        $disk = new CommandSpec(
            name: 'mcp__srv__review',
            description: 'a human-authored command file',
            category: 'Project',
            argumentHint: '',
            template: 'DISK ROW BODY',
            tier: 'project',
        );
        $host = $this->host(mcpProvider: $provider, customCommands: ['mcp__srv__review' => $disk]);

        $ticket = $host->submit('/mcp__srv__review main branch');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the disk row is a plain file-shaped command');
        $sent = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && \str_contains($m->content, 'DISK ROW BODY'),
        ));
        self::assertCount(1, $sent);
        self::assertSame(0, (int) ($counters['fetches'] ?? 0), 'the shadowed server was never round-tripped');

        $this->drain($host);
        $host->release();
    }

    public function testAFailedFetchIsRefusedVisiblyAndNeverSentEmpty(): void
    {
        $host = $this->host(mcpProvider: $this->provider(failPrompt: true));

        $ticket = $host->submit('/mcp__srv__review');

        self::assertSame(TurnTicket::PENDING, $ticket->admitted);
        $this->drain($host);

        $empties = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && \trim($m->content) === '',
        ));
        self::assertSame([], $empties, 'an un-fetched prompt is never sent as an empty turn');
        $settled = \array_values(\array_filter(
            $host->history(),
            static fn (Message $m): bool => \str_contains($m->content, 'mcp__srv__review'),
        ));
        self::assertNotEmpty($settled, 'the refusal is recorded in the transcript, not swallowed');
        self::assertSame(1, \count(\array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User,
        )), 'no user turn was added for the failed fetch');

        $host->release();
    }

    /** A plain (non-mention, non-command) submission keeps working over the same wiring. */
    public function testAnOrdinaryServedTurnIsUntouchedByTheWiring(): void
    {
        $host = $this->host(mcpProvider: $this->provider(failPrompt: false));

        $ticket = $host->submit('hello there');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        $this->drain($host);
        $host->release();
    }

    // ── harness ────────────────────────────────────────────────────────

    /** @param array<string, CommandSpec>|null $customCommands */
    private function host(?\Closure $mcpProvider, ?array $customCommands = null): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                backend: new EchoBackend(),
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
            customCommands: $customCommands,
            mcpProvider: $mcpProvider,
        );
    }

    /**
     * A real McpClient over a seeded provider server, handed as the lazy
     * closure SessionHost now takes. The counters bag records every fetch the
     * client round-trips, so the shadow test can prove the server slept.
     */
    private function provider(bool $failPrompt, ?\ArrayObject $counters = null): \Closure
    {
        $counters ??= new \ArrayObject();
        $server = new class($failPrompt, $counters) implements McpServer, McpPromptProvider, McpResourceProvider {
            public function __construct(
                private readonly bool $fail,
                private readonly \ArrayObject $counters,
            ) {}

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
            public function listPrompts(): array
            {
                return [[
                    'name' => 'review',
                    'description' => 'review the target',
                    'arguments' => [['name' => 'target', 'required' => true]],
                ]];
            }

            /** @return array<mixed> */
            public function prompt(string $name, array $arguments = []): array
            {
                $this->counters['fetches'] = (int) ($this->counters['fetches'] ?? 0) + 1;
                if ($this->fail) {
                    throw new \RuntimeException('the fake server refuses this prompt');
                }

                return ['messages' => [[
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'FETCHED BODY target=' . ($arguments['target'] ?? '?')],
                ]]];
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

        $client = new McpClient($this->sandbox . '/absent-' . \bin2hex(\random_bytes(4)) . '.json', unrestricted: true);
        (new \ReflectionProperty(McpClient::class, 'servers'))->setValue($client, ['srv' => $server]);

        return static fn (): ?McpClient => $client;
    }

    /** Pump the loop until the host settles (the ServedProjectCommandTrustTest shape). */
    private function drain(SessionHost $host): void
    {
        if (!$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the turn settled');
    }
}
