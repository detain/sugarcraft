<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Context\Compaction\StepSummarizer;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ContextOverflow;
use SugarCraft\Crush\Providers\ProviderResponseException;
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 2.7-1b: a step the provider refuses as too long for its window
 * (ContextOverflow, 2.7-1a) is relieved as far as the in-turn machinery goes —
 * every older tool output pruned, then a step summary — and sent once more.
 */
final class OverflowPruneAndRetryTest extends TestCase
{
    private const OVERFLOW_FRAME = ['error' => ['message' => "The input (131072 tokens) is longer than the model's context length (100000 tokens).", 'code' => 400]];

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testARefusedStepIsPrunedMaximallyAndSentOnceMore(): void
    {
        $refusals = 0;
        $provider = self::provider('probe', static function () use (&$refusals): void {
            if ($refusals++ === 0) {
                throw ProviderStreamException::fromErrorEvent(self::OVERFLOW_FRAME, 'SGLANG request failed: ');
            }
        });

        $turn = $this->engine($provider)->withTools([self::tool('probe')])->completeTranscript([new UserMessage('map it')]);

        $this->assertSame('all mapped', $turn->reply->content);
        // Two tool steps, the refused third request, its retry. No summary:
        // after the prune the range is far under StepSummarizer's floor.
        $this->assertCount(4, $provider->requests);
        $retried = $provider->requests[3];
        $this->assertNotSame(self::wire($provider->requests[2]->messages), self::wire($retried->messages));
        foreach (self::results($retried->messages) as $id => $content) {
            $this->assertSame(PrunedOutputPlaceholder::for('probe', ['n' => (int) substr($id, 1)]), $content, "{$id} is a placeholder, newest output included");
        }
        $this->assertSame(['c1', 'c2'], array_keys(self::results($retried->messages)));
        $this->assertSame(['c1', 'c2'], array_keys(self::results($turn->transcript)), 'the turn\'s own rows are never rewritten');
        foreach (self::results($turn->transcript) as $content) {
            $this->assertStringStartsWith('lorem ipsum', $content);
        }
    }

    public function testProtectedOutputIsSummarisedInsteadAndTheErrorResponseFlagCounts(): void
    {
        $refusals = 0;
        $provider = self::provider('Task', static function () use (&$refusals): void {
            if ($refusals++ === 0) {
                // A provider that reports instead of throwing: only the flag
                // says what the failure was.
                throw ProviderResponseException::fromResponse(new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: 'request rejected',
                    errorContextOverflow: true,
                ));
            }
        });

        $turn = $this->engine($provider)->withTools([self::tool('Task')])->completeTranscript([new UserMessage('map it')]);

        $this->assertSame('all mapped', $turn->reply->content);
        // Two tool steps, the refused request, the summary, the retry.
        $this->assertCount(5, $provider->requests);
        $this->assertTrue(self::asksForSummary($provider->requests[3]));
        $retried = $provider->requests[4];
        $this->assertStringStartsWith(sprintf(CompressionBlock::HEADER, 1), $retried->messages[0]->content());
        $this->assertSame(['c2'], array_keys(self::results($retried->messages)), 'c1 is in the summary, the unsent step goes whole');
    }

    public function testASecondRefusalPropagates(): void
    {
        $provider = self::provider('probe', static function (): void {
            throw ProviderStreamException::fromErrorEvent(self::OVERFLOW_FRAME, 'SGLANG request failed: ');
        });

        try {
            $this->engine($provider)->withTools([self::tool('probe')])->completeTranscript([new UserMessage('map it')]);
            $this->fail('a step refused twice must fail the turn');
        } catch (TurnInterrupted $e) {
            $this->assertInstanceOf(ProviderStreamException::class, $e->getPrevious());
            $this->assertTrue(ContextOverflow::matches($e->getPrevious()));
        }

        $this->assertCount(4, $provider->requests, 'retried exactly once');
    }

    public function testARefusalNothingCanRelieveIsNotRetried(): void
    {
        $provider = new ScriptedProvider([
            static function (): CompleteResponse {
                throw ProviderStreamException::fromErrorEvent(self::OVERFLOW_FRAME, 'SGLANG request failed: ');
            },
        ], contextWindow: 100_000);

        try {
            $this->engine($provider)->completeTranscript([new UserMessage(str_repeat('a very long prompt ', 10))]);
            $this->fail('nothing to prune or summarise: the refusal stands');
        } catch (TurnInterrupted $e) {
            $this->assertInstanceOf(ProviderStreamException::class, $e->getPrevious());
            $this->assertTrue(ContextOverflow::matches($e->getPrevious()));
        }

        $this->assertCount(1, $provider->requests, 'the identical request is not sent again');
    }

    public function testAnyOtherFailureIsNotTreatedAsAnOverflow(): void
    {
        $provider = self::provider('probe', static function (): void {
            throw new \RuntimeException('401 Unauthorized');
        });

        try {
            $this->engine($provider)->withTools([self::tool('probe')])->completeTranscript([new UserMessage('map it')]);
            $this->fail('a 401 is not an overflow');
        } catch (TurnInterrupted $e) {
            $this->assertSame('401 Unauthorized', $e->getPrevious()?->getMessage());
        }

        $this->assertCount(3, $provider->requests);
    }

    /**
     * Steps 1-2 call $tool (30k tokens of output each, 100k window: under the
     * 80k step budget, so the estimate never refuses anything); the third
     * request runs $third first, which may throw; a summary request gets a
     * short summary; anything else answers.
     *
     * @param \Closure(): void $third
     */
    private static function provider(string $tool, \Closure $third): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step, $tool, $third): CompleteResponse {
                if (self::asksForSummary($request)) {
                    return new CompleteResponse(content: 'SUMMARY: probed once.');
                }
                $step++;
                if ($step <= 2) {
                    return new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", $tool, ['n' => $step])]);
                }
                $third();

                return new CompleteResponse(content: 'all mapped');
            },
        ], contextWindow: 100_000);
    }

    private static function asksForSummary(CompleteRequest $request): bool
    {
        $last = $request->messages[array_key_last($request->messages)] ?? null;

        return $last instanceof UserMessage && $last->content() === StepSummarizer::INSTRUCTION;
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-overflow-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root);
    }

    private static function tool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'Reads a lot.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 10_000));
            }
        };
    }

    /**
     * @param list<mixed> $messages
     * @return array<string, string> call id => content
     */
    private static function results(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $results[$message->toolCallId()] = $message->content();
            }
        }

        return $results;
    }

    /** @param list<mixed> $messages */
    private static function wire(array $messages): string
    {
        return (string) json_encode(array_map(static fn (Message $m): array => $m->toArray(), $messages));
    }
}
