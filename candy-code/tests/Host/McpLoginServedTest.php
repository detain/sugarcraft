<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\McpAuthHostCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\ServedMcpLogins;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Support\McpLoginFork;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-MCP-4.c: `mcp auth login <server>` run by a SERVED client. The host
 * command intercepts the login, forks it through the TUI's own machinery,
 * and {@see ServedMcpLogins} keeps the follow-up beats aimed at the session
 * that typed the command; {@see ServerContext::pump()} carries them into
 * that transcript as `message.created` notices. The child is stubbed
 * through the fork's argv seam, exactly as the TUI-side pins do.
 */
final class McpLoginServedTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-servedlogin-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        mkdir($this->sandbox . '/tmp', 0700, true);
        ServedMcpLogins::clear();
    }

    protected function tearDown(): void
    {
        McpLoginFork::releaseAll();
        ServedMcpLogins::clear();
        $this->restoreHomeSandbox();
        self::purgeTree($this->sandbox);
    }

    private static function purgeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::purgeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    /** A child that prints its authorize URL line, pauses briefly, exits $code. */
    private function stubLoginChild(int $code = 0): void
    {
        McpLoginFork::$argvOverride = static fn (string $server, array $rest): array => [
            \PHP_BINARY,
            '-r',
            'echo "    http://127.0.0.1:41239/cb/' . $server . "\n\";" . 'usleep(150000);exit(' . $code . ');',
        ];
    }

    /**
     * Collect watcher rows until $want have landed (or the budget runs out).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function collectDue(int $want, int $tries = 120): array
    {
        $rows = [];
        for ($i = 0; $i < $tries && count($rows) < $want; ++$i) {
            foreach (ServedMcpLogins::pollDue() as $row) {
                $rows[] = $row;
            }
            usleep(50_000);
        }

        return $rows;
    }

    public function testTheWatchDeliversTheUrlRowThenTheClosingRowThenRetires(): void
    {
        $this->stubLoginChild(0);
        [$notice, $armed] = McpLoginFork::arm('w4csrvA', [], $this->sandbox . '/tmp');
        self::assertTrue($armed, $notice);
        ServedMcpLogins::register('w4csrvA', 'sess-A');

        $rows = $this->collectDue(2);
        self::assertCount(2, $rows, 'the browser link then the closing beat, in order');
        self::assertSame('sess-A', $rows[0][0]);
        self::assertStringContainsString('Open this URL to authorize `w4csrvA`', $rows[0][1]);
        self::assertStringContainsString('http://127.0.0.1:41239/cb/w4csrvA', $rows[0][1], 'the link itself rides the row');
        self::assertStringContainsString('✓ MCP login for `w4csrvA` finished (exit 0)', $rows[1][1]);

        self::assertSame([], ServedMcpLogins::pollDue(), 'the done beat retires the watch — no re-announce');
    }

    public function testAFailedLoginClosesWithTheFailedRow(): void
    {
        $this->stubLoginChild(3);
        [, $armed] = McpLoginFork::arm('w4csrvF', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);
        ServedMcpLogins::register('w4csrvF', 'sess-F');

        $rows = $this->collectDue(2);
        self::assertCount(2, $rows);
        self::assertStringContainsString('✗ MCP login for `w4csrvF` exited 3 without storing', $rows[1][1]);
        self::assertSame([], ServedMcpLogins::pollDue());
    }

    public function testRegisterWithoutASessionWatchesNothing(): void
    {
        $this->stubLoginChild(0);
        [, $armed] = McpLoginFork::arm('w4cnone', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);

        ServedMcpLogins::register('w4cnone', null);
        ServedMcpLogins::register('w4cnone', '');
        self::assertSame([], ServedMcpLogins::pollDue(), 'no session means no transcript to aim at — nothing is watched');
    }

    public function testAGoneSlotRetiresItsWatchWithoutPrinting(): void
    {
        ServedMcpLogins::register('w4cghost', 'sess-G');
        self::assertSame([], ServedMcpLogins::pollDue(), 'a slot that never existed answers the gone beat in silence');

        // If the gone beat had NOT retired the watch, the re-armed child's
        // URL below would surface as a row; the empty collect proves silence.
        $this->stubLoginChild(0);
        [, $armed] = McpLoginFork::arm('w4cghost', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);
        self::assertSame([], $this->collectDue(1, 8), 'the retired watch stays retired');
    }

    public function testTheHostCommandInterceptArmsAndAnswersWithTheServedRow(): void
    {
        $this->stubLoginChild(0);
        $context = CommandContext::new(sessionId: 'sess-H', root: $this->sandbox . '/tmp');
        $result = (new McpAuthHostCommand())->run($context, '/mcp login w4chost');

        self::assertTrue(McpLoginFork::isArmed('w4chost'), 'the served arm reached the real fork');
        $reply = (string) ($result->rows[1]->content ?? '');
        self::assertStringContainsString('on the SERVER host', $reply, 'the served row spells the cross-machine story');
        self::assertStringContainsString('/mcp login w4chost --code <that-url>', $reply, 'and the paste-back form with it');

        $rows = $this->collectDue(2);
        self::assertCount(2, $rows, 'the intercept armed a watch aimed at the typing session');
        self::assertSame(['sess-H', 'sess-H'], [$rows[0][0], $rows[1][0]]);
        self::assertStringContainsString('Open this URL to authorize `w4chost`', $rows[0][1]);
    }

    public function testBareLoginStillRoutesToTheGuidanceArm(): void
    {
        $context = CommandContext::new(sessionId: 'sess-B', root: $this->sandbox . '/tmp');
        $result = (new McpAuthHostCommand())->run($context, '/mcp login');

        $reply = (string) ($result->rows[1]->content ?? '');
        self::assertStringContainsString('candy-code mcp auth login <server>', $reply, 'the bare form still lands the shell guidance');
        self::assertSame([], ServedMcpLogins::pollDue(), 'guidance arms nothing');
    }

    public function testALoginLineWithoutAServerTargetFallsThroughToo(): void
    {
        $context = CommandContext::new(sessionId: 'sess-T', root: $this->sandbox . '/tmp');
        (new McpAuthHostCommand())->run($context, '/mcp login --code');

        self::assertFalse(McpLoginFork::isArmed('--code'));
        self::assertSame([], ServedMcpLogins::pollDue());
    }

    public function testPumpRoutesTheServedRowsIntoTheDurableEvents(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/sessions.db');
        $hub = SessionHub::new(WorkspaceContext::new(
            root: $this->sandbox,
            sessionStore: $store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
        ));
        $host = $hub->create('served-pump-1');
        self::assertInstanceOf(SessionHost::class, $host);
        $server = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->sandbox), 'served-pump', Loop::get());

        $this->stubLoginChild(0);
        [, $armed] = McpLoginFork::arm('w4cpump', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);
        ServedMcpLogins::register('w4cpump', 'served-pump-1');

        $contents = [];
        for ($i = 0; $i < 200 && count($contents) < 2; ++$i) {
            $server->pump();
            $contents = $this->noticeRows($host, 'served-pump-1');
            usleep(20_000);
        }

        self::assertCount(2, $contents, 'pump carried both beats into the session transcript');
        self::assertStringContainsString('Open this URL to authorize `w4cpump`', $contents[0][0]);
        self::assertStringContainsString('✓ MCP login for `w4cpump` finished (exit 0)', $contents[1][0]);
        foreach ($contents as [$content, $payload]) {
            self::assertSame('notice', $payload['kind'], 'the row carries the notice kind Role::System rows paint with');
            self::assertNotSame('', $content);
        }
        self::assertSame([], ServedMcpLogins::pollDue(), 'and the watch retired with the child');
    }

    public function testPumpDropsRowsForSessionsThatClosedMidLogin(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/sessions2.db');
        $hub = SessionHub::new(WorkspaceContext::new(
            root: $this->sandbox,
            sessionStore: $store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
        ));
        $server = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->sandbox), 'served-pump-2', Loop::get());

        $this->stubLoginChild(0);
        [, $armed] = McpLoginFork::arm('w4cpump2', [], $this->sandbox . '/tmp');
        self::assertTrue($armed);
        ServedMcpLogins::register('w4cpump2', 'no-such-session');

        for ($i = 0; $i < 40; ++$i) {
            $server->pump();
            usleep(20_000);
        }

        self::assertSame([], ServedMcpLogins::pollDue(), 'a vanished host drops the rows; the child final beat retires the watch anyway');
    }

    /**
     * BRIEF promise: a second session's login is independent. Two children on
     * two server names are watched for two live sessions AT ONCE; every row
     * must land in its own transcript and nowhere else. Red under a pollDue
     * that ignored the session map (e.g. routed every row to one session).
     */
    public function testTwoConcurrentLoginWatchesStayInTheirOwnSessions(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/sessions3.db');
        $hub = SessionHub::new(WorkspaceContext::new(
            root: $this->sandbox,
            sessionStore: $store,
            backend: new EchoBackend(),
            commandLoader: new CommandLoader(),
        ));
        $hostX = $hub->create('served-two-x');
        $hostY = $hub->create('served-two-y');
        self::assertInstanceOf(SessionHost::class, $hostX);
        self::assertInstanceOf(SessionHost::class, $hostY);
        $server = ServerContext::new($hub, ServerConfig::new($this->sandbox)->withRoot($this->sandbox), 'served-two', Loop::get());

        $this->stubLoginChild(0);
        [, $armedX] = McpLoginFork::arm('w4ctx', [], $this->sandbox . '/tmp');
        [, $armedY] = McpLoginFork::arm('w4cty', [], $this->sandbox . '/tmp');
        self::assertTrue($armedX, 'the two arms are independent slots');
        self::assertTrue($armedY, 'the two arms are independent slots');
        ServedMcpLogins::register('w4ctx', 'served-two-x');
        ServedMcpLogins::register('w4cty', 'served-two-y');

        $rowsX = [];
        $rowsY = [];
        for ($i = 0; $i < 200 && (count($rowsX) < 2 || count($rowsY) < 2); ++$i) {
            $server->pump();
            $rowsX = $this->noticeRows($hostX, 'served-two-x');
            $rowsY = $this->noticeRows($hostY, 'served-two-y');
            usleep(20_000);
        }

        self::assertCount(2, $rowsX, 'session X received exactly its url beat and its closing beat');
        self::assertCount(2, $rowsY, 'session Y received exactly its url beat and its closing beat');
        self::assertStringContainsString('Open this URL to authorize `w4ctx`', $rowsX[0][0]);
        self::assertStringContainsString('http://127.0.0.1:41239/cb/w4ctx', $rowsX[0][0], 'X got its own link, built from its own server name');
        self::assertStringContainsString('✓ MCP login for `w4ctx` finished (exit 0)', $rowsX[1][0]);
        self::assertStringContainsString('Open this URL to authorize `w4cty`', $rowsY[0][0]);
        self::assertStringContainsString('http://127.0.0.1:41239/cb/w4cty', $rowsY[0][0], 'Y got its own link, built from its own server name');
        self::assertStringContainsString('✓ MCP login for `w4cty` finished (exit 0)', $rowsY[1][0]);

        foreach ($rowsX as [$content, $payload]) {
            self::assertStringNotContainsString('w4cty', $content, 'zero cross-talk: nothing aimed at Y rides into X');
        }
        foreach ($rowsY as [$content, $payload]) {
            self::assertStringNotContainsString('w4ctx', $content, 'zero cross-talk: nothing aimed at X rides into Y');
        }

        self::assertSame([], ServedMcpLogins::pollDue(), 'each watch retired on its own child exit — neither starved the other');
    }

    /**
     * The session's durable message.created notice rows.
     *
     * @return list<array{0: string, 1: array<string, mixed>}> [content, payload]
     */
    private function noticeRows(SessionHost $host, string $sessionId): array
    {
        $log = $host->events();
        self::assertNotNull($log, 'the fixture store is durable');
        $rows = [];
        foreach ($log->since($sessionId, 0, 200) as $event) {
            if ($event['type'] === 'message.created' && (($event['payload']['kind'] ?? null)) === 'notice') {
                $rows[] = [(string) $event['payload']['content'], (array) $event['payload']];
            }
        }

        return $rows;
    }
}
