<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\SocketSteerInbox;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tui\Pane;

/**
 * Roadmap 1.C-3 at the keyboard (decision D6): Enter while a turn runs
 * STEERS it — the message goes into the running turn through its handle —
 * and Tab queues the draft for after it. A steer the turn never read is sent
 * as the next prompt when it settles; one it read is not sent twice.
 */
final class EnterWhileBusySteersTest extends TestCase
{
    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
    }

    public function testEnterMidTurnSteersTheRunningTurn(): void
    {
        $token = new CancellationToken();

        [$next, $cmd] = $this->busy($token, 'check the fixtures first')->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($cmd, 'nothing is dispatched: the turn in flight takes it');
        $this->assertTrue($next->inFlight);
        $this->assertSame('', $next->inputBuf);
        $this->assertSame(['check the fixtures first'], array_column($token->takeSteers(), 'text'));
        $this->assertSame(['check the fixtures first'], $next->queuedPrompts(), 'held as the fallback');
        $notice = $next->history[array_key_last($next->history)];
        $this->assertTrue($notice->uiOnly);
        $this->assertStringStartsWith('Steering — the agent reads this at its next step', $notice->content);
    }

    public function testATurnThatCannotTakeASteerQueuesItInstead(): void
    {
        $token = new CancellationToken();
        $chat = (new Chat(history: [Message::user('go')], backend: new EchoBackend(), inFlight: true, generation: 1, inFlightCancellation: $token, inputBuf: 'later'))
            ->withSize(120, 20);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame([], $token->takeSteers(), 'not an engine turn: nothing to steer into');
        $this->assertSame(['later'], $next->queuedPrompts());
        $this->assertStringStartsWith('Queued (1 waiting)', $next->history[array_key_last($next->history)]->content);

        $stopping = new CancellationToken();
        $stopping->cancelSoft();
        [$refused] = $this->busy($stopping, 'too late')->update(new KeyMsg(KeyType::Enter));
        $this->assertSame([], $stopping->takeSteers(), 'a turn already stopping has no next step');
        $this->assertSame(['too late'], $refused->queuedPrompts());
    }

    public function testTabMidTurnQueuesWithoutSteeringAndTheShellYieldsIt(): void
    {
        $token = new CancellationToken();
        $chat = $this->busy($token, 'then update the docs');

        $app = App::new(new ScriptedProvider([]), 'm')->withChat($chat)->withPane(Pane::Chat);
        [$after] = $app->update(new KeyMsg(KeyType::Tab));

        $this->assertSame(Pane::Chat, $after->pane, 'the shell yielded Tab instead of cycling focus');
        $this->assertSame(['then update the docs'], $after->chat->queuedPrompts());
        $this->assertSame([], $token->takeSteers());

        // Not mid-turn, Tab is the focus cycle again.
        $this->assertFalse((new Chat(inputBuf: 'idle draft'))->queueOwnsTab());
    }

    public function testASteerTheTurnReadIsNotSentAgainButOneItMissedIs(): void
    {
        $settled = $this->settled(['read it', 'missed it'], [
            Message::user('go'),
            Message::user(SocketSteerInbox::content('read it'))->withUserVisible(false),
            Message::assistant('done'),
        ]);

        [$next] = self::release($settled);

        $this->assertSame([], $next->queuedPrompts(), 'the delivered steer is dropped and the missed one went out');
        $this->assertTrue($next->inFlight, 'the missed steer started the next turn');
        $this->assertSame(1, count(array_filter(
            $next->history,
            static fn (Message $m): bool => $m->content === 'missed it' && $m->userVisible,
        )), 'sent as an ordinary prompt');
        $this->assertSame([], array_filter(
            $next->history,
            static fn (Message $m): bool => $m->content === 'read it' && $m->userVisible,
        ), 'never as a second prompt');
    }

    public function testOnlyTheJustSettledTurnsSteersCount(): void
    {
        // The same words were steered into an EARLIER turn; queued now, they
        // are a new message and must still go out.
        $settled = $this->settled(['again'], [
            Message::user('first'),
            Message::user(SocketSteerInbox::content('again'))->withUserVisible(false),
            Message::assistant('one'),
            Message::user('second'),
            Message::assistant('two'),
        ]);

        [$next] = self::release($settled);

        $this->assertTrue($next->inFlight);
        $this->assertSame('again', $next->history[array_key_last(array_filter($next->history, static fn (Message $m): bool => $m->userVisible && $m->role->value === 'user'))]->content);
    }

    private function busy(CancellationToken $token, string $draft): Chat
    {
        return (new Chat(
            history: [Message::user('go')],
            backend: EngineBackend::new(new ScriptedProvider([]), 'm'),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $token,
            inputBuf: $draft,
        ))->withSize(120, 20);
    }

    /**
     * @param list<string>  $queue
     * @param list<Message> $history
     */
    private function settled(array $queue, array $history): Chat
    {
        return (new Chat(history: $history, backend: new EchoBackend(), queuedPrompts: $queue))->withSize(120, 20);
    }

    /** @return array{0: Chat, 1: mixed} */
    private static function release(Chat $chat): array
    {
        $method = new \ReflectionMethod(Chat::class, 'releaseQueuedPrompts');

        return $method->invoke(null, [$chat, null]);
    }
}
