<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\Methods\AgentsMethods;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;

/**
 * The Agent View's `Ctrl+X b` over the wire (roadmap P-E3 / W10-d):
 * `agents.control` takes `background`, which reaches a running run's mailbox
 * as {@see AgentInbox::BACKGROUND_VERB} — the verb the run's Task call reads
 * to promote itself to a background session — and is refused, as in the TUI,
 * for a nested run and for one that has finished.
 */
final class AgentsBackgroundControlTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testTheWireEnumIsTheInboxVerbsPlusBackground(): void
    {
        self::assertSame([...AgentInbox::CONTROL_VERBS, AgentInbox::BACKGROUND_VERB], AgentsMethods::CONTROL_VERBS);

        $params = MethodSchemas::params('agents.control');
        self::assertNotNull($params);
        self::assertSame([], ProtocolSchema::errors(['sessionId' => 's', 'agentId' => 'a', 'verb' => 'background'], $params, 'agents.control'));
    }

    public function testBackgroundReachesARunningRunsMailboxAsAControl(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit($this->beat(SubAgentActivity::OP_STARTED, 'run-1', 1));
        $this->fixture->run(0.08);

        $sent = $client->call('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-1', 'verb' => 'background']);
        self::assertSame('queued', $sent['status']);

        $inbox = $this->fixture->hub->workspace()->agentInbox($sessionId);
        self::assertInstanceOf(AgentInbox::class, $inbox);
        self::assertSame([AgentInbox::BACKGROUND_VERB], array_map(static fn ($m): string => $m->text, $inbox->takeControls('run-1')));
    }

    public function testANestedOrFinishedRunIsNotSentToTheBackground(): void
    {
        [$client, $sessionId] = $this->turn();
        $this->fixture->backend->emit($this->beat(SubAgentActivity::OP_STARTED, 'run-2', 1, parentAgentId: 'run-1'));
        $this->fixture->backend->emit($this->beat(SubAgentActivity::OP_STARTED, 'run-3', 1));
        $this->fixture->backend->emit($this->beat(SubAgentActivity::OP_FINISHED, 'run-3', 2, outcome: SubAgentActivity::OUTCOME_COMPLETE));
        $this->fixture->run(0.08);

        $nested = $client->request('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-2', 'verb' => 'background']);
        self::assertSame(ErrorCode::Conflict->value, $nested['error']['code']);
        self::assertSame('agent_nested', $nested['error']['data']['kind']);

        $finished = $client->request('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-3', 'verb' => 'background']);
        self::assertSame('agent_finished', $finished['error']['data']['kind']);

        $inbox = $this->fixture->hub->workspace()->agentInbox($sessionId);
        self::assertSame([], $inbox?->takeControls('run-2') ?? [], 'nothing was queued');

        $paused = $client->call('agents.control', ['sessionId' => $sessionId, 'agentId' => 'run-2', 'verb' => 'pause']);
        self::assertSame('queued', $paused['status'], 'a nested run still takes the other verbs');
    }

    /** @return array{0: \SugarCraft\Crush\Tests\Server\Support\WireClient, 1: string} */
    private function turn(): array
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'delegate it']);

        return [$client, $sessionId];
    }

    private function beat(string $op, string $id, int $seq, string $outcome = '', ?string $parentAgentId = null): SubAgentActivity
    {
        return new SubAgentActivity(
            op: $op,
            id: $id,
            name: 'reviewer',
            task: 'fix it',
            seq: $seq,
            tail: '',
            parentCallId: 'c1',
            outcome: $outcome,
            parentAgentId: $parentAgentId,
        );
    }
}
