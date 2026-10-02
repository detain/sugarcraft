<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * Menu-bar and shell commands run without touching the user's draft
 * (audit 15b-05).
 *
 * The shell used to run them by typing into the chat: Backspace/Delete until
 * the draft was empty, then `/name`, then Enter. Mid-turn that destroyed the
 * draft and THEN refused, with a notice claiming "your draft is still in the
 * box" over a box that held `/model`; idle the command ran and the draft was
 * silently gone. Every test here drives the real shell entry point,
 * {@see App::consumeShellCmd()}, against a draft with its cursor parked
 * mid-line, because a cursor at the end is what let the old clear hide.
 *
 * @see \SugarCraft\Crush\Chat::runCommand()
 * @see \SugarCraft\Crush\Chat::runPaletteAction()
 */
final class MenuCommandDraftPreservationTest extends TestCase
{
    private const DRAFT = 'my carefully composed follow-up draft';

    /** Characters of DRAFT ahead of the cursor in {@see withDraft()}. */
    private const TAIL = 6;

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('stub');
        TuiRenderer::setSize(200, 60);
        MenuBar::closeMenu();
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        MenuBar::closeMenu();
    }

    private function app(Chat $chat): App
    {
        return App::new($this->provider, 'test-model')->withChat($chat);
    }

    /** DRAFT typed into $chat, cursor moved TAIL characters back from its end. */
    private function withDraft(Chat $chat): Chat
    {
        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => self::DRAFT]);
        for ($i = 0; $i < self::TAIL; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        }
        self::assertSame(self::DRAFT, $chat->inputBuf);
        self::assertSame(mb_strlen(self::DRAFT) - self::TAIL, $chat->inputCursorOffset());

        return $chat;
    }

    /** A live turn, started the way the user starts one, then a draft typed after it. */
    private function inFlightWithDraft(): Chat
    {
        [$chat] = (new Chat(inputBuf: 'long running task', backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');

        return $this->withDraft($chat);
    }

    private function assertDraftUntouched(?Chat $chat): void
    {
        self::assertNotNull($chat);
        self::assertSame(self::DRAFT, $chat->inputBuf, 'the draft text survives the command');
        self::assertSame(
            mb_strlen(self::DRAFT) - self::TAIL,
            $chat->inputCursorOffset(),
            'and so does its cursor',
        );
    }

    private function lastOf(Chat $chat): Message
    {
        self::assertNotSame([], $chat->history);

        return $chat->history[count($chat->history) - 1];
    }

    private function lastUserMessage(Chat $chat): ?string
    {
        for ($i = count($chat->history) - 1; $i >= 0; $i--) {
            if ($chat->history[$i]->role === Role::User) {
                return $chat->history[$i]->content;
            }
        }

        return null;
    }

    // =====================================================================
    // Mid-turn: refused before anything is touched, and truthfully
    // =====================================================================

    /** The audit's repro, verbatim: menu Model → Switch model mid-turn. */
    public function testMidTurnMenuCommandKeepsTheDraftAndSaysSo(): void
    {
        $chat = $this->inFlightWithDraft();
        $before = count($chat->history);

        [$next, $cmd] = $this->app($chat)->consumeShellCmd(new MenuSelectedMsg('Model', 'Switch model'));

        $this->assertNull($cmd);
        $this->assertDraftUntouched($next->chat);
        $this->assertTrue($next->chat->inFlight, 'the running turn is left alone');
        $this->assertNull($next->chat->palette(), 'the command did not run: no provider list opened');
        $this->assertCount($before + 1, $next->chat->history, 'exactly one notice is written');

        $notice = $this->lastOf($next->chat);
        $this->assertSame(Role::System, $notice->role);
        $this->assertStringContainsString('/model was not run', $notice->content);
        $this->assertStringContainsString('Your draft was not touched', $notice->content);
        $this->assertStringNotContainsString(
            'still in the box',
            $notice->content,
            'the draft was never the command, so "press Enter again" would run the wrong thing',
        );
    }

    public function testMidTurnProviderSelectCmdKeepsTheDraft(): void
    {
        [$next, $cmd] = $this->app($this->inFlightWithDraft())->consumeShellCmd(new ProviderSelectCmd());

        $this->assertNull($cmd);
        $this->assertDraftUntouched($next->chat);
        $this->assertStringContainsString('/model was not run', $this->lastOf($next->chat)->content);
    }

    /** Ctrl+N is a palette-only row: refused the way Enter on that palette row is. */
    public function testMidTurnNewSessionCmdKeepsTheDraftAndTheTranscript(): void
    {
        $chat = $this->inFlightWithDraft();
        $history = $chat->history;

        [$next, $cmd] = $this->app($chat)->consumeShellCmd(new NewSessionCmd());

        $this->assertNull($cmd);
        $this->assertDraftUntouched($next->chat);
        $this->assertNull($next->chat->palette(), 'the refusal closes the palette it ran under');
        $this->assertSame($history, array_slice($next->chat->history, 0, count($history)));
        $this->assertStringContainsString(
            '"New session" does not run while a turn is in flight',
            $this->lastOf($next->chat)->content,
        );
    }

    /** `/exit` keeps submit()'s mid-turn exception: it ends the process. */
    public function testMidTurnMenuExitStillQuits(): void
    {
        [$next, $cmd] = $this->app($this->inFlightWithDraft())->consumeShellCmd(new MenuSelectedMsg('App', 'Exit'));

        $this->assertNotNull($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
        $this->assertDraftUntouched($next->chat);
    }

    // =====================================================================
    // Idle: the command runs, and the draft is still there afterwards
    // =====================================================================

    public function testIdleMenuCommandRunsAndKeepsTheDraft(): void
    {
        [$next] = $this->app($this->withDraft(new Chat()))
            ->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        $this->assertDraftUntouched($next->chat);
        $this->assertSame('/sessions', $this->lastUserMessage($next->chat), 'the command really ran');
        $this->assertFalse(
            in_array(self::DRAFT, array_map(static fn(Message $m): string => $m->content, $next->chat->history), true),
            'and the draft was not sent anywhere',
        );
    }

    public function testIdleProviderSelectCmdOpensTheProviderListAndKeepsTheDraft(): void
    {
        [$next] = $this->app($this->withDraft(new Chat()))->consumeShellCmd(new ProviderSelectCmd());

        $this->assertDraftUntouched($next->chat);
        $this->assertNotNull($next->chat->palette(), 'bare /model opens the provider list');
        $this->assertSame('providers', $next->chat->palette()->mode);
    }

    public function testIdleNewSessionCmdStartsASessionAndKeepsTheDraft(): void
    {
        $store = new SessionStore(':memory:');
        $chat = $this->withDraft(new Chat(
            [Message::user('old prompt'), Message::assistant('old reply')],
            sessionStore: $store,
        ));

        [$next] = $this->app($chat)->consumeShellCmd(new NewSessionCmd());

        $this->assertDraftUntouched($next->chat);
        $this->assertCount(1, $next->chat->history, 'the old transcript is left behind');
        $this->assertStringStartsWith('New session created: ', $next->chat->history[0]->content);
        $this->assertNotNull($next->chat->currentSessionId());
    }

    /**
     * The palette path's own clobber: with the palette ALREADY open, the old
     * Ctrl+P toggled it shut and "New session" was typed into the draft, then
     * sent to the model as a prompt.
     */
    public function testNewSessionCmdWithThePaletteAlreadyOpenDoesNotTypeIntoTheDraft(): void
    {
        [$open] = $this->withDraft(new Chat(backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($open->palette(), 'fixture: the palette is up');

        [$next] = $this->app($open)->consumeShellCmd(new NewSessionCmd());

        $this->assertDraftUntouched($next->chat);
        $this->assertNull($next->chat->palette());
        $this->assertFalse($next->chat->inFlight, 'no prompt was sent to the model');
        $this->assertNull($this->lastUserMessage($next->chat));
        $this->assertStringContainsString('Session store not configured', $this->lastOf($next->chat)->content);
    }

    /** An open overlay used to catch the typed `/name` too; the command now runs past it. */
    public function testIdleMenuCommandClosesAnOpenPaletteAndRuns(): void
    {
        [$open] = $this->withDraft(new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($open->palette(), 'fixture: the palette is up');

        [$next] = $this->app($open)->consumeShellCmd(new MenuSelectedMsg('Session', 'Switch session'));

        $this->assertDraftUntouched($next->chat);
        $this->assertSame('/sessions', $this->lastUserMessage($next->chat));
    }

    // =====================================================================
    // The Chat entry points' own contract
    // =====================================================================

    public function testRunCommandRefusesProseRatherThanSendingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Chat())->runCommand('hello model');
    }

    public function testRunPaletteActionRefusesAnUnknownLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Chat())->runPaletteAction('No such row');
    }

    /** The typed route is unchanged: its refusal still says the draft (the command) is in the box. */
    public function testTypedMidTurnCommandKeepsItsOwnNotice(): void
    {
        [$chat] = (new Chat(inputBuf: 'long running task', backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => '/model']);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('/model', $next->inputBuf);
        $this->assertStringContainsString('Your draft is still in the box', $this->lastOf($next)->content);
    }
}
