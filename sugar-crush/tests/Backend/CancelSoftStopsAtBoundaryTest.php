<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-4 `cancel_soft`: the parent asks a forked turn to stop, the
 * step's tools finish, and the turn ends at the boundary — with its reply,
 * as a deliberate exit (no step-ceiling flag, no summary request) — while a
 * hard cancel still tears the tree down at once.
 */
final class CancelSoftStopsAtBoundaryTest extends TestCase
{
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

    public function testASoftCancelLetsTheStepFinishAndMakesNoFurtherCall(): void
    {
        self::requireFork();
        $token = new CancellationToken();
        $steps = [];
        $events = [];

        $state = InteractiveTurnHarness::settle(
            self::backend()->completeAsync(
                [Message::user('go')],
                cancellation: $token,
                onEvent: static function (object $e) use (&$events): void {
                    $events[] = $e;
                },
                onStep: static function (StepStarted|UsageUpdated $e) use ($token, &$steps): void {
                    if ($e instanceof StepStarted) {
                        $steps[] = $e->step;
                        // Asked while step 1's slow tool is still to run.
                        $token->cancelSoft();
                    }
                },
            ),
            Loop::get(),
        );

        self::assertTrue($state['settled'], 'the turn never settled');
        self::assertNull($state['error'], 'a soft cancel settles the turn, it does not fail it');
        self::assertSame([1], $steps, 'no step after the boundary');
        self::assertCount(1, array_filter($events, static fn ($e): bool => $e instanceof ToolFinished), 'the step\'s tool ran to the end');
        self::assertSame('step 1', $state['value']->content, 'the reply is the step that ran — no summary request was made');
        self::assertFalse($state['value']->stepsTruncated, 'a requested stop is not a ceiling the user should raise');
        self::assertFalse($token->isCancelled());
    }

    public function testAHardCancelAfterASoftOneStillTearsTheTurnDown(): void
    {
        self::requireFork();
        $token = new CancellationToken();
        $loop = Loop::get();

        $state = InteractiveTurnHarness::settle(
            self::backend(toolSeconds: 5.0)->completeAsync(
                [Message::user('go')],
                cancellation: $token,
                onStep: static function (StepStarted|UsageUpdated $e) use ($token, $loop): void {
                    if ($e instanceof StepStarted) {
                        $token->cancelSoft();
                        $loop->addTimer(0.3, static fn () => $token->cancel());
                    }
                },
            ),
            $loop,
        );

        self::assertTrue($state['settled']);
        self::assertInstanceOf(\RuntimeException::class, $state['error']);
        self::assertSame('Request cancelled', $state['error']->getMessage());
    }

    public function testTheChannelLatchesASoftCancelAndKeepsOtherControlsBuffered(): void
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

        self::assertFalse($channel->softCancelRequested(), 'nothing sent yet');

        foreach ([['kind' => ChildChannel::CANCEL_TOOL, 'callId' => 'c1'], ['kind' => ChildChannel::CANCEL_SOFT]] as $frame) {
            $body = \serialize($frame);
            \fwrite($parent, \pack('N', \strlen($body)) . $body);
        }

        self::assertTrue($channel->softCancelRequested());
        self::assertTrue($channel->softCancelRequested(), 'latched');
        self::assertSame([['kind' => ChildChannel::CANCEL_TOOL, 'callId' => 'c1']], $channel->takeControls(), 'cancel_tool is left for its own reader');

        self::assertTrue($channel->send(ChildChannel::STEP, ['step' => 1, 'maxSteps' => 2]));
        self::assertSame([['kind' => 'step', 'step' => 1, 'maxSteps' => 2]], $written);
    }

    public function testTheTokenCarriesBothStrengths(): void
    {
        $token = new CancellationToken();
        self::assertFalse($token->isSoftCancelled());

        $token->cancelSoft();
        self::assertTrue($token->isSoftCancelled());
        self::assertFalse($token->isCancelled(), 'soft is not hard');

        $hard = new CancellationToken();
        $hard->cancel();
        self::assertTrue($hard->isSoftCancelled(), 'a hard cancel implies the soft one');
    }

    private static function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('cancel_soft crosses completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    /**
     * A model that calls the slow tool on every step, and says SUMMARY when
     * the last row asks it to stop calling tools (the stopped-turn summary
     * request).
     */
    private static function backend(float $toolSeconds = 0.6): EngineBackend
    {
        $step = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                $last = $request->messages[array_key_last($request->messages)] ?? null;
                if ($last instanceof \SugarCraft\Crush\Messages\UserMessage && str_contains($last->content(), 'Do not call any tools')) {
                    return new CompleteResponse(content: 'SUMMARY');
                }
                $step++;

                return new CompleteResponse(content: "step {$step}", toolCalls: [new ToolCall("c{$step}", 'slow', ['n' => $step])]);
            },
        ]);

        return EngineBackend::new($provider, 'm')->withTools([self::slowTool($toolSeconds)])->withMaxSteps(20);
    }

    private static function slowTool(float $seconds): Tool
    {
        return new class($seconds) implements Tool {
            public function __construct(private float $seconds) {}

            public function name(): string { return 'slow'; }

            public function description(): string { return 'takes a while'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                \usleep((int) ($this->seconds * 1_000_000));

                return new ToolResult(toolCallId: '', content: 'slow ok');
            }
        };
    }
}
