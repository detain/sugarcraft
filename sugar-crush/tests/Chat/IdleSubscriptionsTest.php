<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\NoticeSink;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tui\AgentStrip;

/**
 * Two of `Chat::subscriptions()`' between-turn ticks (the O-2g carried
 * handoffs): the live agents strip's linger clock advances while the chat is
 * idle (P-B3), and the runtime-notice poll reads THIS session's notice inbox
 * — the workspace's — rather than whichever sink the process routes to
 * (W2-e).
 */
final class IdleSubscriptionsTest extends TestCase
{
    private float $now = 1000.0;

    public function testAFinishedRunKeepsTheStripClockTickingUntilItAgesOut(): void
    {
        $live = AgentLiveRegistry::new(fn (): float => $this->now);
        $chat = new Chat(workspace: WorkspaceContext::new()->withService(AgentLiveRegistry::class, $live));
        self::assertNull($chat->subscriptions(), 'nothing delegated, nothing armed');

        $live->apply(new SubAgentActivity(SubAgentActivity::OP_STARTED, 'r1', 'explore', 'a task', 1, '', parentCallId: 'call_1'));
        self::assertNull($chat->subscriptions(), 'a run nobody can finish between turns keeps nothing armed');

        $live->apply(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'r1', 'explore', '', 2, 'done', parentCallId: 'call_1'));
        $armed = $chat->subscriptions();
        self::assertNotNull($armed, 'a finished run lingering on the strip arms the idle clock');
        self::assertTrue($armed->has('crush.agent-strip-linger'));

        $this->now += AgentStrip::LINGER_SECONDS + 1;
        [$ticked] = $chat->update(new ToolEventPumpMsg());

        self::assertSame($this->now, $live->now(), 'the tick advanced the strip\'s clock');
        self::assertSame([], AgentStrip::items($live), 'and the run aged off the strip');
        self::assertNull($ticked->subscriptions(), 'so the next reconcile drops the tick');
    }

    public function testMidTurnTheToolEventTickCarriesTheStripClockInstead(): void
    {
        $live = AgentLiveRegistry::new(fn (): float => $this->now);
        $live->apply(new SubAgentActivity(SubAgentActivity::OP_FINISHED, 'r2', 'review', '', 2, 'done', parentCallId: 'call_2'));
        $subscriptions = (new Chat(
            inFlight: true,
            workspace: WorkspaceContext::new()->withService(AgentLiveRegistry::class, $live),
        ))->subscriptions();

        self::assertNotNull($subscriptions);
        self::assertTrue($subscriptions->has('crush.tool-event-poll'));
        self::assertFalse($subscriptions->has('crush.agent-strip-linger'), 'one clock at a time');
    }

    public function testTheNoticePollReadsTheWorkspacesOwnInbox(): void
    {
        $inbox = NoticeSink::new();
        $inbox->arm(crossFork: false);
        $chat = new Chat(drainsRuntimeNotices: true, workspace: WorkspaceContext::new(notices: $inbox));
        self::assertNull($chat->subscriptions(), 'an empty session inbox arms nothing');

        self::assertTrue($inbox->record('the parser dropped a duplicate parameter'));
        $armed = $chat->subscriptions();

        self::assertNotNull($armed, 'a notice pending in THIS session\'s inbox arms the poll');
        self::assertTrue($armed->has('crush.runtime-notice-poll'));
    }
}
