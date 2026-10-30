<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\ContextMeasuredMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-7.c: `--incognito` launches keep nothing. The flag is one decision
 * carried on the Chat itself (and registered on the launch shelf); these
 * pins walk every surface that decision is supposed to reach: the launch
 * opens an id with no row and no minter, the settle drops its memory-writing
 * passes while every sibling pass stays scheduled, the carry survives the
 * clones the update loop performs (a dropped field would silently un-switch
 * the session at the first keystroke), and the status bar paints the
 * segment so nobody mistakes the kept-looking session for a kept one.
 *
 * The serve host's no-persistence law (C-SES-7.e) and the `-p` funnel gate
 * (NonInteractiveSessionPersistenceTest) cover the other two access paths.
 */
final class IncognitoLaunchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/csg7c_' . uniqid((string) getmypid(), true);
        mkdir($this->dir . '/home/.candy-code', 0700, true);
        mkdir($this->dir . '/root', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
        Bootstrap::useSessionLaunch(false);
        Bootstrap::useIncognito(false);
        // An ambient disable env would silently spare the control chat its
        // consolidation pass and make the discriminating half vacuous.
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
    }

    protected function tearDown(): void
    {
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        Bootstrap::useIncognito(false);
        Bootstrap::useSessionLaunch(false);
        $this->restoreHomeSandbox();
        $this->discardIncognitoTree($this->dir);

        parent::tearDown();
    }

    public function testAnIncognitoLaunchOpensAnIdWithNoRowAndNoMinter(): void
    {
        $store = new EnhancedSessionStore($this->dir . '/home/.candy-code/session.db');
        $store->createSession('older', 'p', 'm');

        Bootstrap::useIncognito(true);
        $opened = Bootstrap::openSession($store);

        self::assertNotSame('older', $opened['id'], 'incognito never adopts the kept session');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $opened['id'], 'the id is a throwaway, not a stored handle');
        self::assertNull($opened['name']);
        self::assertSame([], $opened['history']);
        self::assertFalse($opened['picker']);
        // The normal new-session path hands back a minter for the first
        // dispatch; incognito has no row to mint, so the closure itself is
        // absent — no consumer can write a row "by accident" of dispatching.
        self::assertArrayNotHasKey('mint', $opened);
        self::assertNull($store->getSession($opened['id']));

        Bootstrap::useIncognito(false);
        self::assertArrayHasKey('mint', Bootstrap::openSession($store), 'the control launch still carries its minter');
    }

    public function testIncognitoDropsTheTurnEndMemoryPassesAndTheControlSchedulesOne(): void
    {
        $incognitoStore = new MemoryStore($this->dir . '/home');
        $incognitoSummary = $this->fixedSummaryBackend('{"operations":[{"op":"add","content":"The API is versioned under /v2."}]}');
        $incognito = new Chat(
            history: [Message::user(str_repeat('Move the API under /v2 and drop v1 entirely. ', 6))],
            inFlight: true,
            memoryStore: $incognitoStore,
            summaryBackend: $incognitoSummary,
            projectRoot: $this->dir . '/root',
            incognito: true,
        );

        [, $cmd] = $incognito->update(new AssistantMsg(Message::assistant(str_repeat('The API now lives under /v2 and v1 is gone. ', 8))));

        self::assertSame([], $this->incognitoSettlePasses($cmd), 'the settle schedules no announcing pass at all');
        self::assertSame([], $incognitoSummary->asked, 'the summary backend is never consulted');
        self::assertSame([], $incognitoStore->list('project'), 'no note reaches the memory store');

        // Control: one flag bit away, the same fixture DOES consolidate —
        // the drop above is the gate's doing, not a fixture that never fires.
        mkdir($this->dir . '/home2', 0700, true);
        $controlStore = new MemoryStore($this->dir . '/home2');
        $controlSummary = $this->fixedSummaryBackend('{"operations":[{"op":"add","content":"The API is versioned under /v2."}]}');
        $control = new Chat(
            history: [Message::user(str_repeat('Move the API under /v2 and drop v1 entirely. ', 6))],
            inFlight: true,
            memoryStore: $controlStore,
            summaryBackend: $controlSummary,
            projectRoot: $this->dir . '/root',
        );

        [, $controlCmd] = $control->update(new AssistantMsg(Message::assistant(str_repeat('The API now lives under /v2 and v1 is gone. ', 8))));

        self::assertSame([MemoryConsolidatedMsg::class], $this->incognitoSettlePasses($controlCmd));
        self::assertCount(1, $controlSummary->asked);
        self::assertCount(1, $controlStore->list('project'));
    }

    /**
     * The flag must ride every clone the loop performs: the Chat carries it
     * through its own mutate() map, and the surfaces read the Chat field —
     * never a sessionStore-absence sniff, because "no store" is also the
     * shape of a headless host and a read-only window.
     */
    public function testIncognitoSurvivesTheClonesTheLoopPerforms(): void
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            incognito: true,
        );

        self::assertTrue($chat->incognito());
        self::assertTrue($chat->withSize(120, 40)->incognito(), 'a resize clone keeps the flag');
        self::assertTrue($chat->withSessionStore(new EnhancedSessionStore($this->dir . '/home/.candy-code/other.db'))->incognito(), 'attaching a store does not flip the decision — surfaces read the flag, not the store');
        self::assertFalse((new Chat(history: [], backend: new EchoBackend()))->incognito(), 'the default stays saving');
    }

    public function testTheStatusBarPaintsTheIncognitoSegmentOnlyForAnIncognitoLaunch(): void
    {
        $quiet = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
        ))->withSize(120, 30);
        $dark = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            incognito: true,
        ))->withSize(120, 30);

        self::assertStringNotContainsString('incognito', $this->incognitoBar($quiet), 'a saving launch is never mislabelled');
        self::assertStringContainsString('incognito', $this->incognitoBar($dark));
    }

    /** The painted bar, sentinels off — same access the segment family uses. */
    private function incognitoBar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))->invoke(null, $chat, Renderer::publishedFrame());

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    /**
     * The passes the settle actually scheduled, minus the context-remeasure
     * stamp — the sibling that stays on for everyone.
     *
     * @return list<class-string>
     */
    private function incognitoSettlePasses(?\Closure $cmd): array
    {
        self::assertNotNull($cmd);
        $batch = $cmd();
        $types = [];

        foreach ($batch instanceof BatchMsg ? $batch->cmds : [$batch] as $child) {
            $async = $child instanceof AsyncCmd ? $child : $child();
            self::assertInstanceOf(AsyncCmd::class, $async);
            $landed = null;
            $async->promise->then(static function (mixed $msg) use (&$landed): void {
                $landed = $msg;
            });

            if ($landed !== null && !$landed instanceof ContextMeasuredMsg) {
                $types[] = $landed::class;
            }
        }

        return $types;
    }

    private function fixedSummaryBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.002)));
            }
        };
    }

    private function discardIncognitoTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->discardIncognitoTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
