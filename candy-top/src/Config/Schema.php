<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * The option roster: every key candy-top reads from / writes to config.conf,
 * in btop's file order, plus the runtime-only state keys btop keeps in the
 * same maps but never persists.
 *
 * Mirrors aristocratos/btop src/btop_config.cpp v1.4.7 for the Linux build
 * with GPU_SUPPORT: `descriptions` gives the persisted keys and their write
 * order (only these are accepted by Config::load — btop builds `valid_names`
 * from it), `bools`/`ints`/`strings` give the defaults, `intValid` /
 * `stringValid` / `presetsValid` give the value law.
 *
 * Wave U (adopted open upstream PRs, evaluated in
 * prompt_kit/findings/btop-upstream-prs.md) extends the 1.4.7 roster. The
 * compatibility law for a btop.conf <-> config.conf round trip:
 *  - New keys are additive: show_zswap (#1739), show_core_freq (#1785),
 *    net_hide_ip (#1573), proc_command_basename (#1859),
 *    proc_filter_containers (#1873), mem_selected (#1747), disks_order
 *    (#1700), proc_box_width_percent (#1476). Stock btop and our reader both
 *    skip unknown keys, so either file still loads in the other program.
 *  - New *values* of existing enums are a soft break: `block2` in
 *    graph_symbol / graph_symbol_<box> (#1783) and `io read|write|total` in
 *    proc_sorting (#1823). Stock btop warns and keeps its default.
 *  - The presets 4th field `proc:P:G:W` (#1476) is a strict superset of
 *    btop's grammar — every 3-field string parses identically — and is only
 *    ever written back when the user wrote it; stock btop rejects a whole
 *    presets string containing one and resets presets to its default.
 *  - From #1873 only proc_filter_containers is adopted; its `ctr` box is
 *    deferred (Wave U4), so a #1873-btop config naming `ctr` in shown_boxes
 *    or presets is rejected here.
 * Placement follows each PR's position in btop's `descriptions`.
 */
final class Schema
{
    /** btop release the key set and defaults were taken from. */
    public const BTOP_VERSION = '1.4.7';

    /** btop Config::ONE_DAY_MILLIS — update_ms ceiling. */
    public const ONE_DAY_MILLIS = 86_400_000;

    /** btop's update_ms floor. */
    public const MIN_UPDATE_MS = 100;

    /** btop Config::valid_graph_symbols, plus `block2` sextants (btop PR #1783 — stock 1.4.7 rejects it). */
    public const GRAPH_SYMBOLS = ['braille', 'block', 'block2', 'tty'];

    /** btop Config::valid_graph_symbols_def — per-box symbols may also defer to graph_symbol. */
    public const GRAPH_SYMBOLS_DEF = ['default', 'braille', 'block', 'block2', 'tty'];

    /** btop Config::valid_boxes (GPU build). */
    public const BOXES = ['cpu', 'mem', 'net', 'proc', 'gpu0', 'gpu1', 'gpu2', 'gpu3', 'gpu4', 'gpu5'];

    /** btop Config::temp_scales. */
    public const TEMP_SCALES = ['celsius', 'fahrenheit', 'kelvin', 'rankine'];

    /** btop Config::freq_modes (Linux only). */
    public const FREQ_MODES = ['first', 'range', 'lowest', 'highest', 'average'];

    /** btop PR #1785 Config::show_core_freq_values — per-core frequency in the cpu box. */
    public const SHOW_CORE_FREQ_VALUES = ['off', 'value', 'graph'];

    /** btop PR #1747 Config::mem_metrics_values — mem_selected: one focused mem graph, or "default". */
    public const MEM_METRICS_VALUES = ['default', 'used', 'available', 'cached', 'free', 'swap_used'];

    /** btop PR #1476 Proc::width_p — default proc box width % when mem or net is shown. */
    public const PROC_BOX_WIDTH_PERCENT = 55;

    /** btop Config::show_gpu_values. */
    public const SHOW_GPU_VALUES = ['Auto', 'On', 'Off'];

    /** btop Config::base_10_bitrate_values. */
    public const BASE_10_BITRATE_VALUES = ['Auto', 'True', 'False'];

    /** btop Config::disable_preset_options. */
    public const DISABLE_PRESET_OPTIONS = ['Off', 'Default', 'Custom', 'All'];

    /**
     * btop Proc::sort_vector (btop_shared.cpp) — the values proc_sorting can
     * take. btop PR #1823 appends the three io sorts after btop's eight, so
     * left/right sort cycling keeps btop's order first; stock 1.4.7 rejects
     * them and falls back to "cpu lazy".
     */
    public const PROC_SORTING = [
        'pid', 'name', 'command', 'threads', 'user', 'memory', 'cpu direct', 'cpu lazy',
        'io read', 'io write', 'io total',
    ];

    /** btop Logger::log_levels. */
    public const LOG_LEVELS = ['DISABLED', 'ERROR', 'WARNING', 'INFO', 'DEBUG'];

    /**
     * Fields btop's Linux collector can offer for cpu_graph_upper/lower
     * besides "Auto"/"total" (/proc/stat columns). btop does not validate the
     * option against them — the list is detected at runtime and GPU fields
     * (`gpu-*`) join it — so neither does candy-top; this is for the menu.
     */
    public const CPU_GRAPH_FIELDS = ['Auto', 'total', 'user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal', 'guest', 'guest_nice'];

    /** @var array<string, Option>|null */
    private static ?array $options = null;

    private function __construct()
    {
    }

    /**
     * Every option keyed by name, persisted ones first in btop write order.
     *
     * @return array<string, Option>
     */
    public static function options(): array
    {
        return self::$options ??= self::build();
    }

    /** The option named $name, or null for an unknown key. */
    public static function option(string $name): ?Option
    {
        return self::options()[$name] ?? null;
    }

    /**
     * Names of the options read from and written to config.conf, in order.
     *
     * @return list<string>
     */
    public static function persistedNames(): array
    {
        return array_keys(array_filter(self::options(), static fn (Option $o): bool => $o->persisted));
    }

    /**
     * Defaults keyed by name.
     *
     * @return array<string, bool|int|string>
     */
    public static function defaults(): array
    {
        return array_map(static fn (Option $o): bool|int|string => $o->default, self::options());
    }

    /** @return array<string, Option> */
    private static function build(): array
    {
        $graphSymbol = static fn (string $box): Option => Option::string(
            'graph_symbol_' . $box,
            'default',
            self::GRAPH_SYMBOLS_DEF,
            'config.warn.invalid_graph_symbol_box',
        );

        $list = [
            Option::string('color_theme', 'Default'),
            Option::bool('theme_background', true),
            Option::bool('truecolor', true),
            Option::bool('force_tty', false),
            Option::string('disable_presets', 'Off', self::DISABLE_PRESET_OPTIONS),
            Option::string(
                'presets',
                'cpu:1:default,proc:0:default cpu:0:default,mem:0:default,net:0:default cpu:0:block,net:0:tty',
                validator: static function (string $v): void {
                    Presets::parse($v);
                },
            ),
            Option::bool('vim_keys', false),
            Option::bool('disable_mouse', false),
            Option::bool('rounded_corners', true),
            Option::bool('terminal_sync', true),
            Option::string('graph_symbol', 'braille', self::GRAPH_SYMBOLS, 'config.warn.invalid_graph_symbol'),
            $graphSymbol('cpu'),
            $graphSymbol('gpu'),
            $graphSymbol('mem'),
            $graphSymbol('net'),
            $graphSymbol('proc'),
            // btop skips this law while loading (Global::init_conf) — an empty
            // or unknown list is settled afterwards, see Config::withShownBoxesSettled().
            Option::string('shown_boxes', 'cpu mem net proc', validator: self::validateBoxes(...), validateOnLoad: false),
            Option::int('update_ms', 2000, self::MIN_UPDATE_MS, self::ONE_DAY_MILLIS),
            Option::string('proc_sorting', 'cpu lazy', self::PROC_SORTING),
            Option::bool('proc_reversed', false),
            Option::bool('proc_tree', false),
            Option::bool('proc_command_basename', false),
            // btop #1791c puts this right after proc_tree too; #1859's key
            // keeps that slot and this one follows it. The remembered choices
            // live in an XDG state file (State\TreeStateFile), not in btop's
            // internal proc_tree_state config key.
            Option::bool('proc_tree_persist_state', false),
            Option::bool('proc_colors', true),
            Option::bool('proc_gradient', true),
            Option::bool('proc_per_core', false),
            Option::bool('proc_mem_bytes', true),
            Option::bool('proc_cpu_graphs', true),
            Option::bool('proc_info_smaps', false),
            // btop PR #1476 stores any int and clamps at use; candy-top
            // rejects out-of-range values instead (a stored 150 would be a
            // silent 100), and withProcBoxWidthPercent() clamps the key path.
            Option::int('proc_box_width_percent', self::PROC_BOX_WIDTH_PERCENT, 0, 100),
            Option::bool('proc_left', false),
            Option::bool('proc_filter_kernel', false),
            Option::bool('proc_filter_containers', false),
            Option::bool('proc_follow_detailed', true),
            Option::bool('proc_aggregate', false),
            Option::int('proc_tree_auto_collapse', 0, 0, 10000),
            Option::bool('keep_dead_proc_usage', false),
            Option::string('cpu_graph_upper', 'Auto'),
            Option::string('cpu_graph_lower', 'Auto'),
            Option::string('show_gpu_info', 'Auto', self::SHOW_GPU_VALUES),
            Option::bool('cpu_invert_lower', true),
            Option::bool('cpu_single_graph', false),
            Option::bool('cpu_bottom', false),
            Option::bool('show_uptime', true),
            Option::bool('show_cpu_watts', true),
            Option::bool('check_temp', true),
            Option::string('cpu_sensor', 'Auto'),
            Option::bool('show_coretemp', true),
            Option::string('cpu_core_map', '', validator: self::validateCoreMap(...)),
            Option::string('temp_scale', 'celsius', self::TEMP_SCALES),
            Option::bool('base_10_sizes', false),
            Option::bool('show_cpu_freq', true),
            Option::string('freq_mode', 'first', self::FREQ_MODES),
            Option::string('show_core_freq', 'off', self::SHOW_CORE_FREQ_VALUES),
            Option::string('clock_format', '%X'),
            Option::bool('background_update', true),
            Option::string('custom_cpu_name', ''),
            Option::string('disks_filter', ''),
            Option::string('disks_order', ''),
            Option::bool('mem_graphs', true),
            Option::string('mem_selected', 'default', self::MEM_METRICS_VALUES),
            Option::bool('mem_below_net', false),
            Option::bool('zfs_arc_cached', true),
            Option::bool('show_swap', true),
            Option::bool('show_zswap', true),
            Option::bool('swap_disk', true),
            Option::bool('show_disks', true),
            Option::bool('only_physical', true),
            Option::bool('use_fstab', true),
            Option::bool('zfs_hide_datasets', false),
            Option::bool('disk_free_priv', false),
            Option::bool('show_io_stat', true),
            Option::bool('io_mode', false),
            Option::bool('io_graph_combined', false),
            Option::string('io_graph_speeds', '', validator: self::validateIoSpeeds(...)),
            Option::bool('swap_upload_download', false),
            Option::int('net_download', 100),
            Option::int('net_upload', 100),
            Option::bool('net_auto', true),
            Option::bool('net_sync', true),
            Option::string('net_iface', ''),
            Option::string('base_10_bitrate', 'Auto', self::BASE_10_BITRATE_VALUES),
            Option::bool('net_hide_ip', false),
            Option::bool('show_battery', true),
            Option::string('selected_battery', 'Auto'),
            Option::bool('show_battery_watts', true),
            Option::string('log_level', 'WARNING', self::LOG_LEVELS, 'config.warn.invalid_log_level'),
            Option::bool('save_config_on_exit', true),
            Option::bool('nvml_measure_pcie_speeds', true),
            Option::bool('rsmi_measure_pcie_speeds', true),
            Option::bool('gpu_mirror_graph', true),
            Option::string('shown_gpus', 'nvidia amd intel apple'),
            Option::string('custom_gpu_name0', ''),
            Option::string('custom_gpu_name1', ''),
            Option::string('custom_gpu_name2', ''),
            Option::string('custom_gpu_name3', ''),
            Option::string('custom_gpu_name4', ''),
            Option::string('custom_gpu_name5', ''),

            // Runtime state btop keeps in the same maps but outside
            // `descriptions`: never loaded, never written.
            Option::bool('tty_mode', false, persisted: false),
            // btop Term::current_tty.starts_with("/dev/tty"): the session runs
            // on a real console (set by withTtyModeResolved()); the options
            // menu's force_tty toggle leaves tty_mode alone there.
            Option::bool('tty_console', false, persisted: false),
            Option::bool('lowcolor', false, persisted: false),
            Option::string('proc_filter', '', persisted: false),
            Option::bool('proc_filtering', false, persisted: false),
            Option::bool('show_detailed', false, persisted: false),
            Option::bool('pause_proc_list', false, persisted: false),
            Option::bool('follow_process', false, persisted: false),
        ];

        $byName = [];
        foreach ($list as $option) {
            $byName[$option->name] = $option;
        }

        return $byName;
    }

    /**
     * btop stringValid("shown_boxes") minus the terminal-size check, which
     * needs a live terminal and belongs to the app. Applies to menu/`with()`
     * edits only; the file loader skips it like btop does.
     */
    private static function validateBoxes(string $value): void
    {
        $boxes = array_filter(explode(' ', $value), static fn (string $b): bool => $b !== '');
        if ($boxes === []) {
            throw new InvalidOptionValue(Lang::t('config.warn.no_boxes'), 'shown_boxes');
        }
        foreach ($boxes as $box) {
            if (!\in_array($box, self::BOXES, true)) {
                throw new InvalidOptionValue(Lang::t('config.warn.invalid_boxes'), 'shown_boxes');
            }
        }
    }

    /** btop stringValid("cpu_core_map"): space-separated `int:int` pairs. */
    private static function validateCoreMap(string $value): void
    {
        foreach (array_filter(explode(' ', $value), static fn (string $m): bool => $m !== '') as $map) {
            $parts = array_values(array_filter(explode(':', $map), static fn (string $p): bool => $p !== ''));
            if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                throw new InvalidOptionValue(Lang::t('config.warn.invalid_core_map'), 'cpu_core_map');
            }
        }
    }

    /** btop stringValid("io_graph_speeds"): space-separated `mountpoint:int` pairs. */
    private static function validateIoSpeeds(string $value): void
    {
        foreach (array_filter(explode(' ', $value), static fn (string $m): bool => $m !== '') as $map) {
            $parts = array_values(array_filter(explode(':', $map), static fn (string $p): bool => $p !== ''));
            if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
                throw new InvalidOptionValue(Lang::t('config.warn.invalid_io_speeds'), 'io_graph_speeds');
            }
        }
    }
}
