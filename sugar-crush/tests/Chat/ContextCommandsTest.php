<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 3.B-2 through the TUI: `/sweep` and `/pruning` are dispatched from
 * the composer, answer with UI-only rows, call no model, and change the
 * SESSION's ledger — the one its turn runner hands the next turn, and the one
 * a persisting session stores.
 */
final class ContextCommandsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string|false $env = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-ctx-cmds-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->env = getenv(PruningMode::ENV);
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->env === false ? putenv(PruningMode::ENV) : putenv(PruningMode::ENV . '=' . $this->env);
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testSweepPrunesTheLastTurnsOutputsForTheNextTurn(): void
    {
        $history = self::history();
        [$next, $cmd] = $this->type(new Chat(history: $history), '/sweep');

        $this->assertNull($cmd, '/sweep calls no model');
        $added = \array_slice($next->history, \count(self::history()));
        $this->assertCount(2, $added);
        $this->assertTrue($added[0]->uiOnly && $added[1]->uiOnly);
        $this->assertStringStartsWith('Swept 1 tool output', $added[1]->content);

        $ledger = self::ledgerOf($next);
        $this->assertSame(PruneReason::Swept, $ledger->prune('c1')?->reason, 'the next turn starts from the sweep');
        $this->assertSame($history, \array_slice($next->history, 0, \count($history)), 'the transcript is untouched');
    }

    public function testPruningSetsTheSessionsModeAndSweepThenRefuses(): void
    {
        [$next] = $this->type(new Chat(history: self::history()), '/pruning off');

        $this->assertSame(PruningMode::Off, self::ledgerOf($next)->mode);
        $this->assertStringStartsWith('Context pruning: off', $next->history[array_key_last($next->history)]->content);

        [$after] = $this->type($next, '/sweep');
        $this->assertStringStartsWith('Context pruning is off for this session', $after->history[array_key_last($after->history)]->content);
        $this->assertFalse(self::ledgerOf($after)->isPruned('c1'));
    }

    public function testAPersistingSessionStoresTheSweep(): void
    {
        mkdir($this->sandbox . '/db', 0o700, true);
        $store = new EnhancedSessionStore($this->sandbox . '/db/session.db');
        $store->createSession('s', 'p', 'm');
        $chat = new Chat(history: self::history(), sessionStore: $store, currentSessionId: 's');

        $this->type($chat, '/sweep');

        $this->assertTrue($store->loadContextLedger('s')?->isPruned('c1'), 'stored beside the transcript, so a resume keeps it');
    }

    /** @return array{0: Chat, 1: mixed} */
    private function type(Chat $chat, string $draft): array
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat->withSize(120, 30), ['inputBuf' => $draft]);

        return $drafted->update(new KeyMsg(KeyType::Enter));
    }

    private static function ledgerOf(Chat $chat): ContextLedger
    {
        return (new \ReflectionMethod($chat, 'sessionContextLedger'))->invoke($chat);
    }

    /** @return list<Message> */
    private static function history(): array
    {
        $output = str_repeat('a long line of output ', 100);

        return [
            Message::user('read it'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'c1')])->withStepId('s_a_1')->withUserVisible(false),
            Message::assistant($output)->withToolResults([new ToolResult('Read', $output, null, 'c1')])->withStepId('s_a_1'),
            Message::assistant('read it')->withStepId('s_a_2'),
        ];
    }
}
