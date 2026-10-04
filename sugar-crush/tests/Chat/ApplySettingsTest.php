<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\StatusLineTickMsg;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg;

/**
 * Roadmap N-P3: a settings save takes effect by its apply mode, through
 * {@see Chat::applySettings()} — live keys now (Chat state, a Cmd, or an engine
 * rebuild held while a turn runs), next-turn keys by the engine's own per-turn
 * read, restart keys only reported — and the session tier saves nothing to disk.
 */
final class ApplySettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-apply-settings-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        StatusLineCommand::reset();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        StatusLineCommand::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    // ── live: the engine ────────────────────────────────────────────────

    public function testMaxToolStepsMovesTheIdleEngineAndAResetRestoresItsDefault(): void
    {
        $this->writeConfig(['maxToolSteps' => 7]);
        [$chat] = $this->engineChat()->applySettings(['maxToolSteps'], '/x/config.json');

        self::assertSame(7, self::maxSteps($chat), 'the running engine takes the saved ceiling at once');
        self::assertSame([], $chat->pendingSettingsApply());

        $this->writeConfig([]);
        [$chat] = $chat->applySettings(['maxToolSteps']);
        self::assertSame(1000, self::maxSteps($chat), 'a reset is the engine default, read off EngineBackend');
    }

    public function testMaxToolStepsWaitsOutARunningTurnAndAppliesOnceItEnds(): void
    {
        $this->writeConfig(['maxToolSteps' => 9]);
        $running = new Chat(inFlight: true, backend: new EngineBackend(new EchoProvider(), 'echo'));
        $before = $running->backend();

        [$chat] = $running->applySettings(['maxToolSteps'], '/x/config.json');
        self::assertSame($before, $chat->backend(), 'never swapped under a running turn');
        self::assertSame(['maxToolSteps'], $chat->pendingSettingsApply());
        self::assertStringContainsString('1 when this turn ends', self::toastText($chat));

        // Still running: a message that leaves the turn in flight releases nothing.
        [$still] = $chat->update(new StatusLineTickMsg());
        self::assertSame(['maxToolSteps'], $still->pendingSettingsApply());

        // The first message after which nothing is in flight applies it.
        $idle = new Chat(backend: $before, pendingSettingsApply: ['maxToolSteps']);
        [$after, $cmd] = $idle->update(new StatusLineTickMsg());
        self::assertSame(9, self::maxSteps($after));
        self::assertSame([], $after->pendingSettingsApply());
        self::assertStringContainsString('maxToolSteps now applies', self::toastText($after));
        self::assertNotNull($cmd, 'the release brings its own toast tick');
    }

    // ── live: Chat state and Cmds ───────────────────────────────────────

    public function testASessionTierThemeIsPaintedNowAndNothingIsWritten(): void
    {
        $writer = $this->writer();
        $path = $writer->write(SettingsTier::Session, ['theme' => 'dracula']);

        self::assertSame(SettingsWriter::SESSION_TARGET, $path);
        self::assertFileDoesNotExist($this->home . '/.sugar-crush/config.json', 'the session tier writes no file');
        self::assertSame('dracula', Bootstrap::readUserConfig()['theme'] ?? null, 'the merged settings carry it above every file');

        [$chat] = (new Chat(backend: new EchoBackend()))->applySettings(['theme'], $path);
        self::assertSame('dracula', $chat->theme()->name);
    }

    public function testStatusLineIsReinstalledByTheReturnedCmdNotByUpdate(): void
    {
        $this->writeConfig(['statusLine' => ['type' => 'command', 'command' => 'echo hi']]);

        [, $cmd] = (new Chat(backend: new EchoBackend()))->applySettings(['statusLine'], '/x/config.json');
        self::assertNull(StatusLineCommand::active(), 'applySettings() itself installs nothing');

        $messages = self::runCmd($cmd);
        self::assertNotNull(StatusLineCommand::active(), 'the Cmd installs the saved command');
        self::assertContainsOnlyInstancesOf(\SugarCraft\Core\Msg::class, $messages);
        self::assertNotEmpty(array_filter($messages, static fn ($m): bool => $m instanceof StatusLineTickMsg), 'and asks for a refresh now');
    }

    // ── next turn and restart: reported, not applied ────────────────────

    public function testNextTurnAndRestartKeysAreReportedAndChangeNothingNow(): void
    {
        $chat = $this->engineChat();
        [$after] = $chat->applySettings(['parallelToolCalls', 'instructions'], '/x/config.json');

        self::assertSame($chat->backend(), $after->backend());
        self::assertSame(
            'Saved 2 settings to /x/config.json · 1 next turn · 1 needs a restart (instructions)',
            self::toastText($after),
        );

        // A confirmed trust grant arrives as a save too; frozen keys wait for
        // the next launch like every restart key.
        [$granted] = $chat->applySettings(['trustedProjectHooks'], '/x/config.json');
        self::assertStringContainsString('1 needs a restart (trustedProjectHooks)', self::toastText($granted));
    }

    // ── the toast ───────────────────────────────────────────────────────

    public function testTheToastIsPaintedTopRightAndOnlyItsOwnTickClearsIt(): void
    {
        [$first, $cmd] = (new Chat(backend: new EchoBackend(), rows: 24, cols: 100))->applySettings(['parallelToolCalls'], 'cfg');
        $tick = self::runCmd($cmd);
        self::assertCount(1, $tick);
        self::assertInstanceOf(SettingsToastExpiredMsg::class, $tick[0]);

        $frame = Ansi::strip(Renderer::render($first));
        self::assertStringContainsString('Saved 1 setting to cfg', $frame);
        foreach (explode("\n", $frame) as $line) {
            self::assertLessThanOrEqual(100, \SugarCraft\Core\Util\Width::string($line), 'the toast never widens a row past the terminal');
        }

        // A second save supersedes the first; the first save's tick must not
        // take the second toast down.
        [$second] = $first->applySettings(['maxOutputTokens'], 'cfg');
        [$kept] = $second->update($tick[0]);
        self::assertNotNull($kept->settingsToast());

        [$gone] = $kept->update(new SettingsToastExpiredMsg($tick[0]->generation + 1));
        self::assertNull($gone->settingsToast());
        self::assertStringNotContainsString('Saved 1 setting', Ansi::strip(Renderer::render($gone)));
    }

    // ── the schema contract ─────────────────────────────────────────────

    /**
     * "Live" is a promise: every editable live key the settings view can save
     * must be one {@see Chat::applySettings()} applies, or the badge lies.
     * `provider` is live through `/model`, `layout` through the pane shell;
     * the view writes neither.
     */
    public function testEveryLiveKeyTheViewCanSaveHasAnApplyArm(): void
    {
        $handled = ['maxToolSteps', 'theme', 'statusLine'];
        $ownDoors = array_keys(SettingsWriter::LIVE_COMMAND_KEYS);
        foreach (SettingsSchema::all() as $definition) {
            if ($definition->applyMode !== ApplyMode::Live || \in_array($definition->ui, [UiEditability::ReadOnly, UiEditability::Hidden], true)) {
                continue;
            }

            self::assertContains($definition->key, [...$handled, ...$ownDoors], "{$definition->key} is badged live but nothing applies it");
        }
    }

    public function testTheSessionTierRefusesWhatItCouldNeverApply(): void
    {
        $writer = $this->writer();

        self::assertNull($writer->refusal(SettingsTier::Session, 'maxOutputTokens', 100));
        self::assertNull($writer->refusal(SettingsTier::Session, 'maxToolSteps', 5));
        self::assertStringContainsString('/model', (string) $writer->refusal(SettingsTier::Session, 'provider', 'openai'));
        self::assertStringContainsString('restart', (string) $writer->refusal(SettingsTier::Session, 'instructions', ['AGENTS.md']));
        self::assertStringContainsString('config.json', (string) $writer->refusal(SettingsTier::Session, 'permissionMode', 'plan'));
        self::assertNotNull($writer->refusal(SettingsTier::Session, 'trustedProjectHooks', ['/x']), 'trust never goes through a save');
        self::assertSame(['maxOutputTokens', 'parallelToolCalls', 'parallelToolDeadlineSeconds', 'maxToolSteps', 'theme', 'statusLine'], SettingsWriter::sessionKeys());

        $writer->write(SettingsTier::Session, ['maxOutputTokens' => 100]);
        self::assertSame(['maxOutputTokens' => 100], $writer->current(SettingsTier::Session));
        $writer->write(SettingsTier::Session, [], ['maxOutputTokens']);
        self::assertSame([], $writer->current(SettingsTier::Session), 'a reset drops the session value; the files show through');
    }

    // ── the App door ────────────────────────────────────────────────────

    public function testTheShellHandsASuccessfulSaveToTheChatAndAFailureToNobody(): void
    {
        $this->writeConfig(['maxToolSteps' => 11]);
        $app = App::new($this->createMock(\SugarCraft\Crush\Providers\ProviderInterface::class), 'test-model')->withChat($this->engineChat());

        [$failed, $none] = $app->update(SettingsSavedMsg::failed(SettingsTier::You, ['maxToolSteps'], 'disk full'));
        self::assertNull($none);
        self::assertNull($failed->chat?->settingsToast());

        [$saved, $cmd] = $app->update(SettingsSavedMsg::saved(SettingsTier::You, ['maxToolSteps'], '/x/config.json'));
        self::assertNotNull($cmd);
        self::assertSame(11, self::maxSteps($saved->chat));
        self::assertStringContainsString('1 applies now', self::toastText($saved->chat));

        // The toast's expiry is a Msg the shell passes through to the chat.
        $expiry = self::runCmd($cmd);
        [$cleared] = $saved->update($expiry[0]);
        self::assertNull($cleared->chat?->settingsToast());

        // A keystroke does not disturb a toast.
        [$typed] = $saved->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNotNull($typed->chat?->settingsToast());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function engineChat(): Chat
    {
        return new Chat(backend: new EngineBackend(new EchoProvider(), 'echo'));
    }

    private function writer(): SettingsWriter
    {
        return SettingsWriter::new(Bootstrap::userConfigPath(), Bootstrap::writeUserConfig(...));
    }

    /** @param array<string, mixed> $data */
    private function writeConfig(array $data): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode($data === [] ? new \stdClass() : $data));
    }

    private static function maxSteps(?Chat $chat): int
    {
        self::assertInstanceOf(Chat::class, $chat);
        $backend = $chat->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);

        return (int) (new \ReflectionProperty(EngineBackend::class, 'maxSteps'))->getValue($backend);
    }

    private static function toastText(?Chat $chat): string
    {
        $toast = $chat?->settingsToast();
        self::assertNotNull($toast, 'a save always reports itself');

        return trim((string) preg_replace('/[│╭╮╰╯─✔ℹ\s]+/u', ' ', Ansi::strip($toast->view('', 400, 0))));
    }

    /**
     * Run a Cmd the way Program does — a batch fans out, a tick fires its
     * producer at once — and collect the Msgs.
     *
     * @return list<\SugarCraft\Core\Msg>
     */
    private static function runCmd(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            $out = [];
            foreach ($msg->cmds as $inner) {
                array_push($out, ...self::runCmd($inner));
            }

            return $out;
        }

        if ($msg instanceof TickRequest) {
            $produced = ($msg->produce)();

            return $produced === null ? [] : [$produced];
        }

        return $msg === null ? [] : [$msg];
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
