<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\AgentResume;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * candy-code-host-011: AgentResume's socket pair no longer lies by silence.
 * A dead peer is counted and surfaced, a fork downgrade is announced.
 */
final class AgentResumeIpcHonestyTest extends TestCase
{
    public function testDeadPeerCountsEveryRefusedWrite(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parentEnd, $childEnd] = $pair;
        fclose($parentEnd);

        $task = new class () implements DelegatesToEngine, Tool {
            public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): Tool
            {
                return $this;
            }
            public function name(): string { return 'Task'; }
            public function description(): string { return ''; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult { return new ToolResult($args['id'], 'done'); }
        };

        $m = new \ReflectionMethod(AgentResume::class, 'runInChild');
        $m->setAccessible(true);
        $dropped = $m->invoke(null, $childEnd, $task, (new \ReflectionClass(EngineBackend::class))->newInstanceWithoutConstructor(), ['id' => 'c1']);

        $this->assertGreaterThanOrEqual(1, $dropped, 'the result line itself was refused and is counted');
    }

    public function testParentReportsADegradedFeedAheadOfTheResult(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$parentEnd, $childEnd] = $pair;
        fwrite($childEnd, json_encode(['k' => 'result', 'content' => 'all good', 'error' => false, 'dropped' => 3]) . "\n");
        fclose($childEnd);

        $m = new \ReflectionMethod(AgentResume::class, 'collect');
        $m->setAccessible(true);
        $result = null;
        // A pid that is no child of ours: waitpid answers -1 at once.
        $m->invoke(null, $parentEnd, 0x7ffffff0, 'c1', null)->then(static function (ToolResult $r) use (&$result): void {
            $result = $r;
            Loop::stop();
        });
        $guard = Loop::addTimer(2.0, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($guard);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertStringContainsString('3', $result->content());
        $this->assertStringContainsString('degraded', $result->content());
        $this->assertStringEndsWith('all good', $result->content());
        $this->assertFalse($result->isError());
    }

    public function testAHealthyFeedStaysSilent(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$parentEnd, $childEnd] = $pair;
        fwrite($childEnd, json_encode(['k' => 'result', 'content' => 'all good', 'error' => false, 'dropped' => 0]) . "\n");
        fclose($childEnd);

        $m = new \ReflectionMethod(AgentResume::class, 'collect');
        $m->setAccessible(true);
        $result = null;
        $m->invoke(null, $parentEnd, 0x7ffffff0, 'c1', null)->then(static function (ToolResult $r) use (&$result): void {
            $result = $r;
            Loop::stop();
        });
        $guard = Loop::addTimer(2.0, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($guard);

        $this->assertSame('all good', $result?->content());
    }

    public function testForkDowngradeIsAnnouncedOnTheResult(): void
    {
        $m = new \ReflectionMethod(AgentResume::class, 'downgraded');
        $m->setAccessible(true);
        /** @var ToolResult $r */
        $r = $m->invoke(null, new ToolResult('c1', 'the report', false));

        $this->assertStringContainsString('could not be forked', $r->content());
        $this->assertStringEndsWith('the report', $r->content());
    }
}
