<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\ProcessTreeKillTest;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResultsMsg;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit F-E2, the dormant Chat kill site ({@see Chat}'s
 * `waitForToolChildrenAsync()`): an Esc-Esc cancel (or the parallel deadline)
 * SIGKILLed the forked PHP child only. Bash runs its command setsid'd in its
 * own process group, so the command was reparented to init and ran to
 * completion. The engine half was fixed in `c54372b2a`
 * (`ProcessContainment::killTree()`); this is the Chat half.
 */
final class ChatToolCancelKillsCommandTreeTest extends TestCase
{
    /** @var list<int> */
    private array $strays = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Chat tool fan-out requires ext-pcntl and ext-posix.');
        }
        if (!ProcessTree::available() || ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('the tree walk needs /proc and the orphan needs a setsid-detached command.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $pid) {
            @\posix_kill($pid, 9);
        }

        parent::tearDown();
    }

    public function testCancellingTheTurnKillsTheRunningCommand(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $chat = (new Chat())->registerTool(
            'slow',
            static fn(array $args): string => (new Bash())->execute(['command' => $marker])->content(),
        );

        [$running, $cmd] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new ToolCall('slow', [], 'call_1')]),
        ));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        // The command must actually be up before the cancel, or the test
        // proves nothing about what the cancel does to it.
        $up = \microtime(true) + 5.0;
        while (ProcessTreeKillTest::pidsRunning($marker) === [] && \microtime(true) < $up) {
            \usleep(20_000);
        }
        $this->assertNotSame([], ProcessTreeKillTest::pidsRunning($marker), 'fixture: the command never started');

        // Esc-Esc: the user cancels the turn.
        [$once] = $running->update(new KeyMsg(KeyType::Escape));
        [$cancelled] = $once->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($cancelled->inFlight, 'fixture: the double Escape cancelled the turn');

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });
        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }
        $this->assertInstanceOf(ToolResultsMsg::class, $resolved, 'the cancelled batch never settled');

        $deadline = \microtime(true) + 2.0;
        do {
            $survivors = ProcessTreeKillTest::pidsRunning($marker);
            \usleep(20_000);
        } while ($survivors !== [] && \microtime(true) < $deadline);
        \array_push($this->strays, ...$survivors);

        self::assertSame([], $survivors, "a cancelled Chat tool call's command outlived it");
    }
}
