<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 1.B-2: the turn's rows cross the fork's `result` frame. The child
 * writes each as {@see Message::jsonSerialize()}'s plain arrays (the parent
 * decodes with `allowed_classes => false`), the parent rebuilds them with
 * {@see Message::fromArray()}, and the forked reply must equal the in-process
 * one field for field - the async half of the app tells the same story as the
 * sync half.
 */
final class ResultFrameTranscriptCodecTest extends TestCase
{
    public function testTheForkedReplyCarriesTheSameStepsAsTheInProcessOne(): void
    {
        $this->requireFork();

        $sync = $this->backend('ok')->complete([Message::user('go')]);
        $forked = $this->drainUntilSettled($this->backend('ok')->completeAsync([Message::user('go')]));

        $this->assertInstanceOf(Message::class, $forked);
        $this->assertSame('answer', $forked->content);
        $this->assertNotNull($forked->stepId);
        $this->assertSame(self::shape($sync->turnTranscript), self::shape($forked->turnTranscript));
        $this->assertFalse($forked->turnTranscript[0]->userVisible);
        $this->assertSame('pondering', $forked->turnTranscript[0]->reasoning);
        $this->assertSame($forked->turnTranscript[0]->stepId, $forked->turnTranscript[1]->stepId);
    }

    public function testATurnThatOutgrowsTheFrameBudgetSendsAMarkerNotTheOutput(): void
    {
        $this->requireFork();

        $huge = str_repeat('x', intdiv(EngineBackend::MAX_FRAME_BYTES, 4) + 1024);
        $forked = $this->drainUntilSettled($this->backend($huge)->completeAsync([Message::user('go')]));

        $this->assertInstanceOf(Message::class, $forked, 'the turn must settle, not lose its result frame');
        $this->assertSame('answer', $forked->content);
        $result = $forked->turnTranscript[1]->toolResults[0];
        $this->assertStringStartsWith('[tool output not carried', $result->result);
        $this->assertSame('call_1', $result->id, 'the pairing survives the omission');
    }

    public function testAFrameWithoutTheKeysSettlesWithNoTranscript(): void
    {
        $legacy = $this->settleFrame(['ok' => true, 'content' => 'x']);

        $this->assertSame([], $legacy->turnTranscript);
        $this->assertNull($legacy->stepId);
    }

    public function testGarbageInTheKeysIsDroppedNotTrusted(): void
    {
        $garbage = $this->settleFrame([
            'ok' => true,
            'content' => 'x',
            'stepId' => ['not', 'a', 'string'],
            'transcript' => ['a string row', 42, ['role' => 'assistant', 'content' => 'kept', 'stepId' => 's_1_1', 'userVisible' => false]],
        ]);

        $this->assertNull($garbage->stepId);
        $this->assertCount(1, $garbage->turnTranscript, 'only the array row is a row');
        $this->assertSame('kept', $garbage->turnTranscript[0]->content);
        $this->assertSame('s_1_1', $garbage->turnTranscript[0]->stepId);
        $this->assertFalse($garbage->turnTranscript[0]->userVisible);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl and the frame never crosses a serialize boundary');
        }
    }

    private function backend(string $output): EngineBackend
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'looking', toolCalls: [new EngineToolCall('call_1', 'echo', ['q' => 1])], reasoning: 'pondering'),
            new CompleteResponse(content: 'answer'),
        ]);

        return EngineBackend::new($provider, 'm')->withTools([self::echoTool($output)]);
    }

    /**
     * @param list<Message> $rows
     * @return list<array<string, mixed>>
     */
    private static function shape(array $rows): array
    {
        return array_map(static function (Message $row): array {
            $array = $row->jsonSerialize();
            unset($array['createdAt'], $array['stepId']);

            return $array;
        }, $rows);
    }

    /** @param array<string, mixed> $frame */
    private function settleFrame(array $frame): Message
    {
        $backend = $this->backend('ok');
        $deferred = new Deferred();

        (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame, $deferred, null);

        $resolved = null;
        $deferred->promise()->then(static function (Message $m) use (&$resolved): void {
            $resolved = $m;
        });
        $this->assertInstanceOf(Message::class, $resolved, 'the frame must settle, not reject');

        return $resolved;
    }

    private function drainUntilSettled(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }

    private static function echoTool(string $output): Tool
    {
        return new class ($output) implements Tool {
            public function __construct(private readonly string $output)
            {
            }

            public function name(): string
            {
                return 'echo';
            }

            public function description(): string
            {
                return 'answers';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): EngineToolResult
            {
                return new EngineToolResult(toolCallId: '', content: $this->output);
            }
        };
    }
}
