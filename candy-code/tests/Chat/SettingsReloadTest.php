<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\SettingsFileWatch;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\Settings\OpenSettingsMsg;

/**
 * C-TUI-5.c: settings hot reload. A hand edit to the user settings files used
 * to wait for a restart because only saves through the settings view ever
 * reached {@see Chat::applySettings()}; the per-turn re-read covers next-turn
 * keys and nothing else. Two doors close the gap and this file pins both:
 * the turn-settle arm in {@see \SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion}
 * (a cheap watch of the fingerprinted files feeds the merged-value movers
 * through the SAME applySettings at the turn boundary) and `/settings reload`
 * (the same check forced on the spot, toasting "nothing changed" when the
 * files really are quiet). Restart-roster keys keep their restart story
 * through both doors, and an engine key that moves while a queued prompt
 * carries the turn on parks on the existing `pendingSettingsApply` shelf.
 */
final class SettingsReloadTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/crush-settings-reload-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        StatusLineCommand::reset();
        LaunchNotices::reset();
        SettingsFileWatch::resetForTesting();
    }

    protected function tearDown(): void
    {
        SettingsFileWatch::resetForTesting();
        SessionSettings::reset();
        StatusLineCommand::reset();
        LaunchNotices::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function writeDisk(array $values): void
    {
        file_put_contents(
            Bootstrap::userConfigPath(),
            json_encode($values, JSON_PRETTY_PRINT) . "\n",
        );
    }

    /**
     * A settled idle chat holding a slash draft, shaped like the command test's
     * own door (history of one exchange so Enter dispatches as a submission).
     */
    private function draftChat(string $draft): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(100, 30);
    }

    /**
     * A chat mid-turn, exactly the shape the settle arm sees when an assistant
     * reply lands: in flight, engine wired, optionally carrying a queued prompt.
     */
    private function settlingChat(array $queued = []): Chat
    {
        return new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inFlight: true,
            queuedPrompts: $queued,
            backend: new EngineBackend(new EchoProvider(), 'echo'),
        );
    }

    /**
     * The box's plain-text contents after the settle/save toast machinery has
     * rendered it; '' when no toast is showing. Same collapse as the settings
     * view's own report so substrings survive a wrap.
     */
    private static function reloadToast(?Chat $chat): string
    {
        $toast = $chat?->settingsToast();

        if ($toast === null) {
            return '';
        }

        return trim((string) preg_replace('/[\x{2502}\x{256D}\x{256E}\x{2570}\x{256F}\x{2500}\x{2714}\x{2139}\s]+/u', ' ', Ansi::strip($toast->view('', 400, 0))));
    }

    public function testSettlingATurnAdoptsAHandEditOfTheme(): void
    {
        $this->writeDisk(['theme' => 'dark']);

        // The first settle only baselines the watch: there is no earlier
        // merged snapshot to diff the files against.
        [$seeded] = $this->settlingChat()->update(new AssistantMsg(Message::assistant('all done')));
        self::assertSame('dark', $seeded->theme()->name);
        self::assertSame('', self::reloadToast($seeded), 'a first look is a baseline, not a change');

        $this->writeDisk(['theme' => 'dracula']);
        [$done] = $this->settlingChat()->update(new AssistantMsg(Message::assistant('again')));

        self::assertSame('dracula', $done->theme()->name, 'the hand edit is in force at the turn boundary');
        self::assertFalse($done->inFlight);
        $toast = self::reloadToast($done);
        self::assertStringContainsString('reloaded from disk', $toast);
        self::assertStringContainsString('1 applies now', $toast);
    }

    public function testAnEngineKeyUnderACarriedTurnParksOnTheShelf(): void
    {
        $this->writeDisk(['maxToolSteps' => 4]);
        self::assertSame([], SettingsFileWatch::changedKeys(), 'baseline');

        $this->writeDisk(['maxToolSteps' => 6]);
        [$done] = $this->settlingChat(['next question'])
            ->update(new AssistantMsg(Message::assistant('partial answer')));

        // The queued prompt carries a fresh turn, so the settle is NOT a turn
        // end for the engine: the key parks exactly like a mid-session save
        // would, and the release at the real end re-applies it.
        self::assertTrue($done->inFlight, 'the queued prompt carries the turn on');
        self::assertSame(['maxToolSteps'], $done->pendingSettingsApply());
        $toast = self::reloadToast($done);
        self::assertStringContainsString('reloaded from disk', $toast);
        self::assertStringContainsString('1 when this turn ends', $toast);
    }

    public function testSlashSettingsReloadAppliesWithoutATurn(): void
    {
        $this->writeDisk(['theme' => 'tokyoNight']);
        self::assertSame([], SettingsFileWatch::changedKeys(), 'baseline');

        $this->writeDisk(['theme' => 'dracula']);
        [$next, $cmd] = $this->draftChat('/settings reload')->update(new KeyMsg(KeyType::Enter));

        self::assertSame('dracula', $next->theme()->name);
        self::assertSame('', $next->inputBuf);
        self::assertFalse($next->inFlight);
        self::assertNotInstanceOf(OpenSettingsMsg::class, $cmd === null ? $cmd : $cmd(), 'reload never opens the view');
        self::assertStringContainsString('reloaded from disk', self::reloadToast($next));
        self::assertCount(2, $next->history);
    }

    public function testTheReloadSpellingSurvivesAliasesAndCase(): void
    {
        $this->writeDisk(['theme' => 'dark']);
        self::assertSame([], SettingsFileWatch::changedKeys());

        // The alias rides the exact-spelling table (a capitalised COMMAND name
        // is unknown fleet-wide); the handler lowercases the ARGUMENT.
        foreach (['/config reload' => 'light', '/settings RELOAD' => 'ansi'] as $draft => $theme) {
            $this->writeDisk(['theme' => $theme]);
            [$next] = $this->draftChat($draft)->update(new KeyMsg(KeyType::Enter));
            self::assertSame($theme, $next->theme()->name, $draft);
        }
    }

    public function testASecondReloadSaysNothingChanged(): void
    {
        $this->writeDisk(['theme' => 'dark']);
        self::assertSame([], SettingsFileWatch::changedKeys());

        $this->writeDisk(['theme' => 'tokyoNight']);
        [$one] = $this->draftChat('/settings reload')->update(new KeyMsg(KeyType::Enter));
        self::assertSame('tokyoNight', $one->theme()->name);
        self::assertStringContainsString('reloaded from disk', self::reloadToast($one));

        [$two] = $this->draftChat('/settings reload')->update(new KeyMsg(KeyType::Enter));
        $toast = self::reloadToast($two);
        self::assertStringContainsString('Nothing changed', $toast);
        self::assertStringNotContainsString('Saved', $toast);
    }

    public function testTheFirstForcedLookOnlyBaselinesTheFiles(): void
    {
        // A process that launches with dracula already on disk must not toast
        // "reloaded" for the values it read at launch: the first observation
        // seeds. /settings reload right after a reset is that first look.
        $this->writeDisk(['theme' => 'dracula']);
        [$next, $cmd] = $this->draftChat('/settings reload')->update(new KeyMsg(KeyType::Enter));

        self::assertSame('dark', $next->theme()->name);
        self::assertNotInstanceOf(OpenSettingsMsg::class, $cmd === null ? $cmd : $cmd());
        self::assertStringContainsString('Nothing changed', self::reloadToast($next));
    }

    public function testARestartKeyReloadedStillOnlyRestarts(): void
    {
        $this->writeDisk(['titleModel' => 'fast']);
        self::assertSame([], SettingsFileWatch::changedKeys());

        $this->writeDisk(['titleModel' => 'cheap-model']);
        [$next] = $this->draftChat('/settings reload')->update(new KeyMsg(KeyType::Enter));

        $toast = self::reloadToast($next);
        self::assertStringContainsString('reloaded from disk', $toast);
        self::assertStringContainsString('needs a restart', $toast);
        self::assertStringContainsString('titleModel', $toast);
        self::assertStringNotContainsString('applies now', $toast);
    }
}
