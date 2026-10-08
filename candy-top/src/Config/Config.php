<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * Immutable candy-top settings: btop's option set, keys and defaults.
 *
 * Mirrors aristocratos/btop Config::getB/getI/getS/set — but as a value
 * object: every with*() validates through the {@see Schema} and returns a new
 * instance, so a rejected value can never half-apply (btop keeps the old
 * value on a bad set; here the old instance simply survives the throw).
 * Generic access is by btop key name; the typed accessors cover the options
 * the app reads every frame.
 */
final class Config
{
    /** @param array<string, bool|int|string> $values Complete — one entry per Schema option. */
    private function __construct(
        private readonly array $values,
    ) {
    }

    /** btop's built-in defaults. */
    public static function new(): self
    {
        return new self(Schema::defaults());
    }

    /** Whether $key is a known option. */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    /**
     * Raw typed value of $key.
     *
     * @throws InvalidOptionValue For an unknown key.
     */
    public function value(string $key): bool|int|string
    {
        if (!$this->has($key)) {
            throw self::unknown($key);
        }

        return $this->values[$key];
    }

    /**
     * Boolean option value — btop Config::getB.
     *
     * @throws InvalidOptionValue For an unknown or non-bool key.
     */
    public function bool(string $key): bool
    {
        $value = $this->value($key);
        if (!\is_bool($value)) {
            throw self::mismatch($key, OptionType::Bool);
        }

        return $value;
    }

    /**
     * Integer option value — btop Config::getI.
     *
     * @throws InvalidOptionValue For an unknown or non-int key.
     */
    public function int(string $key): int
    {
        $value = $this->value($key);
        if (!\is_int($value)) {
            throw self::mismatch($key, OptionType::Int);
        }

        return $value;
    }

    /**
     * String option value — btop Config::getS.
     *
     * @throws InvalidOptionValue For an unknown or non-string key.
     */
    public function string(string $key): string
    {
        $value = $this->value($key);
        if (!\is_string($value)) {
            throw self::mismatch($key, OptionType::String);
        }

        return $value;
    }

    /**
     * Every value keyed by name, Schema order.
     *
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Copy with $key set to $value — btop Config::set.
     *
     * @throws InvalidOptionValue For an unknown key, wrong type or a value btop rejects.
     */
    public function with(string $key, bool|int|string $value): self
    {
        $option = Schema::option($key) ?? throw self::unknown($key);

        return $this->mutate([$key => $option->check($value)]);
    }

    /**
     * Copy with $key set from its config-file spelling (`True`, `2000`, an
     * unquoted string) — what the options menu's text field produces.
     * $fromFile selects the loader's law (shown_boxes unvalidated, as btop).
     *
     * @throws InvalidOptionValue
     */
    public function withParsed(string $key, string $raw, bool $fromFile = false): self
    {
        $option = Schema::option($key) ?? throw self::unknown($key);

        return $this->mutate([$key => $option->parse($raw, $fromFile)]);
    }

    /**
     * Copy with shown_boxes settled against the machine — btop.cpp's
     * post-load `set_boxes` check: the file loader accepts shown_boxes
     * verbatim, so an unknown box name, a gpuN beyond the $gpuCount GPUs
     * actually detected, or more than {@see GpuPanels::MAX} gpu boxes
     * (btop PR #1730) resets the list to "cpu mem net proc". A null
     * $gpuCount skips only the index check (the GPUs have not been
     * sampled). An empty list is legitimate (btop then draws no boxes) and
     * is kept.
     */
    public function withShownBoxesSettled(?int $gpuCount): self
    {
        $boxes = $this->shownBoxes();
        $valid = \count(GpuPanels::targets($boxes)) <= GpuPanels::MAX;
        foreach ($boxes as $box) {
            $gpu = GpuPanels::index($box);
            $valid = $valid && GpuPanels::valid($box) && ($gpu === null || $gpuCount === null || $gpu < $gpuCount);
        }

        return $valid ? $this : $this->mutate(['shown_boxes' => (string) Schema::defaults()['shown_boxes']]);
    }

    /**
     * Copy with every PERSISTED option taken from $other, runtime state
     * (tty_mode, proc_filter, show_detailed, ...) kept — what a config
     * reload does (btop init_config re-runs Config::load over the live
     * maps, which only ever touches `descriptions` keys). $other's values
     * were validated when it was built, so no law re-runs here.
     */
    public function withPersistedFrom(Config $other): self
    {
        $changes = [];
        foreach (Schema::persistedNames() as $name) {
            $changes[$name] = $other->values[$name];
        }

        return $this->mutate($changes);
    }

    /**
     * Whether any persisted option differs from $other — btop's `write_new`
     * trigger (any Config::set of a `descriptions` key).
     */
    public function persistedDiffers(Config $other): bool
    {
        foreach (Schema::persistedNames() as $name) {
            if ($this->values[$name] !== $other->values[$name]) {
                return true;
            }
        }

        return false;
    }

    /**
     * $key's value in btop's menu spelling — Config::getAsString: bools
     * `True`/`False`, ints as digits, strings as stored.
     */
    public function asString(string $key): string
    {
        $value = $this->value($key);

        return match (true) {
            \is_bool($value) => $value ? 'True' : 'False',
            default => (string) $value,
        };
    }

    /** Copy with the bool option $key inverted — btop Config::flip. */
    public function flipped(string $key): self
    {
        return $this->with($key, !$this->bool($key));
    }

    /**
     * Copy with update_ms clamped into [100, 86_400_000].
     *
     * Clamping, not throwing: this is the `+`/`-` key path (btop_input.cpp
     * guards the step so the timer pins at the bounds instead of erroring).
     */
    public function withUpdateMs(int $ms): self
    {
        return $this->with('update_ms', max(Schema::MIN_UPDATE_MS, min(Schema::ONE_DAY_MILLIS, $ms)));
    }

    /**
     * Copy with $preset applied — btop Config::apply_preset minus the
     * terminal-size gate (the app checks the minimum size before calling).
     * P=1 sets cpu_bottom / mem_below_net / proc_left for those boxes; each
     * box's graph symbol lands on graph_symbol_<box> (gpuN share
     * graph_symbol_gpu); the box list becomes shown_boxes (gpu slots back
     * to the default order, btop set_boxes). A proc box also
     * sets proc_box_width_percent (btop PR #1476): its W field clamped to
     * 0-100, or the 55 default when W is absent or `default` — so applying
     * a preset always resets a width the user nudged with Shift+arrows.
     */
    public function withPreset(Preset $preset): self
    {
        $changes = [];
        foreach ($preset->boxes as $box) {
            $position = match ($box->box) {
                'cpu' => 'cpu_bottom',
                'mem' => 'mem_below_net',
                'proc' => 'proc_left',
                default => null,
            };
            if ($position !== null) {
                $changes[$position] = $box->alternate;
            }
            if ($box->box === 'proc') {
                $changes['proc_box_width_percent'] = $box->widthPercent() ?? Schema::PROC_BOX_WIDTH_PERCENT;
            }
            // btop PR #1873 apply_preset: the ctr box uses graph_symbol_proc;
            // so do candy-top's VM dashboard cards.
            if ($box->box !== 'ctr' && $box->box !== 'vms') {
                $symbolKey = str_starts_with($box->box, 'gpu') ? 'graph_symbol_gpu' : 'graph_symbol_' . $box->box;
                $changes[$symbolKey] = $box->graphSymbol;
            }
        }
        $changes['shown_boxes'] = implode(' ', $preset->boxNames());

        $next = $this;
        foreach ($changes as $key => $value) {
            $next = $next->with($key, $value);
        }

        return $next;
    }

    /** Data/frame period in milliseconds (default 2000). */
    public function updateMs(): int
    {
        return $this->int('update_ms');
    }

    /** Theme name: "Default", "TTY" or a `.theme` file stem. */
    public function colorTheme(): string
    {
        return $this->string('color_theme');
    }

    /** Global graph symbol family: braille | block | block2 | tty. */
    public function graphSymbol(): string
    {
        return $this->string('graph_symbol');
    }

    /**
     * Effective graph symbol for $box: its graph_symbol_<box> override, or
     * graph_symbol when that says "default"; gpuN read graph_symbol_gpu.
     * TTY mode forces "tty" for every box (btop_draw.cpp cpu box:
     * `tty_mode ? "tty" : graph_symbol_cpu`).
     */
    public function graphSymbolFor(string $box): string
    {
        if ($this->ttyMode()) {
            return 'tty';
        }
        $key = str_starts_with($box, 'gpu') ? 'graph_symbol_gpu' : 'graph_symbol_' . $box;
        $override = $this->has($key) ? $this->string($key) : 'default';

        return $override === 'default' ? $this->graphSymbol() : $override;
    }

    /** @return list<string> Boxes to show, in configured order. */
    public function shownBoxes(): array
    {
        return array_values(array_filter(
            explode(' ', $this->string('shown_boxes')),
            static fn (string $b): bool => $b !== '',
        ));
    }

    /** The parsed `presets` option, built-in preset 0 first. */
    public function presets(): Presets
    {
        return Presets::parse($this->string('presets'));
    }

    /** proc_sorting: one of {@see Schema::PROC_SORTING}. */
    public function procSorting(): string
    {
        return $this->string('proc_sorting');
    }

    /**
     * Copy with proc_box_width_percent clamped into [0, 100] — the
     * Shift/Alt+Shift+arrow key path of btop PR #1476, which pins at the
     * bounds instead of erroring. The layout further clamps to the min/max
     * box widths the window allows without touching the stored value.
     */
    public function withProcBoxWidthPercent(int $percent): self
    {
        return $this->with('proc_box_width_percent', max(0, min(100, $percent)));
    }

    /** proc_box_width_percent (btop PR #1476): proc box width % when mem or net is shown, 0-100. */
    public function procBoxWidthPercent(): int
    {
        return $this->int('proc_box_width_percent');
    }

    /** show_core_freq (btop PR #1785): off | value | graph — per-core frequency in the cpu box. */
    public function showCoreFreq(): string
    {
        return $this->string('show_core_freq');
    }

    /**
     * mem_selected (btop PR #1747): "default" for the stacked mem meters, or
     * the one metric (used | available | cached | free | swap_used) drawn
     * as a single full-height graph.
     */
    public function memSelected(): string
    {
        return $this->string('mem_selected');
    }

    /**
     * disks_order (btop PR #1700) as a list: mountpoints (and the `swap`
     * pseudo-disk) to show first, in order. Whitespace-split like btop's
     * ssplit, duplicates dropped keeping the first — btop's
     * apply_disks_order skips a mountpoint already placed.
     *
     * @return list<string>
     */
    public function disksOrder(): array
    {
        $tokens = preg_split('/\s+/', $this->string('disks_order'), -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens === false ? [] : $tokens));
    }

    /** temp_scale: celsius | fahrenheit | kelvin | rankine. */
    public function tempScale(): string
    {
        return $this->string('temp_scale');
    }

    /** strftime clock format with btop's /host /user /uptime tokens; "" disables the clock. */
    public function clockFormat(): string
    {
        return $this->string('clock_format');
    }

    /**
     * Whether TTY mode is in effect (runtime `tty_mode`, settled at startup by
     * {@see withTtyModeResolved()}).
     */
    public function ttyMode(): bool
    {
        return $this->bool('tty_mode');
    }

    /**
     * Copy with the runtime `tty_mode` settled — btop configure_tty_mode():
     * an explicit CLI `--force-tty`/`--no-force-tty` wins, then config
     * `force_tty`, then auto-detection of a real `/dev/tty*` console.
     */
    public function withTtyModeResolved(?bool $cliForce, bool $onRealTty): self
    {
        $tty = $cliForce ?? ($this->bool('force_tty') || $onRealTty);

        return $this->with('tty_mode', $tty)->with('tty_console', $onRealTty);
    }

    /** Rounded box corners — btop ignores this in TTY mode, and so does this accessor. */
    public function roundedCorners(): bool
    {
        return $this->bool('rounded_corners') && !$this->ttyMode();
    }

    /** btop PR #1881 gpu_box_columns: null for "Auto", else the forced column count (1-6). */
    public function gpuBoxColumns(): ?int
    {
        $value = $this->string('gpu_box_columns');

        return $value === 'Auto' ? null : (int) $value;
    }

    /**
     * @param array<string, bool|int|string> $changes Already validated.
     */
    private function mutate(array $changes): self
    {
        // btop set_boxes clears current_gpu_panel_slots: a new shown_boxes
        // written without its slots (options menu, preset, reload, file)
        // starts from the default slot order (btop PR #1730).
        if (isset($changes['shown_boxes']) && !isset($changes[GpuPanels::SLOTS_KEY])
            && $changes['shown_boxes'] !== $this->values['shown_boxes']) {
            $changes[GpuPanels::SLOTS_KEY] = '';
        }
        // btop PR #1873 calcSizes: `if (not Ctr::shown) Ctr::selected.clear()`.
        $boxes = (string) ($changes['shown_boxes'] ?? $this->values['shown_boxes'] ?? '');
        if (!\in_array('ctr', explode(' ', $boxes), true) && ($changes[Schema::CTR_SELECTED] ?? $this->values[Schema::CTR_SELECTED] ?? '') !== '') {
            $changes[Schema::CTR_SELECTED] = '';
        }

        return new self(array_replace($this->values, $changes));
    }

    private static function unknown(string $key): InvalidOptionValue
    {
        return new InvalidOptionValue(Lang::t('config.warn.unknown_key', ['name' => $key]), $key);
    }

    private static function mismatch(string $key, OptionType $type): InvalidOptionValue
    {
        return new InvalidOptionValue(
            Lang::t('config.warn.type_mismatch', ['name' => $key, 'type' => $type->value]),
            $key,
        );
    }
}
