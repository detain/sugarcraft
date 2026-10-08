<?php

declare(strict_types=1);

namespace SugarCraft\Top;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\View as CoreView;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Msg\UpdateStepMsg;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\View\ClockFormat;
use SugarCraft\Top\View\FrameBuilder;
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
 * Behind the size notice only `q` and `1`-`4` act. Otherwise a visible
 * panel reporting {@see Panel::modal()} (btop's open proc filter) gets
 * every key and only left clicks, ahead of the globals; else every key is
 * offered to the visible panels ({@see Panel::capturesKey()}, layout
 * order) and the first that claims it gets it alone — btop's proc box owns
 * `+`/`-`/`=` while proc_tree is on. Only unclaimed keys reach the global
 * `q`, `1`-`4`, `+`/`-` handling, then the broadcast.
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
 * Mirrors aristocratos/btop main loop, Input::process global keys, and
 * Draw::calcSizes / update_clock.
 */
final class App implements Model
{
    /** btop's numeric box toggles (all_boxes, GPU build index 1-4). */
    public const BOX_KEYS = ['1' => 'cpu', '2' => 'mem', '3' => 'net', '4' => 'proc'];

    /** update_ms step per `+`/`-` press. */
    public const UPDATE_STEP_MS = 100;

    /** update_ms step while `+`/`-` is held (btop_input.cpp cpu-box actions). */
    public const UPDATE_HOLD_STEP_MS = 1000;

    /** btop Input::history length: a hold is this many identical keys in a row. */
    public const HOLD_HISTORY = 50;

    /** btop `last_press >= time_ms() - 200`. */
    public const HOLD_WINDOW_SEC = 0.2;

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
    ) {
    }

    /**
     * @param array<string, Panel> $panels  from {@see Panel\Panels::standard()}
     * @param ?\Closure(): ClockTickMsg $clock reads wall time + uptime; null = the live system
     * @param ?ColorProfile $profile null = derived from config (truecolor / tty_mode)
     */
    public static function start(
        Config $config,
        Palette $palette,
        HostInfo $host,
        array $panels,
        ?\Closure $clock = null,
        ?ColorProfile $profile = null,
    ): self {
        $profile ??= self::profileFor($config);

        return new self($config, Ink::new($palette, $profile), $host, $panels, $clock ?? self::systemClock());
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

            return $next->broadcast($msg);
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
            $panel = $this->panels[$msg->box] ?? null;

            return $panel === null ? [$this, null] : $this->deliver($panel, $msg);
        }
        if ($msg instanceof UpdateStepMsg) {
            return $this->stepUpdateMs($msg);
        }
        if ($msg instanceof SetOptionMsg) {
            return $this->applyOptions([$msg->key => $msg->value]);
        }
        if ($msg instanceof KeyMsg) {
            $name = $msg->string();
            $self = $this->mutate(lastKey: $name, keyRun: $name === $this->lastKey ? $this->keyRun + 1 : 1);
            if ($name === 'ctrl+c') {
                return [$self, Cmd::quit()];
            }
            if (!$self->framed()) {
                return $self->sizeNoticeKey($msg);
            }
            // A modal panel (a filter prompt) takes `q` and digits as text; a
            // mere claim never may, or a panel could disable quit / toggles.
            $owner = $self->modalPanel() ?? (self::isGlobalKey($msg) ? null : $self->captor($msg));
            if ($owner !== null) {
                return $self->deliver($owner, $msg);
            }

            return $self->handleKey($msg) ?? $self->broadcast($msg);
        }

        if ($msg instanceof MouseMsg) {
            // Behind the size notice btop reads only `q` and `1`-`4`.
            if (!$this->framed()) {
                return [$this, null];
            }
            $modal = $this->modalPanel();
            // btop_input.cpp:158-161: while filtering every mouse event but a
            // click becomes "" — never reaching history or any handler.
            if ($modal !== null && !self::isClick($msg)) {
                return [$this, null];
            }
            // btop pushes every mouse event ("mouse_click", "mouse_scroll_up",
            // ...) into Input::history too (btop_input.cpp:193-195), so a
            // mouse event breaks a held `+`/`-` run.
            $name = 'mouse:' . $msg->action->name . ':' . $msg->button->name;
            $self = $this->mutate(lastKey: $name, keyRun: $name === $this->lastKey ? $this->keyRun + 1 : 1);

            return $modal !== null ? $self->deliver($modal, $msg) : $self->broadcast($msg);
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
            [$w, $h] = FrameBuilder::minSize($boxes);

            return SizeError::surface($this->cols, $this->rows, $w, $h);
        }

        $surface = Surface::new($this->cols, $this->rows, $this->ink->base());
        FrameBuilder::paintChrome($surface, $this->layout, $this->ink, $this->config, $this->host, $this->preset);
        $border = FrameBuilder::border($this->config);
        foreach ($this->layout->ordered() as $box => $rect) {
            $panel = $this->panels[$box] ?? null;
            $panel?->paint(
                $surface->region($rect),
                new PanelFrame($this->layout, $rect, $this->ink, $border, $this->config, $this->host),
            );
        }
        FrameBuilder::paintClock($surface, $this->layout, $this->ink, $this->config, $this->clockText());

        return $surface;
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

    /** Copy with a replaced theme (options-menu theme cycling, P-F/P-G). */
    public function withPalette(Palette $palette): self
    {
        return $this->mutate(ink: Ink::new($palette, $this->ink->profile()));
    }

    /**
     * Swap in `$config` (options menu, presets — P-F; {@see SetOptionMsg}
     * from a panel). Layout follows it, and so does the colour profile when
     * truecolor / tty_mode change what {@see profileFor()} derives (an
     * explicit start() profile survives any change that does not). The
     * palette is NOT reloaded: a tty_mode flip that should swap to the TTY
     * theme needs ThemeRegistry I/O, so P-F pairs it with withPalette(). A
     * changed update_ms bumps {@see $generation} AND returns the re-armed
     * data tick, and boxes the new config shows that the old one hid are
     * sampled at once — so the returned Cmd MUST be dispatched: dropping it
     * would leave only the outdated tick armed and stop every data update.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function applyConfig(Config $config): array
    {
        $periodChanged = $config->updateMs() !== $this->config->updateMs();
        $before = $this->config->shownBoxes();
        $profile = self::profileFor($config);
        $ink = $profile !== self::profileFor($this->config) ? Ink::new($this->ink->palette(), $profile) : null;
        $next = $this->mutate(config: $config, ink: $ink, generation: $this->generation + ($periodChanged ? 1 : 0))->relayout();
        $cmds = [$periodChanged ? $next->dataTick() : null];
        foreach (array_diff($config->shownBoxes(), $before) as $box) {
            $cmds[] = ($next->panels[$box] ?? null)?->collect($next->context($box));
        }
        $cmds = array_values(array_filter($cmds));

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
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
     * route the result through {@see applyConfig()}.
     *
     * @param array<string, bool|int|string> $set
     * @return array{0: self, 1: ?\Closure}
     */
    public function applyOptions(array $set): array
    {
        $config = $this->config;
        foreach ($set as $key => $value) {
            try {
                $config = $config->with((string) $key, $value);
            } catch (InvalidOptionValue) {
                continue;
            }
        }

        return $config === $this->config ? [$this, null] : $this->applyConfig($config);
    }

    /** True when the shown boxes are laid out and painted (no size notice). */
    private function framed(): bool
    {
        return $this->layout !== null && FrameBuilder::fits($this->cols, $this->rows, $this->config->shownBoxes());
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

        return [$next, self::batch($result->cmd, $applied)];
    }

    /** The first visible panel (layout order) that owns all input, or null. Caller checks framed(). */
    private function modalPanel(): ?Panel
    {
        foreach (array_keys($this->layout?->ordered() ?? []) as $box) {
            $panel = $this->panels[$box] ?? null;
            if ($panel !== null && $panel->modal($this->context($box))) {
                return $panel;
            }
        }

        return null;
    }

    /** The first visible panel (layout order) that claims `$key`, or null. Caller checks framed(). */
    private function captor(KeyMsg $key): ?Panel
    {
        foreach (array_keys($this->layout?->ordered() ?? []) as $box) {
            $panel = $this->panels[$box] ?? null;
            if ($panel !== null && $panel->capturesKey($key, $this->context($box))) {
                return $panel;
            }
        }

        return null;
    }

    /** `q` or a box toggle `1`-`4`: never offered to {@see Panel::capturesKey()}. */
    private static function isGlobalKey(KeyMsg $key): bool
    {
        return $key->string() === 'q'
            || ($key->type === KeyType::Char && !$key->ctrl && !$key->alt && isset(self::BOX_KEYS[$key->rune]));
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
            return [$this, Cmd::quit()];
        }
        if ($key->type === KeyType::Char && !$key->ctrl && !$key->alt && isset(self::BOX_KEYS[$key->rune])) {
            return $this->toggleBox(self::BOX_KEYS[$key->rune]);
        }

        return [$this, null];
    }

    /** @return array{0: self, 1: ?\Closure}|null */
    private function handleKey(KeyMsg $key): ?array
    {
        if ($key->string() === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($key->type !== KeyType::Char || $key->ctrl || $key->alt) {
            return null;
        }
        if (isset(self::BOX_KEYS[$key->rune])) {
            return $this->toggleBox(self::BOX_KEYS[$key->rune]);
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
     * terminal. Any toggle drops the active preset. A box toggled ON is
     * sampled at once (btop Runner::run("all", false, true) after the
     * toggle) instead of sitting empty until the next data tick.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function toggleBox(string $box): array
    {
        $boxes = $this->config->shownBoxes();
        $pos = array_search($box, $boxes, true);
        if ($pos === false) {
            $boxes[] = $box;
        } else {
            array_splice($boxes, $pos, 1);
        }
        if ($boxes === [] || ($this->cols > 0 && !FrameBuilder::fits($this->cols, $this->rows, $boxes))) {
            return [$this, null];
        }
        try {
            $config = $this->config->with('shown_boxes', implode(' ', $boxes));
        } catch (InvalidOptionValue) {
            return [$this, null];
        }
        $next = $this->mutate(config: $config, preset: null, presetSet: true)->relayout();

        return [$next, $pos === false ? ($next->panels[$box] ?? null)?->collect($next->context($box)) : null];
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
        foreach ($this->config->shownBoxes() as $box) {
            $cmds[] = isset($this->panels[$box]) ? $this->panels[$box]->collect($this->context($box)) : null;
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
        $layout = FrameBuilder::layout($this->cols, $this->rows, $this->config, $this->host->coreCount, $showTemp);

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
        );
    }
}
