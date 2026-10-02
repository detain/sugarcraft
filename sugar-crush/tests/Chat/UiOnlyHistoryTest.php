<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\SessionTitledMsg;

/**
 * Audit 15b-03: Chat's history holds rows that exist only for the person at
 * the terminal - a slash command's echo and output, `/help`, the queued-prompt
 * and mid-turn refusal notices, launch/runtime notices, a failed turn's error
 * string - and every backend used to be handed ALL of them as real turns. The
 * repro (`/help`, `/permissions`, prompt 1, mid-turn `/budget`, mid-turn
 * prompt 2) sent eight messages on turn 2, the first an assistant row holding
 * the `/help` listing, where the conversation was three.
 *
 * Every assertion lands on the history a recording backend RECEIVED, because
 * that is the boundary the defect crossed; the transcript itself is asserted
 * only to prove the rows are still shown.
 */
final class UiOnlyHistoryTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir === '') {
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testHelpThenAPromptSendsTheBackendOnlyThePrompt(): void
    {
        $backend = self::recorder();
        $chat = new Chat(backend: $backend);

        $chat = $this->enter($chat, '/help');
        $this->assertSame([], $backend->calls, '/help is answered locally');
        $this->assertTrue($chat->history[0]->uiOnly, 'the listing is still in the transcript, flagged');

        $this->enter($chat, 'what does this repo do?');

        $this->assertCount(1, $backend->calls);
        $this->assertSame([['user', 'what does this repo do?']], self::shape($backend->calls[0]));
    }

    public function testTheAuditSequenceSendsOnlyRealTurnsOnTheQueuedSecondTurn(): void
    {
        $backend = self::recorder(manual: true);
        $chat = new Chat(backend: $backend);

        $chat = $this->enter($chat, '/help');
        $chat = $this->enter($chat, '/permissions');
        $chat = $this->enter($chat, 'first question');
        $this->assertTrue($chat->inFlight, 'fixture: the first turn is held open');
        $chat = $this->enter($chat, '/budget');        // refused mid-turn: a notice
        $chat = $this->enter($chat, 'second question'); // queued: a notice

        $this->assertSame([['user', 'first question']], self::shape($backend->calls[0]));

        $backend->deferreds[0]->resolve(Message::assistant('answer one'));
        $chat = $this->drain($chat, $this->pendingTurns);

        $this->assertCount(2, $backend->calls, 'the queued prompt was released as turn 2');
        $this->assertSame(
            [['user', 'first question'], ['assistant', 'answer one'], ['user', 'second question']],
            self::shape($backend->calls[1]),
        );

        // Shown, not deleted: every UI row is still in the transcript.
        $contents = array_map(static fn(Message $m): string => $m->content, $chat->history);
        $this->assertContains('/permissions', $contents);
        $this->assertNotEmpty(array_filter($contents, static fn(string $c): bool => str_starts_with($c, 'Queued (1 waiting)')));
    }

    public function testASessionWhoseFirstInputWasACommandIsStillTitled(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-cmd-first', 'sugarcrush', 'test-model');
        $titler = self::recorder(reply: 'Repo overview');
        $chat = new Chat(
            backend: self::recorder(),
            sessionStore: $store,
            currentSessionId: 'sess-cmd-first',
            titleBackend: $titler,
        );

        $chat = $this->enter($chat, '/permissions');
        $chat = $this->enter($chat, 'what does this repo do?');

        // The title backend also answers prompt suggestions, so pick the
        // title call out by its instruction rather than by position.
        $instruction = (new \ReflectionClassConstant(Chat::class, 'TITLE_PROMPT'))->getValue();
        $titleCalls = array_values(array_filter(
            $titler->calls,
            static fn(array $h): bool => ($h[0] ?? null)?->content === $instruction,
        ));
        $this->assertCount(1, $titleCalls, 'the echo is a row, not a user turn, so the first prompt is still turn one');
        $this->assertSame(
            [['user', 'what does this repo do?']],
            array_slice(self::shape($titleCalls[0]), 1),
            'and the title model reads the conversation without the command',
        );
        $this->assertSame('Repo overview', $chat->currentSessionName());
    }

    public function testThePromptSuggestionIsBuiltFromAgentVisibleRowsOnly(): void
    {
        $suggester = self::recorder(reply: 'and the tests?');
        $chat = new Chat(
            history: [
                Message::user('/rules')->withUiOnly(),
                Message::assistant('RULES-LISTING-NOT-FOR-THE-MODEL')->withUiOnly(),
            ],
            backend: self::recorder(reply: 'It renders TUIs.'),
            titleBackend: $suggester,
        );

        $this->enter($chat, 'what does this repo do?');

        $this->assertNotSame([], $suggester->calls, 'fixture: a settled turn asks for a suggestion');
        $asked = end($suggester->calls);
        foreach ($asked as $message) {
            $this->assertStringNotContainsString('RULES-LISTING-NOT-FOR-THE-MODEL', $message->content);
            $this->assertNotSame('/rules', $message->content);
        }
    }

    public function testAFailedTurnsErrorStringIsNotReplayedAsTheModelsOwnWords(): void
    {
        $backend = self::recorder(failFirst: true);
        $chat = $this->enter(new Chat(backend: $backend), 'first');

        $last = $chat->history[count($chat->history) - 1];
        $this->assertStringContainsString('[error: provider down]', $last->content, 'the user still sees the failure');
        $this->assertTrue($last->uiOnly);

        $this->enter($chat, 'second');

        $this->assertSame([['user', 'first'], ['user', 'second']], self::shape($backend->calls[1]));
    }

    public function testLaunchNoticesAreShownButNeverSent(): void
    {
        $backend = self::recorder();
        $chat = (new Chat(backend: $backend))->withLaunchNotices(['Provider fell back to echo']);

        $this->assertSame(Role::System, $chat->history[0]->role);
        $this->enter($chat, 'hello');

        $this->assertSame([['user', 'hello']], self::shape($backend->calls[0]));
    }

    public function testTheTokenEstimateSkipsUiOnlyRows(): void
    {
        $visible = new Chat(history: [Message::user('hi')]);
        $padded = new Chat(history: [
            Message::user('hi'),
            Message::assistant(str_repeat('x', 40_000))->withUiOnly(),
            Message::notice(str_repeat('y', 40_000)),
        ]);

        $this->assertSame($visible->contextTokens(), $padded->contextTokens(), 'a row never sent occupies none of the window');
    }

    public function testUiOnlyRowsKeepTheirFlagThroughATranscriptSaveAndResume(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_uionly_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $store = new EnhancedSessionStore($this->dir . '/session.db');
        $store->createSession('s1', 'p', 'm');

        $store->saveTranscript('s1', [
            Message::user('/help')->withUiOnly(),
            Message::assistant('listing')->withUiOnly(),
            Message::user('real prompt'),
            Message::assistant('real answer'),
            Message::notice('Queued (1 waiting)'),
        ]);
        $resumed = Chat::loadTranscript($store, 's1');

        $this->assertSame([true, true, false, false, true], array_map(static fn(Message $m): bool => $m->uiOnly, $resumed));
        $this->assertSame(
            [['user', 'real prompt'], ['assistant', 'real answer']],
            self::shape(Message::agentVisible($resumed)),
        );
    }

    public function testARewindCheckpointRowKeepsItsUiOnlyFlag(): void
    {
        $row = Message::assistant('Context compacted')->withUiOnly()->jsonSerialize();

        $this->assertTrue(Chat::reviveCheckpointMessage($row)->uiOnly);
        $this->assertFalse(Chat::reviveCheckpointMessage(Message::assistant('real')->jsonSerialize())->uiOnly);
    }

    // =========================================================================

    /** @var list<PromiseInterface> */
    private array $pendingTurns = [];

    /**
     * Type $draft (set directly: typing "/" opens the slash popup, whose Enter
     * is a different gesture) and press Enter, running every Cmd that settles
     * synchronously. A promise still pending is parked on $pendingTurns.
     */
    private function enter(Chat $chat, string $draft): Chat
    {
        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
        [$chat, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $this->pump($chat, $cmd);
    }

    private function pump(Chat $chat, ?\Closure $cmd, int $depth = 0): Chat
    {
        if ($cmd === null || $depth > 20) {
            return $chat;
        }
        foreach ($this->runCmd($cmd) as $msg) {
            [$chat, $next] = $chat->update($msg);
            $chat = $this->pump($chat, $next, $depth + 1);
        }

        return $chat;
    }

    /** @param list<PromiseInterface> $pending */
    private function drain(Chat $chat, array &$pending): Chat
    {
        $ready = [];
        foreach ($pending as $promise) {
            $promise->then(static function ($msg) use (&$ready): void {
                if ($msg instanceof Msg) {
                    $ready[] = $msg;
                }
            });
        }
        $pending = [];
        foreach ($ready as $msg) {
            [$chat, $next] = $chat->update($msg);
            $chat = $this->pump($chat, $next);
        }

        return $chat;
    }

    /** @return list<Msg> */
    private function runCmd(\Closure $cmd): array
    {
        $out = [];
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            foreach ($msg->cmds as $inner) {
                if ($inner !== null) {
                    array_push($out, ...$this->runCmd($inner));
                }
            }

            return $out;
        }
        if ($msg instanceof AsyncCmd) {
            $settled = false;
            $msg->promise->then(static function ($v) use (&$out, &$settled): void {
                $settled = true;
                if ($v instanceof Msg) {
                    $out[] = $v;
                }
            });
            if (!$settled) {
                $this->pendingTurns[] = $msg->promise;
            }

            return $out;
        }
        if ($msg instanceof Msg) {
            $out[] = $msg;
        }

        return $out;
    }

    /**
     * @param array<int, Message> $history
     * @return list<array{0: string, 1: string}>
     */
    private static function shape(array $history): array
    {
        return array_values(array_map(
            static fn(Message $m): array => [$m->role->value, $m->content],
            $history,
        ));
    }

    /**
     * A backend that records every history it is handed. $manual holds each
     * turn open on a Deferred; $failFirst rejects the first call.
     */
    private static function recorder(string $reply = 'ok', bool $manual = false, bool $failFirst = false): Backend
    {
        return new class ($reply, $manual, $failFirst) implements Backend {
            /** @var list<array<int, Message>> */
            public array $calls = [];

            /** @var list<Deferred> */
            public array $deferreds = [];

            public function __construct(
                private readonly string $reply,
                private readonly bool $manual,
                private bool $failFirst,
            ) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls[] = $history;

                return Message::assistant($this->reply);
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->calls[] = $history;
                if ($this->failFirst) {
                    $this->failFirst = false;

                    return \React\Promise\reject(new \RuntimeException('provider down'));
                }
                if ($this->manual) {
                    $deferred = new Deferred();
                    $this->deferreds[] = $deferred;

                    return $deferred->promise();
                }

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }
}
