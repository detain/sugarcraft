<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-TUI-3.e: `Alt+O` (`chat.verbose-toggle`) flips the session's verbose
 * dial — `expandToolOutput`, the key the settings view edits — in one
 * keystroke. These pin the contract the chord's own docblock states: the
 * flip lands through the SESSION tier of the SettingsWriter, so the
 * `config.json` bytes never move and its door is never opened; the key is
 * Live and not an engine key, so even mid-turn it applies at once rather
 * than parking for the settle; the second press restores whatever baseline
 * the first covered; and a Chat built without the writer service reports
 * the flip as unavailable instead of half-applying it.
 */
final class VerboseToggleKeyTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';
    private int $doorKnocks = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-verbose-toggle-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        StatusLineCommand::reset();
        LaunchNotices::reset();
        $this->doorKnocks = 0;
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        StatusLineCommand::reset();
        LaunchNotices::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    public function testTheChordFlipsTheDialAndNamesTheDirection(): void
    {
        self::assertFalse(UiSettings::bool('expandToolOutput'), 'fixture: the shipped default is collapsed');

        [$on] = $this->verboseChat()->update(self::altO());
        self::assertTrue(UiSettings::bool('expandToolOutput'), 'the press lands in UiSettings — the store the Live renderer reads');
        self::assertStringContainsString('Verbose tool output on', self::peekToast($on));

        [$off] = $on->update(self::altO());
        self::assertFalse(UiSettings::bool('expandToolOutput'), 'the second press collapses again');
        self::assertStringContainsString('Verbose tool output off', self::peekToast($off));
    }

    public function testNoPressOpensTheConfigDoorOrMintsConfigBytes(): void
    {
        $chat = $this->verboseChat();
        [, $cmd] = $chat->update(self::altO());
        $this->assertNotNull($cmd, 'a flip that lands schedules its toast expiry');

        [$after] = $chat->update(self::altO());
        [, $back] = $after->update(self::altO());

        self::assertNotNull($back, 'the restore arm runs the same live path');
        self::assertSame(0, $this->doorKnocks, 'a session-tier save must never reach the config door');
        self::assertFileDoesNotExist($this->home . '/.candy-code/config.json', 'and it must never mint a config file either');
    }

    public function testTheSecondPressRestoresTheSavedBaselineNotTheDefault(): void
    {
        $configPath = $this->home . '/.candy-code/config.json';
        $bytes = (string) json_encode(['expandToolOutput' => true]);
        file_put_contents($configPath, $bytes);

        self::assertTrue(UiSettings::bool('expandToolOutput'), 'fixture: the user tier opens verbose');

        [$first] = $this->verboseChat()->update(self::altO());
        self::assertFalse(UiSettings::bool('expandToolOutput'), 'the session tier covers it for this session');
        self::assertStringContainsString('Verbose tool output off', self::peekToast($first));

        [$again] = $first->update(self::altO());
        self::assertTrue(UiSettings::bool('expandToolOutput'), 'the second press restores the saved baseline');
        self::assertStringContainsString('Verbose tool output on', self::peekToast($again));

        self::assertSame($bytes, file_get_contents($configPath), 'the user file never moved');
        self::assertSame(0, $this->doorKnocks, 'and its door never opened');
    }

    public function testAMidTurnPressLandsAtOnceAndHoldsNothing(): void
    {
        $running = new Chat(
            inFlight: true,
            backend: EngineBackend::new(new ScriptedProvider([]), 'm'),
            workspace: $this->workspaceWithWriter(),
        );

        [$after] = $running->update(self::altO());

        self::assertTrue($after->inFlight, 'the running turn is undisturbed');
        self::assertTrue(UiSettings::bool('expandToolOutput'), 'a Live non-engine key is not parked behind the turn');
        self::assertSame([], $after->pendingSettingsApply(), 'nothing queued for the settle');
        self::assertStringContainsString('Verbose tool output on', self::peekToast($after));
    }

    public function testAChatWithoutAWriterServiceReportsTheFlipAsUnavailable(): void
    {
        $bare = (new Chat(backend: EngineBackend::new(new ScriptedProvider([]), 'm')))->withSize(100, 30);

        [$after] = $bare->update(self::altO());

        self::assertFalse(UiSettings::bool('expandToolOutput'), 'no writer means no flip, not a half-applied one');
        self::assertSame([], SessionSettings::all(), 'and nothing entered the session overlay either');
        self::assertStringContainsString('cannot flip', self::peekToast($after));
    }

    private function verboseChat(): Chat
    {
        return (new Chat(
            backend: EngineBackend::new(new ScriptedProvider([]), 'm'),
            workspace: $this->workspaceWithWriter(),
        ))->withSize(100, 30);
    }

    private function workspaceWithWriter(): WorkspaceContext
    {
        return WorkspaceContext::new(root: $this->home)->withService(
            SettingsWriter::class,
            SettingsWriter::new(
                Bootstrap::userConfigPath(),
                function (array $set, array $unset): string {
                    $this->doorKnocks++;

                    throw new \LogicException('the verbose chord saves on the session tier; this door must stay shut');
                },
            ),
        );
    }

    private static function altO(): KeyMsg
    {
        // The registry label's own spelling, read back like the drift suite
        // reads it: the Alt branch of that decoder hands the rune through
        // the label's capital, exactly as a shifted terminal key would.
        return new KeyMsg(KeyType::Char, 'O', alt: true);
    }

    private static function peekToast(?Chat $chat): string
    {
        return (string) preg_replace(
            '/[│╭╮╰╯─✔ℹ\s]+/u',
            ' ',
            Ansi::strip($chat?->settingsToast()?->view('', 400, 0) ?? ''),
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
