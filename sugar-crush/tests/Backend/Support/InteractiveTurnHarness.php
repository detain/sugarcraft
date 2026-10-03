<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend\Support;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The shared fixture of the `ForkChannel*` tests (roadmap 1.C-1): an engine
 * whose gate ASKS about one tool call, and a way to drive the forked turn on a
 * given loop to settlement.
 *
 * One place rather than a copy per file, because the four files differ only in
 * what the receiver does with the question — answer it, answer it late, let it
 * outlive a cancel, or leave it open past the idle ceiling.
 */
final class InteractiveTurnHarness
{
    /**
     * A turn that calls `Edit` (with $arguments) $calls times, one call per
     * step, then answers "done". Default mode asks about every non-read-only
     * tool, so each call is a question from the permission gate alone.
     *
     * @param array<string, mixed>                        $arguments
     * @param list<CompleteResponse|\Closure>             $after      replaces the closing "done"
     */
    public static function provider(int $calls = 1, array $arguments = [], array $after = []): ScriptedProvider
    {
        $script = [];
        for ($i = 1; $i <= $calls; $i++) {
            $script[] = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_' . $i, 'Edit', $arguments)]);
        }
        foreach ($after === [] ? [new CompleteResponse(content: 'done')] : $after as $answer) {
            $script[] = $answer;
        }

        return new ScriptedProvider($script);
    }

    public static function backend(ScriptedProvider $provider): EngineBackend
    {
        return EngineBackend::new($provider, 'm')
            ->withTools([self::recordingTool()])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default));
    }

    /**
     * A tool named `Edit` that says `ran`. It runs in the forked child, so the
     * parent learns it ran from the `finished` frame's content, never from a
     * counter on this object.
     */
    public static function recordingTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Edit'; }
            public function description(): string { return 'says it ran'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }

    /**
     * Run $loop until $promise settles, bounded by a REAL-time guard so a turn
     * that never settles fails the test instead of hanging the suite.
     *
     * @return array{settled: bool, value: mixed, error: ?\Throwable}
     */
    public static function settle(PromiseInterface $promise, LoopInterface $loop, float $guardSeconds = 20.0): array
    {
        $state = ['settled' => false, 'value' => null, 'error' => null];
        $promise->then(
            static function (mixed $value) use (&$state, $loop): void {
                $state['settled'] = true;
                $state['value'] = $value;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$state, $loop): void {
                $state['settled'] = true;
                $state['error'] = $e;
                $loop->stop();
            },
        );

        if (!$state['settled']) {
            $guard = $loop->addTimer($guardSeconds, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }

        return $state;
    }
}
