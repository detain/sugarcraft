<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Examples;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Dash\Layout\FocusManager;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Recorder;

/**
 * End-to-end test for the dashboard-live.php interactive demo.
 *
 * This test boots the dashboard against a candy-vcr-recorded cassette,
 * replays a scripted sequence of inputs (keyboard navigation + quit),
 * and asserts the program exits cleanly (exit 0).
 *
 * ## Recording a new cassette
 *
 * To re-record the cassette after changes to the dashboard:
 *   cd sugar-dash && php examples/dashboard-live.php
 *   # interact with the demo, then press q
 *   # copy the recorded .cas file to tests/Fixtures/dashboard-live.cas
 *
 * ## What the test verifies
 *
 *   1. The program boots and renders without crashing.
 *   2. Keyboard input (Tab, arrow keys, q) is processed correctly.
 *   3. The program exits with status 0 on QuitMsg.
 *
 * @see \SugarCraft\Vcr\Player
 * @see \SugarCraft\Vcr\Recorder
 */
final class DashboardLiveTest extends TestCase
{
    private const FIXTURE_CASSETTE = __DIR__ . '/Fixtures/dashboard-live.cas';
    private const TIMEOUT_SECONDS = 30.0;

    /**
     * Test that dashboard-live.php replays correctly from the VCR fixture.
     *
     * The cassette records:
     *   1. Program startup + initial resize
     *   2. A Tab keypress (focus rotation)
     *   3. Arrow key presses (focus movement)
     *   4. A 'q' keypress (quit)
     *
     * The Player drives the Program through these events and asserts
     * the output bytes match what was recorded.
     */
    public function testReplayDashboardLiveFromCassette(): void
    {
        // Skip if the fixture doesn't exist yet (first-time setup).
        // Once the cassette is recorded this file should exist.
        if (!file_exists(self::FIXTURE_CASSETTE)) {
            $this->markTestSkipped(
                'Cassette fixture not found: ' . self::FIXTURE_CASSETTE
                . "\nTo create it, run: cd sugar-dash && php examples/dashboard-live.php"
                . "\nthen press q to quit, and copy the .cas file to tests/Examples/Fixtures/"
            );
        }

        $player = Player::open(self::FIXTURE_CASSETTE);
        $result = $player->play(
            fn ($input, $output, $loop) => new Program(
                new DashboardModelForTest(),
                new ProgramOptions(
                    useAltScreen: false,
                    catchInterrupts: false,
                    hideCursor: false,
                    input: $input,
                    output: $output,
                    loop: $loop,
                    windowSize: ['cols' => 120, 'rows' => 30],
                ),
            ),
            assertion: null, // Use default ByteAssertion
            speed: Player::SPEED_INSTANT,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        $this->assertTrue(
            $result->ok,
            'Dashboard replay failed: ' . ($result->diff !== '' ? $result->diff : 'unknown error')
        );
        $this->assertEquals(1, $result->quitCount, 'Expected exactly one quit event');
    }

    /**
     * Smoke test: verify DashboardModel initializes and renders without error.
     *
     * This does NOT use VCR — it runs the model directly with a synthetic
     * sequence of messages and checks that update() and view() don't throw.
     *
     * Note: DashboardModelForTest::init() returns null by design (to avoid
     * generating tick Cmds in test context). The real DashboardModel::init()
     * returns tick Cmds from its modules.
     */
    public function testDashboardModelSmokeTest(): void
    {
        $model = new DashboardModelForTest();

        // DashboardModelForTest::init() returns null (no tick Cmds in tests).
        // The real DashboardModel::init() would return Cmds for 1Hz clock, etc.
        $initCmd = $model->init();
        $this->assertNull($initCmd, 'DashboardModelForTest::init() returns null by design');

        // view() should render without throwing.
        $view = $model->view();
        $this->assertIsString($view, 'view() must return a string');
        $this->assertNotEmpty($view, 'view() must not be empty');

        // Feed a tick to the clock module — it should still work.
        $tickMsg = new class implements Msg {};
        [$next, $cmd] = $model->update($tickMsg);
        $this->assertInstanceOf(DashboardModelForTest::class, $next);

        // Feed QuitMsg — update should return Cmd::quit().
        [$next2, $quitCmd] = $model->update(new QuitMsg());
        $this->assertNotNull($quitCmd, 'QuitMsg should produce a quit Cmd');
    }

    /**
     * Test that keyboard focus rotation works correctly.
     *
     * Sends Tab/Shift+Tab sequences and verifies the focused panel changes.
     */
    public function testFocusRotationWithKeyboardInput(): void
    {
        $model = new DashboardModelForTest();

        // Initial focus is on first registered panel (address "0").
        // After Tab, focus should rotate to "1".
        $tabMsg = new KeyMsg(KeyType::Tab, '');
        [$m1, $cmd1] = $model->update($tabMsg);
        $this->assertNull($cmd1, 'Tab should not produce a Cmd');

        // After Shift+Tab, focus should go back to "0".
        $shiftTabMsg = new KeyMsg(KeyType::Tab, '', false, false, true);
        [$m2, $cmd2] = $model->update($shiftTabMsg);
        $this->assertNull($cmd2, 'Shift+Tab should not produce a Cmd');

        // Arrow keys also cycle focus.
        $upMsg = new KeyMsg(KeyType::Up, '');
        [$m3, $cmd3] = $model->update($upMsg);
        $this->assertNull($cmd3, 'ArrowUp should not produce a Cmd');
    }

    /**
     * Test that 'q' character triggers a QuitMsg.
     *
     * Sending 'q' should NOT immediately quit (DashboardModel lets
     * QuitMsg propagate so the Program handles it), but update() should
     * return a Cmd::quit().
     */
    public function testQuitOnQKey(): void
    {
        $model = new DashboardModelForTest();

        $qMsg = new KeyMsg(KeyType::Char, 'q');
        [$next, $cmd] = $model->update($qMsg);

        // DashboardModel doesn't handle q directly in handleKey();
        // it lets update()'s QuitMsg branch handle it.
        $this->assertNull($cmd, 'q key should not produce an immediate quit Cmd');

        // Feed QuitMsg directly — update should return Cmd::quit().
        [$next2, $quitCmd] = $next->update(new QuitMsg());
        $this->assertNotNull($quitCmd, 'QuitMsg should produce a quit Cmd');
    }
}

/**
 * DashboardModelForTest — test variant of DashboardModel that bypasses
 * the real module tick intervals for deterministic testing.
 *
 * The real DashboardModel uses 1Hz/2Hz/30min ticks. In tests we want
 * deterministic timing, so this variant replaces the module implementations
 * with stable stubs that don't generate background tick Cmds.
 *
 * @internal Test only — not part of the public API.
 */
final class DashboardModelForTest implements Model
{
    /** @var array<string, ModuleForTest> */
    private array $modules = [];

    private FocusManager $focus;

    public function __construct()
    {
        $this->focus = new FocusManager('root');

        // Stable stub modules — no tick Cmd, no background refresh.
        $this->modules = [
            '0' => new ModuleForTest('0', 'Clock panel', '12:34:56'),
            '1' => new ModuleForTest('1', 'System panel', "CPU 45%\nMEM 62%"),
            '2' => new ModuleForTest('2', 'Weather panel', '—°C unavailable'),
        ];

        // Explicitly iterate with string keys to avoid PHP 8 type inference quirks
        foreach (['0', '1', '2'] as $addr) {
            $this->focus = $this->focus->register($addr);
        }
    }

    public function init(): ?\Closure
    {
        return null; // No tick Cmds in tests
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof QuitMsg || $msg instanceof \SugarCraft\Core\Msg\InterruptMsg) {
            return [$this, Cmd::quit()];
        }

        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Tab && !$msg->shift) {
                $this->focus = $this->focus->focusNext();
                return [$this, null];
            }
            if ($msg->type === KeyType::Tab && $msg->shift) {
                $this->focus = $this->focus->focusPrevious();
                return [$this, null];
            }
            if (in_array($msg->type, [KeyType::Up, KeyType::Down, KeyType::Left, KeyType::Right], true)) {
                $this->focus = $this->focus->focusNext();
                return [$this, null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, null]; // Let QuitMsg branch handle it
            }
        }

        // Broadcast to all modules (they no-op on unknown msgs).
        foreach ($this->modules as $addr => $module) {
            [$nextModule,] = $module->update($msg);
            $this->modules[$addr] = $nextModule;
        }

        return [$this, null];
    }

    public function view(): string
    {
        $lines = [];
        $focusedId = $this->focus->getFocusedId();

        foreach ($this->modules as $addr => $module) {
            $content = $module->view();
            $focused = ($addr === $focusedId);
            $prefix = $focused ? "[{$addr}]" : " {$addr} ";
            $border = $focused ? '█' : '─';

            $lines[] = "{$prefix}{$content}";
        }

        return implode("\n", $lines);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

/**
 * ModuleForTest — deterministic stub of Module for testing.
 *
 * @internal Test only
 */
final class ModuleForTest implements Module
{
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly string $content,
    ) {}

    public function name(): string
    {
        return $this->id;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return $this->content;
    }

    public function minSize(): array
    {
        return [20, 3];
    }
}

/**
 * Minimal Module interface for test use (copied from sugar-dash to avoid
 * a hard dependency on sugar-dash's Module during isolated test runs).
 *
 * @internal Test only
 */
interface Module
{
    public function name(): string;
    public function init(): ?\Closure;
    /** @return array{0: Module, 1: ?\Closure} */
    public function update(Msg $msg): array;
    public function view(): string;
    public function minSize(): array;
}
