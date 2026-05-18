<?php

declare(strict_types=1);

/**
 * dashboard-live.php — The canonical interactive dashboard demo.
 *
 * This is the headline SugarDash example. It wires together:
 *   - \SugarCraft\Core\Program running the full event loop
 *   - TermiosFactory + SignalForwarder for raw mode + SIGWINCH
 *   - Registry with Clock / System / Weather modules
 *   - Boxer address-tree layout + FocusManager for panel rotation
 *   - Per-panel Cmd::tick for refresh cadence
 *   - Keyboard: q / Ctrl-C quit, Tab / arrows focus rotation
 *   - 1Hz Clock + System refresh + 30min Weather (wttr.in, cached)
 *
 * ## Architecture
 *
 * The dashboard is a single `DashboardModel` that composes multiple
 * `Module` instances (which follow the same init/update/view contract
 * as `Core\Model`). FocusManager tracks which panel is focused.
 * Each panel renders into a Boxer layout cell, and per-panel tick
 * Cmds drive refresh.
 *
 * ## Keyboard controls
 *
 *   - `q` or `Ctrl-C`  — quit the program
 *   - `Tab`           — rotate focus to next panel
 *   - `Shift+Tab`      — rotate focus to previous panel
 *   - `Up/Down/Left/Right` — move focus between panels
 *
 * ## Modules
 *
 *   - Clock   — 1Hz ticking clock (HH:MM:SS)
 *   - System  — CPU/Mem/GPU/Uptime stats (2s refresh)
 *   - Weather — live wttr.in data (30min refresh, 30min cache TTL)
 *
 * @see \SugarCraft\Core\Program
 * @see \SugarCraft\Dash\Layout\Boxer\Boxer
 * @see \SugarCraft\Dash\Layout\FocusManager
 * @see \SugarCraft\Dash\Module\Module
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Dash\Layout\Boxer\Boxer;
use SugarCraft\Dash\Layout\Boxer\Node;
use SugarCraft\Dash\Layout\FocusManager;
use SugarCraft\Dash\Modules\Clock\{ClockModule, TickMsg as ClockTickMsg};
use SugarCraft\Dash\Modules\System\{SystemModule, RefreshMsg as SystemRefreshMsg};
use SugarCraft\Dash\Modules\Weather\{WeatherModule as RealWeatherModule, TickMsg as WeatherTickMsg, WttrInClient};

/**
 * Module contract — mirrors Core\Model but scoped to a single dashboard panel.
 *
 * Each module is an independent Elm-style component: init() returns an
 * optional startup Cmd, update() receives Msgs and returns [next, ?Cmd],
 * and view() renders the current state as a string.
 *
 * @see \SugarCraft\Dash\Module\Module (SSOT in sugar-dash)
 */
interface Module
{
    /** Unique panel identifier used by FocusManager. */
    public function name(): string;

    /** Return an initial Cmd to run on startup, or null. */
    public function init(): ?\Closure;

    /**
     * Handle a Msg, returning [nextModule, optionalCmd].
     *
     * @return array{0: Module, 1: ?\Closure}
     */
    public function update(Msg $msg): array;

    /** Render the module to a string. */
    public function view(): string;

    /** Minimum [width, height] in cells needed to render meaningfully. */
    public function minSize(): array;
}

// ─── Dashboard Model ──────────────────────────────────────────────────────────

/**
 * DashboardModel — composes Clock / System / Weather modules in a Boxer layout.
 *
 * This is the root Model that Program drives. It holds the module
 * instances, a FocusManager for keyboard-driven panel selection, and
 * a Boxer for rendering. Each module manages its own state immutably;
 * DashboardModel just routes Msgs and assembles the final view.
 *
 * ## Layout (Boxer address tree)
 *
 *   root (horizontal split)
 *   ├── "0" — Clock panel (left, fixed width)
 *   ├── "1" — System panel (center, flexible)
 *   └── "2" — Weather panel (right, fixed width)
 *
 * ## Focus rotation
 *
 * Tab/Shift+Tab cycle through registered panel IDs. Arrow keys
 * also rotate focus (Up/Down cycle vertically, Left/Right horizontally).
 *
 * ## Refresh cadence
 *
 * Each module returns its own tick Cmd from update(). ClockModule
 * ticks at 1Hz; SystemModule at 2Hz; WeatherModule at 30min intervals.
 * DashboardModel does NOT impose its own tick — it just routes the
 * tick Msgs to the right module instance.
 *
 * @see \SugarCraft\Core\Model
 * @see \SugarCraft\Dash\Layout\Boxer\Boxer
 * @see \SugarCraft\Dash\Layout\FocusManager
 */
final class DashboardModel implements Model
{
    /** Registered module instances keyed by address string. */
    private array $modules = [];

    /** FocusManager tracks which panel has keyboard focus. */
    private FocusManager $focus;

    /** Boxer layout tree + rendered items. */
    private Boxer $boxer;

    public function __construct(string $weatherLocation = 'auto')
    {
        $this->focus = new FocusManager('root');

        // Instantiate the three panels. Clock ticks at 1Hz, System at 2Hz,
        // Weather at 30min. Each module's init() Cmd is collected
        // and scheduled below.
        $this->modules = [
            '0' => new ClockModule(),
            '1' => new SystemModule(),
            '2' => new RealWeatherModule(new WttrInClient(), $weatherLocation),
        ];

        // Register each panel with FocusManager and build the Boxer tree.
        foreach (array_keys($this->modules) as $addr) {
            $this->focus = $this->focus->register($addr);
        }

        // Horizontal layout: Clock | System | Weather
        // Clock: 20 cells fixed width
        // System: flexible (flex 1)
        // Weather: 20 cells fixed width
        $this->boxer = $this->buildBoxer();
    }

    /**
     * Build the Boxer layout tree.
     *
     *   root (horizontal)
     *   ├── 0 → Clock (fixed 20 wide)
     *   ├── 1 → System (flex 1)
     *   └── 2 → Weather (fixed 20 wide)
     */
    private function buildBoxer(): Boxer
    {
        $root = Node::horizontal(
            Node::leaf('0'), // Clock — fixed width handled in view()
            Node::leaf('1'), // System — flexible
            Node::leaf('2'),  // Weather — fixed width
        );

        return Boxer::tree($root);
    }

    /** @inheritDoc */
    public function init(): ?\Closure
    {
        // Collect init Cmds from every module and batch them so all
        // tick timers fire simultaneously on the first frame.
        $cmds = [];
        foreach ($this->modules as $module) {
            $cmd = $module->init();
            if ($cmd !== null) {
                $cmds[] = $cmd;
            }
        }

        if ($cmds === []) {
            return null;
        }

        return Cmd::batch(...$cmds);
    }

    /**
     * Route incoming Msgs to the appropriate module and rebuild the view.
     *
     * ## Message routing
     *
     *   - ClockTickMsg   → ClockModule (address "0")
     *   - SystemRefreshMsg → SystemModule (address "1")
     *   - WeatherTickMsg  → WeatherModule (address "2")
     *   - FocusMsgs (Tab/arrows) → FocusManager
     *   - QuitMsg / InterruptMsg → quit the program
     *
     * Any other Msg is silently routed to all modules (they return
     * [self, null] if they don't handle it).
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        // ── Quit / Interrupt ──────────────────────────────────────────
        if ($msg instanceof QuitMsg || $msg instanceof InterruptMsg) {
            return [$this, Cmd::quit()];
        }

        // ── Focus / Keyboard ─────────────────────────────────────────────
        if ($msg instanceof KeyMsg) {
            $handled = $this->handleKey($msg);
            if ($handled) {
                return [$this, null];
            }
        }

        // ── Window resize — propagate to Boxer and re-render ────────────
        if ($msg instanceof WindowSizeMsg) {
            // Boxer will pick up the new size via setSize() in view().
            // No model state change needed.
            return [$this, null];
        }

        // ── Route tick Msgs to their target modules ───────────────────
        $targetAddr = $this->msgToAddress($msg);
        if ($targetAddr !== null) {
            $module = $this->modules[$targetAddr] ?? null;
            if ($module !== null) {
                [$nextModule, $nextCmd] = $module->update($msg);
                $this->modules[$targetAddr] = $nextModule;
                return [$this, $nextCmd];
            }
        }

        // Fallback: broadcast to all modules. They no-op if unhandled.
        foreach ($this->modules as $addr => $module) {
            [$nextModule,] = $module->update($msg);
            $this->modules[$addr] = $nextModule;
        }

        return [$this, null];
    }

    /**
     * Route keyboard input to FocusManager or quit handling.
     *
     * Returns true when the key was consumed (quit or focus rotated).
     */
    private function handleKey(KeyMsg $msg): bool
    {
        // Quit on q or Ctrl-C
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return false; // Let update() handle QuitMsg
        }

        if ($msg->ctrl && $msg->type === KeyType::Char && $msg->rune === 'c') {
            return false; // Let update() handle InterruptMsg
        }

        // Tab → focus next panel
        if ($msg->type === KeyType::Tab && !$msg->shift) {
            $this->focus = $this->focus->focusNext();
            return true;
        }

        // Shift+Tab → focus previous panel
        if ($msg->type === KeyType::Tab && $msg->shift) {
            $this->focus = $this->focus->focusPrevious();
            return true;
        }

        // Arrow keys — cycle through panels (Up/Down both cycle; Left/Right both cycle)
        // This gives users multiple ways to navigate.
        if (in_array($msg->type, [KeyType::Up, KeyType::Down, KeyType::Left, KeyType::Right], true)) {
            $focused = $this->focus->getFocusedId();
            if ($focused === null) {
                $this->focus = $this->focus->focusNext();
            } else {
                $this->focus = $this->focus->focusNext();
            }
            return true;
        }

        return false;
    }

    /**
     * Map a Msg type to the address of the module that handles it.
     *
     * Returns null if the msg should be broadcast to all modules.
     */
    private function msgToAddress(Msg $msg): ?string
    {
        return match (true) {
            $msg instanceof ClockTickMsg => '0',
            $msg instanceof SystemRefreshMsg => '1',
            $msg instanceof WeatherTickMsg => '2',
            default => null,
        };
    }

    /** @inheritDoc */
    public function view(): string
    {
        // Collect current rendered content for each module, keyed by address.
        $items = [];
        foreach ($this->modules as $addr => $module) {
            $view = $module->view();
            $focused = $this->focus->isFocused($addr);

            // Render the panel with a focus indicator border.
            // When focused, show a bright border. Otherwise a dim border.
            $items[$addr] = new FocusedPanel($addr, $view, $focused, $module->minSize());
        }

        // Rebuild the boxer with fresh content + proper sizing.
        // We recreate the boxer each frame to pick up module state changes.
        $root = Node::horizontal(
            Node::leaf('0'),
            Node::leaf('1'),
            Node::leaf('2'),
        );

        $boxer = Boxer::tree($root, $items);
        // Width can be overridden via COLUMNS env var for responsive testing.
        // Convention: 80 = narrow (collapses to single column), 120 = wide.
        $columns = (int) ($_ENV['COLUMNS'] ?? 120);
        $boxer = $boxer->setSize($columns, 30);

        return $boxer->render();
    }
}

/**
 * FocusedPanel — wraps a module's view with a focus-indicator border.
 *
 * Renders the panel content with ASCII box-drawing characters and a
 * highlighted border when focused. This makes it visually clear which
 * panel has keyboard focus.
 *
 * @implements \SugarCraft\Dash\Foundation\Item
 */
final class FocusedPanel implements \SugarCraft\Dash\Foundation\Item
{
    public function __construct(
        private readonly string $id,
        private readonly string $content,
        private readonly bool $focused,
        private readonly array $minSize,
    ) {}

    public function render(): string
    {
        $borderChar = $this->focused ? '█' : '─';
        $idLabel = $this->focused ? "[{$this->id}]" : $this->id;

        $lines = explode("\n", $this->content);
        $width = $this->minSize()[0] ?? 20;

        $output = '';
        foreach ($lines as $line) {
            // Pad or truncate line to fit the panel width
            $display = mb_strlen($line) > $width
                ? mb_substr($line, 0, $width)
                : $line . str_repeat(' ', $width - mb_strlen($line));

            $prefix = $this->focused ? "▓ " : "  ";
            $suffix = $this->focused ? " ▓" : "  ";
            $output .= $prefix . $display . $suffix . "\n";
        }

        return $output;
    }

    public function getWidth(): int
    {
        return $this->minSize()[0] ?? 20;
    }

    public function getHeight(): int
    {
        $lines = explode("\n", $this->content);
        return max(count($lines), $this->minSize()[1] ?? 3);
    }
}

// ─── Main entrypoint ───────────────────────────────────────────────────────────

/**
 * Run the interactive dashboard.
 *
 * Usage:
 *   php examples/dashboard-live.php
 *
 * Press `q` or `Ctrl-C` to quit. Use `Tab` / arrow keys to rotate focus.
 */
(function (): int {
    // Detect if we're in a real TTY (not a pipe/socket). When not a TTY
    // (e.g. CI environment), bail early so we don't block waiting for input.
    if (function_exists('stream_isatty') && !stream_isatty(STDOUT)) {
        fwrite(STDERR, "dashboard-live.php requires a real terminal (STDOUT is not a TTY)\n");
        fwrite(STDERR, "Hint: run this example interactively in your terminal\n");
        return 1;
    }

    // Weather location: WEATHER_LOCATION env var or auto-detect via IP.
    $weatherLocation = $_ENV['WEATHER_LOCATION'] ?? 'auto';

    // Build the program. useAltScreen=true gives us a clean slate.
    // catchInterrupts=true lets Ctrl-C trigger InterruptMsg.
    // openTty=true opens /dev/tty so the program works even when
    // STDIN is piped from a file.
    $model = new DashboardModel($weatherLocation);
    $options = new ProgramOptions(
        useAltScreen: true,
        catchInterrupts: true,
        hideCursor: true,
        openTty: true,
    );

    $program = new Program($model, $options);

    echo "\n";
    $cols = (int) ($_ENV['COLUMNS'] ?? 120);
    $mode = $cols < 90 ? 'NARROW (single-column)' : 'WIDE (multi-column)';
    echo " ╔══════════════════════════════════════════════════════════╗\n";
    echo " ║  SugarDash Interactive Dashboard                       ║\n";
    echo " ║  COLUMNS={$cols} — {$mode}                             ║\n";
    echo " ║                                                          ║\n";
    echo " ║  [q] or [Ctrl-C]     Quit                               ║\n";
    echo " ║  [Tab] / [Shift-Tab] Rotate focus                       ║\n";
    echo " ║  [Arrow keys]       Navigate panels                     ║\n";
    echo " ║  COLUMNS=80         Force narrow; COLUMNS=120 force wide  ║\n";
    echo " ╚══════════════════════════════════════════════════════════╝\n";
    echo "\n";

    $program->run();

    echo "\n";
    echo "Goodbye from SugarDash!\n";

    return 0;
})();
