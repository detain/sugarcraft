<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\App\StatusLineTickMsg;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Code\Tui\Settings\SettingsToastExpiredMsg;

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
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        StatusLineCommand::reset();
        // C-PRV-1.b: the switch notice rides the launch shelf, which is a
        // process-static — clear it on both sides like every other resident.
        LaunchNotices::reset();
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

    public function testReasoningEffortMovesTheIdleEngineAndAResetAsksNothing(): void
    {
        // C-PRV-1.b: the saved tier now rides only where the pair shapes an
        // effort field, so the live-apply mechanic is pinned on a shaping
        // pair; the unshaped pair gets its own test below.
        $this->writeConfig(['reasoningEffort' => 'high']);
        [$chat] = $this->shapingChat()->applySettings(['reasoningEffort'], '/x/config.json');

        self::assertSame('high', self::effort($chat), 'the running engine takes the saved tier at once');
        self::assertSame([], $chat->pendingSettingsApply());
        self::assertSame([], array_filter(
            $chat->history,
            static fn ($row): bool => str_contains($row->content, 'left off') || str_contains($row->content, 'comes off'),
        ), 'a pair that shapes needs no announcement');

        // PRV-2: null IS the engine's "ask nothing" — unlike the step ceiling,
        // a reset restores no numeric default, it clears the ask.
        $this->writeConfig([]);
        [$chat] = $chat->applySettings(['reasoningEffort']);
        self::assertNull(self::effort($chat), 'a reset means no effort on the wire, not a remembered tier');
    }

    public function testASavedTierOnAPairThatShapesNothingComesOffWithOneRow(): void
    {
        // C-PRV-1.b root cause: the persisted tier used to land on ANY engine,
        // including a pair (echo here) whose wire has no field for it — the
        // transcript looked configured while the request stayed plain. Now the
        // engine keeps no tier and the switch says so once, uiOnly.
        $this->writeConfig(['reasoningEffort' => 'high']);
        $before = $this->engineChat();
        $historyBefore = count($before->history);

        [$chat] = $before->applySettings(['reasoningEffort'], '/x/config.json');

        self::assertNull(self::effort($chat), 'the unshaped pair carries no silent tier');
        $rows = array_slice($chat->history, $historyBefore);
        self::assertCount(1, $rows, 'the switch states the exception once');
        self::assertTrue($rows[0]->uiOnly, 'the notice never rides the transcript to the model');
        self::assertStringContainsString('comes off the running engine', $rows[0]->content);

        // Re-applying (another settings save, the palette, a reset-and-set)
        // does not repeat the sentence — the shelf de-dupes on identity.
        [$again] = $chat->applySettings(['reasoningEffort']);
        self::assertCount(0, array_slice($again->history, count($chat->history)),
            'the same reason is not stated twice');
        self::assertNull(self::effort($again), 'and the engine still carries nothing');
    }

    public function testNonsenseOrCasewiseEffortResolvesLikeTheLaunchRuleNotWorse(): void
    {
        // One resolver, read by launch and live apply alike
        // (Bootstrap::resolvedReasoningEffort): a recognized tier is lowered
        // to its wire spelling, and anything else is exactly as good as
        // unset — never a throw a settings typo could weaponise against the
        // next save. Pinned on a shaping pair (C-PRV-1.b) so the assertion is
        // about the resolver, not the effort gate.
        $this->writeConfig(['reasoningEffort' => 'High ']);
        [$chat] = $this->shapingChat()->applySettings(['reasoningEffort']);
        self::assertSame('high', self::effort($chat), 'the hand-edited spelling lands on the same tier');

        $this->writeConfig(['reasoningEffort' => 'turbo']);
        [$chat] = $chat->applySettings(['reasoningEffort']);
        self::assertNull(self::effort($chat), 'a nonsense level asks nothing');

        $this->writeConfig(['reasoningEffort' => 7]);
        [$chat] = $chat->applySettings(['reasoningEffort']);
        self::assertNull(self::effort($chat), 'a non-string is nonsense too, not a float effort');
    }

    // ── live: Chat state and Cmds ───────────────────────────────────────

    public function testASessionTierThemeIsPaintedNowAndNothingIsWritten(): void
    {
        $writer = $this->writer();
        $path = $writer->write(SettingsTier::Session, ['theme' => 'dracula']);

        self::assertSame(SettingsWriter::SESSION_TARGET, $path);
        self::assertFileDoesNotExist($this->home . '/.candy-code/config.json', 'the session tier writes no file');
        self::assertSame('dracula', Bootstrap::readUserConfig()['theme'] ?? null, 'the merged settings carry it above every file');

        [$chat] = (new Chat(backend: new EchoBackend()))->applySettings(['theme'], $path);
        self::assertSame('dracula', $chat->theme()->name);
    }

    public function testMaxCostUsdMovesTheRunningCapAndAnUnusableValueKeepsIt(): void
    {
        $env = getenv('CANDY_CODE_MAX_COST');
        putenv('CANDY_CODE_MAX_COST');
        try {
            $this->writeConfig(['maxCostUsd' => 2.5]);
            [$chat] = (new Chat(backend: new EchoBackend(), maxCostUsd: 9.0))->applySettings(['maxCostUsd'], '/x/config.json');
            self::assertSame(2.5, $chat->maxCostUsd(), 'the saved ceiling is the session\'s cap at once');
            self::assertStringContainsString('1 applies now', self::toastText($chat));

            $this->writeConfig(['maxCostUsd' => 0]);
            [$kept] = $chat->applySettings(['maxCostUsd']);
            self::assertSame(2.5, $kept->maxCostUsd(), 'a value that is not a ceiling never uncaps the session');
            self::assertStringContainsString('maxCostUsd is not a spend ceiling', self::toastText($kept));

            $this->writeConfig([]);
            [$cleared] = $kept->applySettings(['maxCostUsd']);
            self::assertNull($cleared->maxCostUsd(), 'a reset is no cap, as a launch without the key has');
        } finally {
            putenv($env === false ? 'CANDY_CODE_MAX_COST' : 'CANDY_CODE_MAX_COST=' . $env);
        }
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

    public function testAReloadKeyIsAnnouncedForTheReloadWordAndChangesNothingNow(): void
    {
        // w5j (MINOR-2): the reload bucket of the save summary — `keybindings`
        // is the one ApplyMode::Reload key, and TUI-4a's promise is that a
        // save alone says the word it takes (`/keys reload`) while touching
        // nothing live. The family's next-turn and restart legs have their
        // pins above; this closes the third bucket.
        $overridesBefore = KeyBindingRegistry::overrides();
        $suppressedBefore = KeyBindingRegistry::suppressed();

        [$chat] = (new Chat(backend: new EchoBackend()))->applySettings(['keybindings'], '/x/config.json');
        self::assertStringContainsString('1 applies on /keys reload (keybindings)', self::toastText($chat));

        // A reload-pending save is not a success badge — the Info symbol says
        // the keymap is still waiting on the word.
        $raw = (string) $chat->settingsToast()?->view('', 400, 0);
        self::assertStringContainsString('ℹ', $raw, 'the reload bucket paints Info, not Success');
        self::assertStringNotContainsString('✔', $raw);

        // The leg promises the word, not an apply: the live keymap is inert.
        self::assertSame($overridesBefore, KeyBindingRegistry::overrides());
        self::assertSame($suppressedBefore, KeyBindingRegistry::suppressed());

        // Mixed buckets keep their own clauses side by side.
        [$mixed] = (new Chat(backend: new EchoBackend()))->applySettings(['keybindings', 'instructions'], '/x/config.json');
        $text = self::toastText($mixed);
        self::assertStringContainsString('1 applies on /keys reload (keybindings)', $text);
        self::assertStringContainsString('1 needs a restart (instructions)', $text);
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
        // N-P4g: the interface keys read through UiSettings apply by the
        // save dropping its held values.
        $handled = ['maxToolSteps', 'reasoningEffort', 'theme', 'statusLine', ...\SugarCraft\Code\Config\Settings\UiSettings::KEYS];
        // N-P4b: every key the session's CompactorConfig is read from
        // rebuilds it on a save.
        $handled = [...$handled, ...\SugarCraft\Code\Context\CompactorConfig::SETTINGS];
        // N-P4d: read as each project note is written.
        $handled[] = \SugarCraft\Code\Context\ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES;
        $handled[] = \SugarCraft\Code\Cli\Bootstrap::MAX_COST_SETTING;
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
        self::assertSame(['maxOutputTokens', 'reasoningEffort', 'parallelToolCalls', 'parallelToolDeadlineSeconds', 'maxToolSteps', 'maxCostUsd', 'compaction.reminderPercent', 'compaction.autoPercent', 'compaction.blockPercent', 'compaction.keepRecent', 'compaction.summaryUserChars', 'compaction.summaryAssistantChars', 'compaction.toolOutputChars', 'compaction.reminderTokens', 'compaction.autoTokens', 'compaction.blockTokens', 'compaction.modelTokenCaps', 'compaction.idleOfferSeconds', 'compaction.mode', 'compaction.refillLimit', 'compaction.idleAuto', 'promptCache.keepWarm', 'contextPruning.minContextTokens', 'contextPruning.maxContextTokens', 'contextPruning.nudgeFrequency', 'contextPruning.iterationNudgeThreshold', 'repoMap.enabled', 'repoMap.maxBytes', 'env.gitDiffAfterWrites', 'env.diffMaxBytes', 'autoReviewScope', 'permissions.autoStrikeLimit', 'permissions.autoTotalLimit', 'permissions.autoAllowReadOnly', 'redactToolOutput', 'toolOutputCapBytes', 'mcpResultCapBytes', 'readMaxBytes', 'readPageLines', 'readPageBytes', 'toolSpillWindowPercent', 'toolInstructionCapBytes', 'toolSpillCaptureBytes', 'toolSpillMinCapBytes', 'globMaxMatches', 'webFetchMaxBytes', 'webFetchTimeoutSeconds', 'bashInteractiveIdleSeconds', 'bashTimeoutSeconds', 'bashMaxTimeoutSeconds', 'chatToolTimeoutSeconds', 'grepTimeoutSeconds', 'requireReadBeforeWrite', 'sd.timeoutSeconds', 'skills.pathNudges', 'embeddingModel', 'subagentMaxTurns', 'subagentMaxDepth', 'subagentMaxActive', 'theme', 'statusLine', 'queueMode', 'terminalBackground', 'mouse', 'mouseClicks', 'scrollWheelLines', 'doubleEscSeconds', 'resumeCostWarnUsd', 'paletteMru', 'diffPreviewRows', 'diffStyle', 'toolOutputPreviewLines', 'sessions.autoTitle', 'promptSuggestions', 'promptSuggestionHistory', 'sessions.awayRecap', 'expandToolOutput', 'maxCheckpoints', 'vimMode', 'screenReader', 'providerRetryAttempts', 'providerRetryBaseBackoffMs'], SettingsWriter::sessionKeys());

        $writer->write(SettingsTier::Session, ['maxOutputTokens' => 100]);
        self::assertSame(['maxOutputTokens' => 100], $writer->current(SettingsTier::Session));
        $writer->write(SettingsTier::Session, [], ['maxOutputTokens']);
        self::assertSame([], $writer->current(SettingsTier::Session), 'a reset drops the session value; the files show through');
    }

    // ── the App door ────────────────────────────────────────────────────

    public function testTheShellHandsASuccessfulSaveToTheChatAndAFailureToNobody(): void
    {
        $this->writeConfig(['maxToolSteps' => 11]);
        $app = App::new($this->createMock(\SugarCraft\Code\Providers\ProviderInterface::class), 'test-model')->withChat($this->engineChat());

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

    /**
     * The same live-apply mechanic on a pair that DOES shape an effort field
     * (C-PRV-1.b): anthropic with a claude model is on the EffortGate roster.
     */
    private function shapingChat(): Chat
    {
        return new Chat(backend: new EngineBackend(
            new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6'),
            'claude-sonnet-4-6',
        ));
    }

    private function writer(): SettingsWriter
    {
        return SettingsWriter::new(Bootstrap::userConfigPath(), Bootstrap::writeUserConfig(...));
    }

    /** @param array<string, mixed> $data */
    private function writeConfig(array $data): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode($data === [] ? new \stdClass() : $data));
    }

    private static function maxSteps(?Chat $chat): int
    {
        self::assertInstanceOf(Chat::class, $chat);
        $backend = $chat->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);

        return (int) (new \ReflectionProperty(EngineBackend::class, 'maxSteps'))->getValue($backend);
    }

    private static function effort(?Chat $chat): string|float|null
    {
        self::assertInstanceOf(Chat::class, $chat);
        $backend = $chat->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);

        return $backend->reasoningEffort();
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
