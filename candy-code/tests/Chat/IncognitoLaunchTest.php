<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\ContextMeasuredMsg;
use SugarCraft\Code\App\InitialPromptMsg;
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
 * opens an id with no row and no minter, the tab path's separate lazy minter
 * is gated at the seam too (rv1 MAJOR-1), the settle drops its memory-writing
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

    /**
     * rv1 MAJOR-1: a tab does NOT ride the launch-mode ladder —
     * `openTabSession()` hands every fresh tab the ISS-102 lazy minter, and
     * Chat's submit fires that minter whatever store came alongside it. An
     * `--incognito` process that opened a new tab and typed one message used
     * to leave a real row (provider, model, cwd, branch) in the workspace
     * store, showing up in `/sessions` — the exact record the flag promises
     * will not exist. And the tab open itself ran the empty-session sweep, a
     * store WRITE from a launch that keeps nothing (rv1's "full honesty"
     * cure arm). The seam's own gate (no minter on the flagged Chat), the
     * behavioral outcome (dispatch mints nothing), and the sweep's own
     * silence (an aged prunable empty row survives an incognito tab open) are
     * pinned together, and the keeping tab at the end proves BOTH arms are
     * live: one flag bit away, the identical sweep deletes the aged row and
     * the identical dispatch DOES mint.
     */
    public function testAnIncognitoTabOpensWriteFreeMintsNoRowAndTheKeepingTabDoesBoth(): void
    {
        // A real workspace is described for the tab factory; ambient
        // provider-selection env would steer it (the same neutralisation the
        // other launch-paying tests apply before calling Bootstrap itself).
        $neutral = [];
        foreach (['CANDY_CODE_PROVIDER', 'CANDY_CODE_BACKEND_CMD', 'CANDY_CODE_BACKEND_CMD_STREAM', 'CANDY_CODE_MODEL', 'CANDY_CODE_TITLE_MODEL'] as $name) {
            $neutral[$name] = getenv($name);
            putenv($name);
        }

        try {
            Bootstrap::useIncognito(true);

            // Cold tab: this call describes the workspace (its retention
            // sweep runs while the store is still empty, so the aged row
            // seeded below cannot be eaten by THAT write) and memoizes it —
            // every later tab in this test rides the same store handle.
            $tab = Bootstrap::tabChat($this->dir . '/root', null);
            $store = (new \ReflectionProperty(Chat::class, 'workspace'))->getValue($tab)->sessionStore;
            self::assertInstanceOf(EnhancedSessionStore::class, $store, 'the tab rides a real workspace store — the rows the bug could write are real ones');
            self::assertTrue($tab->incognito());
            self::assertNull((new \ReflectionProperty(Chat::class, 'sessionMint'))->getValue($tab), 'an incognito tab is handed no minter to fire');

            // Seed an aged, nameless, transcript-less row: exactly the shape
            // pruneEmptySessions() deletes (minAge past, nothing to keep it),
            // written through an own handle onto the file the tab store just
            // opened.
            $seed = new EnhancedSessionStore($this->dir . '/home/.candy-code/session.db');
            $seed->createSession('aged-empty', 'p', 'm');
            (new \ReflectionProperty(EnhancedSessionStore::class, 'pdo'))
                ->getValue($seed)
                ->exec("UPDATE sessions SET updated_at = '2000-01-01 00:00:00' WHERE id = 'aged-empty'");
            self::assertNotNull($store->getSession('aged-empty'), 'the seed sits on the same store the tab reads — the sweeps below would be vacuous otherwise');

            // The bug's headline: first dispatch used to fire the minter. The
            // returned Cmd is never RUN — the backend is not dialed; a pending
            // minter would have fired synchronously inside update().
            $tab->update(new InitialPromptMsg('first words on an incognito tab'));
            self::assertSame(
                ['aged-empty'],
                array_column($store->listSessions(), 'id'),
                'a --incognito tab minted a real session row on its first prompt',
            );

            // A second incognito tab open is the sweep's own probe: with the
            // MAJOR-1 cure the open is read-only and the aged row stands;
            // with `!self::incognito()` dropped from the prune gate, this is
            // where it dies.
            Bootstrap::tabChat($this->dir . '/root', null);
            self::assertNotNull($store->getSession('aged-empty'), 'an incognito tab open ran the empty-session sweep — the flag turns WRITES off, and the sweep writes');

            // Both positive controls, one flag bit away, on the SAME store:
            // the keeping tab's open DOES prune the aged row, and its first
            // dispatch DOES mint. (The kept tab rides the memoized workspace,
            // so no retention sweep re-decides the seed.)
            Bootstrap::useIncognito(false);
            $kept = Bootstrap::tabChat($this->dir . '/root', null);
            self::assertFalse($kept->incognito(), 'the control tab is a plain keeping tab');
            self::assertNull($store->getSession('aged-empty'), 'the keeping tab ran the sweep the incognito one skipped — the arm above is the gate, not a dead path');
            self::assertNotNull((new \ReflectionProperty(Chat::class, 'sessionMint'))->getValue($kept), 'the keeping fresh tab still carries its minter');
            $kept->update(new InitialPromptMsg('first words on a keeping tab'));
            self::assertSame(
                [$kept->currentSessionId()],
                array_column($store->listSessions(), 'id'),
                'the same dispatch one flag away writes its row: the defect shape, now correctly reserved for keeping launches',
            );
        } finally {
            foreach ($neutral as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
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
