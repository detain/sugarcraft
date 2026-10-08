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
     * verbatim, so an unknown box name, or a gpuN beyond the $gpuCount GPUs
     * actually detected, resets the list to "cpu mem net proc". An empty
     * list is legitimate (btop then draws no boxes) and is kept.
     */
    public function withShownBoxesSettled(int $gpuCount): self
    {
        foreach ($this->shownBoxes() as $box) {
            $valid = \in_array($box, Schema::BOXES, true)
                && (!str_starts_with($box, 'gpu') || (int) substr($box, 3) < $gpuCount);
            if (!$valid) {
                return $this->mutate(['shown_boxes' => (string) Schema::defaults()['shown_boxes']]);
            }
        }

        return $this;
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
     * graph_symbol_gpu); the box list becomes shown_boxes.
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
            $symbolKey = str_starts_with($box->box, 'gpu') ? 'graph_symbol_gpu' : 'graph_symbol_' . $box->box;
            $changes[$symbolKey] = $box->graphSymbol;
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

    /** Global graph symbol family: braille | block | tty. */
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

        return $this->with('tty_mode', $tty);
    }

    /** Rounded box corners — btop ignores this in TTY mode, and so does this accessor. */
    public function roundedCorners(): bool
    {
        return $this->bool('rounded_corners') && !$this->ttyMode();
    }

    /**
     * @param array<string, bool|int|string> $changes Already validated.
     */
    private function mutate(array $changes): self
    {
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
