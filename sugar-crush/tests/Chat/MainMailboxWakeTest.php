<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\Live\AgentRunCards;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackgroundTickMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Roadmap 4.4: a reply a background sub-agent sends the session's own agent
 * (`SendMessage` to `parent`) lands in the `main` mailbox. While a turn runs,
 * its child drains that mailbox at each step boundary; while the TUI is IDLE
 * nothing would, so the background poll that a running background session
 * keeps armed drains it and dispatches the reply as a turn
 * ({@see Chat::pumpBackgroundSessions()}). Driven through the real `update()`
 * entry with the {@see BackgroundTickMsg} the live poll sends.
 */
final class MainMailboxWakeTest extends TestCase
{
    private const RUN_ID = 'subagent_3_bgwake';

    private string $session;

    private string $root;

    protected function setUp(): void
    {
        $this->session = 'main-wake-' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . '/sc_main_wake_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        if (AgentInbox::forSession($this->session) === null) {
            self::markTestSkipped('no owned home for mailboxes');
        }
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
        self::remove((string) AgentInbox::defaultRoot() . '/' . $this->session);
        self::remove((string) SubAgentTranscriptLog::defaultRoot() . '/' . $this->session);
    }

    public function testAnIdleChatWakesWhenASubAgentReplyLandsInTheMainMailbox(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = $this->polled($supervisor);
        $this->assertFalse($chat->inFlight, 'fixture: the chat is idle');
        $this->assertNotNull($chat->subscriptions(), 'the running background session keeps the poll armed');

        $this->send('all green: 3 files changed');
        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNotNull($cmd, 'the woken turn comes back with its Cmd, or it is never sent');
        $this->assertTrue($next->inFlight, 'an idle chat starts the turn at once');
        $this->assertSame([], $next->queuedPrompts());

        $row = $next->history[array_key_last($next->history)];
        $this->assertSame(Role::User, $row->role, 'the reply is a turn the agent reads');
        $this->assertFalse($row->uiOnly);
        $this->assertStringStartsWith('<child-message from="' . self::RUN_ID . '">', $row->content);
        $this->assertStringContainsString('all green: 3 files changed', $row->content);

        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains('A sub-agent sent the agent a message; it goes to the agent now.', $contents);
        $this->assertSame([], self::engine($this->session)->mainMailbox()?->drain(0) ?? [], 'taken once: the mailbox is empty');
    }

    public function testTwoRepliesWakeOneTurn(): void
    {
        $chat = $this->polled(new BackgroundSupervisor());
        $this->send('first finding');
        $this->send('second finding');

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNotNull($cmd);
        $row = $next->history[array_key_last($next->history)];
        $this->assertStringContainsString('first finding', $row->content);
        $this->assertStringContainsString('second finding', $row->content, 'both in the one turn');
        $contents = array_map(static fn (Message $m): string => $m->content, $next->history);
        $this->assertContains('Sub-agents sent the agent 2 messages; they go to the agent now.', $contents);
    }

    public function testABusyChatLeavesTheReplyToTheRunningTurnsChild(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(self::backgroundSession());
        $chat = new Chat(inputBuf: 'the first thing', backend: self::engine($this->session, $this->root), backgroundSupervisor: $supervisor);
        [$busy] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($busy->inFlight, 'fixture: a turn is running');

        $this->send('mid-turn reply');
        [$next] = $busy->update(new BackgroundTickMsg());

        $this->assertSame($busy->queuedPrompts(), $next->queuedPrompts(), 'nothing queued from the mailbox');
        $rows = self::engine($this->session)->mainMailbox()?->drain(0) ?? [];
        $this->assertCount(1, $rows, 'the reply waits for the running turn\'s step boundary');
    }

    public function testNoEngineSessionNoWake(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession(self::backgroundSession());
        [$chat] = (new Chat(backend: self::engine(null), backgroundSupervisor: $supervisor))->update(new BackgroundTickMsg());
        $this->send('nobody can read this');

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertSame($chat, $next, 'nothing moved');
        $this->assertNull($cmd);
    }

    /** A chat that has already announced the running background session, the usual prior state. */
    private function polled(BackgroundSupervisor $supervisor): Chat
    {
        $supervisor->addSession(self::backgroundSession());
        [$polled] = (new Chat(backend: self::engine($this->session, $this->root), backgroundSupervisor: $supervisor))
            ->update(new BackgroundTickMsg());

        return $polled;
    }

    private function send(string $text): void
    {
        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);
        $inbox->send(AgentRunCards::MAIN, AgentMessage::new('agent:' . self::RUN_ID, $text));
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
