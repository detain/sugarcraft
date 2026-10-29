<?php

/**
 * C-AGT-4.e REVIEW-1 finding 4: the TUI drain leg.
 *
 * Chat::pumpBackgroundSessions() drains THIS session's cross-session mailbox
 * (the SessionMailbox a peer's `SendMessage` to `session:<id>` wrote) on the
 * same door that wakes the chat for a sub-agent's `parent` reply — mail goes
 * to the agent as a followup-shaped turn when idle; while a turn runs the
 * drain is skipped and the message waits for the idle beat. Before this file
 * neither drain leg had a test: deleting the mail block in Chat left the whole
 * filtered suite green.
 *
 * Pins:
 * - an idle chat wakes: notice row + the framed message as the turn's user
 *   row + the mailbox consumed;
 * - two messages land as one turn;
 * - a busy chat neither queues nor consumes — the mail waits.
 *
 * Deleting the `$mail = ... drainSessionMail()` block in
 * Chat::pumpBackgroundSessions() reddens the wake pins; deleting the gate on
 * the busy pin would consume the mail (drain-to-empty asserts).
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\Live\AgentMessage;
use SugarCraft\Code\Agents\Live\MessageMode;
use SugarCraft\Code\Agents\Live\SessionMailbox;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

final class SessionMailWakeTest extends TestCase
{
    private string $session;

    private string $root;

    protected function setUp(): void
    {
        $this->session = 'sm-' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . '/sc_session_mail_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        if (SessionMailbox::forSession($this->session) === null) {
            self::markTestSkipped('no owned home for session mail');
        }
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
        $inbox = SessionMailbox::forSession($this->session);
        if ($inbox !== null) {
            self::remove($inbox->directory());
        }
    }

    public function testAnIdleChatWakesWhenSessionMailArrives(): void
    {
        $chat = $this->polled(new BackgroundSupervisor());
        $this->assertFalse($chat->inFlight, 'fixture: the chat is idle');

        $this->plant('the deploy script needs a flag');
        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNotNull($cmd, 'the woken turn comes back with its Cmd, or it is never sent');
        $this->assertTrue($next->inFlight, 'an idle chat starts the turn at once');

        $row = $next->history[array_key_last($next->history)];
        $this->assertSame(Role::User, $row->role, 'the mail is a turn the agent reads');
        $this->assertStringStartsWith('<session-message from="xm-peer" mode="followup">', $row->content);
        $this->assertStringContainsString('the deploy script needs a flag', $row->content);
        $this->assertStringContainsString('never user approval', $row->content, 'the frame carries its no-authority line');

        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains('Another session sent the agent a message; it goes to the agent now.', $contents);

        $inbox = SessionMailbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $this->assertSame([], $inbox->take(), 'taken once: the mailbox is empty');
    }

    public function testTwoMessagesWakeOneTurn(): void
    {
        $chat = $this->polled(new BackgroundSupervisor());
        $this->plant('first finding');
        $this->plant('second finding');

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNotNull($cmd);
        $row = $next->history[array_key_last($next->history)];
        $this->assertStringContainsString('first finding', $row->content);
        $this->assertStringContainsString('second finding', $row->content, 'both in the one turn');
    }

    public function testABusyChatLeavesSessionMailToTheIdleBeat(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(self::backgroundSession());
        $chat = new Chat(inputBuf: 'the first thing', backend: self::engine($this->session, $this->root), backgroundSupervisor: $supervisor);
        [$busy] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($busy->inFlight, 'fixture: a turn is running');

        $this->plant('mid-turn mail');
        [$next] = $busy->update(new BackgroundTickMsg());

        $this->assertSame($busy->queuedPrompts(), $next->queuedPrompts(), 'nothing queued from session mail while a turn runs');
        $inbox = SessionMailbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $this->assertCount(1, $inbox->take(), 'the mail waits — the busy beat neither submits nor consumes it');
    }

    /** A chat that has already announced the running background session, the usual prior state. */
    private function polled(BackgroundSupervisor $supervisor): Chat
    {
        $supervisor->addSession(self::backgroundSession());
        [$polled] = (new Chat(backend: self::engine($this->session, $this->root), backgroundSupervisor: $supervisor))
            ->update(new BackgroundTickMsg());

        return $polled;
    }

    /** Land one signed followup message from a peer session in THIS session's mailbox. */
    private function plant(string $text): void
    {
        $why = SessionMailbox::send($this->session, AgentMessage::new('agent:xm-peer', $text, MessageMode::Followup));
        $this->assertNull($why, 'the send is accepted by the open inbox: ' . (string) $why);
    }

    private static function engine(?string $session, ?string $root = null): EngineBackend
    {
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()->withSessionId($session);

        return $root === null ? $engine : $engine->withRoot($root);
    }

    private static function backgroundSession(): BackgroundSession
    {
        return (new BackgroundSession(
            id: 'bg1',
            name: 'job bg1',
            agent: new Agent(
                name: 'bg-agent',
                description: 'Background agent',
                prompt: '',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the background work',
            workingDirectory: '/tmp',
            createdAt: new \DateTimeImmutable(),
        ))->withStatus(BackgroundSessionStatus::Running);
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

            return;
        }
        @unlink($path);
    }
}
