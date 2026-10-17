<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Code\Tools\ToolCall;

/**
 * Roadmap 1.C-2, the W1 hand-off: what the frame channel delivers to the turn
 * child — a reply's note, the grandchild refusal's reason, "nobody answered"
 * — reaches the model, because {@see ChildChannel::approver()} answers an
 * {@see ApprovalVerdict} instead of the `bool` that dropped all three.
 */
final class ChildChannelVerdictTest extends TestCase
{
    public function testAGrandchildsRefusalIsAReasonNotAPersonSpeaking(): void
    {
        [, $child] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $channel = new ChildChannel(
            $child,
            static function (array $frame): void {
            },
            ForkChannelEofIsUnansweredTest::drain(),
            'default',
            (int) \getmypid() + 1,
        );

        $verdict = ($channel->approver())(new ToolCall('call_1', 'Task', ['agent' => 'coder']), ForkChannelEofIsUnansweredTest::gateAsk());

        self::assertInstanceOf(ApprovalVerdict::class, $verdict);
        self::assertSame(DenialKind::Refused, $verdict->denialKind());
        self::assertSame(ChildChannel::GRANDCHILD_REFUSAL, $verdict->feedback);
        self::assertStringNotContainsString('the user said', $verdict->denialMessage('Task needs approval'));
        \fclose($child);
    }

    public function testARejectionsNoteReachesTheModelThroughTheForkedTurn(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the channel lives on completeAsync()\'s fork, which needs ext-pcntl.');
        }

        $events = [];
        $state = InteractiveTurnHarness::settle(
            InteractiveTurnHarness::backend(InteractiveTurnHarness::provider())->completeInteractive(
                [Message::user('go')],
                null,
                null,
                static function (object $event) use (&$events): void {
                    $events[] = $event;
                    if ($event instanceof PermissionAsked) {
                        $event->ask->reply(PermissionReply::Reject, 'edit b.txt instead');
                    }
                },
            ),
            Loop::get(),
        );

        self::assertTrue($state['settled']);
        $finished = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolFinished));
        self::assertCount(1, $finished);
        $content = $finished[0]->result->content();
        self::assertStringStartsWith(DenialKind::Refused->value, $content);
        self::assertStringContainsString('the user said: edit b.txt instead', $content, 'the note never reached the result the model reads');
        self::assertSame(DenialKind::Refused, $finished[0]->result->denial());
    }
}
