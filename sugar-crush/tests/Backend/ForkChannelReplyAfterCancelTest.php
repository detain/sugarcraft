<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;

/**
 * Roadmap 1.C-1: a turn torn down while a question is open settles that
 * question `cancelled` — reported, so a receiver can take its prompt down —
 * and an answer given afterwards (the user pressing `y` on a modal the cancel
 * raced) is a harmless no-op: nothing is written to the closed socket and
 * nothing throws into the UI (Appendix O §5.1 parent side, step 4).
 */
final class ForkChannelReplyAfterCancelTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks (and only tears a turn down) with ext-pcntl + ext-posix.');
        }
    }

    public function testCancellingWithAQuestionOpenSettlesItAndALateAnswerIsANoOp(): void
    {
        $tracked = new \ReflectionProperty(EngineBackend::class, 'unreapedChildren');
        $before = \array_keys($tracked->getValue());

        $cancellation = new CancellationToken();
        $events = [];
        /** @var ?PendingAsk $open */
        $open = null;
        $promise = InteractiveTurnHarness::backend(InteractiveTurnHarness::provider())->completeInteractive(
            [Message::user('go')],
            null,
            $cancellation,
            static function (object $event) use (&$events, &$open, $cancellation): void {
                $events[] = $event;
                if ($event instanceof PermissionAsked) {
                    $open = $event->ask;
                    $cancellation->cancel();
                }
            },
        );
        $turnChild = \array_values(\array_diff(\array_keys($tracked->getValue()), $before))[0] ?? null;

        $state = InteractiveTurnHarness::settle($promise, Loop::get());

        self::assertTrue($state['settled'], 'the cancelled turn never settled');
        self::assertInstanceOf(\RuntimeException::class, $state['error']);
        self::assertStringContainsString('cancelled', $state['error']->getMessage());

        self::assertInstanceOf(PendingAsk::class, $open, 'fixture: the question never arrived');
        self::assertTrue($open->isSettled());
        $resolved = \array_values(\array_filter($events, static fn(object $e): bool => $e instanceof PermissionResolved));
        self::assertCount(1, $resolved, 'the open question was not reported settled');
        self::assertTrue($resolved[0]->cancelled);
        self::assertSame($open->askId, $resolved[0]->askId);
        self::assertSame('Request cancelled', $resolved[0]->note);
        self::assertSame([], \array_filter($events, static fn(object $e): bool => $e instanceof ToolFinished), 'the tool ran after all');

        $count = \count($events);
        self::assertFalse($open->reply(PermissionReply::Once), 'a reply after the cancel was accepted');
        self::assertFalse($open->cancel());
        self::assertCount($count, $events, 'a late reply emitted another event');
        self::assertTrue($resolved[0]->cancelled, 'a late reply rewrote the settlement');

        if (\is_int($turnChild)) {
            self::assertArrayNotHasKey($turnChild, $tracked->getValue(), 'the cancelled turn child was never reaped');
        }
    }
}
