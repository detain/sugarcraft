<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tests\Backend\Support\InteractiveTurnHarness;

/**
 * P4-12 CC-1 e0 review: a forked turn child runs its turn on the loop, and it
 * must run that loop alone. The fork copies the parent's loop with its timers
 * and read streams, so a child that kept the copy would fire the parent's
 * callbacks while it waits for an answer, and read the parent's keystrokes.
 *
 * The parent arms a fast periodic timer on the loop before it forks and
 * records the pid of every process that fires it. Each record is a pid, so
 * the child can be told from the parent. The parent holds the question open
 * for a while so the child really suspends on the loop.
 */
final class ForkedTurnOwnsItsLoopTest extends TestCase
{
    private ?string $marks = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the turn child lives on completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->marks !== null && \is_file($this->marks)) {
            \unlink($this->marks);
        }
        parent::tearDown();
    }

    public function testAForkedTurnThatSuspendsOnAnAskDoesNotFireTheParentsTimers(): void
    {
        $this->marks = \tempnam(\sys_get_temp_dir(), 'turn-loop-');
        self::assertIsString($this->marks, 'fixture: no temporary file for the pid marks');
        $marks = $this->marks;

        $loop = Loop::get();
        // Armed before the fork, so the child inherits it in its copy of the loop.
        $tick = $loop->addPeriodicTimer(0.001, static function () use ($marks): void {
            \file_put_contents($marks, \getmypid() . "\n", FILE_APPEND);
        });

        $events = [];
        $backend = InteractiveTurnHarness::backend(InteractiveTurnHarness::provider());
        try {
            $state = InteractiveTurnHarness::settle(
                $backend->completeInteractive(
                    [Message::user('go')],
                    null,
                    null,
                    static function (object $event) use (&$events, $loop): void {
                        $events[] = $event;
                        if ($event instanceof PermissionAsked) {
                            // Armed on delivery: the fork takes longer than any fixed
                            // delay, so a timer armed up front fires before the question.
                            $ask = $event->ask;
                            $loop->addTimer(0.1, static function () use ($ask): void {
                                $ask->reply(PermissionReply::Once);
                            });
                        }
                    },
                ),
                $loop,
            );
        } finally {
            // The shared loop outlives this test; a timer left armed fails the run.
            $loop->cancelTimer($tick);
        }

        self::assertTrue($state['settled'], 'the turn never settled');
        self::assertNull($state['error']);
        self::assertSame('done', $state['value']->content);
        $asked = \array_filter($events, static fn (object $e): bool => $e instanceof PermissionAsked);
        self::assertCount(1, $asked, 'the child never suspended on a question, so the proof is vacuous');
        $finished = \array_values(\array_filter($events, static fn (object $e): bool => $e instanceof ToolFinished));
        self::assertCount(1, $finished);
        self::assertSame('ran', $finished[0]->result->content());

        $pids = \array_values(\array_filter(\explode("\n", (string) \file_get_contents($marks)), static fn (string $line): bool => $line !== ''));
        self::assertNotEmpty($pids, 'fixture: the parent timer never fired, so the proof is vacuous');
        $parent = (string) \getmypid();
        $foreign = \array_values(\array_unique(\array_filter($pids, static fn (string $pid): bool => $pid !== $parent)));
        self::assertSame(
            [],
            $foreign,
            'the forked turn child fired a timer it inherited from the parent (pids: ' . \implode(', ', $foreign) . ')',
        );
    }
}
