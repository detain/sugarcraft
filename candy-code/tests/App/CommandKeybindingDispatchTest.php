<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-EXT-5 (plan_comparison): `App::handleKey()` consults the command map
 * before the alias funnel, and a hit runs the slash command through the
 * menu-bar idiom — `Chat::runCommand('/'.$name)` — so typing parity comes
 * free: drafts ride the command, mid-turn refusals say so, unknown names
 * answer with the ordinary unknown-command notice.
 *
 * Fixtures bind `command:keys` to `Ctrl+Z` — a chord no shipped row claims,
 * so nothing about the press is confounded by an existing binding (the same
 * harmless-counterpart choice the collision fixtures make).
 */
final class CommandKeybindingDispatchTest extends TestCase
{
    /** @var array<string, string> */
    private array $overridesBefore = [];

    /** @var list<string> */
    private array $suppressedBefore = [];

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('stub');
        TuiRenderer::setSize(200, 60);

        $this->overridesBefore = KeyBindingRegistry::overrides();
        $this->suppressedBefore = KeyBindingRegistry::suppressed();
        KeyBindingRegistry::useOverrides([]);
    }

    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides(
            $this->overridesBefore + array_fill_keys($this->suppressedBefore, null),
        );
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    public function testABoundChordRunsTheSlashCommandAndKeepsTheDraft(): void
    {
        KeyBindingRegistry::useOverrides(['command:keys' => 'Ctrl+Z']);

        $app = $this->shell()->withChat(new Chat());
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'h'));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertSame('hi', $app->chat->inputBuf, 'fixture: the draft is in the input before the chord');

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertNotNull($next->chat->keyHelp(), 'the bound chord must open the /keys overlay like typing the command');
        self::assertSame('hi', $next->chat->inputBuf, 'the draft must ride the dispatch untouched — the menu-bar law, now the chord law');
    }

    public function testTheCommandClaimsTheChordBeforeTheAliasFunnel(): void
    {
        // The ordering IS the design: `chat.palette` moved onto Ctrl+Z would
        // (post-translation) open the palette on that press; the consult
        // sitting above the funnel gives the key to the command instead.
        // Moving the consult below `honourKeybindingAliases()` reddens this.
        KeyBindingRegistry::useOverrides([
            'command:keys' => 'Ctrl+Z',
            'chat.palette' => 'Ctrl+Z',
        ]);

        $app = $this->shell()->withChat(new Chat());
        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertNotNull($next->chat->keyHelp(), 'the command wins the shared chord');
        self::assertNull($next->chat->palette(), 'and the alias translation never runs for the press');
    }

    public function testAnUnknownBoundNameAnswersWithTheOrdinaryUnknownNotice(): void
    {
        // A binding can name a command that does not exist (a bundle
        // removed, a typo the grammar still permits). The parity promise is
        // that the press then behaves exactly like typing it: a notice row,
        // no overlay, no crash.
        KeyBindingRegistry::useOverrides(['command:cext5nope' => 'Ctrl+Z']);

        $app = $this->shell()->withChat(new Chat());
        $before = count($app->chat->history);

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertNull($next->chat->keyHelp());
        self::assertSame($before + 1, count($next->chat->history), 'the refusal is the ordinary transcript notice');
        $last = $next->chat->history[array_key_last($next->chat->history)];
        self::assertStringContainsString('cext5nope', $last->content);
    }

    public function testAnUnboundChordStillTravelsTheOrdinaryPath(): void
    {
        // Negative control: with no command entry the press is inert input,
        // exactly as before the feature — the consult must add nothing to
        // the no-binding case.
        $app = $this->shell()->withChat(new Chat());

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertNull($next->chat->keyHelp());
        self::assertNull($next->chat->palette());
        self::assertSame('', $next->chat->inputBuf);
    }

    public function testTheBindingDispatchesAStatusRowWhenNoChatIsHosted(): void
    {
        // The shell can exist without a hosted chat (early launch, a shell
        // case an embedding app may build). The bound press then says so
        // through the status line — the same answer a menu-bar command
        // gives without a chat — rather than dereferencing null.
        KeyBindingRegistry::useOverrides(['command:keys' => 'Ctrl+Z']);

        $app = $this->shell();
        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertSame(
            Lang::t('tui.app.no_chat', ['name' => 'keys']),
            $next->status,
        );
    }

    public function testAMidTurnChatRefusesTheBoundCommandLikeTypedInput(): void
    {
        // Parity with the menu-bar law: while a turn runs, a command would
        // rewrite history the turn is about to append to, so the press
        // answers with the in-flight notice and leaves the overlay shut.
        KeyBindingRegistry::useOverrides(['command:keys' => 'Ctrl+Z']);

        $app = $this->shell()->withChat(new Chat([], '', true));
        [$next] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

        self::assertNull($next->chat->keyHelp(), 'the overlay must not open mid-turn');
        $last = $next->chat->history[array_key_last($next->chat->history)];
        self::assertStringContainsString(
            'commands do not run while a turn is in flight',
            $last->content,
            'the refusal is the ordinary in-flight notice, not a silent drop',
        );
    }

    private function shell(): App
    {
        return App::new($this->provider, 'test-model');
    }
}
