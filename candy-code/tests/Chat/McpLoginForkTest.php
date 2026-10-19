<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\McpLoginProgressMsg;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\McpLoginFork;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap MCP-5: in-TUI `/mcp login <server>` forks the CLI's loopback
 * login as a background child. The fork owns the pipes; Chat owns only the
 * transcript beats. The child is stubbed through the argv seam so the whole
 * announce/poll/exit chain runs without OAuth.
 */
final class McpLoginForkTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-mcplogin-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        mkdir($this->sandbox . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        McpLoginFork::releaseAll();
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * A child that prints an authorization URL, pauses briefly, exits clean.
     *
     */
    private function stubChild(string $body, int $holdMicros = 0): void
    {
        McpLoginFork::$argvOverride = static fn (string $server, array $rest): array => [
            \PHP_BINARY, '-r', $body . ($holdMicros > 0 ? 'usleep(' . $holdMicros . ');' : ''),
        ];
    }

    /**
     * @param callable(McpLoginProgressMsg): bool $matches
     */
    private function pollFor(string $server, callable $matches, int $tries = 100): McpLoginProgressMsg
    {
        for ($i = 0; $i < $tries; ++$i) {
            $msg = McpLoginFork::poll($server);
            if ($msg instanceof McpLoginProgressMsg && $matches($msg)) {
                return $msg;
            }
            self::assertNotSame(McpLoginProgressMsg::PHASE_GONE, $msg?->phase, 'slot vanished mid-poll');
            usleep(50_000);
        }
        self::fail('no matching beat within ' . ($tries * 50) . 'ms');
    }

    public function testArmThenUrlThenDoneThenGoneOverAStubbedChild(): void
    {
        $this->stubChild('echo "  Open this URL to authorize:\n\n    http://127.0.0.1:41239/cb/code-abc\n";');

        [$notice, $armed] = McpLoginFork::arm('w4cstub', [], $this->sandbox . '/tmp');
        self::assertTrue($armed, $notice);
        self::assertStringContainsString('w4cstub', $notice);
        self::assertTrue(McpLoginFork::isArmed('w4cstub'));

        $url = $this->pollFor('w4cstub', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_URL);
        self::assertSame('http://127.0.0.1:41239/cb/code-abc', $url->text);

        $done = $this->pollFor('w4cstub', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_DONE);
        self::assertSame(0, $done->exitCode);
        self::assertStringContainsString('http://127.0.0.1:41239', $done->text, 'the tail carries what the child printed');
        self::assertFalse(McpLoginFork::isArmed('w4cstub'), 'the slot is released on exit');

        $gone = McpLoginFork::poll('w4cstub');
        self::assertInstanceOf(McpLoginProgressMsg::class, $gone);
        self::assertSame(McpLoginProgressMsg::PHASE_GONE, $gone?->phase);
    }

    public function testUrlIsAnnouncedOnceNotOnEveryBeat(): void
    {
        $this->stubChild('echo "http://127.0.0.1:1/x\n"; usleep(200000);');

        [$notice, $armed] = McpLoginFork::arm('w4conce', []);
        self::assertTrue($armed, $notice);

        $sawUrl = 0;
        for ($i = 0; $i < 60; ++$i) {
            $msg = McpLoginFork::poll('w4conce');
            if ($msg instanceof McpLoginProgressMsg && $msg->phase === McpLoginProgressMsg::PHASE_URL) {
                ++$sawUrl;
            }
            if ($msg instanceof McpLoginProgressMsg && $msg->phase === McpLoginProgressMsg::PHASE_DONE) {
                break;
            }
            usleep(20_000);
        }
        self::assertSame(1, $sawUrl, 'the URL beat fires exactly once per slot');
    }

    public function testASecondLoginForTheSameServerIsRefusedWhileTheFirstLives(): void
    {
        $this->stubChild('usleep(1500000);');

        [, $armed] = McpLoginFork::arm('w4cbusy', []);
        self::assertTrue($armed);

        [$second, $armedAgain] = McpLoginFork::arm('w4cbusy', []);
        self::assertFalse($armedAgain, 'a second slot must not open');
        self::assertStringContainsString('already running', $second);

        // A different server is independent.
        [, $otherArmed] = McpLoginFork::arm('w4cother', []);
        self::assertTrue($otherArmed);
    }

    public function testFailingExitCodeRidesTheDoneBeat(): void
    {
        $this->stubChild('fwrite(STDERR, "✗ Login failed: nope\n"); exit(3);');

        [, $armed] = McpLoginFork::arm('w4cfail', []);
        self::assertTrue($armed);

        $done = $this->pollFor('w4cfail', static fn (McpLoginProgressMsg $m): bool => $m->phase === McpLoginProgressMsg::PHASE_DONE);
        self::assertSame(3, $done->exitCode);
        self::assertStringContainsString('Login failed', $done->text, 'stderr drains into the tail too');
    }

    public function testSlashMcpLoginWithAServerArmsThroughChat(): void
    {
        $this->stubChild('usleep(1500000);');
        $chat = (new Chat(inputBuf: '/mcp login srvA', backend: new EchoBackend()))->withSize(100, 30);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($cmd, 'the poll Cmd must ride the return channel');
        self::assertTrue(McpLoginFork::isArmed('srvA'));
        $rows = \array_slice($next->history, -2);
        self::assertSame('/mcp login srvA', $rows[0]->content, 'the draft echoes as a UI-only user row');
        self::assertStringContainsString('srvA', $rows[1]->content);
    }

    public function testBeatsThroughChatAppendRowsAndStopOnDone(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login srvB', backend: new EchoBackend()))->withSize(100, 30);
        $waiting = $chat->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_WAITING));
        self::assertNotNull($waiting[1], 'a waiting beat re-arms silently');
        self::assertCount(\count($chat->history), $waiting[0]->history, 'waiting paints nothing');

        $url = $waiting[0]->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_URL, 'http://127.0.0.1:9/z'));
        self::assertNotNull($url[1], 'the URL beat re-arms to keep watching');
        $row = $url[0]->history[\count($url[0]->history) - 1];
        self::assertStringContainsString('http://127.0.0.1:9/z', $row->content);

        $done = $url[0]->update(new McpLoginProgressMsg('srvB', McpLoginProgressMsg::PHASE_DONE, '', 0));
        self::assertNull($done[1], 'done ends the chain');
        $closed = $done[0]->history[\count($done[0]->history) - 1];
        self::assertStringContainsString('NEXT launch', $closed->content, 'the honesty sentence rides the close row');
    }

    public function testFailedBeatNamesTheCodeAndGoneStopsSilently(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login srvC', backend: new EchoBackend()))->withSize(100, 30);
        $failed = $chat->update(new McpLoginProgressMsg('srvC', McpLoginProgressMsg::PHASE_DONE, 'tail', 7));
        self::assertNull($failed[1]);
        $row = $failed[0]->history[\count($failed[0]->history) - 1];
        self::assertStringContainsString('7', $row->content);

        $before = \count($chat->history);
        $gone = $chat->update(new McpLoginProgressMsg('srvC', McpLoginProgressMsg::PHASE_GONE));
        self::assertNull($gone[1]);
        self::assertCount($before, $gone[0]->history, 'a gone beat paints nothing');
    }

    public function testBareLoginStillRoutesToTheHostGuidance(): void
    {
        $chat = (new Chat(inputBuf: '/mcp login', backend: new EchoBackend()))->withSize(100, 30);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertFalse(McpLoginFork::isArmed('login'), 'no server named — nothing forks');
        self::assertNull($cmd, 'the guide is a terminal answer, not a poll chain');
        $joined = \implode("\n", \array_map(static fn (Message $m): string => $m->content, $next->history));
        self::assertStringContainsString('candy-code mcp auth login <server>', $joined, 'the bare form still routes to the host guidance rows');
    }
}
