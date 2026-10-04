<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-B2, the Chat half: a delegated run's v2 frames reach the live
 * registry on both the live pump and the settled queue, and the transcript
 * draws one width-safe line per run under its Task row — animated while it
 * runs, its real outcome once it ends, "queued" while it waits for a slot.
 */
final class AgentLiveLinesTest extends TestCase
{
    private const GENERATION = 3;

    private float $now = 1000.0;

    public function testALiveRunGetsAnAnimatedLineUnderItsTaskRow(): void
    {
        [$chat, $inbox] = $this->running(100);

        $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore', 'description' => 'Map the login flow'])];
        $inbox[] = [self::GENERATION, self::frame('started', 1)];
        $inbox[] = [self::GENERATION, self::frame('progress', 2, [ActivityItem::toolStarted('c1', 'Grep', '"LoginController" routes/')], ['tools' => 7, 'tokensIn' => 3100, 'tokensOut' => 1000, 'startedAt' => 988.0])];
        $chat = $this->pump($chat, 2);

        $frame = self::plain(Renderer::render($chat));
        self::assertStringContainsString('running: ', $frame, 'the Task row itself is unchanged while the run works');
        self::assertStringContainsString('└ ⠋ Grep "LoginController" routes/ · 7 tools · 0:12 · 4.1K tok', $frame);

        // The pump's tick moves the registry's clock — and only it does.
        $this->now = 1000.08;
        self::assertStringContainsString('└ ⠋', self::plain(Renderer::render($chat)), 'rendering never reads the clock');
        [$chat] = $chat->update(new ToolEventPumpMsg());
        self::assertStringContainsString('└ ⠙ Grep', self::plain(Renderer::render($chat)));
    }

    public function testOneTickFoldsEveryBeatQueuedBehindTheFirst(): void
    {
        [$chat, $inbox] = $this->running(100);
        $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore'])];
        [$chat] = $chat->update(new ToolEventPumpMsg());

        $inbox[] = [self::GENERATION, self::frame('started', 1)];
        $inbox[] = [self::GENERATION, self::frame('progress', 2, [ActivityItem::thinking()])];
        $inbox[] = [self::GENERATION, self::frame('progress', 3, [ActivityItem::text('The guard is attached in')])];
        $inbox[] = [self::GENERATION, new ToolFinished('tc_1', 'Task', new ToolResult('tc_1', 'the report'))];
        [$chat, $more] = $chat->update(new ToolEventPumpMsg());

        self::assertCount(1, $inbox, 'the three beats went in one tick; the Task\'s own finish waits its turn');
        self::assertInstanceOf(ToolFinished::class, $inbox[0][1]);
        self::assertNotNull($more, 'the pump re-arms for what is left');
        self::assertSame(3, $chat->agentLive()->get('run_a')?->seq);
        self::assertStringContainsString('└ ⠋ "The guard is attached in"', self::plain(Renderer::render($chat)));
    }

    public function testAQueuedMemberSaysQueuedOnItsTaskRow(): void
    {
        [$chat, $inbox] = $this->running(100);
        $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore'])];
        $inbox[] = [self::GENERATION, new SubAgentActivity('queued', SubAgentActivity::queuedId('tc_1'), 'explore', 'map it', 1, '', parentCallId: 'tc_1')];
        $chat = $this->pump($chat, 2);

        $frame = self::plain(Renderer::render($chat));
        self::assertStringContainsString('◌ queued: ', $frame);
        self::assertStringNotContainsString('running: ', $frame, 'nothing runs yet, and the row must not say so');
        self::assertDoesNotMatchRegularExpression('/└ /u', $frame, 'a queued member has no activity to show');

        $inbox[] = [self::GENERATION, self::frame('started', 1)];
        [$chat] = $chat->update(new ToolEventPumpMsg());

        $frame = self::plain(Renderer::render($chat));
        self::assertStringContainsString('⠴ running: ', $frame, 'its own start takes the slot over');
        self::assertStringContainsString('└ ⠋ starting…', $frame);
    }

    public function testTheSettledQueueFillsTheLineWithTheRunsRealOutcome(): void
    {
        [$chat] = $this->running(100);
        $events = [
            new ToolStarted('tc_1', 'Task', ['agent' => 'explore']),
            self::frame('started', 1),
            self::frame('progress', 2, [ActivityItem::toolStarted('c1', 'Read', 'a.php')], ['tools' => 1, 'startedAt' => 900.0]),
            new SubAgentActivity('finished', 'run_a', 'explore', '', 3, '', parentCallId: 'tc_1', stats: ['tools' => 3, 'tokensIn' => 9000, 'tokensOut' => 800, 'startedAt' => 900.0], outcome: 'failed', error: 'sub-agent "explore" failed: step cap 50 reached', resumeId: 'abcdef0123456789'),
            new ToolFinished('tc_1', 'Task', new ToolResult('tc_1', 'sub-agent "explore" failed', isError: true, durationMs: 31_000)),
        ];

        $msg = new BackendToolEventsMsg($events, Message::assistant('done'), self::GENERATION);
        for ($i = 0; $i < count($events); $i++) {
            [$chat, $cmd] = $chat->update($msg);
            $msg = $cmd();
            self::assertInstanceOf(BackendToolEventsMsg::class, $msg);
        }

        $frame = self::plain(Renderer::render($chat));
        self::assertStringNotContainsString('running: ', $frame);
        self::assertStringContainsString('└ ✗ failed: step cap 50 reached · 3 tools · 0:31 · 9.8K tok · resumable', $frame, 'timed by the row\'s own duration, not the replay\'s clock');
    }

    public function testAFinishedRunKeepsItsLineUnderTheSettledRow(): void
    {
        [$chat, $inbox] = $this->running(100);
        $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore'])];
        $inbox[] = [self::GENERATION, self::frame('started', 1)];
        $inbox[] = [self::GENERATION, new SubAgentActivity('finished', 'run_a', 'explore', '', 2, 'the report', parentCallId: 'tc_1', stats: ['tools' => 2, 'tokensIn' => 400, 'tokensOut' => 100, 'startedAt' => 990.0], outcome: 'complete')];
        $inbox[] = [self::GENERATION, new ToolFinished('tc_1', 'Task', new ToolResult('tc_1', 'the report', durationMs: 4_000))];
        $chat = $this->pump($chat, 3);

        self::assertStringContainsString('└ ✓ done · 2 tools · 0:04 · 500 tok', self::plain(Renderer::render($chat)));
    }

    public function testARunThatNeverReportedItsEndDrawsNothingUnderTheSettledRow(): void
    {
        [$chat, $inbox] = $this->running(100);
        $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore'])];
        $inbox[] = [self::GENERATION, self::frame('progress', 2, [ActivityItem::toolStarted('c1', 'Read', 'a.php')])];
        $inbox[] = [self::GENERATION, new ToolFinished('tc_1', 'Task', new ToolResult('tc_1', 'interrupted', isError: true))];
        $chat = $this->pump($chat, 3);

        self::assertDoesNotMatchRegularExpression('/└ /u', self::plain(Renderer::render($chat)), 'a frozen spinner would say it still runs');
    }

    public function testABeatFromAnAbandonedTurnNeverReachesTheRegistry(): void
    {
        [$chat, $inbox] = $this->running(100);
        $inbox[] = [self::GENERATION - 1, self::frame('started', 1)];
        [$chat] = $chat->update(new ToolEventPumpMsg());

        self::assertNull($chat->agentLive()->get('run_a'));
    }

    public function testAWorkspaceRegisteredRegistryIsTheOneUsed(): void
    {
        $registry = AgentLiveRegistry::new(fn (): float => $this->now);
        $chat = new Chat(backend: new EchoBackend(), workspace: WorkspaceContext::new()->withService(AgentLiveRegistry::class, $registry));

        self::assertSame($registry, $chat->agentLive());
    }

    public function testEveryRowFitsThePaneAtEveryWidth(): void
    {
        foreach ([24, 30, 40, 60, 80, 120, 160] as $cols) {
            [$chat, $inbox] = $this->running($cols);
            $inbox[] = [self::GENERATION, new ToolStarted('tc_1', 'Task', ['agent' => 'explore', 'description' => str_repeat('a long description ', 10)])];
            $inbox[] = [self::GENERATION, self::frame('progress', 2, [ActivityItem::toolStarted('c1', 'Bash', str_repeat('make -j8 everything ', 10))], [
                'tools' => 123, 'tokensIn' => 1_234_567, 'tokensOut' => 1, 'costUsd' => 3.25, 'startedAt' => 1.0,
            ])];
            $chat = $this->pump($chat, 2);

            $frame = Renderer::render($chat);
            self::assertMatchesRegularExpression('/└ ⠋/u', self::plain($frame), "the line is drawn at {$cols} columns");
            foreach (explode("\n", $frame) as $row) {
                self::assertLessThanOrEqual($cols, Width::string(self::plain($row)), "at {$cols} columns: " . self::plain($row));
            }
        }
    }

    /**
     * A Chat mid-turn on its own inbox, whose registry runs on this test's
     * clock.
     *
     * @return array{0: Chat, 1: \ArrayObject<int, array{0: int, 1: object}>}
     */
    private function running(int $cols): array
    {
        $inbox = new \ArrayObject();
        AgentLiveRegistry::of($inbox, fn (): float => $this->now);
        $chat = new Chat(history: [Message::user('go')], backend: new EchoBackend(), inFlight: true, generation: self::GENERATION, liveToolEvents: $inbox);
        [$chat] = $chat->update(new WindowSizeMsg($cols, 40));

        return [$chat, $inbox];
    }

    private function pump(Chat $chat, int $ticks): Chat
    {
        for ($i = 0; $i < $ticks; $i++) {
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        return $chat;
    }

    /**
     * @param list<ActivityItem> $items
     * @param array<string, int|float> $stats
     */
    private static function frame(string $op, int $seq, array $items = [], array $stats = []): SubAgentActivity
    {
        return new SubAgentActivity($op, 'run_a', 'explore', '', $seq, '', parentCallId: 'tc_1', items: $items, stats: $stats);
    }

    private static function plain(string $text): string
    {
        return Ansi::strip(preg_replace('/\x{E000}[^\x{E001}]*\x{E001}/u', '', $text) ?? $text);
    }
}
