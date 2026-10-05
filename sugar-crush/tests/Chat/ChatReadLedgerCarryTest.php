<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\ToolResultsMsg;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\ReadLedger;

/**
 * Roadmap 3.I-2 remainder: the Chat-native tool path carries the read ledger
 * home from its per-call child. Each {@see Chat::registerTool()} callback runs
 * in a forked child ({@see Chat::forkToolCalls()}), so a `Read` there records
 * into the child's copy of the ledger only; with the ledger named through
 * {@see Chat::withReadLedger()} the child's result payload carries what it
 * recorded and the parent's collector merges it — the next call, forked from
 * the parent, then refuses an `Edit` of a file that changed since that read.
 *
 * Driven through the live route (update → AsyncCmd → loop), because the claim
 * is about what crosses the real fork boundary.
 */
final class ChatReadLedgerCarryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('the carry is across a pcntl_fork() child');
        }
        $this->dir = sys_get_temp_dir() . '/crush-chat-ledger-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAReadInAForkedCallReachesTheParentsLedger(): void
    {
        $path = $this->file('a.txt', "alpha\nbeta\n");
        $ledger = ReadLedger::new();
        $chat = $this->chatWithTools($ledger)->withReadLedger($ledger);

        $result = $this->runCall($chat, new ToolCall('read', ['file_path' => 'a.txt']));

        $this->assertFalse($result->isError());
        $this->assertTrue($ledger->has($path), 'the child recorded the read, and the parent merged it');
        $this->assertSame(hash('xxh128', "alpha\nbeta\n"), $ledger->toArray()[$path]['hash']);
    }

    public function testTheNextCallsEditOfAFileChangedSinceThatReadIsRefused(): void
    {
        $path = $this->file('a.txt', "alpha\nbeta\n");
        $ledger = ReadLedger::new();
        $chat = $this->chatWithTools($ledger)->withReadLedger($ledger);

        $this->runCall($chat, new ToolCall('read', ['file_path' => 'a.txt']));
        file_put_contents($path, "alpha\nbeta\ngamma\n");
        $refused = $this->runCall($chat, new ToolCall('edit', ['file_path' => 'a.txt', 'old_string' => 'beta', 'new_string' => 'BETA']));

        $this->assertStringContainsString('changed on disk since you last read it', $refused->result);
        $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($path), 'the stale edit left the file untouched');
    }

    public function testWithoutANamedLedgerTheChildsReadDiesWithIt(): void
    {
        $path = $this->file('a.txt', "alpha\n");
        $ledger = ReadLedger::new();
        $chat = $this->chatWithTools($ledger);

        $this->runCall($chat, new ToolCall('read', ['file_path' => 'a.txt']));

        $this->assertNull($chat->readLedger());
        $this->assertFalse($ledger->has($path), 'the known-negative: without withReadLedger() nothing crosses the fork');
    }

    public function testTheBindingOutlivesLaterClonesOfAWorkspacelessChat(): void
    {
        $ledger = ReadLedger::new();
        $chat = (new Chat())->withReadLedger($ledger);

        $this->assertSame($ledger, $chat->readLedger());
        $this->assertSame($ledger, $chat->registerTool('noop', static fn (): string => '')->readLedger());
        $this->assertNull((new Chat())->readLedger(), 'another conversation does not see it');
    }

    public function testAWorkspaceChatRegistersItAsAWorkspaceService(): void
    {
        $ledger = ReadLedger::new();
        $chat = (new Chat(workspace: WorkspaceContext::new(root: $this->dir)))->withReadLedger($ledger);

        $this->assertSame($ledger, $chat->workspace()?->service(ReadLedger::class));
        $this->assertSame($ledger, $chat->readLedger());
    }

    public function testRecordedSinceIsOnlyTheNewAndReRecordedRows(): void
    {
        $a = $this->file('a.txt', "a\n");
        $b = $this->file('b.txt', "b\n");
        $ledger = ReadLedger::new();
        $ledger->record($a);
        $before = $ledger->toArray();
        $ledger->record($b);

        $this->assertSame([$b], array_keys($ledger->recordedSince($before)));
        $this->assertSame([$a, $b], array_keys($ledger->recordedSince([])));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function chatWithTools(ReadLedger $ledger): Chat
    {
        $read = new Read($this->dir, readLedger: $ledger);
        $edit = new Edit($this->dir, readLedger: $ledger);

        return (new Chat(history: [Message::user('go')], inFlight: true))
            ->registerTool('read', static fn (array $args): string => $read->execute($args)->content())
            ->registerTool('edit', static fn (array $args): string => $edit->execute($args)->content());
    }

    private function runCall(Chat $chat, ToolCall $toolCall): ToolResult
    {
        $message = Message::assistant('calling')->withToolCalls([$toolCall]);
        [$afterPlaceholders, $cmd] = $chat->update(new AssistantMsg($message));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(static function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });
        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($safety);
        }
        $this->assertInstanceOf(ToolResultsMsg::class, $resolved, 'the tool call did not settle');

        [$final] = $afterPlaceholders->update($resolved);

        return $final->history[2]->toolResults[0];
    }

    private function file(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
