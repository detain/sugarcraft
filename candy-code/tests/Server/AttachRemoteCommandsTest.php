<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\RemoteFeedPumpMsg;
use SugarCraft\Code\Backend\RemoteBackend;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-7.f: an attached window runs the server's OWN slash commands on the
 * server.
 *
 * `command.list` arrives once after the hello and is cached
 * ({@see RemoteSessionHost::serverCommands()}); a name the roster says runs
 * `server` — `/compact`, `/status` — goes out as `command.exec` and its
 * reply rows land in the window the way a locally-run host command's do,
 * while a screen-only name (`/theme`) still runs right here. The whole band
 * runs over the ProtocolFixture's loopback server — the same real sockets
 * {@see \SugarCraft\Code\Tests\Backend\RemoteBackendTest} drives — so the
 * roster fetch, the exec round trip, and the busy refusal are all genuine
 * wire traffic, and the window's Chat is a real one typed into.
 */
final class AttachRemoteCommandsTest extends TestCase
{
    use HomeSandboxTrait;

    private const ATTACH_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private ProtocolFixture $fixture;

    private Server $server;

    private string $sandbox = '';

    /** @var list<RemoteSessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/ccmp-csess7f-' . \bin2hex(\random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->fixture = ProtocolFixture::new();
        $this->server = Server::new(
            ServerConfig::new($this->fixture->dir)->withPort(0),
            AuthContext::new(TokenStore::new($this->fixture->dir)->withOverride(self::ATTACH_TOKEN)),
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->close();
        }
        RemoteSessionHost::useForLaunch(null);
        $this->server->stop();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testTheServerCommandCacheArrivesWithTheHelloRoster(): void
    {
        $host = $this->attached();

        // The cache fills right after the subscribe chain; a window that
        // typed a command one loop later must already know its runsIn.
        self::assertTrue(
            $this->until(static fn (): bool => $host->serverCommands() !== []),
            'the post-hello command.list never filled the cache',
        );
        $roster = $host->serverCommands();
        self::assertSame('server', $roster['compact'] ?? null, 'compact ships a host body');
        self::assertSame('server', $roster['clear'] ?? null, 'clear ships a host body');
        self::assertSame('client', $roster['theme'] ?? null, 'theme opens a screen only this side can paint');
        // C-WEB-2.e: /help ships a host body now, so the roster admits it as
        // a server command; the attached window's local page still answers
        // when the host is not a serve session (runsIn gates dispatch, not
        // the TUI's own handler).
        self::assertSame('server', $roster['help'] ?? null, 'help ships a host body');
    }

    public function testAHostOwnedCommandTypedInAnAttachedWindowExecutesOnTheServer(): void
    {
        $host = $this->attached();
        self::assertTrue(
            $this->until(static fn (): bool => ($host->serverCommands()['status'] ?? null) === 'server'),
            'status should be a server command',
        );
        [$chat, $backend] = $this->window($host, '/status');

        [$afterEnter, $msg] = $this->submitAndSettle($chat);
        self::assertInstanceOf(RemoteFeedPumpMsg::class, $msg);
        [$folded, $foldCmd] = $afterEnter->update($msg);
        self::assertNull($foldCmd, 'a plain row fold carries no follow-up command');

        // The server's status page names the SESSION IT SERVES — the window's
        // own copy of the command could not print this id, so a matching row
        // proves the page crossed the wire and folded as a host result does.
        $contents = \array_map(static fn (Message $m): string => $m->content, $folded->history);
        self::assertSame('/status', $contents[2] ?? '', 'the typed command echoes first, like a local host run');
        self::assertStringContainsString((string) $host->sessionId(), $contents[3] ?? '');
        self::assertSame(Role::Assistant, $folded->history[3]->role);
        self::assertTrue($folded->history[2]->uiOnly, 'the echo row never re-crosses the wire');

        self::assertSame('', $folded->inputBuf, 'the draft is spent');
        self::assertFalse($folded->inFlight, 'a command is not a turn');
        self::assertSame([], $this->fixture->backend->sent, 'the server ran the command, not a prompt');
    }

    public function testCompactRoutesToTheServerAndStartsNoWindowLocalFold(): void
    {
        $host = $this->attached();
        self::assertTrue(
            $this->until(static fn (): bool => ($host->serverCommands()['compact'] ?? null) === 'server'),
            'compact should be a server command',
        );
        [$chat, $backend] = $this->window($host, '/compact');

        [$afterEnter, $msg] = $this->submitAndSettle($chat);
        self::assertInstanceOf(RemoteFeedPumpMsg::class, $msg);
        [$folded] = $afterEnter->update($msg);

        // /compact answers with a compact-transcript EFFECT and no rows: the
        // fold happens on the server's copy and the effect kind carries no
        // payload across the wire (docs/SERVER.md says so). So the window
        // must neither fold locally (the pre-step behaviour: its own
        // summariser burning a turn) nor pretend it compacted.
        self::assertCount(2, $folded->history, 'the window kept its rows and folded nothing itself');
        self::assertSame([], $this->fixture->backend->sent, 'no local summariser turn was started');
        self::assertFalse($folded->inFlight);
        self::assertSame('', $folded->inputBuf);
    }

    public function testAScreenOnlyCommandStillRunsInThisWindow(): void
    {
        $host = $this->attached();
        self::assertTrue(
            $this->until(static fn (): bool => ($host->serverCommands()['theme'] ?? null) === 'client'),
            'theme should be a client command',
        );
        [$chat, $backend] = $this->window($host, '/theme');

        // Drive Enter and let the reply land: on the honest path the local
        // theme handler answers with [model, null]; were the remote arm to
        // misfire on a client name, the same drive would collect the
        // server's ui_only refusal into the inbox — and the pump below would
        // land it as rows, reddening this test.
        [$afterEnter, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));
        $resolved = null;
        if ($cmd !== null) {
            $async = $cmd();
            if ($async instanceof AsyncCmd) {
                $async->promise->then(static function (mixed $m) use (&$resolved): void {
                    $resolved = $m;
                });
                self::assertTrue(
                    $this->until(function () use (&$resolved): bool {
                        return $resolved !== null;
                    }),
                    'a misfired exec reply never reported back',
                );
            }
        }
        [$pumped] = $afterEnter->update($resolved ?? new RemoteFeedPumpMsg());

        self::assertSame(
            [],
            $backend->takeRemoteResults(),
            'a screen-only command must never queue an exec reply',
        );
        foreach ($pumped->history as $row) {
            self::assertStringNotContainsString(
                'was not run on the server',
                $row->content,
                'a screen-only command must never take the exec round trip',
            );
        }
        self::assertFalse($backend->remoteFeedPending());
        self::assertSame([], $this->fixture->backend->sent, 'no command.exec turn, no local turn');
        self::assertFalse($pumped->inFlight);
    }


    public function testACommandRefusedByABusyServerLandsAsAnErrorRow(): void
    {
        $host = $this->attached();
        [$chat, $backend] = $this->window($host, '/status');

        // A real turn on the server first — the window's own attachment is
        // the one running it, so the server is busy when the command lands
        // and refuses it exactly as it refuses a second client's command.
        $own = $backend->completeInteractive([Message::user('holding the turn')], null, null, null, null, null);
        self::assertTrue(
            $this->until(fn (): bool => $this->fixture->backend->isRunning()),
            'the holding turn never started',
        );

        [$afterEnter, $msg] = $this->submitAndSettle($chat);
        self::assertInstanceOf(RemoteFeedPumpMsg::class, $msg);
        [$folded] = $afterEnter->update($msg);

        $contents = \array_map(static fn (Message $m): string => $m->content, $folded->history);
        self::assertSame('/status', $contents[2] ?? '', 'the refusal still echoes what was typed');
        self::assertStringContainsString('was not run on the server', $contents[3] ?? '');
        self::assertStringContainsString('status', $contents[3] ?? '', 'the row names the command');

        $this->fixture->backend->settle(Message::assistant('released'));
        $this->await($own);
    }


    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: Chat, 1: RemoteBackend} */
    private function window(RemoteSessionHost $host, string $draft = ''): array
    {
        $backend = RemoteBackend::new($host);
        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: $backend,
        ))->withSize(100, 30);

        return [$chat, $backend];
    }

    /**
     * Enter on a typed command, then wait for the exec round trip: the
     * remote arm answers with a promise command whose AsyncCmd resolves to
     * the pump message that applies the server's rows.
     *
     * @return array{0: Chat, 1: mixed}
     */
    private function submitAndSettle(Chat $chat): array
    {
        [$afterEnter, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));
        self::assertInstanceOf(\Closure::class, $cmd, 'the remote arm returns a promise command');
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $msg = null;
        $async->promise->then(static function (mixed $resolved) use (&$msg): void {
            $msg = $resolved;
        });
        self::assertTrue(
            $this->until(function () use (&$msg): bool {
                return $msg !== null;
            }),
            'the command.exec reply never landed',
        );

        return [$afterEnter, $msg];
    }

    private function wsUrl(): string
    {
        return 'ws://127.0.0.1:' . $this->server->port() . '/ws';
    }

    private function attached(?string $target = null): RemoteSessionHost
    {
        $host = $this->await(Attach::connect($this->wsUrl(), self::ATTACH_TOKEN)->then(
            static fn (RemoteSessionHost $host): PromiseInterface => $host->hello()->then(static fn (): PromiseInterface => $host->open($target)),
        ));
        self::assertInstanceOf(RemoteSessionHost::class, $host, $host instanceof \Throwable ? $host->getMessage() : 'no answer');
        $this->hosts[] = $host;
        $this->fixture->run(0.05);

        return $host;
    }

    /** Run the loop until $condition holds (or five seconds pass); whether it did. */
    private function until(\Closure $condition, float $timeout = 5.0): bool
    {
        $deadline = \microtime(true) + $timeout;
        while (!$condition()) {
            if (\microtime(true) >= $deadline) {
                return false;
            }
            $this->fixture->run(0.01);
        }

        return true;
    }

    private function await(PromiseInterface $promise): mixed
    {
        return $this->fixture->await($promise);
    }
}
