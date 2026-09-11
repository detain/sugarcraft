<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;

/**
 * @see Chat::handleRewindCommand()
 */
final class RewindCommandTest extends TestCase
{
    private string $tempDir;
    private EnhancedSessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rewind_cmd_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->sessionStore = new EnhancedSessionStore($this->tempDir . '/sessions.db');
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // /rewind with no session store configured
    // =========================================================================

    public function testRewindNotConfiguredWhenStoreIsNull(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    public function testRewindNotConfiguredWhenStoreLacksCheckpointSupport(): void
    {
        // Use plain SessionStore which doesn't have restoreCheckpoint method
        $plainStore = new SessionStore($this->tempDir . '/plain_sessions.db');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $plainStore,
            currentSessionId: 'some-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('does not support checkpoints', $lastMsg->content);
    }

    // =========================================================================
    // /rewind with no active session
    // =========================================================================

    public function testRewindNoActiveSession(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: null, // no active session
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No active session', $lastMsg->content);
    }

    // =========================================================================
    // /rewind with no checkpoints
    // =========================================================================

    public function testRewindNoCheckpointsAvailable(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $chat = new Chat(
            history: [],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No checkpoints available', $lastMsg->content);
    }

    // =========================================================================
    // /rewind restores state correctly
    // =========================================================================

    public function testRewindRestoresCheckpointState(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        // Save a checkpoint with specific state
        $originalState = [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
            'inputBuf' => 'original input',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ];
        $this->sessionStore->saveCheckpoint('test-session', $originalState);

        // Add more checkpoints to simulate more conversation
        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
                ['role' => 'user', 'content' => 'Tell me more'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
                Message::user('Tell me more'),
                Message::assistant('More info'),
            ],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Rewound', $lastMsg->content);

        // State should be restored to original checkpoint
        $this->assertCount(5, $next->history);
        $this->assertSame('Hello', $next->history[0]->content);
        $this->assertSame('Hi there!', $next->history[1]->content);
        // E681 EMPTY POLARITY: the rewind target (checkpoint 1, saved above with
        // 'inputBuf' => '') had no draft, so the restore must yield ''. After the
        // fix this asserts the saved '' genuinely ROUND-TRIPS — pre-E681 it passed
        // vacuously because handleRewindCommand blanked the box regardless.
        $this->assertSame('', $next->inputBuf);
    }

    /**
     * E681 NON-EMPTY POLARITY, end to end through the real save path: type a
     * draft, submit, then /rewind — the box comes back with the text.
     *
     * MEASURED SHAPE: the auto-checkpoint fires at submit, so the draft it
     * captures is the prompt that turn just sent (the buffer's pre-clear
     * contents); that is what a rewind of that checkpoint re-seeds. Every
     * real-save checkpoint therefore carries a non-empty draft — the empty
     * polarity for real saves does not exist, only for hand-saved/legacy
     * checkpoints (pinned by the test above and the legacy-key one below).
     *
     * Pre-fix this was red twice over: saveCheckpoint stored $next->inputBuf
     * (always '' after submit's clear), and handleRewindCommand restored ''
     * (hardcoded, ignoring the extracted field).
     */
    public function testRewindRestoresTheDraftTheSubmitCaptured(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        // Turn 1: a live draft goes through the REAL submit path, which
        // auto-saves the checkpoint mid-update() — that capture is the fix.
        $chat = new Chat(
            history: [],
            inputBuf: 'fix the login bug',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($afterSubmit->inFlight);
        $this->assertSame('', $afterSubmit->inputBuf, 'submit still clears the box');

        // Rewind: the checkpoint of that turn restores its draft into the box.
        $rewinder = new Chat(
            history: [Message::user('fix the login bug')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        [$next] = $rewinder->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $this->assertStringContainsString('Rewound', $next->history[count($next->history) - 1]->content);
        $this->assertSame('fix the login bug', $next->inputBuf, 'the submitted draft comes back on rewind');
    }

    /**
     * E4, end to end through the real save path: the checkpoint captures the
     * CARET where the user was editing, not just the text, and the restore
     * puts it back there.
     *
     * The failure this pins is invisible to the E681 draft test above: a
     * rewound draft whose caret lands at the END while the user was mid-edit
     * types into the wrong place the moment they press a key. Both halves are
     * load-bearing — save must write `inputCursor`, restore must re-apply it
     * AFTER the mutate (mutate's replace-the-whole-draft route rebuilds the
     * widget at end-of-text, so smuggling the offset through the same mutate
     * call cannot win).
     */
    public function testRewindRestoresTheCursorPositionTheSubmitCaptured(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $start = new Chat(
            history: [],
            inputBuf: 'fix the login bug',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        // Park the caret mid-draft: five LEFTs from the end (17 chars → 12).
        for ($i = 0; $i < 5; $i++) {
            [$start] = $start->update(new KeyMsg(KeyType::Left));
        }
        $this->assertSame(12, $start->inputCursorOffset(), 'fixture: the caret is mid-draft before submit');

        [$afterSubmit] = $start->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($afterSubmit->inFlight);
        $this->assertSame('', $afterSubmit->inputBuf, 'submit still clears the box');

        $rewinder = new Chat(
            history: [Message::user('fix the login bug')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        [$next] = $rewinder->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('fix the login bug', $next->inputBuf);
        $this->assertSame(
            12,
            $next->inputCursorOffset(),
            'E4: the caret comes back where the user was editing, not at the end of the draft',
        );
    }

    /**
     * E4 legacy polarity: checkpoints written before the cursor key existed
     * (hand-saved or pre-E4 auto-saves) carry `inputBuf` with no
     * `inputCursor`. The restore must fall back to end-of-text — the exact
     * pre-E4 behaviour — rather than jumping the caret to offset 0 or
     * crashing on the missing key.
     */
    public function testRewindOfALegacyCheckpointWithoutACursorKeyRestoresCaretAtEnd(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');
        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => 'half-typed draft',
            // deliberately NO 'inputCursor' key
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [Message::user('Hello'), Message::assistant('Hi')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('half-typed draft', $next->inputBuf);
        $this->assertSame(
            16,
            $next->inputCursorOffset(),
            'a checkpoint with no captured caret restores the caret at the end, exactly as before E4',
        );
    }

    /**
     * E681 EMPTY POLARITY, legacy shape: checkpoints written before the draft
     * field was populated carry no `inputBuf` key at all. The restore must fall
     * back to '' — no phantom text, no crash.
     */
    public function testRewindOfALegacyCheckpointWithoutADraftKeyRestoresEmpty(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');
        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            // deliberately NO 'inputBuf' key
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [Message::user('Hello'), Message::assistant('Hi')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $this->assertSame('', $next->inputBuf);
    }

    /**
     * crush_feat.md §1 E7, end to end: a checkpoint taken while a tool call
     * was still running (the auto-save in {@see Chat::update()} fires per
     * prompt, so this is the ordinary case for a crash mid-call) used to
     * restore its `Message::toolRunning()` placeholder as a plain bubble -
     * losing the dead call's id entirely. Rewinding now yields a real
     * interrupted result under that id.
     */
    public function testRewindHealsACheckpointTakenMidToolCall(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'run it'],
                ['role' => 'system', 'content' => 'bash(cmd: "sleep 900")', 'pendingToolCallId' => 'call_9'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);
        $chat = new Chat(
            history: [Message::user('run it')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $healed = $next->history[1];
        $this->assertNull($healed->pendingToolCallId, 'a restored placeholder kept spinning');
        $this->assertCount(1, $healed->toolResults);
        $this->assertSame('call_9', $healed->toolResults[0]->id);
        $this->assertTrue(Chat::isInterruptedResult($healed->toolResults[0]));
    }

    public function testRewindMultipleStepsBack(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        // Save 5 checkpoints
        for ($i = 0; $i < 5; $i++) {
            $this->sessionStore->saveCheckpoint('test-session', [
                'messages' => array_fill(0, $i + 1, ['role' => 'user', 'content' => "Message $i"]),
                'inputBuf' => "input $i",
                'agentContext' => ['currentSessionId' => 'test-session'],
            ]);
        }

        $chat = new Chat(
            history: [
                Message::user('Message 0'),
                Message::user('Message 1'),
                Message::user('Message 2'),
                Message::user('Message 3'),
                Message::user('Message 4'),
            ],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // Rewind 3 steps back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Rewound', $lastMsg->content);

        // Should have 2 messages (checkpoint at index 1, which is 3 steps back: 4-3+1=2)
        $this->assertCount(7, $next->history);
    }

    // =========================================================================
    // /rewind with invalid step count
    // =========================================================================

    public function testRewindWithZeroStepsDefaultsToOne(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'user', 'content' => 'World'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::user('World'),
                Message::user('Extra'),
            ],
            inputBuf: '/rewind 0',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // /rewind 0 should default to 1 step back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        // Should have restored to checkpoint with 2 messages (1 step back from checkpoint index 2)
        $this->assertCount(4, $next->history);
    }

    public function testRewindWithNegativeStepsDefaultsToOne(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'user', 'content' => 'World'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [
                Message::user('Hello'),
                Message::user('World'),
                Message::user('Extra'),
            ],
            inputBuf: '/rewind -5',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        // /rewind -5 should default to 1 step back
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $this->assertCount(4, $next->history);
    }

    // =========================================================================
    // A checkpointed role survives the round trip (E33 review round, finding 2)
    // =========================================================================

    /**
     * A `system` checkpoint row must come back as a `Role::System` message.
     *
     * {@see Chat::reviveCheckpointMessage()} had no `'system'` arm — its match
     * read `default => Message::user($content)` — so every app-authored system
     * row came back as a USER message and the provider was told the user had
     * said it. `_Request cancelled._`, the compaction notice, the automatic
     * tier's report and the 70% context reminder all land there.
     *
     * The `user` and unknown-role rows are asserted alongside because the fix is
     * an added arm, not a changed default: a `tool` row (nothing this app
     * serialises — {@see Role} has no such case) must still coerce to `user`, or
     * the untypeable-draft reachability route
     * {@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest} drives goes away
     * silently.
     */
    public function testRewindRestoresASystemRowAsASystemMessage(): void
    {
        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');
        $this->sessionStore->saveCheckpoint('test-session', [
            'messages' => [
                ['role' => 'user', 'content' => 'do the thing'],
                ['role' => 'system', 'content' => '_Request cancelled._'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'tool', 'content' => "col1\tcol2"],
                ['content' => 'no role at all'],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [Message::user('do the thing')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(
            [Role::User, Role::System, Role::Assistant, Role::User, Role::User],
            array_map(
                static fn(Message $m): Role => $m->role,
                array_slice($next->history, 0, 5),
            ),
            'a system row must not come back as a user message: the app would be manufacturing '
            . 'user turns out of its own notices and putting them on the provider wire',
        );
        $this->assertSame(
            '_Request cancelled._',
            $next->history[1]->content,
            'and the content is untouched either way',
        );
    }

    /**
     * E33's guarantee — history carries at most ONE context-usage reminder —
     * must survive a `/rewind`, which is the only path that reads a checkpoint
     * back.
     *
     * With the reminder revived as `Role::User` it could never be stripped
     * again: {@see Chat::isContextReminder()} requires `Role::System` on
     * purpose, so that a user quoting the reminder in a prompt is never deleted.
     * So the mis-roled copy was permanent, a fresh system copy was appended
     * beside it on the next turn, and one more accrued per rewind — measured,
     * marker-carrying roles went `["system"]` -> `["user"]` -> `["user",
     * "system"]`.
     *
     * The count here is deliberately ROLE-BLIND, which is only sound because
     * this fixture contains no user message that quotes the reminder. That case
     * is the one
     * {@see \SugarCraft\Crush\Tests\Chat\ContextReminderDedupTest::testAUserMessageQuotingTheReminderIsNeverRemoved()}
     * pins, and the two assertions must not be merged.
     */
    public function testARewoundContextReminderIsStillDeduplicated(): void
    {
        $marker = 'Heads up: this conversation has grown to ~';
        $stale = $marker . '70123 estimated tokens, past the context-usage reminder threshold. '
            . 'Consider running /compact soon to keep the session responsive.';

        $this->sessionStore->createSession('test-session', 'openai', 'gpt-4');
        $this->sessionStore->saveCheckpoint('test-session', [
            // 280,000 chars is ~70,010 estimated tokens, over the 70% tier of
            // the 100,000-token fallback window EchoBackend gets and well under
            // the 85% automatic-compaction tier.
            'messages' => [
                ['role' => 'user', 'content' => str_repeat('x', 280_000)],
                ['role' => 'system', 'content' => $stale],
            ],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'test-session'],
        ]);

        $chat = new Chat(
            history: [Message::user('placeholder')],
            inputBuf: '/rewind',
            backend: new EchoBackend(),
            sessionStore: $this->sessionStore,
            currentSessionId: 'test-session',
        );

        [$rewound, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $carriers = static fn(Chat $c): array => array_values(array_map(
            static fn(Message $m): string => $m->role->value,
            array_filter(
                $c->history,
                static fn(Message $m): bool => str_starts_with($m->content, $marker),
            ),
        ));
        $this->assertSame(
            ['system'],
            $carriers($rewound),
            'the rewound reminder must still be a system message, or nothing below can strip it',
        );

        // One real turn on top of the rewound history. The tier fires again
        // (the history is still over 70%), so the fresh copy is appended - and
        // the rewound one has to go.
        foreach (['h', 'i'] as $char) {
            [$rewound] = $rewound->update(new KeyMsg(KeyType::Char, $char));
        }
        [$next, $cmd] = $rewound->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must dispatch');

        $this->assertSame(
            ['system'],
            $carriers($next),
            'exactly one message may carry the marker after a rewind plus a turn - a second, '
            . 'mis-roled as `user`, is E33 finding 2 and accrues one more copy per rewind',
        );
    }
}
