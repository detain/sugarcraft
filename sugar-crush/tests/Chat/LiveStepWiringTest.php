<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 1.C-4, the Chat half: an engine turn's `step` / `usage` frames
 * reach the live inbox, the pump keeps the latest of each for the turn on
 * screen, and with them the first Escape becomes a soft stop that a real
 * forked turn obeys at its next step boundary.
 */
final class LiveStepWiringTest extends TestCase
{
    private const GENERATION = 7;

    public function testThePumpKeepsTheLatestStepAndBillOfTheTurnOnScreen(): void
    {
        $inbox = new \ArrayObject();
        $chat = new Chat(history: [Message::user('go')], backend: new EchoBackend(), inFlight: true, generation: self::GENERATION, liveToolEvents: $inbox);

        $inbox[] = [self::GENERATION, new StepStarted(1, 50)];
        $inbox[] = [self::GENERATION, new UsageUpdated(1, Usage::new(10, 0.01), Usage::new(10, 0.01))];
        $inbox[] = [self::GENERATION, new StepStarted(2, 50)];
        $inbox[] = [self::GENERATION - 1, new StepStarted(9, 50)];
        for ($i = 0; $i < 4; $i++) {
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        self::assertSame(2, $chat->liveStep()?->step, 'the newest step of THIS turn; a stale turn\'s is dropped');
        self::assertSame(1, $chat->liveUsage()?->step, 'the bill stays with the step it was for');
    }

    public function testANewTurnsFirstEventClearsTheOldTurnsOther(): void
    {
        $inbox = new \ArrayObject();
        $chat = new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            generation: self::GENERATION,
            liveToolEvents: $inbox,
            liveUsage: new UsageUpdated(4, null, Usage::new(1, 9.0)),
            liveStepGeneration: self::GENERATION - 1,
        );

        $inbox[] = [self::GENERATION, new StepStarted(1, 50)];
        [$chat] = $chat->update(new ToolEventPumpMsg());

        self::assertSame(1, $chat->liveStep()?->step);
        self::assertNull($chat->liveUsage(), 'the earlier turn\'s spend is not this turn\'s');
    }

    public function testTheFirstEscapeStopsARealForkedTurnAfterItsStep(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the step frames cross completeAsync()\'s fork, which needs ext-pcntl.');
        }

        $chat = new Chat(inputBuf: 'go', backend: self::engine(), liveToolEvents: new \ArrayObject());
        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $resolved = $this->start($cmd);

        $stepping = $this->pumpUntil($running, static fn (Chat $c): bool => $c->liveStep() !== null);
        self::assertSame(1, $stepping->liveStep()?->step, 'the turn\'s first step never reached the Chat');

        [$stopping] = $stepping->update(new KeyMsg(KeyType::Escape, ''));
        self::assertTrue($stopping->inFlight, 'a soft stop lets the step finish');
        self::assertTrue($stopping->stopRequested());

        $this->runUntil(static fn (): bool => $resolved->msg !== null);
        self::assertNotNull($resolved->msg, 'the turn never settled after the soft stop');

        $done = $this->apply($stopping, $resolved->msg);
        self::assertFalse($done->inFlight);
        $contents = array_map(static fn (Message $m): string => $m->content, $done->history);
        self::assertContains('step 1', $contents, 'the stopped turn keeps its reply');
        self::assertNotContains('SUMMARY', $contents, 'a requested stop asks for no summary');
        self::assertNull($done->liveStep());
    }

    private static function engine(): EngineBackend
    {
        $step = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                if ($request->tools === null) {
                    return new CompleteResponse(content: 'SUMMARY');
                }
                $step++;

                return new CompleteResponse(content: "step {$step}", toolCalls: [new ToolCall("c{$step}", 'slow', [])]);
            },
        ]);

        return EngineBackend::new($provider, 'm')->withTools([self::slowTool()])->withMaxSteps(20);
    }

    private static function slowTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'slow'; }

            public function description(): string { return 'takes a while'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                \usleep(800_000);

                return new ToolResult(toolCallId: '', content: 'slow ok');
            }
        };
    }

    /** Start the turn's Cmd and capture the Msg its promise settles to. */
    private function start(?\Closure $cmd): \stdClass
    {
        self::assertNotNull($cmd);
        $box = new \stdClass();
        $box->msg = null;
        $pending = [$cmd];
        $found = false;
        while (($next = array_shift($pending)) !== null) {
            $out = $next();
            if ($out instanceof BatchMsg) {
                array_push($pending, ...$out->cmds);
            } elseif ($out instanceof AsyncCmd) {
                $found = true;
                $out->promise->then(static function (mixed $msg) use ($box): void {
                    $box->msg = $msg;
                });
            }
        }
        self::assertTrue($found, 'submitting started no async turn');

        return $box;
    }

    private function pumpUntil(Chat $chat, \Closure $done, float $seconds = 15.0): Chat
    {
        $deadline = microtime(true) + $seconds;
        while (!$done($chat) && microtime(true) < $deadline) {
            $this->tick();
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        return $chat;
    }

    private function runUntil(\Closure $done, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$done() && microtime(true) < $deadline) {
            $this->tick();
        }
    }

    private function tick(): void
    {
        $loop = Loop::get();
        $timer = $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($timer);
    }

    /** Fold a settled turn's Msg the way Program::runCmd() does. */
    private function apply(Chat $chat, Msg $msg): Chat
    {
        $next = $msg;
        for ($i = 0; $i < 32 && $next !== null; $i++) {
            [$chat, $cmd] = $chat->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof Msg && !$produced instanceof AsyncCmd && !$produced instanceof BatchMsg) {
                    $next = $produced;
                }
            }
        }

        return $chat;
    }
}
