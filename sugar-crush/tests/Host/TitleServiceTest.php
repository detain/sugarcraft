<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\TitleService;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PromptSuggestionMsg;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Session\TitleSource;
use SugarCraft\Crush\SessionTitledMsg;
use SugarCraft\Crush\Usage;

/**
 * O-2d: the auto-title and prompt-suggestion side-calls run without a `Chat`,
 * and `Chat` reaches them through the workspace locator. Each test drives the
 * service the way a headless host would — gate, thunk, resolved Msg — and the
 * last two pin that `Chat` asks the registered instance, not one of its own.
 */
final class TitleServiceTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

    /**
     * N-P4g: the three settings behind the side-calls — `sessions.autoTitle`,
     * `promptSuggestions` and `promptSuggestionHistory` — read on use.
     */
    public function testTheSideCallsFollowTheirSettings(): void
    {
        $home = sys_get_temp_dir() . '/crush-title-settings-' . bin2hex(random_bytes(6));
        mkdir($home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($home);
        $write = static function (array $values) use ($home): void {
            file_put_contents($home . '/.sugar-crush/config.json', json_encode($values, JSON_THROW_ON_ERROR));
            \SugarCraft\Crush\Config\Settings\UiSettings::forget();
        };
        $write([]);
        try {
            $store = self::store('s1');
            $titler = self::recorder('Named');
            $first = [Message::user('the login redirect loops')];
            $settled = [];
            for ($i = 1; $i <= 10; $i++) {
                $settled[] = Message::user("q{$i}");
                $settled[] = Message::assistant("a{$i}");
            }

            self::assertTrue(TitleService::autoTitleEnabled());
            self::assertTrue(TitleService::promptSuggestionsEnabled());
            self::assertSame(TitleService::PROMPT_SUGGESTION_HISTORY, TitleService::promptSuggestionHistory());

            $write(['sessions.autoTitle' => false]);
            self::assertNull(TitleService::new()->titleCall($titler, $store, 's1', null, $first), 'no automatic title');
            self::assertNotNull(TitleService::new()->regenerateCall($titler, $store, 's1', $first), '/rename --auto still asks');

            $write(['promptSuggestions' => false]);
            self::assertNull(TitleService::new()->suggestionCall($titler, $settled, 1, 's', false));

            $write(['promptSuggestionHistory' => 4]);
            $call = TitleService::new()->suggestionCall($titler, $settled, 1, 's', false);
            self::assertNotNull($call);
            self::settle($call());
            $sent = $titler->calls[count($titler->calls) - 1];
            self::assertCount(4 + 2, $sent, 'the last four messages, between the framing and the request');
            self::assertSame('q9', $sent[1]->content);

            $write(['promptSuggestionHistory' => 0]);
            self::assertSame(TitleService::PROMPT_SUGGESTION_HISTORY, TitleService::promptSuggestionHistory(), 'out of range is the default');
        } finally {
            \SugarCraft\Crush\Config\Settings\UiSettings::forget();
            $this->restoreHomeSandbox();
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }
    }

    public function testTitleCallNamesAnUnnamedSessionAndRecordsItAsAuto(): void
    {
        $store = self::store('s1');
        $titler = self::recorder("<think>hmm</think>\nFix the login redirect\nsecond line", Usage::new(totalTokens: 7, costUsd: 0.01));

        $call = TitleService::new()->titleCall($titler, $store, 's1', null, [Message::user('the login redirect loops')]);
        self::assertNotNull($call);

        $msg = self::settle($call());
        self::assertInstanceOf(SessionTitledMsg::class, $msg);
        self::assertSame('s1', $msg->sessionId);
        self::assertSame('Fix the login redirect', $msg->title);
        self::assertSame(7, $msg->usage?->totalTokens);

        $row = $store->getSession('s1');
        self::assertSame('Fix the login redirect', $row['name']);
        self::assertSame(TitleSource::Auto, TitleSource::fromStored($row['title_source']));

        self::assertSame(TitleService::TITLE_PROMPT, $titler->calls[0][0]->content, 'the title instruction leads the request');
    }

    /**
     * B2: a `/rename` that lands while the title request is in flight wins in
     * the store, and the resolved Msg carries the cost but no title to latch.
     */
    public function testAUserRenameDuringTheRequestWinsAndTheCostStillArrives(): void
    {
        $store = self::store('s1');
        $call = TitleService::new()->titleCall(
            self::recorder('Generated name', Usage::new(totalTokens: 3)),
            $store,
            's1',
            null,
            [Message::user('hello')],
        );
        self::assertNotNull($call);

        $store->renameSession('s1', 'Mine');
        $msg = self::settle($call());

        self::assertInstanceOf(SessionTitledMsg::class, $msg);
        self::assertSame('', $msg->title);
        self::assertSame(3, $msg->usage?->totalTokens);
        self::assertSame('Mine', $store->getSession('s1')['name']);
        self::assertSame(TitleSource::User, TitleSource::fromStored($store->getSession('s1')['title_source']));
    }

    public function testTitleCallIsGatedToTheFirstVisibleUserTurnOfAnUnnamedStoredSession(): void
    {
        $service = TitleService::new();
        $store = self::store('s1');
        $titler = self::recorder('T');
        $first = [Message::user('one')];

        self::assertNull($service->titleCall(null, $store, 's1', null, $first), 'no title backend, no title (15b-12)');
        self::assertNull($service->titleCall($titler, null, 's1', null, $first), 'no store');
        self::assertNull($service->titleCall($titler, $store, null, null, $first), 'no session');
        self::assertNull($service->titleCall($titler, $store, 's1', 'Named', $first), 'already named');
        self::assertNull(
            $service->titleCall($titler, $store, 's1', null, [Message::user('one'), Message::assistant('a'), Message::user('two')]),
            'second turn',
        );
        self::assertNotNull(
            $service->titleCall($titler, $store, 's1', null, [Message::user('/permissions')->withUiOnly(), Message::user('one')]),
            'a UI-only echo is a row, not a turn (15b-03)',
        );
        self::assertSame([], $titler->calls, 'gating alone never calls the backend');
    }

    public function testSuggestionCallSendsTheClippedTailAndStampsTheMsg(): void
    {
        $titler = self::recorder('User: "run the tests"', Usage::new(totalTokens: 2));
        $history = [Message::system('sys'), Message::user('fix it'), Message::assistant(str_repeat('x', 3000))];

        $call = TitleService::new()->suggestionCall($titler, $history, 4, 's9', false);
        self::assertNotNull($call);

        $msg = self::settle($call());
        self::assertInstanceOf(PromptSuggestionMsg::class, $msg);
        self::assertSame(4, $msg->generation);
        self::assertSame(3, $msg->historyCount);
        self::assertSame('s9', $msg->sessionId);
        self::assertSame('run the tests', TitleService::sanitizeSuggestion($msg->suggestion));

        $sent = $titler->calls[0];
        self::assertSame(TitleService::PROMPT_SUGGESTION_PROMPT, $sent[0]->content);
        self::assertSame(TitleService::PROMPT_SUGGESTION_REQUEST, $sent[count($sent) - 1]->content);
        self::assertCount(4, $sent, 'system rows of the conversation are not shown');
        self::assertSame(TitleService::PROMPT_SUGGESTION_MESSAGE_CHARS, mb_strlen($sent[2]->content));
    }

    public function testSuggestionCallIsSkippedWhenOffCappedOrNotAfterAReply(): void
    {
        $titler = self::recorder('x');
        $settled = [Message::user('q'), Message::assistant('a')];

        self::assertNull(TitleService::new()->suggestionCall(null, $settled, 1, 's', false));
        self::assertNull(TitleService::new()->withPromptSuggestions(false)->suggestionCall($titler, $settled, 1, 's', false));
        self::assertNull(TitleService::new()->suggestionCall($titler, $settled, 1, 's', true), 'spend cap reached');
        self::assertNull(TitleService::new()->suggestionCall($titler, [Message::user('q')], 1, 's', false));
        self::assertNull(TitleService::new()->suggestionCall($titler, [Message::user('q'), Message::assistant('  ')], 1, 's', false));

        putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1');
        try {
            self::assertNull(TitleService::new()->suggestionCall($titler, $settled, 1, 's', false));
        } finally {
            putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS');
        }

        self::assertNotNull(TitleService::new()->suggestionCall($titler, $settled, 1, 's', false));
    }

    public function testWithPromptSuggestionsIsImmutable(): void
    {
        $on = TitleService::new();
        $off = $on->withPromptSuggestions(false);

        self::assertTrue($on->promptSuggestions);
        self::assertFalse($off->promptSuggestions);
        self::assertNotSame($on, $off);
    }

    public function testSanitizersKeepOneSafeLine(): void
    {
        self::assertSame('Title', TitleService::sanitizeTitle("\x9b38;2;255;0;0mTitle\nmore"));
        self::assertSame(TitleService::TITLE_MAX_CHARS, mb_strlen(TitleService::sanitizeTitle(str_repeat('t', 300))));
        self::assertSame('', TitleService::sanitizeTitle("\n  \n"));

        self::assertSame('', TitleService::sanitizeSuggestion('NONE'));
        self::assertSame('', TitleService::sanitizeSuggestion('"none"'));
        self::assertSame('add a test', TitleService::sanitizeSuggestion("<think>x</think>\nme: 'add a test'"));
        self::assertSame(TitleService::PROMPT_SUGGESTION_MAX_CHARS, mb_strlen(TitleService::sanitizeSuggestion(str_repeat('s', 500))));
    }

    /** `Chat` asks the service its workspace registered, not a default of its own. */
    public function testChatUsesTheWorkspaceRegisteredService(): void
    {
        $settled = [Message::user('q'), Message::assistant('a')];
        $titler = self::recorder('x');
        $off = WorkspaceContext::new()->withService(TitleService::class, TitleService::new()->withPromptSuggestions(false));

        $registered = new Chat(history: $settled, titleBackend: $titler, workspace: $off);
        $default = new Chat(history: $settled, titleBackend: $titler, workspace: WorkspaceContext::new());

        self::assertNull(self::scheduleSuggestion($registered), 'the registered service turned suggestions off');
        self::assertNotNull(self::scheduleSuggestion($default), 'no registered service falls back to the default');
    }

    /** The title Cmd a Chat schedules still resolves through the service to the same Msg. */
    public function testChatTitleCmdResolvesThroughTheService(): void
    {
        $store = self::store('s1');
        $chat = new Chat(
            history: [Message::user('hello')],
            sessionStore: $store,
            currentSessionId: 's1',
            titleBackend: self::recorder('Greeting'),
            workspace: WorkspaceContext::new()->withService(TitleService::class, TitleService::new()),
        );

        $cmd = (new \ReflectionMethod(Chat::class, 'scheduleTitleGeneration'))->invoke($chat, $chat);
        self::assertInstanceOf(\Closure::class, $cmd);

        $async = $cmd();
        self::assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $async);
        $msg = self::settle($async->promise);
        self::assertInstanceOf(SessionTitledMsg::class, $msg);
        self::assertSame('Greeting', $msg->title);
        self::assertSame('Greeting', $store->getSession('s1')['name']);
    }

    private static function scheduleSuggestion(Chat $chat): ?\Closure
    {
        /** @var ?\Closure */
        return (new \ReflectionMethod(Chat::class, 'schedulePromptSuggestion'))->invoke($chat);
    }

    private static function settle(PromiseInterface $promise): mixed
    {
        $out = null;
        $promise->then(static function (mixed $v) use (&$out): void {
            $out = $v;
        });

        return $out;
    }

    private static function store(string $id): SessionStore
    {
        $store = new SessionStore(':memory:');
        $store->createSession($id, 'p', 'm');

        return $store;
    }

    /** A backend that records every history it is handed and answers $reply. */
    private static function recorder(string $reply, ?Usage $usage = null): Backend
    {
        return new class ($reply, $usage) implements Backend {
            /** @var list<array<int, Message>> */
            public array $calls = [];

            public function __construct(private readonly string $reply, private readonly ?Usage $usage)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls[] = $history;

                return Message::assistant($this->reply)->withUsage($this->usage);
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->calls[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage($this->usage));
            }
        };
    }
}
