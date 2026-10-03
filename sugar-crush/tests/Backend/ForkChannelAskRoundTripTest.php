<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\InteractiveTurn;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;

/**
 * Roadmap 1.C-1: an ASK raised inside a FORKED turn reaches the parent as a
 * {@see PermissionAsked}, and the answer the parent gives through its
 * {@see PendingAsk} reaches the blocked child — the two-way channel on the
 * turn's socketpair (Appendix O §5.1, decision D1).
 *
 * Before it, the only possible outcome of that ASK was the child's own
 * "no approver is attached" refusal, which
 * {@see testPlainCompleteAsyncStillSettlesTheAskInTheChild()} keeps pinned for
 * every caller that did not opt in.
 */
final class ForkChannelAskRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the channel lives on completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    public function testEngineBackendDeclaresTheInteractiveCapability(): void
    {
        self::assertInstanceOf(InteractiveTurn::class, InteractiveTurnHarness::backend(InteractiveTurnHarness::provider()));
    }

    public function testAnAnswerGivenWhileTheQuestionIsDeliveredLetsTheToolRun(): void
    {
        $events = [];
        $state = $this->runInteractive(
            InteractiveTurnHarness::provider(),
            static function (object $event) use (&$events): void {
                $events[] = $event;
                if ($event instanceof PermissionAsked) {
                    $event->ask->reply(PermissionReply::Once);
                }
            },
        );

        self::assertTrue($state['settled'], 'the turn never settled');
        self::assertNull($state['error']);
        self::assertSame('done', $state['value']->content);

        $asked = self::only($events, PermissionAsked::class);
        self::assertCount(1, $asked);
        $ask = $asked[0]->ask;
        self::assertSame(PendingAsk::askId('call_1', 'Edit', []), $ask->askId, 'the D1 askId is the hash of {toolCallId, tool, args}');
        self::assertSame('call_1', $ask->toolCallId);
        self::assertSame('Edit', $ask->tool);
        self::assertSame('gate', $ask->source);
        self::assertSame('default', $ask->mode);
        self::assertSame(['once', 'always', 'reject'], $ask->suggestions);
        self::assertSame(['tool' => 'Edit'], $ask->alwaysScope);
        self::assertStringContainsString('Edit', $ask->reason);

        $resolved = self::only($events, PermissionResolved::class);
        self::assertCount(1, $resolved);
        self::assertSame($ask->askId, $resolved[0]->askId);
        self::assertSame(PermissionReply::Once, $resolved[0]->reply);
        self::assertFalse($resolved[0]->cancelled);

        $finished = self::only($events, ToolFinished::class);
        self::assertCount(1, $finished);
        self::assertSame('ran', $finished[0]->result->content(), 'the child never ran the tool it was allowed to');
        self::assertFalse($finished[0]->result->isError());

        // The question sits in the turn's own order: asked and answered
        // before the call it is about finished.
        self::assertLessThan(
            array_search($finished[0], $events, true),
            array_search($resolved[0], $events, true),
        );
    }

    public function testAnAnswerFromALaterLoopTickReachesTheBlockedChild(): void
    {
        $loop = Loop::get();
        $events = [];
        $state = $this->runInteractive(
            InteractiveTurnHarness::provider(),
            static function (object $event) use (&$events, $loop): void {
                $events[] = $event;
                if ($event instanceof PermissionAsked) {
                    $ask = $event->ask;
                    $loop->addTimer(0.2, static function () use ($ask): void {
                        $ask->reply(PermissionReply::Once);
                    });
                }
            },
        );

        self::assertTrue($state['settled']);
        self::assertNull($state['error']);
        $finished = self::only($events, ToolFinished::class);
        self::assertCount(1, $finished);
        self::assertSame('ran', $finished[0]->result->content());
    }

    public function testARejectKeepsTheToolFromRunningAsARefusal(): void
    {
        $events = [];
        $state = $this->runInteractive(
            InteractiveTurnHarness::provider(),
            static function (object $event) use (&$events): void {
                $events[] = $event;
                if ($event instanceof PermissionAsked) {
                    $event->ask->reply(PermissionReply::Reject, 'not that file');
                }
            },
        );

        self::assertTrue($state['settled']);
        $finished = self::only($events, ToolFinished::class);
        self::assertCount(1, $finished);
        self::assertTrue($finished[0]->result->isError());
        self::assertNotSame('ran', $finished[0]->result->content());
        self::assertSame(DenialKind::Refused, $finished[0]->result->denial(), 'a reject is a decision, not an unanswered question');

        $resolved = self::only($events, PermissionResolved::class);
        self::assertSame('not that file', $resolved[0]->note);
    }

    public function testAlwaysAnswersTheSameCallForTheRestOfTheTurn(): void
    {
        $events = [];
        $state = $this->runInteractive(
            InteractiveTurnHarness::provider(2, ['path' => 'a.txt']),
            static function (object $event) use (&$events): void {
                $events[] = $event;
                if ($event instanceof PermissionAsked) {
                    $event->ask->reply(PermissionReply::Always);
                }
            },
        );

        self::assertTrue($state['settled']);
        self::assertCount(1, self::only($events, PermissionAsked::class), 'the second identical call was asked again');
        $finished = self::only($events, ToolFinished::class);
        self::assertCount(2, $finished);
        foreach ($finished as $event) {
            self::assertSame('ran', $event->result->content());
        }
    }

    public function testPlainCompleteAsyncStillSettlesTheAskInTheChild(): void
    {
        $events = [];
        $backend = InteractiveTurnHarness::backend(InteractiveTurnHarness::provider());
        $state = InteractiveTurnHarness::settle(
            $backend->completeAsync([Message::user('go')], null, null, static function (object $event) use (&$events): void {
                $events[] = $event;
            }),
            Loop::get(),
        );

        self::assertTrue($state['settled']);
        self::assertSame([], self::only($events, PermissionAsked::class), 'a caller that never opted in was handed a question');
        self::assertSame([], self::only($events, PermissionResolved::class));
        $finished = self::only($events, ToolFinished::class);
        self::assertCount(1, $finished);
        self::assertSame(DenialKind::Unanswered, $finished[0]->result->denial());
    }

    public function testInteractiveWithNoEventSinkIsPlainCompleteAsync(): void
    {
        $backend = InteractiveTurnHarness::backend(InteractiveTurnHarness::provider());
        $state = InteractiveTurnHarness::settle($backend->completeInteractive([Message::user('go')]), Loop::get());

        self::assertTrue($state['settled'], 'with nobody to ask, the child must not wait for an answer');
        self::assertSame('done', $state['value']->content);
    }

    public function testTheFrameVocabularyIsD1(): void
    {
        self::assertSame(['ask', 'steer_ack', 'usage', 'step'], ChildChannel::TO_PARENT);
        self::assertSame(['ask_reply', 'steer', 'cancel_soft', 'cancel_tool'], ChildChannel::TO_CHILD);
    }

    /**
     * The pcntl-less fallback: no child to block, so only an answer given
     * DURING the delivering `$onEvent` call can count.
     */
    public function testTheBlockingFallbackHearsASynchronousAnswer(): void
    {
        $events = [];
        $value = $this->runBlocking(static function (object $event) use (&$events): void {
            $events[] = $event;
            if ($event instanceof PermissionAsked) {
                $event->ask->reply(PermissionReply::Once);
            }
        });

        self::assertSame('done', $value->content);
        $finished = self::only($events, ToolFinished::class);
        self::assertSame('ran', $finished[0]->result->content());
        self::assertCount(1, self::only($events, PermissionResolved::class));
    }

    public function testTheBlockingFallbackSettlesAnOpenQuestionCancelledAndRefuses(): void
    {
        $events = [];
        $kept = null;
        $this->runBlocking(static function (object $event) use (&$events, &$kept): void {
            $events[] = $event;
            if ($event instanceof PermissionAsked) {
                $kept = $event->ask;
            }
        });

        $resolved = self::only($events, PermissionResolved::class);
        self::assertCount(1, $resolved);
        self::assertTrue($resolved[0]->cancelled);
        self::assertInstanceOf(PendingAsk::class, $kept);
        self::assertFalse($kept->reply(PermissionReply::Once), 'a late answer on the blocking path must be a no-op');
        $finished = self::only($events, ToolFinished::class);
        self::assertTrue($finished[0]->result->isError());
        self::assertNotSame('ran', $finished[0]->result->content());
    }

    /**
     * @return array{settled: bool, value: mixed, error: ?\Throwable}
     */
    private function runInteractive(\SugarCraft\Crush\Tests\Support\ScriptedProvider $provider, \Closure $onEvent): array
    {
        $backend = InteractiveTurnHarness::backend($provider);

        return InteractiveTurnHarness::settle(
            $backend->completeInteractive([Message::user('go')], null, null, $onEvent),
            Loop::get(),
        );
    }

    private function runBlocking(\Closure $onEvent): Message
    {
        $backend = InteractiveTurnHarness::backend(InteractiveTurnHarness::provider());
        $deferred = new Deferred();
        $method = new \ReflectionMethod(EngineBackend::class, 'completeAsyncBlocking');
        $value = null;
        $method->invoke($backend, [Message::user('go')], null, $deferred, $onEvent, null, true)
            ->then(static function (Message $m) use (&$value): void {
                $value = $m;
            });
        self::assertInstanceOf(Message::class, $value, 'the blocking fallback settles before it returns');

        return $value;
    }

    /**
     * @template T of object
     * @param list<object>    $events
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function only(array $events, string $class): array
    {
        return array_values(array_filter($events, static fn(object $e): bool => $e instanceof $class));
    }
}
