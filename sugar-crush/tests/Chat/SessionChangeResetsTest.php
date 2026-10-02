<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Audit 15b-06: the compaction thrash breaker and the activity stamp belong to
 * the session they were counted in, so every route that puts a DIFFERENT session
 * in front of the user resets them - as `/clear` already did for the breaker.
 *
 * Before the fix, session A tripping the breaker made the first prompt of a large
 * session B refused at once by the "Context compaction has run 3 times in a row"
 * notice, although B had never compacted.
 */
final class SessionChangeResetsTest extends TestCase
{
    private const WINDOW = 88_000;

    private string $dir;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/session_change_resets_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
        $this->store = new EnhancedSessionStore($this->dir . '/sessions.db');
        $this->store->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $this->store->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');
        // B is large enough that its first prompt crosses the 85% automatic tier,
        // which is where the breaker is read.
        $this->store->saveTranscript('session-b', self::compactablePairs());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testCtrlTabIntoALargeSessionIsNotRefusedForTheThrashOfTheSessionLeft(): void
    {
        [$switched, $cmd] = $this->trippedChat()->update(new KeyMsg(KeyType::Tab, ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('session-b', $switched->currentSessionId(), 'fixture: Ctrl+Tab reached session B');
        $this->assertResetsApplied($switched);

        $draft = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($switched, ['inputBuf' => 'what changed?']);
        [$sent, $turnCmd] = $draft->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull($turnCmd, "B's first prompt goes out - B has not compacted once");
        foreach ($sent->history as $message) {
            if ($message->role === Role::System) {
                $this->assertStringNotContainsString('times in a row', $message->content);
            }
        }
    }

    public function testResumingFromThePickerResetsTheBreaker(): void
    {
        [$open] = $this->trippedChat()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $picker = $open->sessionPicker();
        $this->assertNotNull($picker, 'fixture: Ctrl+R opens the picker');
        if (($picker->selectedSession()['sessionId'] ?? null) !== 'session-b') {
            [$open] = $open->update(new KeyMsg(KeyType::Down, ''));
        }
        $this->assertSame('session-b', $open->sessionPicker()?->selectedSession()['sessionId'] ?? null);

        [$resumed] = $open->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('session-b', $resumed->currentSessionId(), 'fixture: the picker resumed B');
        $this->assertResetsApplied($resumed);
    }

    public function testATabClickResetsTheBreaker(): void
    {
        [$clicked] = (new \ReflectionMethod(Chat::class, 'selectSessionTab'))
            ->invoke($this->trippedChat(), 'session-b');

        $this->assertSame('session-b', $clicked->currentSessionId(), 'fixture: the click switched to B');
        $this->assertResetsApplied($clicked);
    }

    public function testPaletteNewSessionResetsTheBreaker(): void
    {
        [$current] = $this->trippedChat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        foreach (str_split('new session') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }
        $this->assertSame('New session', $current->paletteMatches()[0] ?? null, 'fixture: the palette row is first');

        [$fresh] = $current->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotContains($fresh->currentSessionId(), ['session-a', 'session-b', null], 'a new id was minted');
        $this->assertResetsApplied($fresh);
    }

    private function assertResetsApplied(Chat $chat): void
    {
        $this->assertSame(
            0,
            (new \ReflectionProperty(Chat::class, 'consecutiveRefillCompactions'))->getValue($chat),
            "the left session's thrash run does not follow the user into another one",
        );
        $this->assertNull($chat->lastActivityAt(), "nor does the left session's activity stamp");
    }

    /** Session A, current, with the breaker tripped and a stale activity stamp. */
    private function trippedChat(): Chat
    {
        $chat = new Chat(
            backend: self::backend(),
            sessionStore: $this->store,
            currentSessionId: 'session-a',
            consecutiveRefillCompactions: IdleCompactionPolicy::REFILL_LIMIT,
        );
        $this->assertTrue(IdleCompactionPolicy::thrashTripped(IdleCompactionPolicy::REFILL_LIMIT));

        return $chat->withLastActivity(new \DateTimeImmutable('-2 hours'));
    }

    /**
     * 13 exchanges totalling ~78,000 estimated tokens: over the 85% tier of the
     * 88,000-token window and, once compacted, under its 95% tier - the shape
     * {@see AutomaticCompactionModelSummaryTest::compactablePairs()} uses.
     *
     * @return list<Message>
     */
    private static function compactablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $heavy = $i < 3;
            $history[] = Message::user($heavy ? str_repeat(chr(97 + $i), 52_000) : 'q' . $i);
            $history[] = Message::assistant($heavy ? str_repeat(chr(110 + $i), 52_000) : 'a' . $i);
        }

        return $history;
    }

    private static function backend(): Backend
    {
        return new class (self::WINDOW) implements Backend, ReportsContextWindow {
            public function __construct(private readonly int $window) {}

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }
}
