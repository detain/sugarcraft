<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Audit AG-5 (the Chat half): since WF-1 the pool enforces
 * {@see SubAgent::$timeout} on its forking path, and the pool
 * {@see Chat::executeAgents()} builds from its {@see AgentPoolConfig} forks
 * every agent through the chat's engine — so a SubAgent built without a
 * timeout was killed at the constructor's 300 s default whatever the config
 * said. The config's `defaultTimeoutSeconds` is now the bound each agent is
 * dispatched under, and `0` means none.
 *
 * Both cases drive a real fork: the scripted provider answers only after
 * {@see self::SLOW_SECONDS}, inside the child.
 */
final class ExecuteAgentsTimeoutTest extends TestCase
{
    private const SLOW_SECONDS = 2.5;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('the engine-backed pool forks one child per agent');
        }
    }

    private static function slowChat(int $configTimeout): Chat
    {
        $provider = new ScriptedProvider([
            static function (): CompleteResponse {
                usleep((int) (self::SLOW_SECONDS * 1_000_000));

                return new CompleteResponse(content: 'finished slowly');
            },
        ]);

        return (new Chat(backend: EngineBackend::new($provider, 'm')))
            ->withAgentPoolConfig(new AgentPoolConfig(maxConcurrent: 1, defaultTimeoutSeconds: $configTimeout));
    }

    /** @return list<AgentResult> */
    private static function dispatch(Chat $chat, SubAgent $agent): array
    {
        return array_values(iterator_to_array(
            $chat->executeAgents([$agent], new CompleteRequest(model: 'm', messages: [])),
            false,
        ));
    }

    public function testTheConfiguredTimeoutBoundsAnAgentThatCarriesNoneOfItsOwn(): void
    {
        // No `timeout:` — the constructor's 300 s default, which used to win.
        $agent = new SubAgent(id: 'slow-' . bin2hex(random_bytes(4)), agent: RosterAgent::named('slow'), task: 'take your time');

        $started = hrtime(true);
        $results = self::dispatch(self::slowChat(1), $agent);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::TimedOut, $results[0]->status, 'the config said 1 s; the agent outlived it');
        self::assertLessThan(self::SLOW_SECONDS, $elapsed, 'killed at the configured bound, not after the slow answer landed');
    }

    public function testAZeroConfigTimeoutLeavesTheAgentUnbounded(): void
    {
        // A 1 s bound on the agent itself: under the config's 0 ("no
        // per-agent bound") the run must outlive it and finish.
        $agent = new SubAgent(id: 'patient-' . bin2hex(random_bytes(4)), agent: RosterAgent::named('patient'), task: 'take your time', timeout: 1);

        $results = self::dispatch(self::slowChat(0), $agent);

        self::assertCount(1, $results);
        self::assertSame(AgentStatus::Completed, $results[0]->status, (string) $results[0]->error?->getMessage());
        self::assertSame('finished slowly', $results[0]->output);
    }
}
