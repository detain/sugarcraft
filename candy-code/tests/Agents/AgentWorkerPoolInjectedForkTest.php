<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ForksAskFreeAgents;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Tests\Support\BuildsPoolSubAgentTrait;

/**
 * P2-1: an injected executor that implements {@see ForksAskFreeAgents} is
 * asked per agent; a cleared agent takes the pool's forking path, the rest
 * keep the inline run, and the executor is told which happened.
 */
final class AgentWorkerPoolInjectedForkTest extends TestCase
{
    use BuildsPoolSubAgentTrait;

    public function testClearedAgentsForkAndRefusedOnesRunInline(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl_fork()');
        }

        $executor = self::executor(['b' => 'may ask: Bash']);
        $pool = new AgentWorkerPool(executor: $executor);

        $results = [];
        foreach ($pool->executeAll([self::agent('a'), self::agent('b'), self::agent('c')], self::request()) as $result) {
            $results[$result->agentId] = (int) $result->output;
        }

        $parent = (int) getmypid();
        $this->assertSame($parent, $results['b'], 'the refused agent ran in the parent');
        $this->assertNotSame($parent, $results['a']);
        $this->assertNotSame($parent, $results['c']);
        $this->assertNotSame($results['a'], $results['c'], 'one child per cleared agent');
        $this->assertSame(
            ['a' => [true, null], 'b' => [false, 'may ask: Bash'], 'c' => [true, null]],
            $executor->log,
        );
        $this->assertGreaterThan(0, $executor->beats, 'the parent idled while children ran');
    }

    public function testWithoutPcntlEveryAgentRunsInlineAndTheReasonIsRecorded(): void
    {
        $executor = self::executor([]);
        $pool = new AgentWorkerPool(executor: $executor);
        (new \ReflectionProperty(AgentWorkerPool::class, 'forcePcntlUnavailableForTesting'))->setValue($pool, true);

        foreach ($pool->executeAll([self::agent('a')], self::request()) as $result) {
            $this->assertSame((int) getmypid(), (int) $result->output);
        }

        $this->assertSame(['a' => [false, 'pcntl_fork() is unavailable in this process']], $executor->log);
    }

    public function testAFailedForkFallsBackInlineAndSaysSo(): void
    {
        $executor = self::executor([]);
        $pool = new AgentWorkerPool(executor: $executor);
        (new \ReflectionProperty(AgentWorkerPool::class, 'forceForkFailureForTesting'))->setValue($pool, true);
        (new \ReflectionProperty(AgentWorkerPool::class, 'forcePcntlUnavailableForTesting'))->setValue($pool, false);

        $previous = ini_get('error_log');
        $log = tempnam(sys_get_temp_dir(), 'sc_p21_forkfail_');
        self::assertIsString($log);
        ini_set('error_log', $log);
        try {
            foreach ($pool->executeAll([self::agent('a')], self::request()) as $result) {
                $this->assertSame((int) getmypid(), (int) $result->output);
            }
            $this->assertSame(1, $pool->forkFailureCount());
            // Its teardown line goes to the redirected log too.
            unset($pool);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }

        $this->assertSame(['a' => [false, 'pcntl_fork() failed']], $executor->log);
    }

    /**
     * @param array<string, string> $refusals agent id => why it must stay inline
     */
    private static function executor(array $refusals): ForksAskFreeAgents
    {
        return new class ($refusals) implements ForksAskFreeAgents {
            /** @var array<string, array{0: bool, 1: ?string}> */
            public array $log = [];

            public int $beats = 0;

            /** @param array<string, string> $refusals */
            public function __construct(private array $refusals)
            {
            }

            public function forkRefusal(SubAgent $agent, CompleteRequest $request): ?string
            {
                return $this->refusals[$agent->id] ?? null;
            }

            public function recordDispatch(SubAgent $agent, bool $forked, ?string $why): void
            {
                $this->log[$agent->id] = [$forked, $why];
            }

            public function whileForkedAgentsRun(): void
            {
                $this->beats++;
            }

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                usleep(100_000);

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: (string) getmypid());
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };
    }

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'test-model', messages: [['role' => 'user', 'content' => 'go']]);
    }
}
