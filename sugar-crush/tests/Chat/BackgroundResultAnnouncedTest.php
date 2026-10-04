<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackgroundSessionStoppedMsg;
use SugarCraft\Crush\BackgroundTickMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;

/**
 * Roadmap 4.3-1: a settled `/bg` session's output comes back.
 *
 * Before this the daemon buffered the answer, the supervisor restored it onto
 * the session, and `Chat::pumpBackgroundSessions()` dropped it behind a
 * status-only notice — README claimed "the result comes back into the
 * transcript" and it never did. Now the settle appends an agent-visible,
 * user-role announcement (header, task, output, stats) and dispatches it as a
 * turn when the chat is idle, or queues it behind the running turn.
 *
 * Driven through the real `update()` entry with the {@see BackgroundTickMsg}
 * the live poll sends; the sessions are seeded into a real supervisor without
 * a daemon, which is the state `reapFinishedDaemon()` leaves behind.
 */
final class BackgroundResultAnnouncedTest extends TestCase
{
    private static function agent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * @param list<string>|null $tags
     */
    private static function session(
        string $id,
        BackgroundSessionStatus $status,
        string $output = '',
        ?array $tags = null,
        ?\DateTimeImmutable $createdAt = null,
    ): BackgroundSession {
        return (new BackgroundSession(
            id: $id,
            name: "job {$id}",
            agent: self::agent(),
            task: "do the {$id} work",
            workingDirectory: '/tmp',
            tags: $tags,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        ))->withOutput($output)->withStatus($status);
    }

    /** A chat that has already announced $id as running, the usual prior state. */
    private static function polledRunning(BackgroundSupervisor $supervisor, string $id, ?Chat $chat = null): Chat
    {
        $supervisor->addSession(self::session($id, BackgroundSessionStatus::Running));
        [$polled] = ($chat ?? new Chat(backend: new EchoBackend(), backgroundSupervisor: $supervisor))
            ->update(new BackgroundTickMsg());

        return $polled;
    }

    private static function last(Chat $chat): Message
    {
        return $chat->history[array_key_last($chat->history)];
    }

    // =====================================================================
    // The chat side
    // =====================================================================

    public function testAnIdleChatDispatchesTheSettledResultAsAUserTurn(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, "the answer is 42\n"));

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNotNull($cmd, 'the dispatched turn comes back with its Cmd, or it is never sent');
        $this->assertTrue($next->inFlight, 'an idle chat starts the turn at once');
        $this->assertSame([], $next->queuedPrompts(), 'and nothing is left waiting');
        $this->assertSame(['s1' => 'completed'], $next->backgroundStatuses());

        $row = self::last($next);
        $this->assertSame(Role::User, $row->role, 'the result is a user-role row');
        $this->assertFalse($row->uiOnly, 'that the model reads');
        $this->assertStringStartsWith("[Background session s1 ('job s1') completed]", $row->content);
        $this->assertStringContainsString("Output:\nthe answer is 42", $row->content);
        $this->assertStringContainsString('Stats: runtime ', $row->content);

        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains("Background session s1 ('job s1') is now completed.", $contents, 'the status notice still lands');
    }

    public function testTheResultReachesTheProviderWire(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, 'BGDONE'));

        [$next] = $chat->update(new BackgroundTickMsg());

        $wire = implode("\n", array_map(static fn (Message $m): string => $m->content, Message::agentVisible($next->history)));
        $this->assertStringContainsString('BGDONE', $wire);
    }

    public function testABusyChatQueuesTheResultBehindTheRunningTurn(): void
    {
        $supervisor = new BackgroundSupervisor();
        [$busy] = (new Chat(inputBuf: 'the first thing', backend: new EchoBackend(), backgroundSupervisor: $supervisor))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($busy->inFlight, 'fixture: a turn is running');
        $busy = self::polledRunning($supervisor, 's1', $busy);
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, 'later answer'));

        [$next, $cmd] = $busy->update(new BackgroundTickMsg());

        $this->assertNull($cmd, 'nothing new starts while a turn runs');
        $this->assertCount(1, $next->queuedPrompts());
        $this->assertStringContainsString('later answer', $next->queuedPrompts()[0]);
        $this->assertSame('Its result goes to the agent as soon as this turn finishes.', self::last($next)->content);
        $this->assertNotSame(Role::User, self::last($next)->role, 'no second user turn is wedged before the running reply');

        [$settled, $released] = $next->update(new AssistantMsg(Message::assistant('the first answer')));

        $this->assertNotNull($released, 'the settle releases the queued result as a turn');
        $this->assertTrue($settled->inFlight);
        $this->assertSame([], $settled->queuedPrompts());
        $this->assertSame(Role::User, self::last($settled)->role);
        $this->assertStringContainsString('later answer', self::last($settled)->content);
    }

    public function testSessionsSettlingInOnePollShareOneTurn(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        $chat = self::polledRunning($supervisor, 's2', $chat);
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, 'first'));
        $supervisor->addSession(self::session('s2', BackgroundSessionStatus::Failed, 'second'));

        [$next] = $chat->update(new BackgroundTickMsg());

        $userRows = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === Role::User));
        $this->assertCount(1, $userRows, 'one turn, not one per session');
        $this->assertStringContainsString("[Background session s1 ('job s1') completed]", $userRows[0]->content);
        $this->assertStringContainsString("[Background session s2 ('job s2') failed]", $userRows[0]->content);
    }

    public function testANonTerminalTransitionAnnouncesNothing(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Streaming));

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight);
        $this->assertSame([], $next->queuedPrompts());
        $this->assertSame("Background session s1 ('job s1') is now streaming.", self::last($next)->content);
    }

    public function testASettleIsAnnouncedOnceAcrossPolls(): void
    {
        $supervisor = new BackgroundSupervisor();
        [$busy] = (new Chat(inputBuf: 'go', backend: new EchoBackend(), backgroundSupervisor: $supervisor))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $busy = self::polledRunning($supervisor, 's1', $busy);
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, 'once'));

        [$first] = $busy->update(new BackgroundTickMsg());
        [$second, $cmd] = $first->update(new BackgroundTickMsg());

        $this->assertSame($first, $second, 'a settled session is not re-queued every poll');
        $this->assertNull($cmd);
        $this->assertCount(1, $second->queuedPrompts());
    }

    public function testASessionTheUserStoppedStartsNoTurn(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Stopped, 'partial'));

        [$reported] = $chat->update(new BackgroundSessionStoppedMsg('s1', BackgroundStopOutcome::StoppedViaIpc, 'job s1'));
        [$next, $cmd] = $reported->update(new BackgroundTickMsg());

        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight, 'the user asked for it to end; no surprise turn');
        $this->assertSame([], $next->queuedPrompts());
    }

    public function testTheUsersHalfTypedDraftSurvivesTheAutoDispatch(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = self::polledRunning($supervisor, 's1');
        foreach (['w', 'i', 'p'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        $supervisor->addSession(self::session('s1', BackgroundSessionStatus::Completed, 'done'));

        [$next] = $chat->update(new BackgroundTickMsg());

        $this->assertTrue($next->inFlight);
        $this->assertSame('wip', $next->inputBuf, 'the draft in the box is the user\'s, not the announcement\'s');
    }

    // =====================================================================
    // The session side: BackgroundSession::announcement() and its figures
    // =====================================================================

    public function testTheAnnouncementCarriesTaskOutputAndRuntime(): void
    {
        $session = self::session(
            's9',
            BackgroundSessionStatus::Completed,
            "line one\nline two\n",
            createdAt: new \DateTimeImmutable('-150 seconds'),
        );

        $text = $session->announcement();

        $this->assertSame([
            "[Background session s9 ('job s9') completed]",
            'Task: do the s9 work',
            'Output:',
            'line one',
            'line two',
        ], array_slice(explode("\n", $text), 0, 5));
        $this->assertMatchesRegularExpression('/^Stats: runtime 2m 3\ds$/m', $text);
    }

    public function testTokensCostErrorAndTheForkResumePointerAppearWhenKnown(): void
    {
        $session = self::session('s9', BackgroundSessionStatus::Failed, '', tags: ['fork', 'session:sess_abc']);
        $session->tokensUsed = 12_345;
        $session->costUsd = 0.0123;
        $session->error = 'provider went away';

        $text = $session->announcement();

        $this->assertStringStartsWith("[Background session s9 ('job s9') failed]", $text);
        $this->assertStringContainsString("\nError: provider went away\n", $text);
        $this->assertStringContainsString("\nOutput: (none)\n", $text);
        $this->assertStringContainsString('12.3K tokens', $text);
        $this->assertStringContainsString('$0.0123', $text);
        $this->assertStringContainsString('resume: sugarcrush --resume sess_abc', $text);
        $this->assertSame('sess_abc', $session->forkedSessionId());
    }

    public function testAPlainBgSessionHasNoResumePointer(): void
    {
        $session = self::session('s9', BackgroundSessionStatus::Completed, 'x');

        $this->assertNull($session->forkedSessionId());
        $this->assertStringNotContainsString('resume:', $session->announcement());
    }

    public function testAnOversizedOutputIsClippedAndSaysSo(): void
    {
        $session = self::session('s9', BackgroundSessionStatus::Completed, str_repeat('é', 30));

        $text = $session->announcement(10);

        $this->assertStringContainsString("Output:\n" . str_repeat('é', 10) . "\n[… 20 more characters of output not shown]", $text);
    }

    public function testEachSettledStatusReadsAsItsOwnOutcome(): void
    {
        $words = [
            'completed' => BackgroundSessionStatus::Completed,
            'failed' => BackgroundSessionStatus::Failed,
            'was stopped' => BackgroundSessionStatus::Stopped,
            'timed out' => BackgroundSessionStatus::TimedOut,
        ];
        foreach ($words as $word => $status) {
            $session = self::session('s9', $status);
            $this->assertTrue($session->isSettled(), $status->value);
            $this->assertStringStartsWith("[Background session s9 ('job s9') {$word}]", $session->announcement());
        }

        foreach ([BackgroundSessionStatus::Pending, BackgroundSessionStatus::Running, BackgroundSessionStatus::Streaming, BackgroundSessionStatus::Stalled] as $status) {
            $this->assertFalse(self::session('s9', $status)->isSettled(), $status->value);
        }
    }

    public function testSettlingStampsCompletedAtOnceAndStatusChangesKeepTheFigures(): void
    {
        $running = self::session('s9', BackgroundSessionStatus::Running);
        $this->assertNull($running->completedAt);
        $running->tokensUsed = 7;
        $running->costUsd = 0.5;
        $running->error = 'boom';

        $done = $running->withStatus(BackgroundSessionStatus::Failed);
        $this->assertNotNull($done->completedAt);
        $this->assertSame(7, $done->tokensUsed);
        $this->assertSame(0.5, $done->costUsd);
        $this->assertSame('boom', $done->error);

        $again = $done->withOutput('late')->withStatus(BackgroundSessionStatus::Stopped);
        $this->assertSame($done->completedAt, $again->completedAt, 'the first settle is the completion time');
    }
}
