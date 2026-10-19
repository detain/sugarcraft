<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * TUI-4a (plan_comparison 6.14): the `keybindings` map hot-reloads through
 * `/keys reload` — digest-compare plus an explicit re-apply, no file watcher
 * (the MCP `mcpConfigChangedSinceLaunch()` shape, E703-α). Each pin drives the
 * real {@see Bootstrap::reloadKeybindings()} against a sandboxed
 * `~/.candy-code/config.json` and judges the LIVE registry, so the whole
 * chain — re-read, digest gate, re-register, re-validate, memo re-derivation
 * — must hold together for any of them to go green.
 *
 * The registry and the digest static are process-global: every test starts
 * from the cleared map and restores what it found, the way
 * {@see \SugarCraft\Code\Tests\Cli\KeybindingCollisionNoticeTest} does for
 * the launch-notice statics beside it.
 */
#[Group('commands')]
final class KeysReloadTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var array<string, string> */
    private array $overridesBefore = [];

    /** @var array<string, bool> */
    private array $suppressedBefore = [];

    private ?string $digestBefore = null;

    private bool $sinkWasArmed = false;

    /** @var array<int, string> */
    private array $launchNoticesBefore = [];

    /** @var array<string, bool> */
    private array $launchNoticesDroppedBefore = [];

    private ?string $tempHome = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempHome = sys_get_temp_dir() . '/w4f-keys-' . getmypid() . '-' . uniqid('', true);
        $this->useHomeSandbox($this->tempHome);

        $this->overridesBefore = KeyBindingRegistry::overrides();
        $this->suppressedBefore = array_fill_keys(KeyBindingRegistry::suppressed(), true);
        $this->digestBefore = self::readDigest();
        $this->launchNoticesBefore = self::readLaunchStatic('launchNotices');
        $this->launchNoticesDroppedBefore = self::readLaunchStatic('launchNoticesDropped');
        $this->sinkWasArmed = RuntimeNoticeSink::isArmed();

        KeyBindingRegistry::useOverrides([]);
        self::writeDigest(null);
        self::writeLaunchStatic('launchNotices', []);
        self::writeLaunchStatic('launchNoticesDropped', []);
        if (!$this->sinkWasArmed) {
            RuntimeNoticeSink::arm(crossFork: false);
        }
    }

    protected function tearDown(): void
    {
        if (!$this->sinkWasArmed) {
            RuntimeNoticeSink::reset();
        }
        KeyBindingRegistry::useOverrides($this->overridesBefore);
        self::writeDigest($this->digestBefore);
        self::writeLaunchStatic('launchNotices', $this->launchNoticesBefore);
        self::writeLaunchStatic('launchNoticesDropped', $this->launchNoticesDroppedBefore);
        $this->restoreHomeSandbox();

        if ($this->tempHome !== null && is_dir($this->tempHome)) {
            @unlink($this->tempHome . '/.candy-code/config.json');
            @rmdir($this->tempHome . '/.candy-code');
            @rmdir($this->tempHome);
        }

        parent::tearDown();
    }

    public function testTheResetClearsEveryMemoTheClassDeclares(): void
    {
        // The census derives from the CLASS, not from a list copied here: a
        // sixth memo added without a sixth clear reddens this pin rather than
        // becoming a stale-derivation bug shipped green.
        $declared = [];
        foreach ((new \ReflectionClass(KeyBindingRegistry::class))->getProperties() as $property) {
            if ($property->isStatic() && str_ends_with($property->getName(), 'Memo')) {
                $declared[] = $property->getName();
            }
        }
        self::assertNotSame([], $declared, 'the registry is expected to memoise derivations by now');

        $body = self::registryBodyOf('resetDerived');
        foreach ($declared as $memo) {
            self::assertMatchesRegularExpression(
                '/self::\$' . preg_quote($memo, '/') . ' =/',
                $body,
                $memo . ' is a process-global memo and resetDerived() must clear it (TUI-4a census)',
            );
        }
    }

    public function testUseOverridesDelegatesTheClearToTheSingleResetSite(): void
    {
        // One clearing site: useOverrides() calls resetDerived() instead of
        // re-listing the memos, so the reload path and the launch path cannot
        // drift apart on what gets dropped.
        $body = self::registryBodyOf('useOverrides');
        self::assertStringContainsString('self::resetDerived();', $body);
        self::assertStringNotContainsString('Memo', $body, 'only resetDerived() may touch the memos');
    }

    public function testReloadingTheAppliedMapAnnouncesUnchangedAndKeepsTheKeymap(): void
    {
        $this->writeSettings(['keybindings' => ['chat.palette' => 'Ctrl+Z']]);
        Bootstrap::reloadKeybindings();
        self::assertSame(['chat.palette' => 'Ctrl+Z'], KeyBindingRegistry::overrides());

        // The second reload reads the same bytes: the applied-map digest says
        // "unchanged", and the registry keeps answering (proving the
        // unchanged path is a no-op, not a wipe).
        RuntimeNoticeSink::drain();
        Bootstrap::reloadKeybindings();
        self::assertSame(['chat.palette' => 'Ctrl+Z'], KeyBindingRegistry::overrides());

        $rows = RuntimeNoticeSink::drain();
        self::assertSame(1, self::countRowsContaining($rows, 'unchanged'), 'got: ' . print_r($rows, true));
    }

    public function testAWrittenMapReachesTheClaimSetsWithoutALaunch(): void
    {
        // This is what no launch ever proved. The rune claim sets are memoised
        // on first consult, so a reload that forgot resetDerived() keeps
        // answering from the derivation built under the OLD map. The pin
        // consults the set BEFORE the reload (seating the memo), suppresses
        // `chat.palette` through a rewritten file, and requires the set to
        // drop `Ctrl+P` afterwards. That is exactly the mutation this reddens.
        $this->writeSettings([]);
        Bootstrap::reloadKeybindings();
        self::assertSame([], KeyBindingRegistry::overrides());

        $before = KeyBindingRegistry::chatCtrlRunes();
        self::assertContains('p', $before, 'fixture: Ctrl+P is a live chat chord before the reload');

        $this->writeSettings(['keybindings' => ['chat.palette' => null]]);
        Bootstrap::reloadKeybindings();

        self::assertSame(['chat.palette'], KeyBindingRegistry::suppressed());
        self::assertNotContains('p', KeyBindingRegistry::chatCtrlRunes(), 'the claim set must re-derive: an unbound chord may not keep claiming its rune');

        $rows = RuntimeNoticeSink::drain();
        self::assertGreaterThanOrEqual(1, self::countRowsContaining($rows, 'keybindings'));
    }

    public function testAnOverrideThatStealsAChordIsReAnnouncedOnTheLiveChannel(): void
    {
        // The launch row rides warnLaunchRows(); a reload row that only went
        // there would be invisible mid-session — the transcript drains the
        // notice sink, not the launch buffer. `chat.repaint` moved onto
        // `Ctrl+P` steals `chat.palette`'s shipped chord (the fixtures are
        // KeybindingCollisionNoticeTest's).
        $this->writeSettings(['keybindings' => ['chat.repaint' => 'Ctrl+P']]);
        Bootstrap::reloadKeybindings();

        $rows = RuntimeNoticeSink::drain();
        self::assertSame(1, self::countRowsContaining($rows, 'collides with the shipped chord'), 'got: ' . print_r($rows, true));
        self::assertSame([], self::readLaunchStatic('launchNotices'), 'a reload row must not enter the launch buffer — nothing drains it mid-session; the row is only useful through the sink');

        // The launch census seam stays launch-only: the reload surface must
        // not grow a second warnLaunchRows() caller, or the cap census and
        // its summed figure drift behind the roster.
        self::assertSame(
            0,
            preg_match('/warnLaunchRows/', self::bootstrapBodyOf('reloadKeybindings')),
            'reloadKeybindings() must announce through the notice sink, not the launch buffer',
        );
    }

    public function testUnbindRidesTheReloadAndAnInvalidReReadKeepsTheLiveKeymap(): void
    {
        $this->writeSettings(['keybindings' => ['chat.agents' => null]]);
        Bootstrap::reloadKeybindings();
        self::assertSame(['chat.agents'], KeyBindingRegistry::suppressed());

        // A half-written edit must not cost the live keymap: the reload
        // consults the validator verdict directly — w5j: no throw to catch,
        // and the quoted line is the validator's own reason rather than the
        // launch refusal — keeping the applied state and announcing the
        // refusal through the same sink.
        $this->writeSettings(['keybindings' => 'not-a-map']);
        RuntimeNoticeSink::drain();
        Bootstrap::reloadKeybindings();
        self::assertSame(['chat.agents'], KeyBindingRegistry::suppressed());

        $rows = RuntimeNoticeSink::drain();
        self::assertSame(1, self::countRowsContaining($rows, 'kept'), 'got: ' . print_r($rows, true));

        // w5j honesty pins: the refusal says WHY in the validator's raw
        // words, and it must not wear the launch speech — "Refusing to
        // start" is a verdict a reload can neither make nor mean.
        self::assertSame(
            1,
            self::countRowsContaining($rows, 'must be an object mapping binding ids to chords'),
            'got: ' . print_r($rows, true),
        );
        self::assertSame(0, self::countRowsContaining($rows, 'Refusing'), 'got: ' . print_r($rows, true));
    }

    public function testTheKeysRowCarriesTheReloadArgumentThroughTheRegistry(): void
    {
        $command = BuiltInCommands::forSpelling('keys');
        self::assertNotNull($command);

        // Bare /keys still opens the reference; the one allowed argument
        // reloads; every foreign spelling stays a prompt — the SlashDispatch
        // corpus pins '/keys foo' verbatim in the draft, and the gate here is
        // what keeps that pin honest.
        self::assertTrue($command->accepts('/keys', 'keys'));
        self::assertTrue($command->accepts('/keys reload', 'keys'));
        self::assertTrue($command->accepts('/keys  reload', 'keys'), 'whitespace inside the draft is trimmed by the same law that trims the whole draft');
        self::assertFalse($command->accepts('/keys foo', 'keys'));
        self::assertFalse($command->accepts('/keys reload help', 'keys'));
    }

    public function testTheHandlerRoutesReloadAwayFromTheOverlay(): void
    {
        $dispatch = new ReflectionMethod(Chat::class, 'handleKeysCommand');
        $dispatch->setAccessible(true);
        $chat = new Chat(history: [Message::user('hello')], inputBuf: '/keys reload', backend: new EchoBackend());

        $this->writeSettings(['keybindings' => ['chat.palette' => 'Ctrl+Z']]);
        [$reloaded, $reloadCmd] = $dispatch->invoke($chat, ['reload']);
        self::assertNull($reloadCmd);
        self::assertNull($reloaded->keyHelp(), 'the reload arm must not open the reference');
        self::assertSame('', self::readInputBuf($reloaded), 'the reload arm must consume the draft');
        self::assertSame(['chat.palette' => 'Ctrl+Z'], KeyBindingRegistry::overrides());

        [$overlay, $overlayCmd] = $dispatch->invoke($chat, []);
        self::assertNull($overlayCmd);
        self::assertSame(0, $overlay->keyHelp(), 'the bare arm opens the reference');
        self::assertSame('', self::readInputBuf($overlay));
    }

    public function testTheChatBootNotesTheAppliedDigestAfterTheCollisionPair(): void
    {
        // The digest must describe what the BOOT registered, or the first
        // reload after any boot-level drift misjudges "unchanged". The
        // adjacency regex is KeybindingCollisionNoticeTest's drain pin
        // extended one step: register → report → note.
        $source = (string) file_get_contents((new \ReflectionClass(Bootstrap::class))->getFileName());
        self::assertSame(
            1,
            preg_match(
                '/useOverrides\(self::keybindingsSetting\(\)\);\s*self::reportKeybindingCollisions\(\);\s*self::noteKeybindingsApplied\(\);/',
                $source,
            ),
            'chat() no longer notes the applied keybindings digest after the collision pair',
        );
    }

    private static function countRowsContaining(array $rows, string $needle): int
    {
        return count(array_filter($rows, static fn (string $row): bool => str_contains($row, $needle)));
    }

    /** @return string the trimmed body of the named registry method */
    private static function registryBodyOf(string $method): string
    {
        return self::bodyOf(KeyBindingRegistry::class, $method);
    }

    /** @return string the trimmed body of the named Bootstrap method */
    private static function bootstrapBodyOf(string $method): string
    {
        return self::bodyOf(Bootstrap::class, $method);
    }

    /** @return string the trimmed body of the named class's method */
    private static function bodyOf(string $class, string $method): string
    {
        $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());

        $start = strpos($source, 'static function ' . $method . '(');
        self::assertIsInt($start, $method . ' must exist on ' . $class);
        $open = strpos($source, '{', $start);
        self::assertIsInt($open);

        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        self::fail('unbalanced body for ' . $method);
    }

    /** @param array<string, mixed> $values */
    private function writeSettings(array $values): void
    {
        $path = $this->tempHome . '/.candy-code/config.json';
        @mkdir(dirname($path), 0o700, true);
        self::assertNotFalse(file_put_contents($path, json_encode($values, JSON_PRETTY_PRINT)));
    }

    private static function readDigest(): ?string
    {
        $property = new ReflectionProperty(Bootstrap::class, 'keybindingsAppliedDigest');
        $property->setAccessible(true);

        /** @var ?string $value */
        $value = $property->getValue();

        return $value;
    }

    private static function writeDigest(?string $value): void
    {
        $property = new ReflectionProperty(Bootstrap::class, 'keybindingsAppliedDigest');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    /** @return array<int|string, mixed> */
    private static function readLaunchStatic(string $name): array
    {
        $property = new ReflectionProperty(Bootstrap::class, $name);
        $property->setAccessible(true);

        /** @var array<int|string, mixed> $value */
        $value = $property->getValue();

        return $value;
    }

    /** @param array<int|string, mixed> $value */
    private static function writeLaunchStatic(string $name, array $value): void
    {
        $property = new ReflectionProperty(Bootstrap::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private static function readInputBuf(Chat $chat): string
    {
        $property = new ReflectionProperty(Chat::class, 'inputBuf');
        $property->setAccessible(true);

        /** @var string $value */
        $value = $property->getValue($chat);

        return $value;
    }
}
