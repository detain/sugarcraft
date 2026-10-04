<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Todo\TodoReminder;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * What `EngineBackend::runTurn()`'s step-top re-reads between the steps of
 * one turn: the read ledger's stale-file paragraph in the `<turn-context>`
 * row (roadmap 3.I-2), and the todo-list reminder once the model has gone
 * {@see TodoReminder::INTERVAL_STEPS} steps without seeing it (roadmap 3.C).
 */
final class StepTopRemindersTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-step-top-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAFileChangedUnderAReadOnlyStepIsNamedInTheNextTurnContextRow(): void
    {
        $path = $this->dir . '/a.txt';
        file_put_contents($path, "alpha\n");
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('r1', 'Read', ['file_path' => 'a.txt'])]),
            new CompleteResponse(content: '', toolCalls: [new ToolCall('g1', 'Glob', ['pattern' => '*'])]),
            new CompleteResponse(content: 'done'),
        ], contextWindow: 1_000_000);

        // `Glob` is read-only to the step loop, so the step after it reuses
        // the previous row; the paragraph must still be re-read there.
        $outsideWrite = self::tool('Glob', static function () use ($path): string {
            file_put_contents($path, "changed by someone else\n");

            return 'a.txt';
        });
        EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($this->dir)
            ->withTools([new Read($this->dir, readLedger: ReadLedger::new()), $outsideWrite])
            ->complete([Message::user('look at a.txt')]);

        $this->assertCount(3, $provider->requests);
        $this->assertStringNotContainsString('Files changed on disk', self::turnContext($provider->requests[1]) ?? '');
        $row = self::turnContext($provider->requests[2]);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Files changed on disk since you last read them', $row);
        $this->assertStringContainsString('- ' . $path, $row);
    }

    public function testTheTodoListIsReShownInsideATurnThatRunsPastTheInterval(): void
    {
        $todo = new ToolCall('t1', 'Todo', ['todos' => [
            ['content' => 'write the parser', 'status' => 'in_progress'],
            ['content' => 'test it', 'status' => 'pending'],
        ]]);
        $script = [new CompleteResponse(content: '', toolCalls: [$todo])];
        for ($i = 1; $i <= TodoReminder::INTERVAL_STEPS; $i++) {
            $script[] = new CompleteResponse(content: '', toolCalls: [new ToolCall("g{$i}", 'Glob', ['pattern' => "*{$i}"])]);
        }
        $script[] = new CompleteResponse(content: 'done');
        $provider = new ScriptedProvider($script, contextWindow: 1_000_000);

        EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($this->dir)
            ->withTools([new Todo(), self::tool('Glob', static fn (): string => 'nothing')])
            ->complete([Message::user('build the parser')]);

        $last = TodoReminder::INTERVAL_STEPS + 1;
        $this->assertCount($last + 1, $provider->requests);
        $this->assertSame(0, self::reminders($provider->requests[$last - 1]), 'not due one step early');
        $this->assertSame(1, self::reminders($provider->requests[$last]), 'due after INTERVAL_STEPS unseen steps');
        $this->assertStringContainsString('write the parser', self::lastReminder($provider->requests[$last]));
    }

    private static function turnContext(CompleteRequest $request): ?string
    {
        return TurnContextBlock::latestIn($request->messages);
    }

    private static function reminders(CompleteRequest $request): int
    {
        return \count(array_filter($request->messages, static fn (mixed $m): bool => TodoReminder::isReminder($m)));
    }

    private static function lastReminder(CompleteRequest $request): string
    {
        $found = '';
        foreach ($request->messages as $message) {
            if (TodoReminder::isReminder($message) && $message instanceof UserMessage) {
                $found = $message->content();
            }
        }

        return $found;
    }

    /** @param \Closure(): string $run */
    private static function tool(string $name, \Closure $run): Tool
    {
        return new class ($name, $run) implements Tool {
            public function __construct(private readonly string $toolName, private readonly \Closure $run)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'Test double.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['pattern' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('', ($this->run)());
            }
        };
    }
}
