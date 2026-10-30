<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionMeta;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-SES-7.d wiring: after a RESUME (`--continue`, `--resume`, a deck tab
 * opened on a stored session) the FIRST real prompt is held once with the
 * cost notice when the guard's numbers say the prompt cache is cold, and the
 * next Enter sends. These tests drive the behaviour through `update()` on
 * Enter — the same keystroke path the TUI presses — so what is pinned is the
 * wiring (when the guard is armed, where it sits in `submit()`, what it
 * consumes), not {@see \SugarCraft\Code\Host\ResumeCostEstimate}'s arithmetic
 * (pinned to the second in tests/Host/ResumeCostEstimateTest.php).
 *
 * The stores are seeded so the resume reads stale or warm; the backend is
 * always an {@see EchoBackend}, which is deliberately the UNPRICED shape
 * (no engine → no price on file → the `≥` notice), and the notice text
 * asserts against the real lang strings.
 */
final class ResumeCostGuardTest extends TestCase
{
    /** ~84k tokens — the design's own example size (measures 84_010). */
    private const HEAVY_CHARS = 336_000;

    private string $tempDir;
    private SessionStore $plainStore;
    private EnhancedSessionStore $enhancedStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cses7d_' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
        $this->plainStore = new SessionStore($this->tempDir . '/plain.db');
        $this->enhancedStore = new EnhancedSessionStore($this->tempDir . '/enh.db');
        // The threshold is read through the memoised settings face; make this
        // test answer for its own process position, never a sibling's cache.
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        UiSettings::forget();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    // =====================================================================
    // The hold
    // =====================================================================

    public function testAStaleResumeHoldsTheFirstPromptWithTheCostNotice(): void
    {
        $chat = $this->resumedChat($this->plainStore);
        // Construction over stored history under a real session id IS the
        // armed state — derived, nothing seeded it.
        $this->assertTrue($chat->resumeCostGuardArmed());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        // Held: no command, nothing in flight, the draft still in the box.
        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight);
        $this->assertSame('hello', $next->inputBuf);

        // The notice row is UI-only and says exactly what the re-send costs.
        $last = $next->history[count($next->history) - 1];
        $this->assertSame(Role::System, $last->role);
        $this->assertTrue($last->uiOnly, 'the guard notice never reaches the model wire');
        $this->assertStringContainsString('Resuming re-sends ~84k tokens uncached', $last->content);
        // EchoBackend has no price on file — the notice must NOT invent one:
        // the threshold rides with `≥`, not `≈`.
        $this->assertStringContainsString('(≥$0.25)', $last->content);
        $this->assertStringContainsString('Press Enter again to send, or run /compact first.', $last->content);

        // Held ONCE: the guard spent itself on the hold.
        $this->assertFalse($next->resumeCostGuardArmed());
    }

    public function testTheSecondEnterSendsPastTheSpentGuard(): void
    {
        [$held, ] = $this->resumedChat($this->plainStore)->update(new KeyMsg(KeyType::Enter, ''));
        $before = count($held->history);

        [$next, $cmd] = $held->update(new KeyMsg(KeyType::Enter, ''));

        // The same draft, unsent, going out now that the user confirmed it.
        $this->assertNotNull($cmd, 'the second Enter dispatches the turn');
        $this->assertSame('hello', $next->history[$before]->content);
        // The notice from the hold survives exactly ONCE — never repeated:
        // the count, not the absence (the first hold's row is still there).
        $this->assertSame(1, count(array_filter(
            $next->history,
            static fn(Message $m): bool => str_contains($m->content, 'Resuming re-sends'),
        )));
    }

    public function testAStaleResumeOnTheEnhancedStoreHoldsToo(): void
    {
        $this->seedEnhancedSession('-2 hours');

        [$next, $cmd] = $this->resumedChat($this->enhancedStore)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $last = $next->history[count($next->history) - 1];
        $this->assertStringContainsString('Resuming re-sends', $last->content);
    }

    // =====================================================================
    // The non-holds
    // =====================================================================

    public function testAWarmResumeSendsStraightOut(): void
    {
        $this->seedEnhancedSession('-90 seconds');

        [$next, $cmd] = $this->resumedChat($this->enhancedStore)->update(new KeyMsg(KeyType::Enter, ''));

        // Inside the cache window the re-send is a cache read — nothing to say.
        $this->assertNotNull($cmd);
        foreach ($next->history as $message) {
            $this->assertStringNotContainsString('Resuming re-sends', $message->content);
        }
        // dispatchTurn consumed the arm: the warm send WAS the resume's first prompt.
        $this->assertFalse($next->resumeCostGuardArmed());
    }

    public function testAFreshLaunchIsNotAResumeAndNeverHolds(): void
    {
        // The armed state DERIVES from construction: empty history, no
        // session row yet, a lazy minter standing in — none of those is a
        // resume, and the guard must stay out of typing's way.
        $chat = new Chat(
            history: [],
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $this->plainStore,
            currentSessionId: null,
        );
        $this->assertFalse($chat->resumeCostGuardArmed());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
    }

    public function testCommandsStayReachableWhileTheHoldStands(): void
    {
        // The notice itself tells the user to run /compact — so slash
        // commands must dispatch (and must NOT spend the arm) while it holds.
        $chat = new Chat(
            history: [Message::user(str_repeat('x', self::HEAVY_CHARS))],
            inputBuf: '/help',
            backend: new EchoBackend(),
            sessionStore: $this->plainStore,
            currentSessionId: 'ses-resumed',
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $last = $next->history[count($next->history) - 1];
        $this->assertStringNotContainsString('Resuming re-sends', $last->content, 'the command dispatched, the guard did not fire');
        // The arm SURVIVED the command: the next real prompt is still the
        // resume's first one and still gets its check.
        $this->assertTrue($next->resumeCostGuardArmed());

        // Commands leave the draft alone, so the box still holds '/help' —
        // type a real draft through the same mutate() the app uses, then the
        // first REAL prompt must meet the guard (arm survived the command).
        [$held, ] = $this->mutate($next, ['inputBuf' => 'hi'])->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('Resuming re-sends', $held->history[count($held->history) - 1]->content);
    }

    public function testTheGuardSwitchesOntoASessionThroughThePickerIsArmed(): void
    {
        // A deck-tab / picker switch onto a stored session is a resume in
        // every sense the guard cares about: switchToSession() arms it.
        // Private and owned by the tab machinery, so the pin reaches it the
        // way the app does — through reflection, with an id whose transcript
        // the store answers empty.
        $base = new Chat(backend: new EchoBackend(), sessionStore: $this->plainStore);
        $method = new \ReflectionMethod(Chat::class, 'switchToSession');
        $switched = $method->invoke($base, 'ses-target', 'switched');

        $this->assertTrue($switched->resumeCostGuardArmed());
        // And the arm belongs to the NEW session's identity: what `/new`
        // does — a fresh currentSessionId with empty history and no named
        // flag — re-derives it (fresh launch shape) instead of inheriting
        // the previous session's answer. mutate() is private too; same seam.
        $cleared = $this->mutate($switched, [
            'currentSessionId' => 'ses-fresh',
            'history' => [],
        ]);
        $this->assertFalse($cleared->resumeCostGuardArmed());
    }

    // =====================================================================

    /** @return list<Message> */
    private function heavyStoredHistory(): array
    {
        return [Message::user(str_repeat('x', self::HEAVY_CHARS))];
    }

    private function resumedChat(SessionStore|EnhancedSessionStore $store): Chat
    {
        return new Chat(
            history: $this->heavyStoredHistory(),
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'ses-resumed',
        );
    }

    /**
     * Give the enhanced store a row for the session `resumedChat()` will
     * ask about, with a chosen last-activity. session_meta carries an FK to
     * sessions, so the session row comes first — same order the app writes.
     */
    private function seedEnhancedSession(string $activity): void
    {
        $this->enhancedStore->createSession('ses-resumed', 'echo', 'test-model');
        $this->enhancedStore->saveSessionMeta(SessionMeta::new(
            'ses-resumed',
            lastActivity: new \DateTimeImmutable($activity),
        ));
    }

    /**
     * The private clone seam, used the way `/new` and the routes through
     * submit() use it — named ctor params only.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(Chat $chat, array $changes): Chat
    {
        $method = new \ReflectionMethod(Chat::class, 'mutate');

        return $method->invoke($chat, $changes);
    }
}
