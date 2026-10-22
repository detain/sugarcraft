<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Message;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\Tools\ToolResult;

/**
 * OBS-1: the two Chat fold sites — the settled backend-tool queue and the
 * live event pump — hand every event the model applies to the sink, once,
 * in arrival order, generation-filtered exactly like the model's own arms.
 */
final class ChatEventFoldTest extends TestCase
{
    public function testThePumpFoldsTheHeadAndEveryCoalescedDeltaOfItsRun(): void
    {
        $recorder = self::recorder();
        $chat = self::chat($recorder, inbox: [
            [1, new TokenDelta('aa')],
            [1, new TokenDelta('bbb')],
            [1, new SpendCapBreached(1, 2.0, 1.0)],
        ]);

        $current = $chat;
        for ($i = 0; $i < 3; $i++) {
            [$current, $cmd] = $current->update(new ToolEventPumpMsg());
            if ($cmd === null || !$cmd() instanceof ToolEventPumpMsg) {
                break;
            }
        }

        $seen = array_map(static fn (object $e): string => $e::class, $recorder->seen);
        $this->assertSame(
            [TokenDelta::class, TokenDelta::class, SpendCapBreached::class],
            $seen,
            'both coalesced deltas fold individually — the model paints them merged, the sink counts them separately',
        );
        $this->assertSame('aa', $recorder->seen[0]->text);
        $this->assertSame('bbb', $recorder->seen[1]->text);
    }

    public function testEventsOfASupersededGenerationAreNotFolded(): void
    {
        $recorder = self::recorder();
        self::chat($recorder, inbox: [[2, new TokenDelta('stale')]])->update(new ToolEventPumpMsg());

        $this->assertSame([], $recorder->seen, 'the model drops the stale event; so does the sink');
    }

    public function testAPermissionSettlementIsFoldedAcrossGenerationsLikeTheArmsApplyIt(): void
    {
        $recorder = self::recorder();
        self::chat($recorder, inbox: [[2, PermissionResolved::cancelled('ask-1')]])->update(new ToolEventPumpMsg());

        $this->assertCount(1, $recorder->seen);
        $this->assertInstanceOf(PermissionResolved::class, $recorder->seen[0]);
    }

    public function testTheSettledQueueFoldsEachEventOnceInOrder(): void
    {
        $recorder = self::recorder();
        $chat = self::chat($recorder, inbox: []);
        $current = $chat;
        $next = new BackendToolEventsMsg(
            [new ToolStarted('c1', 'Read', ['path' => 'x']), new ToolFinished('c1', 'Read', ToolResult::denied('c1', DenialKind::Hook, 'x'))],
            Message::assistant('done'),
        );
        for ($i = 0; $i < 6 && $next !== null; $i++) {
            [$current, $cmd] = $current->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof BackendToolEventsMsg) {
                    $next = $produced;
                }
            }
        }

        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn (object $e): string => $e::class, $recorder->seen),
        );
    }

    public function testAnUnconfiguredChatDarklyCarriesTheNullSink(): void
    {
        $chat = new Chat(history: [Message::user('go')], backend: new EchoBackend());

        $property = new \ReflectionProperty(Chat::class, 'eventSink');
        $property->setAccessible(true);
        $this->assertInstanceOf(NullEventSink::class, $property->getValue($chat));
    }

    public function testTheSinkIsCarriedByIdentityAcrossClones(): void
    {
        $recorder = self::recorder();
        $chat = self::chat($recorder, inbox: []);
        [$afterKey] = $chat->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'x'));

        $property = new \ReflectionProperty(Chat::class, 'eventSink');
        $property->setAccessible(true);
        $this->assertSame($recorder, $property->getValue($afterKey), 'a half-full batch must survive a keystroke');
    }

    private static function chat(EventSink $sink, array $inbox): Chat
    {
        return new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            generation: 1,
            liveToolEvents: new \ArrayObject($inbox),
            eventSink: $sink,
        );
    }

    private static function recorder(): object
    {
        return new class() implements EventSink {
            /** @var list<object> */
            public array $seen = [];

            public bool $flushSeen = false;

            public function consume(object $event): void
            {
                $this->seen[] = $event;
            }

            public function flush(): void
            {
                $this->flushSeen = true;
            }
        };
    }
}
