<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * Every door to the settings view (roadmap N-P1): `/settings [search]`, its
 * `/config` alias, the Ctrl+P row, the F10 App-menu row, and the shell message
 * all of them end in. The view itself is {@see \SugarCraft\Crush\Tests\Tui\Settings\SettingsEditorTest}'s.
 */
final class SettingsCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-settings-cmd-' . bin2hex(random_bytes(5));
        $this->useHomeSandbox($this->sandbox . '/home');
        MenuBar::closeMenu();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));
    }

    private function chat(string $draft = ''): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(100, 30);
    }

    private function app(?Chat $chat = null): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withChat($chat ?? $this->chat())
            ->withSettingsSources(static fn (): SettingsSources => SettingsSources::fromLaunch(null, null, null, null, []));
    }

    /** The message a Cmd closure produces, or null. */
    private static function sent(?\Closure $cmd): ?object
    {
        return $cmd === null ? null : $cmd();
    }

    public function testSlashSettingsAsksTheShellForTheViewAndWritesNothing(): void
    {
        [$next, $cmd] = $this->chat('/settings')->update(new KeyMsg(KeyType::Enter));

        $msg = self::sent($cmd);
        self::assertInstanceOf(OpenSettingsMsg::class, $msg);
        self::assertSame('', $msg->query);
        self::assertSame('', $next->inputBuf);
        self::assertFalse($next->inFlight, 'nothing went to the model');
        self::assertCount(2, $next->history, 'and nothing was written to the transcript');
    }

    public function testTheArgumentPrefillsTheSearchAndConfigIsTheSameCommand(): void
    {
        foreach (['/settings compaction' => 'compaction', '/config' => '', '/config parallel tools' => 'parallel tools'] as $draft => $query) {
            [, $cmd] = $this->chat($draft)->update(new KeyMsg(KeyType::Enter));
            $msg = self::sent($cmd);

            self::assertInstanceOf(OpenSettingsMsg::class, $msg, $draft);
            self::assertSame($query, $msg->query, $draft);
        }
    }

    public function testBothNamesAreReservedToTheApplication(): void
    {
        self::assertTrue(CommandRegistry::isControlPlane('settings'));
        self::assertTrue(CommandRegistry::isControlPlane('config'));
    }

    public function testThePaletteRowAsksForTheSameView(): void
    {
        $spec = PaletteAction::OpenSettings->spec();
        self::assertSame('settings', $spec->name);

        [, $cmd] = $this->chat()->runPaletteAction(PaletteAction::OpenSettings->label());

        self::assertInstanceOf(OpenSettingsMsg::class, self::sent($cmd));
    }

    public function testTheShellOpensTheViewOnTheMessage(): void
    {
        [$app] = $this->app()->update(new OpenSettingsMsg('trusted'));

        self::assertNotNull($app->settingsEditor);
        self::assertSame('trusted', $app->settingsEditor->query);
    }

    public function testTheAppMenuRowOpensTheViewEvenWhileATurnRuns(): void
    {
        [$busy] = $this->chat('hello there')->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->inFlight, 'fixture: a turn is running');

        [$app] = $this->app($busy)->consumeShellCmd(new MenuSelectedMsg('App', PaletteAction::OpenSettings->label()));

        self::assertNotNull($app->settingsEditor, 'the view writes nothing, so a running turn does not refuse it');
        self::assertSame($busy, $app->chat, 'and the chat was not touched');
    }

    public function testTheOpenViewIsModalButCtrlCStillQuits(): void
    {
        [$open] = $this->app()->update(new OpenSettingsMsg());

        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame('', $typed->chat?->inputBuf, 'a key aimed at the view does not type into the chat it covers');
        self::assertNotNull($typed->settingsEditor);

        [, $quit] = $open->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNotNull($quit, 'Ctrl+C reaches the chat, which quits');

        [$closed] = $open->update(new KeyMsg(KeyType::Escape));
        self::assertNull($closed->settingsEditor);
    }

    public function testAnotherMenuRowClosesTheViewSoItsAnswerIsVisible(): void
    {
        [$open] = $this->app()->update(new OpenSettingsMsg());

        [$app] = $open->consumeShellCmd(new MenuSelectedMsg('Session', PaletteAction::SwitchSession->label()));

        self::assertNull($app->settingsEditor);
    }
}
