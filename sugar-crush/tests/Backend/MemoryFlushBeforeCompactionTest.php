<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Compaction\MemoryFlush;
use SugarCraft\Crush\Context\Compaction\StepSummarizer;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.11: before the engine writes a step summary, one silent step lets
 * the model save to memory — only the Memory tool runs, the step never joins
 * the conversation, and it happens once per compaction cycle.
 */
final class MemoryFlushBeforeCompactionTest extends TestCase
{
    private string $dir;

    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-memflush-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/home', 0o700, true);
        mkdir($this->dir . '/repo', 0o700, true);
        $this->store = new MemoryStore($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    public function testTheFlushRunsSilentlyBeforeTheSummaryAndOnlyMemoryWrites(): void
    {
        $probe = self::probe();
        $provider = self::provider(summary: 'SUMMARY OF STEPS 1-2.');
        $events = [];

        $turn = $this->engine($provider)->withTools([$probe, $this->memoryTool()])->completeTranscript(
            [new UserMessage('map the project')],
            onStep: static function (object $event) use (&$events): void {
                $events[] = $event;
            },
        );

        // Three tool steps, the flush, the summary, the step that answers.
        $this->assertCount(6, $provider->requests);
        $flush = $provider->requests[3];
        $this->assertTrue(self::isFlush($flush));
        $this->assertTrue(self::isSummary($provider->requests[4]));
        $this->assertSame($provider->requests[2]->tools, $flush->tools, 'every tool stays advertised: a cached prefix');
        $this->assertSame($provider->requests[2]->systemPrompt, $flush->systemPrompt);
        $this->assertSame(
            self::wire($provider->requests[2]->messages),
            self::wire(\array_slice($flush->messages, 0, \count($provider->requests[2]->messages))),
            'the flush request extends the last request sent: a cached prefix',
        );

        $notes = $this->store->list('user');
        $this->assertCount(1, $notes, 'the Memory call ran');
        $this->assertStringContainsString('the build runs through make ci', $notes[0]->content());
        $this->assertSame(3, $probe->runs, 'any other tool is refused during the flush');

        $this->assertSame(
            self::wire(\array_slice($provider->requests[2]->messages, 0, \count($provider->requests[4]->messages) - 1)),
            self::wire(\array_slice($provider->requests[4]->messages, 0, -1)),
            'silent: the summary request carries none of the flush',
        );
        foreach ($turn->transcript as $message) {
            $this->assertFalse($message instanceof UserMessage && $message->content() === MemoryFlush::INSTRUCTION);
            $this->assertFalse($message instanceof AssistantMessage && self::callsMemory($message), 'the flush step is not in the transcript');
        }
        $this->assertCount(6, array_filter($events, static fn (object $e): bool => $e instanceof UsageUpdated), 'the flush is billed like a step');
        $this->assertSame('all mapped', $turn->reply->content);
    }

    public function testOncePerCompactionCycleEvenWhenTheSummaryIsRetried(): void
    {
        // The summary fails every time, so each later step is still over
        // budget and retries it; the cycle has not moved, so no second flush.
        $provider = self::provider(summary: null, steps: 4);

        $this->engine($provider)->withTools([self::probe(), $this->memoryTool()])->completeTranscript([new UserMessage('map the project')]);

        $this->assertSame(2, \count(array_filter($provider->requests, self::isSummary(...))), 'the summary was tried twice');
        $this->assertSame(1, \count(array_filter($provider->requests, self::isFlush(...))), 'the memory was flushed once');
    }

    public function testNoMemoryToolNoFlush(): void
    {
        $provider = self::provider(summary: 'SUMMARY.');

        $this->engine($provider)->withTools([self::probe()])->completeTranscript([new UserMessage('map the project')]);

        $this->assertSame(0, \count(array_filter($provider->requests, self::isFlush(...))));
        $this->assertSame(1, \count(array_filter($provider->requests, self::isSummary(...))));
    }

    /**
     * Steps call `probe` (30k of output each) until $steps tool steps ran, then
     * answer; 100k window, so the fourth request is the first over budget. The
     * flush is answered with a Memory save and a probe call; the summary with
     * $summary, or a failure for null.
     */
    private static function provider(?string $summary, int $steps = 3): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step, $summary, $steps): CompleteResponse {
                if (self::isFlush($request)) {
                    return new CompleteResponse(content: '', toolCalls: [
                        new ToolCall('f1', 'Memory', ['action' => 'save', 'content' => 'the build runs through make ci', 'scope' => 'user', 'type' => 'convention']),
                        new ToolCall('f2', 'probe', ['n' => 99]),
                    ], usage: Usage::new(60, 0.0, 50, 10, 0));
                }
                if (self::isSummary($request)) {
                    if ($summary === null) {
                        throw new \RuntimeException('summary call failed');
                    }

                    return new CompleteResponse(content: $summary, usage: Usage::new(60, 0.0, 50, 10, 0));
                }
                $step++;

                return $step <= $steps
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'probe', ['n' => $step])])
                    : new CompleteResponse(content: 'all mapped');
            },
        ], contextWindow: 100_000);
    }

    private static function isFlush(CompleteRequest $request): bool
    {
        $last = $request->messages[array_key_last($request->messages)] ?? null;

        return $last instanceof UserMessage && $last->content() === MemoryFlush::INSTRUCTION;
    }

    private static function isSummary(CompleteRequest $request): bool
    {
        $last = $request->messages[array_key_last($request->messages)] ?? null;

        return $last instanceof UserMessage && $last->content() === StepSummarizer::INSTRUCTION;
    }

    private static function callsMemory(AssistantMessage $message): bool
    {
        foreach ($message->toolCalls() ?? [] as $call) {
            if ($call->name() === MemoryTool::NAME) {
                return true;
            }
        }

        return false;
    }

    private function memoryTool(): MemoryTool
    {
        return new MemoryTool(MemoryWriter::new($this->store, $this->dir . '/repo'));
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir . '/repo');
    }

    /** @return object{runs:int}&Tool */
    private static function probe(): Tool
    {
        return new class () implements Tool {
            public int $runs = 0;

            public function name(): string
            {
                return 'probe';
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
                $this->runs++;

                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 10_000));
            }
        };
    }

    /** @param list<mixed> $messages */
    private static function wire(array $messages): string
    {
        return (string) json_encode(array_map(static fn (Message $m): array => $m->toArray(), $messages));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
