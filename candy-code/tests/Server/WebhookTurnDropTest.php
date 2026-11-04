<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Browser;
use ReflectionProperty;
use SugarCraft\Code\Automation\WebhookDispatcher;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * CWPD-10: the C-AGT-5.f outbound webhook turn ledger must not outlive the
 * feed that armed it. A host dropped mid-turn emits no TURN_COMPLETED —
 * the ledger's only other clearing point — so {@see ServerContext::dropFeed()}
 * carries the row away with the feed. Rows here are read through reflection
 * on purpose: the leak IS the internal state, and no client-visible surface
 * grows with it.
 */
final class WebhookTurnDropTest extends TestCase
{
    private string $sandbox;

    private string $project;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-webhook-turn-drop-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testDroppingAFeedMidTurnClearsItsWebhookTurnRow(): void
    {
        $context = $this->contextFollowingOneSession();

        $turns = $this->webhookTurns($context);
        self::assertArrayHasKey('s', $turns, 'fixture: the started turn armed a webhook row');
        self::assertSame(1, $turns['s']['tools'], 'fixture: the tool start counted into the same row');

        $context->dropFeed('s');

        self::assertSame([], $this->webhookTurns($context), 'a host dropped mid-turn must not leave its webhook turn row behind');
    }

    public function testCompletedTurnsRowAlreadyGoneAndDroppingStaysQuiet(): void
    {
        $context = $this->contextFollowingOneSession();
        $host = $context->hub()->get('s');
        self::assertNotNull($host);

        // The normal ending clears the row on its own...
        $host->announce(SessionEvent::new(SessionEvent::TURN_COMPLETED, [], 's', null, 1_700_000_000_900));
        self::assertSame([], $this->webhookTurns($context), 'fixture: TURN_COMPLETED is the normal clearing path');

        // ...and dropping afterwards must be a no-op, not a notice.
        $context->dropFeed('s');
        self::assertSame([], $this->webhookTurns($context));
    }

    /**
     * A served session with the outbound ledger armed: a real dispatcher
     * over NO endpoints — dispatch() is then a silent true, so this test
     * pins the row bookkeeping without any dial.
     */
    private function contextFollowingOneSession(): ServerContext
    {
        $store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $store->createSession('s', 'p', 'm');

        $dispatcher = new WebhookDispatcher(new Browser(), [], null, 64, Loop::get());
        $workspace = WorkspaceContext::new(root: $this->project, sessionStore: $store, backend: new EchoBackend())
            ->withService(WebhookDispatcher::class, $dispatcher);

        $hub = SessionHub::new($workspace);
        $host = $hub->open('s');
        $context = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->project), 'test', Loop::get());
        $context->feedFor($host);

        $host->announce(SessionEvent::new(SessionEvent::TURN_STARTED, [], 's', null, 1_700_000_000_000));
        $host->announce(SessionEvent::new(SessionEvent::TOOL_STARTED, [], 's', null, 1_700_000_000_400));

        return $context;
    }

    /** @return array<string, array{startedMs: int, tools: int}> */
    private function webhookTurns(ServerContext $context): array
    {
        /** @var array<string, array{startedMs: int, tools: int}> $rows */
        $rows = (new ReflectionProperty(ServerContext::class, 'webhookTurns'))->getValue($context);

        return $rows;
    }
}
