<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;

/**
 * Submit a `/workflow run` and drive it to its reply.
 *
 * `Chat::workflowRun()` hands the run to a `\Fiber` that a periodic timer on
 * the ReactPHP loop steps, so `update()` returns the echoed command and a Cmd,
 * and the report arrives later as an {@see AssistantMsg}. Every test that used
 * to read `history[1]` straight off the `update()` return has to run the loop
 * now — which is the behaviour change, not an inconvenience of testing it.
 *
 * @see \SugarCraft\Crush\Chat::driveWorkflowFiber()
 */
trait DrivesWorkflowRunsTrait
{
    /**
     * Submit $command to $chat and return [$chatAfterSubmit, $cmd] WITHOUT
     * running the workflow — the state the user sees on the tick they pressed
     * Enter.
     *
     * @return array{0: Chat, 1: \Closure}
     */
    private function submitWorkflowCommand(Chat $chat): array
    {
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertInstanceOf(
            \Closure::class,
            $cmd,
            'A /workflow run must return a Cmd; a null Cmd means it ran inline and froze update().',
        );

        return [$next, $cmd];
    }

    /**
     * Run the loop until $cmd's promise settles, and return the Msg.
     *
     * One `run()`/`stop()` pair with a safety timer, matching
     * `ChatTest::runToolCallsToCompletion()` — a poll-with-short-timers dance
     * is fragile against real fork and WNOHANG timing.
     */
    private function settleWorkflowCmd(\Closure $cmd, float $timeoutSeconds = 20.0): Msg
    {
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);

        $loop = Loop::get();
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer($timeoutSeconds, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        self::assertInstanceOf(
            AssistantMsg::class,
            $resolved,
            'The workflow did not settle within the test timeout.',
        );

        return $resolved;
    }

    /**
     * The whole round trip: submit, run the loop, apply the reply, and return
     * the assistant's report text.
     */
    private function runWorkflowCommandToReply(Chat $chat): string
    {
        [$next, $cmd] = $this->submitWorkflowCommand($chat);
        [$after] = $next->update($this->settleWorkflowCmd($cmd));

        self::assertCount(2, $after->history, 'the command must produce exactly one reply');

        return $after->history[1]->content;
    }
}
