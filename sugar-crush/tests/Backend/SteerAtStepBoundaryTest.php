<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\CompositeTurnInbox;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\SocketSteerInbox;
use SugarCraft\Crush\Backend\TurnInbox;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-3: a message the user sends while a turn runs reaches the
 * model at the turn's NEXT step boundary — after the step's tools settle,
 * before its next provider call — and the child acknowledges where it landed.
 */
final class SteerAtStepBoundaryTest extends TestCase
{
    private const STEER = 'use the fixtures under tests/';

    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $socket) {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }
        parent::tearDown();
    }

    public function testASteerCrossesTheForkAndLandsAtTheNextStep(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('steer frames cross completeAsync()\'s fork, which needs ext-pcntl.');
        }

        $token = new CancellationToken();
        $steerId = null;

        $state = InteractiveTurnHarness::settle(
            self::backend()->completeAsync(
                [Message::user('go')],
                cancellation: $token,
                onStep: static function (StepStarted|UsageUpdated $e) use ($token, &$steerId): void {
                    if ($e instanceof StepStarted && $e->step === 1) {
                        // Sent while step 1's slow tool is still to run.
                        $steerId = $token->steer(self::STEER);
                    }
                },
            ),
            Loop::get(),
        );

        self::assertTrue($state['settled'], 'the turn never settled');
        self::assertNull($state['error']);
        self::assertIsString($steerId);
        self::assertSame('heard: ' . self::STEER, $state['value']->content, 'step 2\'s request carried the steer');
        self::assertSame([$steerId => 2], $token->acknowledgedSteers(), 'acknowledged at the step it was delivered at');

        $steerRows = array_values(array_filter(
            $state['value']->turnTranscript,
            static fn (Message $m): bool => $m->content === SocketSteerInbox::content(self::STEER),
        ));
        self::assertCount(1, $steerRows, 'the steer comes back in the transcript, for the next turn to replay');
        self::assertFalse($steerRows[0]->userVisible, 'as the model\'s record; Chat shows its own notice');
    }

    public function testTheStepLoopAppendsTheMessageAfterTheStepsToolResults(): void
    {
        $inbox = new class () implements TurnInbox {
            /** @var list<int> */
            public array $steps = [];

            public function drain(int $step): array
            {
                $this->steps[] = $step;

                return $step === 2 ? [new UserMessage(SocketSteerInbox::content(SteerAtStepBoundaryTest::message()))] : [];
            }

            public function pending(): bool
            {
                return false;
            }
        };
        $provider = self::provider();
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withTools([self::slowTool(0.0)])->withMaxSteps(5);

        $transcript = [];
        $run = new \ReflectionMethod(EngineBackend::class, 'runTurn');
        $reply = $run->invokeArgs($backend, [[new UserMessage('go')], null, null, null, null, &$transcript, null, null, $inbox]);

        self::assertSame('heard: ' . self::STEER, $reply->content);
        self::assertSame([1, 2], $inbox->steps, 'drained at the top of every step, numbered from 1');

        $rows = array_values(array_filter(
            $provider->requests[1]->messages,
            static fn (TypedMessage $m): bool => !\SugarCraft\Crush\Context\TurnContextBlock::isTurnContext($m),
        ));
        $last = $rows[array_key_last($rows)];
        self::assertSame(SocketSteerInbox::content(self::STEER), $last->content());
        self::assertInstanceOf(ToolResultMessage::class, $rows[\count($rows) - 2], 'after the step\'s tool result, so the model reads both');
    }

    public function testTheSocketInboxProbesWithoutConsumingAndAcknowledgesWhatItDelivers(): void
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        \array_push($this->open, ...$pair);
        [$parent, $child] = $pair;
        $written = [];
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use (&$written): void {
                $written[] = $frame;
            },
            ForkChannelEofIsUnansweredTest::drain(),
        );
        $inbox = SocketSteerInbox::new($channel);

        self::assertFalse($inbox->pending());
        foreach ([['steerId' => 'st_1', 'text' => self::STEER], ['steerId' => 'st_2', 'text' => "  \n"]] as $steer) {
            $body = \serialize(['kind' => ChildChannel::STEER] + $steer);
            \fwrite($parent, \pack('N', \strlen($body)) . $body);
        }

        self::assertTrue($inbox->pending());
        self::assertTrue($inbox->pending(), 'a probe hands nothing out');
        self::assertSame([], $written, 'and acknowledges nothing');

        $delivered = $inbox->drain(3);
        self::assertSame([SocketSteerInbox::content(self::STEER)], array_map(static fn (TypedMessage $m): string => $m->content(), $delivered), 'a blank steer is not a message');
        self::assertSame([['kind' => ChildChannel::STEER_ACK, 'steerId' => 'st_1', 'step' => 3]], $written);
        self::assertSame([], $inbox->drain(4), 'each steer once');
        self::assertFalse($inbox->pending());
    }

    public function testTheCompositeReadsEveryMemberInOrder(): void
    {
        $one = self::fixed('one');
        $two = self::fixed('two');

        self::assertNull(CompositeTurnInbox::of(null, null));
        self::assertSame($one, CompositeTurnInbox::of(null, $one), 'one member is itself');

        $both = CompositeTurnInbox::of($one, null, $two);
        self::assertInstanceOf(CompositeTurnInbox::class, $both);
        self::assertTrue($both->pending());
        self::assertSame(['one', 'two'], array_map(static fn (TypedMessage $m): string => $m->content(), $both->drain(1)));
    }

    public function testTheTokenRefusesASteerOnceTheTurnIsStopping(): void
    {
        $token = new CancellationToken();
        self::assertNull($token->steer('   '), 'nothing to say');

        $id = $token->steer('a');
        self::assertIsString($id);
        self::assertSame([['steerId' => $id, 'text' => 'a']], $token->takeSteers());
        self::assertSame([], $token->takeSteers(), 'each handed out once');

        $token->cancelSoft();
        self::assertNull($token->steer('b'), 'a stopping turn has no next step to deliver at');
    }

    public static function message(): string
    {
        return self::STEER;
    }

    private static function fixed(string $text): TurnInbox
    {
        return new class ($text) implements TurnInbox {
            public function __construct(private readonly string $text)
            {
            }

            public function drain(int $step): array
            {
                return [new UserMessage($this->text)];
            }

            public function pending(): bool
            {
                return true;
            }
        };
    }

    /**
     * Calls the slow tool until a steering row reaches it, then answers what
     * it heard.
     */
    private static function provider(): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                foreach ($request->messages as $m) {
                    if ($m instanceof UserMessage && str_starts_with($m->content(), SocketSteerInbox::PREFIX)) {
                        return new CompleteResponse(content: 'heard: ' . substr($m->content(), \strlen(SocketSteerInbox::PREFIX)));
                    }
                }
                $step++;

                return new CompleteResponse(content: "step {$step}", toolCalls: [new ToolCall("c{$step}", 'slow', ['n' => $step])]);
            },
        ]);
    }

    private static function backend(): EngineBackend
    {
        return EngineBackend::new(self::provider(), 'm')->withoutHooks()->withTools([self::slowTool(0.6)])->withMaxSteps(5);
    }

    private static function slowTool(float $seconds): Tool
    {
        return new class ($seconds) implements Tool {
            public function __construct(private float $seconds)
            {
            }

            public function name(): string
            {
                return 'slow';
            }

            public function description(): string
            {
                return 'takes a while';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                \usleep((int) ($this->seconds * 1_000_000));

                return new ToolResult(toolCallId: '', content: 'slow ok');
            }
        };
    }
}
