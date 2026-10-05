<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Config\Settings\UiSettings;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Theme;

/**
 * Roadmap N-P4g: the TUI's own constants became interface settings — each
 * default is the constant it replaced, a saved value is read on use, an
 * invalid one falls back to the default, and the two mouse variables still
 * outrank their settings.
 */
final class UiSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-ui-settings-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        foreach (self::VARS as $var) {
            putenv($var);
        }
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    public function testEveryHeldKeyIsALiveInterfaceKey(): void
    {
        foreach (UiSettings::KEYS as $key) {
            $definition = \SugarCraft\Crush\Config\Settings\SettingsSchema::byKey($key);
            self::assertNotNull($definition, $key);
            self::assertSame(\SugarCraft\Crush\Config\Settings\SettingCategory::Interface, $definition->category, $key);
            self::assertSame(\SugarCraft\Crush\Config\Settings\ApplyMode::Live, $definition->applyMode, $key);
        }
    }

    public function testEveryDefaultIsTheConstantItReplaced(): void
    {
        self::assertSame(QueueMode::Steer, QueueMode::onEnter());
        self::assertTrue(UiSettings::bool('mouse'));
        self::assertTrue(UiSettings::bool('mouseClicks'));
        self::assertSame(Chat::SCROLL_WHEEL_LINES, UiSettings::int('scrollWheelLines'));
        self::assertSame(Chat::DOUBLE_ESCAPE_WINDOW_SECONDS, UiSettings::float('doubleEscSeconds'));
        self::assertSame(Chat::PALETTE_MRU_LIMIT, UiSettings::int('paletteMru'));
        self::assertSame(Renderer::DIFF_MAX_ROWS, UiSettings::int('diffPreviewRows'));
        self::assertSame(Renderer::TOOL_OUTPUT_MAX_LINES, UiSettings::int('toolOutputPreviewLines'));
        self::assertSame(EnhancedSessionStore::MAX_CHECKPOINTS_PER_SESSION, EnhancedSessionStore::maxCheckpoints());
        self::assertSame(MouseMode::CellMotion, Chat::mouseMode());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: mixed}> */
    public static function invalidValues(): array
    {
        return [
            'enum outside its values' => [['queueMode' => 'shout'], 'queueMode', 'steer'],
            'int out of range' => [['scrollWheelLines' => 0], 'scrollWheelLines', Chat::SCROLL_WHEEL_LINES],
            'int of the wrong type' => [['diffPreviewRows' => 'lots'], 'diffPreviewRows', Renderer::DIFF_MAX_ROWS],
            'float out of range' => [['doubleEscSeconds' => 9.0], 'doubleEscSeconds', Chat::DOUBLE_ESCAPE_WINDOW_SECONDS],
            'bool of the wrong type' => [['mouse' => 'no'], 'mouse', true],
        ];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueReadsAsTheDefault(array $config, string $key, mixed $default): void
    {
        $this->writeConfig($config);

        self::assertSame($default, UiSettings::value($key));
    }

    public function testAnIntegerWindowIsReadAsSeconds(): void
    {
        $this->writeConfig(['doubleEscSeconds' => 1]);

        self::assertSame(1.0, UiSettings::float('doubleEscSeconds'));
    }

    public function testQueueModeIsTheOneSwitchEnterReads(): void
    {
        $this->writeConfig(['queueMode' => 'followup']);
        self::assertSame(QueueMode::Followup, QueueMode::onEnter());

        $token = new CancellationToken();
        [$next] = $this->midTurn($token, 'then the docs')->update(new KeyMsg(KeyType::Enter));

        self::assertSame(['then the docs'], $next->queuedPrompts(), 'followup queues the message');
        self::assertSame([], $token->takeSteers(), 'and does not steer it in');
        self::assertFalse($token->isSoftCancelled());
    }

    public function testInterruptStopsTheTurnAtItsStepAndQueuesTheMessage(): void
    {
        $this->writeConfig(['queueMode' => 'interrupt']);
        $token = new CancellationToken();
        $chat = $this->midTurn($token, 'stop, do this instead', [
            Message::user('go'),
            Message::toolRunning(new \SugarCraft\Crush\ToolCall('Bash', ['command' => 'sleep 600'], 'call_run')),
        ], new \SugarCraft\Crush\Events\StepStarted(2, 1000, null));

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($token->isSoftCancelled(), 'the turn stops at its step boundary');
        self::assertSame(['call_run'], $token->takeToolCancels(), 'and the call running now stops too');
        self::assertFalse($token->isCancelled(), 'never a hard cancel');
        self::assertSame(['stop, do this instead'], $next->queuedPrompts(), 'the message goes out when the turn settles');
        self::assertSame([], $token->takeSteers());
    }

    public function testTheMouseSettingsTurnTrackingAndClicksOffAndTheVariablesStillWin(): void
    {
        $this->writeConfig(['mouseClicks' => false]);
        self::assertSame(MouseMode::CellMotion, Chat::mouseMode(), 'clicks off keeps the wheel');
        self::assertFalse(Chat::mouseClicksEnabled());

        $this->writeConfig(['mouse' => false]);
        self::assertSame(MouseMode::Off, Chat::mouseMode());
        self::assertSame(MouseMode::Off, Chat::programOptions()->mouseMode, 'the launch asks for no tracking');
        self::assertFalse(Chat::mouseClicksEnabled());

        $this->writeConfig(['mouse' => true]);
        putenv('SUGARCRUSH_DISABLE_MOUSE=1');
        self::assertSame(MouseMode::Off, Chat::mouseMode(), 'the variable outranks the setting');
    }

    public function testASavedMouseSettingAppliesLiveThroughACmd(): void
    {
        $chat = (new Chat())->withSize(80, 20);

        $this->writeConfig(['mouse' => false]);
        // Read once so the held value is stale until the save drops it.
        UiSettings::bool('mouse');
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode(['mouse' => true], JSON_THROW_ON_ERROR));
        [, $cmd] = $chat->applySettings(['mouse'], 'config.json');

        self::assertNotNull($cmd);
        self::assertSame(MouseMode::CellMotion, Chat::mouseMode(), 'the save dropped the held value');
    }

    public function testTheWheelStepIsTheSetting(): void
    {
        $this->writeConfig(['scrollWheelLines' => 5]);
        $history = [];
        for ($i = 1; $i <= 40; $i++) {
            $history[] = new Message(Role::User, "line {$i}", 0);
        }
        $chat = new Chat(history: $history, rows: 14, cols: 80);
        Renderer::render($chat);

        [$scrolled] = $chat->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Motion));

        self::assertSame(5, $scrolled->scrollOffset());
    }

    public function testTheDoubleEscapeWindowIsTheSetting(): void
    {
        $this->writeConfig(['doubleEscSeconds' => 0.2]);
        $read = new \ReflectionMethod(Chat::class, 'doubleEscapeWindowSeconds');

        self::assertSame(0.2, $read->invoke(null));
    }

    public function testThePaletteRemembersAsManyRowsAsTheSettingSays(): void
    {
        $this->writeConfig(['paletteMru' => 2]);
        $remember = new \ReflectionMethod(Chat::class, 'rememberPaletteUse');
        $chat = new Chat();
        foreach (['Exit', 'New session', 'Compact'] as $label) {
            $chat = $remember->invoke($chat, $label);
        }

        self::assertSame(['Compact', 'New session'], $chat->paletteMru());

        $this->writeConfig(['paletteMru' => 0]);
        self::assertSame([], $remember->invoke(new Chat(), 'Exit')->paletteMru(), '0 remembers none');
    }

    public function testTheDiffPreviewKeepsAsManyRowsAsTheSettingSays(): void
    {
        $this->writeConfig(['diffPreviewRows' => 5]);
        $diff = implode("\n", array_map(static fn (int $i): string => "+line {$i}", range(1, 30)));
        $render = new \ReflectionMethod(Renderer::class, 'renderDiff');

        $out = \SugarCraft\Core\Util\Ansi::strip((string) $render->invoke(null, $diff, Theme::byName('dark'), 80));

        self::assertStringContainsString('… 25 more diff lines', $out);
        self::assertStringContainsString('line 5', $out);
        self::assertStringNotContainsString('line 6', $out);
    }

    public function testTheCollapsedToolOutputKeepsAsManyLinesAsTheSettingSays(): void
    {
        $this->writeConfig(['toolOutputPreviewLines' => 3]);
        $read = new \ReflectionMethod(Renderer::class, 'toolOutputPreviewLines');

        self::assertSame(3, $read->invoke(null));
    }

    public function testTheCheckpointCapIsTheSetting(): void
    {
        $this->writeConfig(['maxCheckpoints' => 3]);
        $store = new EnhancedSessionStore($this->home . '/sessions.db');
        $id = 'ui-settings-cap';
        $store->createSession($id, 'echo', 'm');

        for ($turn = 1; $turn <= 5; $turn++) {
            $store->saveCheckpoint($id, ['messages' => [['role' => 'user', 'content' => "turn {$turn}"]], 'inputBuf' => '']);
        }

        self::assertSame(3, EnhancedSessionStore::maxCheckpoints());
        self::assertCount(3, $store->listCheckpoints($id));
    }

    public function testTheStatusLineRefreshIsHeldToItsRange(): void
    {
        $line = static fn (mixed $seconds): ?StatusLineCommand => StatusLineCommand::fromSettings([
            'statusLine' => ['type' => 'command', 'command' => 'echo hi', 'refreshSeconds' => $seconds],
        ]);

        self::assertSame(30.0, $line(30)?->refreshSeconds);
        self::assertSame(StatusLineCommand::REFRESH_SECONDS, $line(0.5)?->refreshSeconds, 'faster than the floor keeps the default');
        self::assertSame(StatusLineCommand::REFRESH_SECONDS, $line(99999)?->refreshSeconds);
        self::assertSame(StatusLineCommand::REFRESH_SECONDS, $line('soon')?->refreshSeconds);
        self::assertSame(StatusLineCommand::MAX_REFRESH_SECONDS, $line(3600)?->refreshSeconds);
    }

    public function testASessionTierValueIsReadToo(): void
    {
        SessionSettings::apply(['scrollWheelLines' => 9]);

        self::assertSame(9, UiSettings::int('scrollWheelLines'), 'a session value changes what the held read is keyed by');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /** @param list<Message>|null $history */
    private function midTurn(
        CancellationToken $token,
        string $draft,
        ?array $history = null,
        ?\SugarCraft\Crush\Events\StepStarted $step = null,
    ): Chat {
        return (new Chat(
            history: $history ?? [Message::user('go')],
            backend: EngineBackend::new(new ScriptedProvider([]), 'm'),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $token,
            inputBuf: $draft,
            liveStep: $step,
            liveStepGeneration: $step === null ? 0 : 1,
        ))->withSize(120, 20);
    }
}
