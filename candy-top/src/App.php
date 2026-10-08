<?php

declare(strict_types=1);

namespace SugarCraft\Top;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View as CoreView;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\ConfigFile;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\ConfigLoadedMsg;
use SugarCraft\Top\Msg\ConfigSavedMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\OpenOverlayMsg;
use SugarCraft\Top\Msg\PaletteMsg;
use SugarCraft\Top\Msg\QuitRequestMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Msg\UpdateStepMsg;
use SugarCraft\Top\Overlay\Menus;
use SugarCraft\Top\Overlay\Overlay;
use SugarCraft\Top\Overlay\OverlayContext;
use SugarCraft\Top\Overlay\OverlayStack;
use SugarCraft\Top\Panel\ClickCapture;
use SugarCraft\Top\Panel\ClockReserve;
use SugarCraft\Top\Panel\GpuRosterSource;
use SugarCraft\Top\Panel\OptionChoices;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Panel\SampleTap;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\ClockFormat;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\GpuRoster;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\SizeError;
use SugarCraft\Top\View\Surface;

/**
 * candy-top's root Model: frame layout, theme, clock, cadence, and the
 * panel roster.
 *
 * Cadence (btop main loop, src/btop.cpp): a data tick every `update_ms`
 * (default 2000) asks each shown panel to sample; a clock tick lands on
 * every wall-clock second for the border clock. Both are one-shot
 * candy-core ticks re-armed inside update() — never via subscriptions(),
 * whose tick ids lock the first closure installed. A `+`/`-` period change
 * bumps {@see $generation} and arms a fresh tick; the stale one is ignored
 * when it fires. Holding `+`/`-` steps 1000 ms instead of 100 once btop's
 * whole 50-key input history is that key and the previous step landed
 * under 200 ms ago; the press time is read in a Cmd ({@see UpdateStepMsg}).
 *
 * Input precedence (full rule on {@see Panel}): `ctrl+c` always quits.
 * Behind the size notice only `q` and `1`-`4` act. Otherwise an open menu
 * ({@see Overlay} on top of the App's {@see OverlayStack}, btop's
 * `Menu::active`) takes every key and mouse event. Otherwise a visible
 * panel reporting {@see Panel::modal()} (btop's open proc filter) gets
 * every key and only left clicks, ahead of the globals; else every key is
 * offered to the visible panels ({@see Panel::capturesKey()}, layout
 * order) and the first that claims it gets it alone — btop's proc box owns
 * `+`/`-`/`=` while proc_tree is on. Only unclaimed keys reach the global
 * `q`, `1`-`4`, `+`/`-` handling, then the broadcast. A bare left click
 * first hits the App's own cpu-title buttons (`menu`, `-`, `+`), then the
 * panels' mapped buttons ({@see ClickCapture}: the owner gets it alone,
 * btop's mouse_mappings), and only an unmapped click is broadcast.
 *
 * Menus (phase P-F1, btop_menu.cpp): `escape`/`m` open the main menu,
 * `f1`/`?`/`h` (`H` with vim_keys) help, `f2`/`o` options; a panel asks for
 * one through {@see PanelResult::$overlay}, a Cmd through
 * {@see OpenOverlayMsg}. While a menu is open the frame is painted dimmed
 * (btop's uncolor + inactive_fg backdrop) and the menu on top. A refused
 * `1`-`4` toggle or shown_boxes write opens btop's size-error box.
 *
 * Options, presets, persistence (phase P-F2): `f2`/`o` open the options
 * menu ({@see Overlay\OptionsMenu}); `p`/`P` cycle the layout presets
 * (btop_input.cpp:262-284) and the cpu title's `preset` button maps to
 * `p`; Shift / Alt+Shift / Ctrl+Shift + arrows resize the proc box
 * (btop PR #1476); `ctrl+r` reloads config.conf and the theme from disk
 * in a Cmd (btop SIGUSR2) and repaints every cached frame (#1849). Any
 * persisted change marks the config dirty (btop `write_new`); every quit
 * path writes it first when save_config_on_exit is on, and switching
 * save_config_on_exit off writes at once — always inside a Cmd, through
 * the injected {@see ConfigFile} (none in tests: nothing is written).
 * Every quit path also writes the proc box's pending tree state
 * ({@see stateSave()}, #1791c) — an XDG state file, never config.conf.
 *
 * Config channel: panels get a fresh {@see PanelContext} (current Config,
 * Layout and their box) on every input call and write options through
 * {@see PanelResult::$set}, which update() validates and applies through
 * {@see applyConfig()} BEFORE the next Msg — btop's Config::set is
 * immediate, and the Program dispatches a whole read's keys before any Cmd
 * runs. {@see SetOptionMsg} stays for asynchronous producers.
 *
 * Resize: WindowSizeMsg is the only size truth; it re-runs the calcSizes
 * port and the next view repaints everything. A terminal below the
 * shown boxes' minimum shows btop's size notice, where only `q` and the
 * box toggles 1-4 work (btop.cpp:180-198).
 *
 * GPU boxes (btop PR #1730/#1881): every `gpuN` box in shown_boxes is
 * drawn by the ONE panel registered as `gpu` ({@see panelFor()}); the
 * number keys 5, 6, 7, 8, 9, 0 toggle gpu box slots 0-5
 * ({@see GpuPanels::toggle()}) — globals, like `1`-`4`, and also behind
 * the size notice. The detected accelerators ({@see roster()}, from the
 * panels implementing {@see GpuRosterSource}) size the gpu grid and the
 * cpu box's GPU rows; a sample that changes them re-runs the layout.
 *
 * Mirrors aristocratos/btop main loop, Input::process global keys, and
 * Draw::calcSizes / update_clock.
 */
final class App implements Model
{
    /** btop's numeric box toggles (all_boxes, GPU build index 1-4). */
    public const BOX_KEYS = ['1' => 'cpu', '2' => 'mem', '3' => 'net', '4' => 'proc'];

    /**
     * btop PR #1873's ctr box toggle — a framed global like `1`-`4`, but
     * not read behind the size notice (the PR leaves btop's resize loop
     * at `1`-`4`).
     */
    public const CTR_KEY = 'x';

    /** update_ms step per `+`/`-` press. */
    public const UPDATE_STEP_MS = 100;

    /** update_ms step while `+`/`-` is held (btop_input.cpp cpu-box actions). */
    public const UPDATE_HOLD_STEP_MS = 1000;

    /** btop Input::history length: a hold is this many identical keys in a row. */
    public const HOLD_HISTORY = 50;

    /** btop `last_press >= time_ms() - 200`. */
    public const HOLD_WINDOW_SEC = 0.2;

    /** The open menus (btop Menu::menuMask); only the top one is shown and gets input. */
    public readonly OverlayStack $overlays;

    /** @var \Closure(Config): Palette loads the theme a config asks for (file I/O: Cmds only). */
    private readonly \Closure $themes;

    /**
     * @param array<string, Panel> $panels keyed by box name
     */
    private function __construct(
        public readonly Config $config,
        public readonly Ink $ink,
        public readonly HostInfo $host,
        private readonly array $panels,
        private readonly \Closure $clock,
        public readonly int $cols = 0,
        public readonly int $rows = 0,
        public readonly ?Layout $layout = null,
        public readonly ?float $now = null,
        public readonly float $uptime = 0.0,
        public readonly int $generation = 0,
        public readonly ?int $preset = null,
        private readonly string $lastKey = '',
        private readonly int $keyRun = 0,
        private readonly ?float $lastStepAt = null,
        ?OverlayStack $overlays = null,
        ?\Closure $themes = null,
        private readonly ?Surface $backdrop = null,
        private readonly ?ConfigFile $file = null,
        public readonly bool $writeNew = false,
        private readonly ?ThemeRegistry $catalog = null,
    ) {
        $this->overlays = $overlays ?? OverlayStack::new();
        $this->themes = $themes ?? self::systemThemes();
    }

    /**
     * @param array<string, Panel> $panels  from {@see Panel\Panels::standard()}
     * @param ?\Closure(): ClockTickMsg $clock reads wall time + uptime; null = the live system
     * @param ?ColorProfile $profile null = derived from config (truecolor / tty_mode)
     * @param ?\Closure(Config): Palette $themes theme loader for runtime theme changes; null = {@see systemThemes()}
     * @param ?ConfigFile $file     where config changes persist (save_config_on_exit) and `ctrl+r` reloads
     *                              from; null = never written or read (tests, demos)
     * @param bool        $writeNew the loaded file needs a rewrite (btop `write_new`: missing, older
     *                              version, or it held rejected values) — saved on exit even unchanged
     * @param ?ThemeRegistry $catalog the theme list the options menu cycles (btop Theme::themes),
     *                                scanned by the caller; null = builtin Default and TTY only, and
     *                                `ctrl+r` does not rescan
     */
    public static function start(
        Config $config,
        Palette $palette,
        HostInfo $host,
        array $panels,
        ?\Closure $clock = null,
        ?ColorProfile $profile = null,
        ?\Closure $themes = null,
        ?ConfigFile $file = null,
        bool $writeNew = false,
        ?ThemeRegistry $catalog = null,
    ): self {
        $profile ??= self::profileFor($config);

        return new self(
            $config,
            Ink::new($palette, $profile),
            $host,
            $panels,
            $clock ?? self::systemClock(),
            themes: $themes,
            file: $file,
            writeNew: $writeNew,
            catalog: $catalog,
        );
    }

    /**
     * The live theme loader: the theme `$config` names, the TTY theme while
     * tty_mode is on (btop Theme::setTheme), read through
     * {@see ThemeRegistry}. Only ever called inside a Cmd.
     *
     * @return \Closure(Config): Palette
     */
    public static function systemThemes(): \Closure
    {
        return static fn (Config $c): Palette => ThemeRegistry::new()->load($c->colorTheme(), $c->bool('theme_background'), $c->ttyMode());
    }

    /**
     * What decides the palette: tty_mode, color_theme, theme_background.
     * A config change that moves it reloads the theme ({@see applyConfig()}).
     */
    public static function themeKey(Config $config): string
    {
        return ($config->ttyMode() ? 'tty' : 'color') . "\0" . $config->colorTheme() . "\0" . ($config->bool('theme_background') ? '1' : '0');
    }

    /**
     * The colour profile `$config` implies: tty_mode forces 16 colours,
     * else truecolor picks 24-bit over the 256-colour cube (btop
     * Theme::setTheme / Config truecolor).
     */
    public static function profileFor(Config $config): ColorProfile
    {
        return match (true) {
            $config->ttyMode() => ColorProfile::Ansi,
            $config->bool('truecolor') => ColorProfile::TrueColor,
            default => ColorProfile::Ansi256,
        };
    }

    /**
     * The Program options bin/candy-top runs with: alt screen, 20 fps and
     * btop's mouse reporting — `?1002h` button-event tracking + `?1006h`
     * SGR (Term::mouse_on, candy-core CellMotion) unless disable_mouse.
     * Runtime flips of disable_mouse answer with the matching Cmd
     * ({@see applyConfig()}).
     */
    public static function programOptions(Config $config): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: true,
            mouseMode: $config->bool('disable_mouse') ? MouseMode::Off : MouseMode::CellMotion,
            framerate: 20.0,
        );
    }

    /**
     * The bytes that put a terminal back after a crash that skipped the
     * Program's own teardown (an exception out of update()/view()/a Cmd
     * escapes Program::run() with no finally): end any synchronized
     * frame, reset SGR, every mouse mode and bracketed paste off, cursor
     * shown, alt screen left — what {@see programOptions()} turned on and
     * a little more, all harmless when already off. Raw mode itself is
     * put back by the Tty's destructor.
     */
    public static function terminalReset(): string
    {
        return Ansi::syncEnd() . Ansi::reset()
            . Ansi::mouseAllMotionOff() . Ansi::mouseCellMotionOff() . Ansi::mouseAllOff()
            . Ansi::bracketedPasteOff() . Ansi::cursorShow() . Ansi::altScreenLeave();
    }

    /** The live clock Cmd: wall time plus /proc/uptime (0 when unreadable). */
    public static function systemClock(): \Closure
    {
        return static function (): ClockTickMsg {
            $raw = @file_get_contents('/proc/uptime');
            $uptime = $raw === false ? 0.0 : (float) strtok($raw, ' ');

            return new ClockTickMsg(microtime(true), $uptime);
        };
    }

    public function init(): ?\Closure
    {
        return Cmd::batch($this->clock, $this->collectAll(), $this->dataTick());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $next = $this->mutate(cols: max(0, $msg->cols), rows: max(0, $msg->rows))->relayout();
            [$next, $cmd] = $next->broadcast($msg);

            // btop repaints everything once on a resize, menu or not.
            return [$next->settledBackdrop(true), $cmd];
        }
        if ($msg instanceof ClockTickMsg) {
            $delay = max(0.01, 1.0 - fmod($msg->time, 1.0));

            return [$this->mutate(now: $msg->time, uptime: $msg->uptime), Cmd::tick($delay, $this->clock)];
        }
        if ($msg instanceof DataTickMsg) {
            if ($msg->generation !== $this->generation) {
                return [$this, null];
            }

            return [$this, Cmd::batch($this->dataTick(), $this->collectAll())];
        }
        if ($msg instanceof SampledMsg) {
            $panel = $this->panelFor($msg->box);
            if ($panel === null) {
                return [$this, null];
            }
            $roster = $this->roster();
            [$next, $cmd] = $this->deliver($panel, $msg);
            // Panels tapping this box's samples ({@see SampleTap}, the ctr box
            // reading the proc scan), visible ones only.
            foreach ($next->visiblePanels() as $tap) {
                if ($tap instanceof SampleTap && $tap->box() !== $panel->box() && $tap->taps() === $msg->box) {
                    [$next, $tapCmd] = $next->deliver($tap, $msg);
                    $cmd = self::batch($cmd, $tapCmd);
                }
            }
            if ($panel instanceof GpuRosterSource && !$next->roster()->equals($roster)) {
                // A new accelerator, or a column that started / stopped
                // measuring, moves btop's gpu_b_height_offsets: re-layout.
                $next = $next->relayout()->settledBackdrop(true);
            }

            return [$next, $cmd];
        }
        if ($msg instanceof UpdateStepMsg) {
            return $this->stepUpdateMs($msg);
        }
        if ($msg instanceof SetOptionMsg) {
            return $this->applyOptions([$msg->key => $msg->value]);
        }
        if ($msg instanceof OpenOverlayMsg) {
            return [$this->withOverlay($msg->overlay), null];
        }
        if ($msg instanceof PaletteMsg) {
            // A load that raced a newer theme change is dropped.
            return [$msg->key === self::themeKey($this->config) ? $this->withPalette($msg->palette) : $this, null];
        }
        if ($msg instanceof QuitRequestMsg) {
            return [$this, $this->quitCmd()];
        }
        if ($msg instanceof ConfigSavedMsg) {
            if ($msg->ok) {
                // Only what was written is clean: a change that landed while
                // the write was in flight still needs saving.
                $clean = $msg->saved !== null && !$this->config->persistedDiffers($msg->saved);

                return [$clean ? $this->mutate(writeNew: false) : $this, null];
            }

            return [$this->framed() ? $this->withOverlay(Menus::warning($msg->error)) : $this, null];
        }
        if ($msg instanceof ConfigLoadedMsg) {
            return $this->reloaded($msg);
        }
        if ($msg instanceof KeyMsg) {
            if (KeyName::dropped($msg)) {
                return [$this, null];
            }
            $name = $msg->string();
            $self = $this->mutate(lastKey: $name, keyRun: $name === $this->lastKey ? $this->keyRun + 1 : 1);
            if ($name === 'ctrl+c') {
                // btop's SIGINT handler runs clean_quit, which saves too.
                return [$self, $self->quitCmd()];
            }
            if (!$self->framed()) {
                return $self->sizeNoticeKey($msg);
            }
            // btop: while Menu::active every key goes to Menu::process.
            if ($self->overlays->top()?->capturesInput() === true) {
                return $self->deliverOverlay($msg);
            }
            // A modal panel (a filter prompt) takes `q` and digits as text; a
            // mere claim never may, or a panel could disable quit / toggles.
            $owner = $self->modalPanel() ?? ($self->isGlobalKey($msg) ? null : $self->captor($msg));
            if ($owner !== null) {
                return $self->deliver($owner, $msg);
            }

            return $self->handleKey($msg) ?? $self->broadcast($msg);
        }

        if ($msg instanceof MouseMsg) {
            // Behind the size notice btop reads only `q` and `1`-`4`; with
            // disable_mouse btop turns mouse reporting off altogether.
            if (!$this->framed() || $this->config->bool('disable_mouse')) {
                return [$this, null];
            }
            if ($this->overlays->top()?->capturesInput() === true) {
                // btop swaps to Menu::mouse_mappings; the menu resolves its own buttons.
                $name = 'mouse:' . $msg->action->name . ':' . $msg->button->name;

                return $this->mutate(lastKey: $name, keyRun: $name === $this->lastKey ? $this->keyRun + 1 : 1)->deliverOverlay($msg);
            }
            $modal = $this->modalPanel();
            // btop_input.cpp:158-161: while filtering every mouse event but a
            // click becomes "" — never reaching history or any handler.
            if ($modal !== null && !self::isClick($msg)) {
                return [$this, null];
            }
            if ($modal === null && (self::isClick($msg) || self::isDrag($msg)) && ($key = $this->chromeButton($msg)) !== null) {
                // btop maps a click — or a drag (btop_input.cpp:174) — to the
                // button's key, pushes THAT into Input::history and processes
                // it as the key (`m` opens the main menu, `p` cycles presets,
                // `+`/`-` step — or expand under a proc tree claim).
                return $this->update(new KeyMsg(KeyType::Char, $key));
            }
            // btop pushes every mouse event ("mouse_click", "mouse_scroll_up",
            // ...) into Input::history too (btop_input.cpp:193-195), so a
            // mouse event breaks a held `+`/`-` run.
            $name = 'mouse:' . $msg->action->name . ':' . $msg->button->name;
            $self = $this->mutate(lastKey: $name, keyRun: $name === $this->lastKey ? $this->keyRun + 1 : 1);
            if ($modal !== null) {
                return $self->deliver($modal, $msg);
            }
            $owner = self::isClick($msg) ? $self->clickOwner($msg) : null;

            return $owner !== null ? $self->deliver($owner, $msg) : $self->broadcast($msg);
        }

        return $this->broadcast($msg);
    }

    public function view(): string|CoreView
    {
        return $this->surface()?->render() ?? '';
    }

    /**
     * The painted frame, null before the first WindowSizeMsg. Exactly
     * {@see $rows} rows of exactly {@see $cols} cells.
     */
    public function surface(): ?Surface
    {
        if ($this->cols <= 0 || $this->rows <= 0) {
            return null;
        }
        $boxes = $this->config->shownBoxes();
        if ($this->layout === null || !$this->framed()) {
            [$w, $h] = FrameBuilder::minSize($boxes, $this->cols, $this->roster(), $this->config->gpuBoxColumns());

            return SizeError::surface($this->cols, $this->rows, $w, $h);
        }

        $top = $this->overlays->top();
        if ($top !== null && $this->backdrop !== null) {
            // background_update off (always in tty mode): the frame under the
            // menu stays as it was when the menu opened (btop pause_output).
            $surface = clone $this->backdrop;
            $top->paint($surface, $this->overlayContext());

            return $surface;
        }
        $surface = $this->frame();
        if ($top !== null) {
            // btop Runner: `Fx::ub + inactive_fg + Fx::uncolor(output)` under the menu.
            $surface->dim($this->ink->fg('inactive_fg'));
            $top->paint($surface, $this->overlayContext());
        }

        return $surface;
    }

    /** The boxes, panels and clock without any menu. Caller checks framed(). */
    private function frame(): Surface
    {
        $surface = Surface::new($this->cols, $this->rows, $this->ink->base());
        FrameBuilder::paintChrome($surface, $this->layout, $this->ink, $this->config, $this->host, $this->preset, FrameBuilder::clockWidth($this->layout, $this->clockText(), $this->clockReserved()));
        $border = FrameBuilder::border($this->config);
        foreach ($this->layout->ordered() as $box => $rect) {
            $panel = $this->panelFor($box);
            $panel?->paint(
                $surface->region($rect),
                new PanelFrame($this->layout, $rect, $this->ink, $border, $this->config, $this->host, $box),
            );
        }
        FrameBuilder::paintClock($surface, $this->layout, $this->ink, $this->config, $this->clockText(), $this->clockReserved());
        BorderFlow::paint($surface, $this->layout, $this->ink, $this->config);

        return $surface;
    }

    /**
     * btop's background_update: off — and always in tty mode — the frame
     * behind an open menu is frozen (btop.cpp:664 pause_output, :759).
     */
    public function freezesBackdrop(): bool
    {
        return !$this->config->bool('background_update') || $this->config->ttyMode();
    }

    /**
     * Keep the frozen backdrop in step with the stack: captured (dimmed)
     * when a menu opens while {@see freezesBackdrop()}, re-captured on
     * `$recapture` (a resize repaints once), dropped when the last menu
     * closes.
     */
    private function settledBackdrop(bool $recapture): self
    {
        if ($this->overlays->isEmpty() || !$this->freezesBackdrop() || $this->layout === null || !$this->framed()) {
            return $this->backdrop === null ? $this : $this->mutate(backdrop: null, backdropSet: true);
        }
        if ($this->backdrop !== null && !$recapture) {
            return $this;
        }
        $frame = $this->frame();
        $frame->dim($this->ink->fg('inactive_fg'));

        return $this->mutate(backdrop: $frame, backdropSet: true);
    }

    /** The menu on top of the stack, or null when none is open. */
    public function overlay(): ?Overlay
    {
        return $this->overlays->top();
    }

    /**
     * Copy with `$overlay` opened on top — btop Menu::show. A terminal
     * smaller than the overlay's minimum gets the size-error box instead
     * ({@see OverlayStack::push()}).
     */
    public function withOverlay(Overlay $overlay): self
    {
        return $this->mutate(overlays: $this->overlays->push($overlay, $this->cols, $this->rows))->settledBackdrop(false);
    }

    /** The context an overlay is updated and painted with, built now. */
    public function overlayContext(): OverlayContext
    {
        $choices = [];
        $gpus = 0;
        foreach ($this->panels as $panel) {
            if ($panel instanceof OptionChoices) {
                $choices = [...$choices, ...$panel->optionChoices()];
                $gpus += $panel->detectedGpus();
            }
        }

        return new OverlayContext($this->config, $this->cols, $this->rows, $this->ink, $choices, $gpus > 0, $this->catalog);
    }

    /**
     * The App-owned cpu title buttons as btop maps them (0-based
     * [x, y, w, h]): `m` (menu), `p` (preset — btop `{button_y, x + 17, 1,
     * 8}`, sized here to the translated label), `-` and `+` (update_ms).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public function chromeButtons(): array
    {
        $cpu = $this->framed() ? $this->layout?->box('cpu') : null;
        if ($cpu === null || $this->layout?->cpuCores === null) {
            return [];
        }
        $y = $this->layout->cpuBottom ? $cpu->bottom() - 1 : $cpu->y;
        $len = strlen($this->config->updateMs() . 'ms');

        return [
            'm' => [$cpu->x + 11, $y, Width::string(Lang::t('button.menu')), 1],
            'p' => [$cpu->x + 17, $y, Width::string(Lang::t('button.preset')) + 2, 1],
            // btop PR #1873 `{button_y, x + 27, 1, 5}`, only where it is drawn (or over the engine label).
            ...(($ctr = FrameBuilder::ctrZone($cpu, $y, $this->host->containerEngine, FrameBuilder::clockWidth($this->layout, $this->clockText(), $this->clockReserved()))) !== null ? ['x' => $ctr] : []),
            '-' => [$cpu->x + $cpu->width - $len - 7, $y, 2, 1],
            '+' => [$cpu->x + $cpu->width - 5, $y, 2, 1],
        ];
    }

    /**
     * True when a visible panel asks for btop's clock reserve
     * ({@see ClockReserve}; the cpu panel's battery badge).
     */
    public function clockReserved(): bool
    {
        if ($this->layout === null) {
            return false;
        }
        foreach ($this->visiblePanels() as $box => $panel) {
            if ($panel instanceof ClockReserve && $panel->reservesClock($this->context($box))) {
                return true;
            }
        }

        return false;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /** The clock string as embedded (before the width budget clip). */
    public function clockText(): string
    {
        if ($this->now === null) {
            return '';
        }

        return ClockFormat::format($this->config->clockFormat(), (int) floor($this->now), $this->host->user, $this->host->host, $this->uptime);
    }

    public function panel(string $box): ?Panel
    {
        return $this->panels[$box] ?? null;
    }

    /** @return array<string, Panel> */
    public function panels(): array
    {
        return $this->panels;
    }

    /**
     * The panel drawing box `$box`: its own roster entry, or — for any
     * `gpuN` box — the panel registered as `gpu` (one panel draws every
     * gpu box, btop's Gpu::draw per shown panel).
     */
    public function panelFor(string $box): ?Panel
    {
        return $this->panels[$box] ?? (GpuPanels::index($box) !== null ? $this->panels['gpu'] ?? null : null);
    }

    /**
     * The detected accelerators — btop Gpu::count / gpu_b_height_offsets /
     * gpu_names — as the largest roster any {@see GpuRosterSource} panel
     * reports (none before a GPU sample).
     */
    public function roster(): GpuRoster
    {
        $best = GpuRoster::none();
        foreach ($this->panels as $panel) {
            if ($panel instanceof GpuRosterSource) {
                $roster = $panel->gpuRoster();
                if ($roster->count() > $best->count()) {
                    $best = $roster;
                }
            }
        }

        return $best;
    }

    /** Copy with a replaced theme (options-menu theme cycling, P-F/P-G). */
    public function withPalette(Palette $palette): self
    {
        // #1849: a new palette repaints everything, the frozen backdrop too.
        return $this->mutate(ink: Ink::new($palette, $this->ink->profile()))->settledBackdrop(true);
    }

    /**
     * Swap in `$config` (options menu, presets — P-F; {@see SetOptionMsg}
     * from a panel). Layout follows it, and so does the colour profile when
     * truecolor / tty_mode change what {@see profileFor()} derives (an
     * explicit start() profile survives any change that does not). When
     * tty_mode, color_theme or theme_background change ({@see themeKey()})
     * the returned Cmd also loads the theme the new config asks for — the
     * TTY theme on a tty_mode flip (btop Theme::setTheme) — off the update
     * path and installs it through a {@see PaletteMsg}. A
     * changed update_ms bumps {@see $generation} AND returns the re-armed
     * data tick, and boxes the new config shows that the old one hid are
     * sampled at once — so the returned Cmd MUST be dispatched: dropping it
     * would leave only the outdated tick armed and stop every data update.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function applyConfig(Config $config, bool $dirty = true): array
    {
        $periodChanged = $config->updateMs() !== $this->config->updateMs();
        $before = $this->config->shownBoxes();
        $profile = self::profileFor($config);
        $ink = $profile !== self::profileFor($this->config) ? Ink::new($this->ink->palette(), $profile) : null;
        $next = $this->mutate(
            config: $config,
            ink: $ink,
            generation: $this->generation + ($periodChanged ? 1 : 0),
            writeNew: $this->writeNew || ($dirty && $config->persistedDiffers($this->config)),
        )->relayout()->settledBackdrop(true);
        $cmds = [$periodChanged ? $next->dataTick() : null];
        if (self::themeKey($config) !== self::themeKey($this->config)) {
            $cmds[] = $next->themeCmd();
        }
        // btop optionsMenu: turning save_config_on_exit off writes at once
        // (write_new forced), so a manual save is "toggle it off and on".
        // Only a user change does — a ctrl+r reload of a hand-edited file
        // that says `false` must not rewrite that file.
        if ($dirty && $this->config->bool('save_config_on_exit') && !$config->bool('save_config_on_exit')) {
            $cmds[] = $next->saveCmd();
        }
        // btop Term::mouse_on / mouse_off when disable_mouse flips.
        if ($config->bool('disable_mouse') !== $this->config->bool('disable_mouse')) {
            $cmds[] = $config->bool('disable_mouse') ? Cmd::disableMouse() : Cmd::enableMouseCellMotion();
        }
        $sampled = [];
        foreach (array_diff($config->shownBoxes(), $before) as $box) {
            $panel = $next->panelFor($box);
            if ($panel !== null && !isset($sampled[$panel->box()]) && !$next->sampledBefore($panel, $before)) {
                $sampled[$panel->box()] = true;
                $next = $next->opened($panel);
                $panel = $next->panelFor($box) ?? $panel;
                $cmds[] = $panel->collect($next->context($box));
                $cmds[] = $next->tapSourceCollect($panel, $sampled);
            }
        }
        $cmds = array_values(array_filter($cmds));

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    /** The Cmd that loads the theme the current config asks for. */
    public function themeCmd(): \Closure
    {
        $themes = $this->themes;
        $config = $this->config;
        $key = self::themeKey($config);

        return static fn (): Msg => new PaletteMsg($themes($config), $key);
    }

    /**
     * A {@see SampleTap} panel that was just shown gets its first data from
     * the box it taps: sample that box now (btop's Runner::run("all") after
     * a toggle re-collects proc together with ctr) unless it is hidden or
     * already being sampled in `$sampled` (panel box => true, updated).
     *
     * @param array<string, bool> $sampled
     */
    private function opened(Panel $panel): self
    {
        return $panel instanceof SampleTap ? $this->withPanel($panel->opened()) : $this;
    }

    private function tapSourceCollect(Panel $panel, array &$sampled): ?\Closure
    {
        if (!$panel instanceof SampleTap) {
            return null;
        }
        $box = $panel->taps();
        $source = $this->panelFor($box);
        if ($source === null || isset($sampled[$source->box()]) || !\in_array($box, $this->config->shownBoxes(), true)) {
            return null;
        }
        $sampled[$source->box()] = true;

        return $source->collect($this->context($box));
    }

    /** The data-tick Cmd for the current period and generation. */
    public function dataTick(): \Closure
    {
        $generation = $this->generation;

        return Cmd::tick($this->config->updateMs() / 1000, static fn (): Msg => new DataTickMsg($generation));
    }

    /**
     * The input context for `$box` as of NOW: current config, current
     * layout, and the box rectangle (null while hidden or behind the size
     * notice). Built per call, so it is never stale after a toggle, option
     * write or resize.
     */
    public function context(string $box): PanelContext
    {
        return new PanelContext($this->config, $this->layout, $this->framed() ? $this->layout?->box($box) : null);
    }

    /**
     * Validate and apply option writes in order (btop Config::set: a value
     * its validator rejects is never stored; the rest still apply), then
     * route the result through {@see applyConfig()}. A shown_boxes value
     * that would not fit the terminal is refused like a `1`-`4` toggle
     * (btop's options menu runs the same Term::get_min_size check) and
     * opens the size-error box.
     *
     * @param array<string, bool|int|string> $set
     * @return array{0: self, 1: ?\Closure}
     */
    public function applyOptions(array $set): array
    {
        $config = $this->config;
        $refused = false;
        foreach ($set as $key => $value) {
            try {
                $next = $config->with((string) $key, $value);
            } catch (InvalidOptionValue) {
                continue;
            }
            if ($key === 'shown_boxes' && !$this->gpusExist($next->shownBoxes())) {
                // btop set_boxes: a gpuN beyond Gpu::count is invalid.
                continue;
            }
            if ($key === 'shown_boxes' && $this->cols > 0 && !$this->fitsBoxes($next->shownBoxes(), $next)) {
                $refused = true;
                continue;
            }
            $config = $next;
        }
        // Behind the size notice a refused write opens nothing, like a refused toggle.
        $app = $refused && $this->framed() ? $this->withOverlay(Menus::sizeError()) : $this;
        if (self::dropsPreset($this->config, $config)) {
            $app = $app->mutate(preset: null, presetSet: true);
        }

        return $config === $this->config ? [$app, null] : $app->applyConfig($config);
    }

    /** True when the shown boxes are laid out and painted (no size notice). */
    private function framed(): bool
    {
        return $this->layout !== null && $this->fitsBoxes($this->config->shownBoxes());
    }

    /**
     * Whether `$boxes` fit the terminal — btop Term::get_min_size with the
     * detected accelerators and `$config`'s gpu_box_columns (default: the
     * current config).
     *
     * @param list<string> $boxes
     */
    private function fitsBoxes(array $boxes, ?Config $config = null): bool
    {
        return FrameBuilder::fits($this->cols, $this->rows, $boxes, $this->roster(), ($config ?? $this->config)->gpuBoxColumns());
    }

    /**
     * btop PR #1730 valid_box_name(check_gpu_count): every gpuN names a
     * detected accelerator.
     *
     * @param list<string> $boxes
     */
    private function gpusExist(array $boxes): bool
    {
        $count = $this->roster()->count();
        foreach (GpuPanels::targets($boxes) as $gpu) {
            if ($gpu >= $count) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shown panels in layout order, each once, keyed by the box its
     * context is built for — the first gpu box stands for the gpu panel.
     *
     * @return array<string, Panel>
     */
    private function visiblePanels(): array
    {
        $out = [];
        $seen = [];
        foreach (array_keys($this->layout?->ordered() ?? []) as $box) {
            $panel = $this->panelFor($box);
            if ($panel !== null && !isset($seen[$panel->box()])) {
                $seen[$panel->box()] = true;
                $out[$box] = $panel;
            }
        }

        return $out;
    }

    /**
     * Whether `$panel` was already sampled under the old box list — the
     * gpu panel draws several boxes, so opening a second gpu box must not
     * re-sample it.
     *
     * @param list<string> $before
     */
    private function sampledBefore(Panel $panel, array $before): bool
    {
        foreach ($before as $box) {
            if ($this->panelFor($box) === $panel) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hand `$msg` to `$panel` with a fresh context, store the panel, and
     * apply its config writes before anything else runs.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function deliver(Panel $panel, Msg $msg): array
    {
        $result = $panel->update($msg, $this->context($panel->box()));
        [$next, $applied] = $this->withPanel($result->panel)->applyOptions($result->set);
        if ($result->overlay !== null) {
            $next = $next->withOverlay($result->overlay);
        }

        return [$next, self::batch($result->cmd, $applied)];
    }

    /**
     * Hand `$msg` to the top overlay (btop Menu::process): store its next
     * state (null closes it), open what it switched to, apply its writes.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function deliverOverlay(Msg $msg): array
    {
        $top = $this->overlays->top();
        if ($top === null) {
            return [$this, null];
        }
        $result = $top->update($msg, $this->overlayContext());
        // btop Menu::process: a Closed or Switch return clears pause_output,
        // so the frame behind the next menu is a fresh one, not the old freeze.
        $switched = $result->overlay === null || $result->push !== null;
        $next = $this->mutate(overlays: $this->overlays->replaceTop($result->overlay, $this->cols, $this->rows))->settledBackdrop($switched);
        if ($result->push !== null) {
            $next = $next->withOverlay($result->push);
        }
        [$next, $applied] = $next->applyOptions($result->set);

        return [$next, self::batch($result->cmd, $applied)];
    }

    /** The first visible panel (layout order) whose mapped button `$msg` hits, or null. */
    private function clickOwner(MouseMsg $msg): ?Panel
    {
        foreach ($this->visiblePanels() as $box => $panel) {
            if ($panel instanceof ClickCapture && $panel->capturesClick($msg, $this->context($box))) {
                return $panel;
            }
        }

        return null;
    }

    /** The App-owned title button under `$msg` ({@see chromeButtons()}), or null. */
    private function chromeButton(MouseMsg $msg): ?string
    {
        $key = KeyName::mapped($msg, $this->chromeButtons());

        return in_array($key, ['m', 'p', 'x', '-', '+'], true) ? $key : null;
    }

    /** The first visible panel (layout order) that owns all input, or null. Caller checks framed(). */
    private function modalPanel(): ?Panel
    {
        foreach ($this->visiblePanels() as $box => $panel) {
            if ($panel->modal($this->context($box))) {
                return $panel;
            }
        }

        return null;
    }

    /** The first visible panel (layout order) that claims `$key`, or null. Caller checks framed(). */
    private function captor(KeyMsg $key): ?Panel
    {
        foreach ($this->visiblePanels() as $box => $panel) {
            if ($panel->capturesKey($key, $this->context($box))) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * btop's global keys, checked before any box (btop_input.cpp:218-238):
     * `q`, the menu keys and the box toggles `1`-`4` — never offered to
     * {@see Panel::capturesKey()}.
     */
    private function isGlobalKey(KeyMsg $key): bool
    {
        $name = KeyName::key($key);

        return in_array($key->string(), ['q', 'ctrl+r'], true)
            || in_array($name, $this->menuKeys(), true)
            || in_array($name, ['p', 'P', ...KeyName::MODIFIED_ARROWS], true)
            || ($key->type === KeyType::Char && !$key->ctrl && !$key->alt
                && (isset(self::BOX_KEYS[$key->rune]) || $key->rune === self::CTR_KEY || GpuPanels::slotFromKey($key->rune) !== null));
    }

    /**
     * The keys that open a menu: escape/m main, f1/?/h help (`H` with
     * vim_keys, where `h` is left), f2/o options.
     *
     * @return list<string>
     */
    private function menuKeys(): array
    {
        return ['escape', 'm', 'f1', '?', $this->config->bool('vim_keys') ? 'H' : 'h', 'f2', 'o'];
    }

    /** btop "mouse_drag": a bare left-button motion (`[<32;...M`). */
    private static function isDrag(MouseMsg $msg): bool
    {
        return $msg->action === MouseAction::Motion
            && $msg->button === MouseButton::Left && !$msg->shift && !$msg->alt && !$msg->ctrl;
    }

    /** btop "mouse_click": a bare left-button press (`[<0;...M`). */
    private static function isClick(MouseMsg $msg): bool
    {
        // candy-core emits MouseClickMsg for EVERY press (right, middle, with
        // modifiers), so the class alone is not btop's click.
        return $msg->action === MouseAction::Press
            && $msg->button === MouseButton::Left && !$msg->shift && !$msg->alt && !$msg->ctrl;
    }

    /**
     * btop.cpp:180-198: the size-notice loop reads only `q` and the box
     * toggles `1`-`4`; every other key is dropped, never broadcast.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function sizeNoticeKey(KeyMsg $key): array
    {
        if ($key->string() === 'q') {
            return [$this, $this->quitCmd()];
        }
        if ($key->type === KeyType::Char && !$key->ctrl && !$key->alt && isset(self::BOX_KEYS[$key->rune])) {
            return $this->toggleBox(self::BOX_KEYS[$key->rune], false);
        }
        // btop PR #1730 term_resize: the gpu slot keys toggle here too.
        $slot = $key->type === KeyType::Char && !$key->ctrl && !$key->alt ? GpuPanels::slotFromKey($key->rune) : null;
        if ($slot !== null) {
            return $this->toggleGpuBox($slot, false);
        }

        return [$this, null];
    }

    /** @return array{0: self, 1: ?\Closure}|null */
    private function handleKey(KeyMsg $key): ?array
    {
        if ($key->string() === 'q') {
            return [$this, $this->quitCmd()];
        }
        if ($key->string() === 'ctrl+r') {
            return [$this, $this->reloadCmd()];
        }
        $name = KeyName::key($key);
        if ($name === 'p' || $name === 'P') {
            return $this->cyclePreset($name === 'p');
        }
        if (in_array($name, KeyName::MODIFIED_ARROWS, true)) {
            return $this->resizeProc($name);
        }
        $help = $this->config->bool('vim_keys') ? 'H' : 'h';
        $menu = match (true) {
            in_array($name, ['escape', 'm'], true) => Menus::main(),
            in_array($name, ['f1', '?', $help], true) => Menus::help(),
            in_array($name, ['f2', 'o'], true) => Menus::options(),
            default => null,
        };
        if ($menu !== null) {
            return [$this->withOverlay($menu), null];
        }
        if ($key->type !== KeyType::Char || $key->ctrl || $key->alt) {
            return null;
        }
        if (isset(self::BOX_KEYS[$key->rune])) {
            return $this->toggleBox(self::BOX_KEYS[$key->rune], true);
        }
        if ($key->rune === self::CTR_KEY) {
            return $this->toggleBox('ctr', true);
        }
        $slot = GpuPanels::slotFromKey($key->rune);
        if ($slot !== null) {
            return $this->toggleGpuBox($slot, true);
        }
        if (!in_array($key->rune, ['+', '=', '-'], true) || !in_array('cpu', $this->config->shownBoxes(), true)) {
            return null;
        }
        $direction = $key->rune === '-' ? -1 : 1;
        // btop: the hold test is "all 50 history entries are `+`" (or `-`),
        // so `=` never accelerates even though it steps like `+`.
        $held = $key->rune !== '=' && $this->keyRun >= self::HOLD_HISTORY;
        $clock = $this->clock;

        return [$this, static fn (): Msg => new UpdateStepMsg($direction, $held, $clock()->time)];
    }

    /**
     * Apply one `+`/`-` step (btop_input.cpp cpu-box actions): `+` only
     * while update_ms <= 86399900, `-` only while >= 200; the 1000 ms hold
     * step needs <= 86399000 / >= 2000 and a previous step < 200 ms ago.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function stepUpdateMs(UpdateStepMsg $step): array
    {
        $ms = $this->config->updateMs();
        $up = $step->direction > 0;
        if ($up ? $ms > 86_399_900 : $ms < 200) {
            return [$this, null];
        }
        $recent = $this->lastStepAt !== null && $this->lastStepAt >= $step->at - self::HOLD_WINDOW_SEC;
        $fast = $step->held && $recent && ($up ? $ms <= 86_399_000 : $ms >= 2000);
        $delta = ($fast ? self::UPDATE_HOLD_STEP_MS : self::UPDATE_STEP_MS) * ($up ? 1 : -1);

        return $this->mutate(lastStepAt: $step->at)->applyConfig($this->config->withUpdateMs($ms + $delta));
    }

    /**
     * btop Config::toggle_box: append or remove `$box`; refused when the
     * result is empty (shown_boxes must name a box) or would not fit the
     * terminal — the latter opens btop's size-error box when `$notify`
     * (a framed toggle; behind the size notice btop's resize loop just
     * ignores it). Any toggle drops the active preset. A box toggled ON is
     * sampled at once (btop Runner::run("all", false, true) after the
     * toggle) instead of sitting empty until the next data tick.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function toggleBox(string $box, bool $notify): array
    {
        $boxes = $this->config->shownBoxes();
        $pos = array_search($box, $boxes, true);
        if ($pos === false) {
            $boxes[] = $box;
        } else {
            array_splice($boxes, $pos, 1);
        }
        if ($boxes === []) {
            return [$this, null];
        }
        if ($this->cols > 0 && !$this->fitsBoxes($boxes)) {
            return [$notify ? $this->withOverlay(Menus::sizeError()) : $this, null];
        }
        try {
            // btop PR #1730 toggle_box keeps current_gpu_panel_slots: write
            // the slots with the boxes, or the shown_boxes write resets them.
            $config = GpuPanels::withBoxes($this->config, $boxes, GpuPanels::slots($this->config));
        } catch (InvalidOptionValue) {
            return [$this, null];
        }
        // btop toggle_box goes through Config::set: the change is saved on exit.
        $next = $this->mutate(config: $config, preset: null, presetSet: true, writeNew: true)->relayout();

        $panel = $next->panels[$box] ?? null;
        if ($pos !== false || $panel === null) {
            return [$next, null];
        }
        $seen = [$panel->box() => true];
        $next = $next->opened($panel);
        $panel = $next->panels[$box] ?? $panel;

        return [$next, self::batch($panel->collect($next->context($box)), $next->tapSourceCollect($panel, $seen))];
    }

    /**
     * btop PR #1730's 5-0 keys (btop_input.cpp): toggle gpu box slot
     * `$slot` ({@see GpuPanels::toggle()}). A free slot with no GPU of
     * that index does nothing; any other refusal — six boxes shown, every
     * GPU already boxed, the last box, or a terminal too small — opens
     * btop's size-error box when `$notify` (btop shows it for every
     * failed toggle_gpu_box). Drops the active preset; opening a box
     * samples the gpu panel at once unless it already draws another box.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function toggleGpuBox(int $slot, bool $notify): array
    {
        $count = $this->roster()->count();
        $active = \in_array($slot, GpuPanels::slots($this->config), true);
        if (!$active && $slot >= $count) {
            return [$this, null];
        }
        try {
            $config = GpuPanels::toggle($this->config, $slot, $count);
        } catch (InvalidOptionValue) {
            $config = null;
        }
        if ($config === null || ($this->cols > 0 && !$this->fitsBoxes($config->shownBoxes()))) {
            return [$notify ? $this->withOverlay(Menus::sizeError()) : $this, null];
        }
        $hadGpu = GpuPanels::targets($this->config->shownBoxes()) !== [];
        $next = $this->mutate(config: $config, preset: null, presetSet: true, writeNew: true)->relayout();
        $gpu = $next->panels['gpu'] ?? null;
        $opened = !$active && !$hadGpu && $gpu !== null;

        return [$next, $opened ? $gpu->collect($next->context('gpu')) : null];
    }

    /**
     * btop's `p` / `P` (btop_input.cpp:262-284): cycle Config::preset_list
     * under disable_presets; a preset whose boxes would not fit opens the
     * size-error box and keeps the old preset.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function cyclePreset(bool $forward): array
    {
        try {
            $presets = $this->config->presets();
        } catch (InvalidOptionValue) {
            return [$this, null];
        }
        $index = $presets->cycle($this->preset, $forward, $this->config->string('disable_presets'));
        if ($index === null) {
            return [$this, null];
        }
        $preset = $presets->at($index);
        // btop: apply_preset fails on a gpuN beyond Gpu::count (set_boxes)
        // or a terminal too small — the size-error box opens and the old
        // preset stays current, so `p` does not move past it.
        if (!$this->gpusExist($preset->boxNames()) || ($this->cols > 0 && !$this->fitsBoxes($preset->boxNames()))) {
            return [$this->withOverlay(Menus::sizeError()), null];
        }
        try {
            $config = $this->config->withPreset($preset);
        } catch (InvalidOptionValue) {
            return [$this, null];
        }

        return $this->mutate(preset: $index, presetSet: true)->applyConfig($config);
    }

    /**
     * btop PR #1476's proc box width keys: Shift + Left/Right step 1 %,
     * Alt+Shift 10 %, Ctrl+Shift + Left/Right jump to the max / min,
     * Ctrl+Shift+Down resets to 55 % — mirrored when proc_left. Only with
     * proc and mem or net shown; a change drops the active preset.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function resizeProc(string $key): array
    {
        $shown = $this->config->shownBoxes();
        if ($this->cols <= 0 || !in_array('proc', $shown, true) || (!in_array('mem', $shown, true) && !in_array('net', $shown, true))) {
            return [$this, null];
        }
        $pct = $this->config->procBoxWidthPercent();
        $minP = (int) round(FrameBuilder::MINIMUMS['proc'][0] / $this->cols * 100);
        $side = in_array('mem', $shown, true) ? FrameBuilder::MINIMUMS['mem'][0] : FrameBuilder::MINIMUMS['net'][0];
        $maxP = (int) round(100 - $side / $this->cols * 100);
        $offset = str_starts_with($key, 'alt') ? ($minP + 10 <= $maxP ? 10 : $maxP - $minP) : 1;
        $left = $this->config->bool('proc_left');
        $grow = static fn (): int => FrameBuilder::clamp($pct + $offset, $minP + $offset, $maxP);
        $shrink = static fn (): int => FrameBuilder::clamp($pct - $offset, $minP, $maxP - $offset);
        $next = match ($key) {
            'shift_left', 'alt_shift_left' => $left ? $shrink() : $grow(),
            'shift_right', 'alt_shift_right' => $left ? $grow() : $shrink(),
            'ctrl_shift_left' => $left ? 0 : 100,
            'ctrl_shift_right' => $left ? 100 : 0,
            default => \SugarCraft\Top\Config\Schema::PROC_BOX_WIDTH_PERCENT,
        };
        if ($next === $pct) {
            return [$this, null];
        }

        return $this->mutate(preset: null, presetSet: true)->applyConfig($this->config->withProcBoxWidthPercent($next));
    }

    /**
     * btop's preset resets outside `p`/`P`: an edit of shown_boxes or
     * presets, a proc width change (#1476) and disable_presets set to
     * anything but Off (btop optionsMenu `current_preset.reset()`).
     */
    private static function dropsPreset(Config $before, Config $after): bool
    {
        foreach (['shown_boxes', 'presets', 'proc_box_width_percent'] as $key) {
            if ($before->value($key) !== $after->value($key)) {
                return true;
            }
        }

        return $before->string('disable_presets') !== $after->string('disable_presets') && $after->string('disable_presets') !== 'Off';
    }

    /**
     * Every quit: save first when btop's clean_quit would
     * (save_config_on_exit and a pending write), then quit.
     */
    public function quitCmd(): \Closure
    {
        $saves = array_values(array_filter([$this->exitSave(), $this->stateSave()]));
        if ($saves === []) {
            return Cmd::quit();
        }
        $saves[] = Cmd::quit();

        return Cmd::sequence(...$saves);
    }

    /**
     * The proc tree-state write (#1791c, `proc_tree_persist_state`) still
     * pending at exit, or null. Runs on every quit path next to the config
     * save, and from `bin/candy-top` after the Program returns (signals,
     * crash); answers {@see \SugarCraft\Top\State\TreeStateSavedMsg}.
     */
    public function stateSave(): ?\Closure
    {
        $proc = $this->panels['proc'] ?? null;

        return $proc instanceof ProcPanel ? $proc->treeStateSave($this->config) : null;
    }

    /**
     * The save btop's clean_quit performs, or null when it would not write:
     * no file, save_config_on_exit off, or nothing changed (btop
     * `write_new`). `bin/candy-top` runs it after the Program returns —
     * a SIGTERM / SIGHUP (trapped in bin) or a SIGINT stops the loop
     * without passing {@see quitCmd()}, and a quit-time save that failed
     * left the flag set — and reports a failure on STDERR.
     */
    public function exitSave(): ?\Closure
    {
        return $this->file !== null && $this->writeNew && $this->config->bool('save_config_on_exit') ? $this->saveCmd() : null;
    }

    /**
     * The Cmd writing the current config atomically ({@see ConfigFile::write()});
     * answers {@see ConfigSavedMsg}. Null without a file.
     */
    public function saveCmd(): ?\Closure
    {
        $file = $this->file;
        if ($file === null) {
            return null;
        }
        $config = $this->config;

        return static function () use ($file, $config): Msg {
            try {
                $file->write($config);
            } catch (\RuntimeException $e) {
                return new ConfigSavedMsg(false, $e->getMessage(), $config);
            }

            return new ConfigSavedMsg(true, '', $config);
        };
    }

    /**
     * `ctrl+r` (btop SIGUSR2): read config.conf over the current values and
     * rescan the themes, inside the Cmd.
     */
    public function reloadCmd(): \Closure
    {
        $file = $this->file;
        $base = $this->config;
        $rescan = $this->catalog !== null;

        return static fn (): Msg => new ConfigLoadedMsg($file?->load($base), $rescan ? ThemeRegistry::new() : null);
    }

    /**
     * Apply a reload (btop.cpp reload_conf): the file's persisted values
     * over the live runtime state, shown_boxes settled, lowcolor from
     * truecolor, the theme reloaded even when its name did not change (the
     * file may have), and — #1849 — every cached render dropped: the
     * frozen backdrop is re-captured once the palette lands.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function reloaded(ConfigLoadedMsg $msg): array
    {
        $config = $this->config;
        $writeNew = $this->writeNew;
        if ($msg->result !== null) {
            $config = $config->withPersistedFrom($msg->result->config)->withShownBoxesSettled($this->roster()->count());
            $config = $config->with('lowcolor', !$config->bool('truecolor'));
            $writeNew = $writeNew || $msg->result->needsRewrite;
        }
        $app = $msg->catalog !== null ? $this->mutate(catalog: $msg->catalog) : $this;
        [$next, $cmd] = $app->applyConfig($config, false);
        $next = $next->mutate(writeNew: $writeNew);
        $theme = self::themeKey($config) === self::themeKey($this->config) ? $next->themeCmd() : null;
        $next = $next->settledBackdrop(true);
        // btop only logs load warnings; on screen the first one shows in a
        // warning box (each rejected value kept its previous setting).
        $warning = $msg->result?->warnings[0] ?? null;
        if ($warning !== null && $next->framed()) {
            $next = $next->withOverlay(Menus::warning($warning));
        }

        return [$next, self::batch($cmd, $theme)];
    }

    /** @return array{0: self, 1: ?\Closure} */
    private function broadcast(Msg $msg): array
    {
        // Each panel's writes apply before the next panel runs, so a later
        // panel's context already carries them (btop's Config::set order).
        $app = $this;
        $cmds = [];
        foreach (array_keys($this->panels) as $box) {
            [$app, $cmds[]] = $app->deliver($app->panels[$box], $msg);
        }

        return [$app, self::batch(...$cmds)];
    }

    private static function batch(?\Closure ...$cmds): ?\Closure
    {
        $cmds = array_values(array_filter($cmds));

        return match (count($cmds)) {
            0 => null,
            1 => $cmds[0],
            default => Cmd::batch(...$cmds),
        };
    }

    /** Sample Cmds for every shown panel, batched. */
    private function collectAll(): ?\Closure
    {
        $cmds = [];
        $seen = [];
        foreach ($this->config->shownBoxes() as $box) {
            $panel = $this->panelFor($box);
            if ($panel !== null && !isset($seen[$panel->box()])) {
                $seen[$panel->box()] = true;
                $cmds[] = $panel->collect($this->context($box));
            }
        }
        $cmds = array_values(array_filter($cmds));

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    private function relayout(): self
    {
        if ($this->cols <= 0 || $this->rows <= 0) {
            return $this->mutate(layout: null, layoutSet: true);
        }
        $showTemp = $this->config->bool('check_temp') && $this->host->hasSensors;
        $layout = FrameBuilder::layout($this->cols, $this->rows, $this->config, $this->host->coreCount, $showTemp, $this->roster());

        return $this->mutate(layout: $layout, layoutSet: true);
    }

    private function withPanel(Panel $panel): self
    {
        $panels = $this->panels;
        $panels[$panel->box()] = $panel;

        return $this->mutate(panels: $panels);
    }

    /**
     * @param ?array<string, Panel> $panels
     */
    private function mutate(
        ?Config $config = null,
        ?Ink $ink = null,
        ?array $panels = null,
        ?int $cols = null,
        ?int $rows = null,
        ?Layout $layout = null,
        bool $layoutSet = false,
        ?float $now = null,
        ?float $uptime = null,
        ?int $generation = null,
        ?int $preset = null,
        bool $presetSet = false,
        ?string $lastKey = null,
        ?int $keyRun = null,
        ?float $lastStepAt = null,
        ?OverlayStack $overlays = null,
        ?Surface $backdrop = null,
        bool $backdropSet = false,
        ?bool $writeNew = null,
        ?ThemeRegistry $catalog = null,
    ): self {
        return new self(
            $config ?? $this->config,
            $ink ?? $this->ink,
            $this->host,
            $panels ?? $this->panels,
            $this->clock,
            $cols ?? $this->cols,
            $rows ?? $this->rows,
            $layoutSet ? $layout : $this->layout,
            $now ?? $this->now,
            $uptime ?? $this->uptime,
            $generation ?? $this->generation,
            $presetSet ? $preset : $this->preset,
            $lastKey ?? $this->lastKey,
            $keyRun ?? $this->keyRun,
            $lastStepAt ?? $this->lastStepAt,
            $overlays ?? $this->overlays,
            $this->themes,
            $backdropSet ? $backdrop : $this->backdrop,
            $this->file,
            $writeNew ?? $this->writeNew,
            $catalog ?? $this->catalog,
        );
    }
}
