<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\ToolCall;

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
            \file_put_contents($marks, 'P ' . \getmypid() . "\n", FILE_APPEND);
        });

        // The first answer runs in the child, before the ask, and arms a timer on
        // the child's own loop. That timer can fire only if the turn suspended on
        // the loop during the ask; a turn that blocked on the socket never runs it.
        $provider = new ScriptedProvider([
            static function () use ($marks): CompleteResponse {
                Loop::get()->addPeriodicTimer(0.001, static function () use ($marks): void {
                    \file_put_contents($marks, 'C ' . \getmypid() . "\n", FILE_APPEND);
                });

                return new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Edit', [])]);
            },
            new CompleteResponse(content: 'done'),
        ]);

        $events = [];
        $backend = InteractiveTurnHarness::backend($provider);
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

        $lines = \array_values(\array_filter(\explode("\n", (string) \file_get_contents($marks)), static fn (string $line): bool => $line !== ''));
        $inherited = self::pidsTagged($lines, 'P ');
        $own = self::pidsTagged($lines, 'C ');
        $parent = (string) \getmypid();
        self::assertNotEmpty(
            \array_filter($inherited, static fn (string $pid): bool => $pid === $parent),
            'fixture: the parent timer never fired, so the proof is vacuous',
        );
        $foreign = \array_values(\array_unique(\array_filter($inherited, static fn (string $pid): bool => $pid !== $parent)));
        self::assertSame(
            [],
            $foreign,
            'the forked turn child fired a timer it inherited from the parent (pids: ' . \implode(', ', $foreign) . ')',
        );
        $childPids = \array_values(\array_unique(\array_filter($own, static fn (string $pid): bool => $pid !== $parent)));
        self::assertNotEmpty(
            $childPids,
            'the child never ran its own loop during the ask, so it did not suspend on the loop',
        );
    }

    /**
     * The pids written after one tag, e.g. `P 123` gives `123` for tag `P `.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function pidsTagged(array $lines, string $tag): array
    {
        $pids = [];
        foreach ($lines as $line) {
            if (\str_starts_with($line, $tag)) {
                $pids[] = \substr($line, \strlen($tag));
            }
        }

        return $pids;
    }
}
