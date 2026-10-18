<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;

/**
 * ISS-047, rv5b: the `keybindings` setting is live dispatch, not a validated
 * memo. An override chord lands at the one keystroke funnel (`App::handleKey`)
 * and fires the binding's shipped behaviour; the shipped chord keeps firing
 * alongside it — the override ADDS a chord, it does not move one.
 */
final class KeybindingChordDispatchTest extends TestCase
{
    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides([]);
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    public function testTheOverrideChordOpensWhatTheShippedChordOpens(): void
    {
        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Q']);

        [$next] = $this->chatHostedApp()->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));

        $this->assertNotNull($next->chat->palette(), 'the alias must reach Chat\'s palette arm');
    }

    public function testTheShippedChordStillFiresAlongsideTheAlias(): void
    {
        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Q']);

        [$next] = $this->chatHostedApp()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $this->assertNotNull($next->chat->palette(), 'the shipped chord is never stranded by an alias');
    }

    public function testWithoutAnOverrideTheAliasKeyIsJustAControlPress(): void
    {
        [$next] = $this->chatHostedApp()->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));

        $this->assertNull($next->chat->palette(), 'no override, no dispatch: Ctrl+Q answers as shipped (inert)');
    }

    /**
     * The other context too: shell.palette ships `Ctrl+K` (the pane shell's
     * claim, answer with a CommandPaletteCmd that feeds Chat's own palette
     * arm). Aliasing it onto `Ctrl+Q` must take EXACTLY that path — the
     * translation happens at the funnel, and every rule below it (claims,
     * handleCtrl, consumeShellCmd) answers the shipped rune unchanged.
     */
    public function testAnOverrideChordReachesAShellContextBinding(): void
    {
        KeyBindingRegistry::useOverrides(['shell.palette' => 'Ctrl+Q']);

        [$viaAlias] = $this->chatHostedApp()->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));
        $this->assertNotNull($viaAlias->chat->palette(), 'the shell-side alias must land on the shipped command');

        [$viaShipped] = $this->chatHostedApp()->update(new KeyMsg(KeyType::Char, 'k', ctrl: true));
        $this->assertNotNull($viaShipped->chat->palette(), 'and the shipped chord still does');
    }

    private function chatHostedApp(): App
    {
        $chat = (new Chat(backend: new EchoBackend()))->withSize(120, 20);

        return App::new(new ScriptedProvider([]), 'm')
            ->withChat($chat)
            ->withPane(Pane::Chat);
    }
}
