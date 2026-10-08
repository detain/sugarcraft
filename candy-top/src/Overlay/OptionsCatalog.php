<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Top\Config\OptionType;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Lang;

/**
 * The options menu's roster: btop's `Menu::categories` table — which
 * option sits in which tab, in what order, under which section heading —
 * and each option's menu description.
 *
 * Mirrors aristocratos/btop src/btop_menu.cpp `categories` with the
 * adopted upstream PRs applied:
 *  - #1411: tabs in box-toggle order — 0 general, 1 cpu, 2 mem, 3 net,
 *    4 proc, 5 gpu — so a digit picks the same box everywhere. The gpu tab
 *    only shows once a GPU answered (btop shows it in every GPU build).
 *  - #1791b: non-selectable section headings (`@slug` entries) and its
 *    regrouping — the box placements (cpu_bottom, mem_below_net,
 *    proc_left) and per-box graph symbols move to general.
 *  - #1476 / #1739 / #1785 / #1573 / #1859 / #1873 / #1747 / #1700 add
 *    their options at the place each PR puts them (proc_box_width_percent
 *    joins the general Layout group next to proc_left, where #1791b put
 *    the other placements).
 *  - candy-top: every persisted Schema option is listed — btop leaves
 *    proc_info_smaps out of the menu; it closes the proc tab here.
 *
 * Descriptions are Lang keys `options.desc.<option>` (first line = btop's
 * bold summary line); headings `options.heading.<slug>`.
 */
final class OptionsCatalog
{
    /** #1411 tab order = box toggle digits. */
    public const CATEGORIES = ['general', 'cpu', 'mem', 'net', 'proc', 'gpu'];

    /**
     * Options candy-top reads only at startup (the collector is built
     * once): the description says a restart is needed, as btop's
     * shown_gpus entry does.
     */
    public const RESTART = ['freq_mode', 'cpu_sensor', 'zfs_arc_cached'];

    /**
     * Options kept for btop.conf compatibility that candy-top does not act
     * on (yet): the description says so.
     */
    public const UNUSED = [
        'terminal_sync', 'log_level', 'show_cpu_watts', 'disk_free_priv', 'keep_dead_proc_usage',
        'proc_info_smaps', 'nvml_measure_pcie_speeds', 'rsmi_measure_pcie_speeds',
    ];

    private const TABLE = [
        'general' => [
            '@appearance', 'color_theme', 'theme_background', 'truecolor',
            '@terminal', 'force_tty', 'vim_keys', 'disable_mouse',
            '@layout', 'disable_presets', 'presets', 'shown_boxes', 'cpu_bottom', 'mem_below_net', 'proc_left', 'proc_box_width_percent',
            '@runtime', 'update_ms', 'rounded_corners', 'terminal_sync',
            '@glyphs', 'graph_symbol', 'graph_symbol_cpu', 'graph_symbol_mem', 'graph_symbol_net', 'graph_symbol_proc', 'graph_symbol_gpu',
            '@status', 'clock_format', 'base_10_sizes', 'background_update', 'show_battery', 'selected_battery', 'show_battery_watts',
            '@diagnostics', 'log_level', 'save_config_on_exit',
        ],
        'cpu' => [
            '@cpu_graphs', 'cpu_graph_upper', 'cpu_graph_lower', 'cpu_invert_lower', 'cpu_single_graph', 'show_gpu_info',
            '@sensors', 'check_temp', 'cpu_sensor', 'show_coretemp', 'cpu_core_map', 'temp_scale',
            '@cpu_status', 'show_cpu_freq', 'freq_mode', 'show_core_freq', 'custom_cpu_name', 'show_uptime', 'show_cpu_watts',
        ],
        'mem' => [
            '@memory', 'mem_graphs', 'mem_selected', 'show_disks',
            '@io', 'show_io_stat', 'io_mode', 'io_graph_combined', 'io_graph_speeds', 'show_swap', 'show_zswap', 'swap_disk',
            '@disks', 'only_physical', 'use_fstab', 'zfs_hide_datasets', 'disk_free_priv', 'disks_filter', 'disks_order', 'zfs_arc_cached',
        ],
        'net' => [
            '@net_graphs', 'swap_upload_download', 'net_download', 'net_upload', 'net_auto', 'net_sync',
            '@interface', 'net_iface', 'base_10_bitrate', 'net_hide_ip',
        ],
        'proc' => [
            '@order', 'proc_sorting', 'proc_reversed', 'proc_tree', 'proc_aggregate', 'proc_tree_auto_collapse', 'proc_tree_persist_state',
            '@rows', 'proc_colors', 'proc_gradient', 'proc_per_core', 'proc_mem_bytes', 'keep_dead_proc_usage', 'proc_cpu_graphs',
            'proc_gpu_graphs', 'proc_gpu_only',
            'proc_filter_kernel', 'proc_filter_containers', 'ctr_show_vms', 'vms_sorting', 'proc_command_basename', 'proc_follow_detailed', 'proc_info_smaps',
        ],
        'gpu' => [
            '@telemetry', 'nvml_measure_pcie_speeds', 'rsmi_measure_pcie_speeds',
            '@gpu_display', 'gpu_mirror_graph', 'gpu_box_columns', 'shown_gpus',
            '@names', 'custom_gpu_name0', 'custom_gpu_name1', 'custom_gpu_name2', 'custom_gpu_name3', 'custom_gpu_name4', 'custom_gpu_name5',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * The tabs shown: every category, gpu only with a GPU (#1411 order).
     *
     * @return list<string>
     */
    public static function categories(bool $gpu): array
    {
        return $gpu ? self::CATEGORIES : array_values(array_diff(self::CATEGORIES, ['gpu']));
    }

    /**
     * The entries of `$category` — option names and `@slug` headings.
     * graph_symbol_gpu (#1791b: general) only shows with a GPU, like btop's
     * GPU_SUPPORT guard.
     *
     * @return list<string>
     */
    public static function entries(string $category, bool $gpu): array
    {
        $entries = self::TABLE[$category] ?? [];

        return $gpu ? $entries : array_values(array_diff($entries, ['graph_symbol_gpu']));
    }

    /** #1791b `is_section_header`. */
    public static function isHeading(string $entry): bool
    {
        return str_starts_with($entry, '@');
    }

    /** The heading text (btop draws `substr(1)` of "@ Name", so with its leading space). */
    public static function heading(string $entry): string
    {
        return ' ' . Lang::t('options.heading.' . substr($entry, 1));
    }

    /**
     * The menu description of `$option`: btop's summary line first, then
     * the body — plus a restart / not-used note for the options
     * {@see RESTART} / {@see UNUSED} list.
     *
     * @return list<string>
     */
    public static function description(string $option): array
    {
        $lines = explode("\n", Lang::t('options.desc.' . $option));
        if (in_array($option, self::RESTART, true)) {
            $lines = [...$lines, '', Lang::t('options.note.restart')];
        } elseif (in_array($option, self::UNUSED, true)) {
            $lines = [...$lines, '', Lang::t('options.note.unused')];
        }

        return $lines;
    }

    /** btop `capitalize(s_replace(option, "_", " "))`. */
    public static function label(string $option): string
    {
        return ucfirst(str_replace('_', ' ', $option));
    }

    /** The largest tab (btop `max_items`, headings included) — sizes the menu box. */
    public static function maxItems(bool $gpu): int
    {
        $max = 0;
        foreach (self::categories($gpu) as $cat) {
            $max = max($max, count(self::entries($cat, $gpu)));
        }

        return $max;
    }

    /** True for an int option (btop isInt: `←`/`→` step and `↵` edits). */
    public static function isInt(string $option): bool
    {
        return Schema::option($option)?->type === OptionType::Int;
    }

    /** True for a bool option (btop isBool: `←`/`→` flip). */
    public static function isBool(string $option): bool
    {
        return Schema::option($option)?->type === OptionType::Bool;
    }
}
