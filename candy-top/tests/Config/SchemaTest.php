<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Option;
use SugarCraft\Top\Config\OptionType;
use SugarCraft\Top\Config\Schema;

/**
 * Pins the roster and defaults against aristocratos/btop
 * src/btop_config.cpp v1.4.7 (`descriptions`, `bools`, `ints`, `strings`).
 */
final class SchemaTest extends TestCase
{
    /**
     * btop's persisted key order (descriptions, Linux + GPU_SUPPORT) with
     * the default from the typed maps.
     *
     * @return array<string, array{string, bool|int|string}>
     */
    public static function btopDefaults(): array
    {
        $rows = [
            ['color_theme', 'Default'], ['theme_background', true], ['truecolor', true], ['force_tty', false],
            ['disable_presets', 'Off'],
            ['presets', 'cpu:1:default,proc:0:default cpu:0:default,mem:0:default,net:0:default cpu:0:block,net:0:tty'],
            ['vim_keys', false], ['disable_mouse', false], ['rounded_corners', true], ['terminal_sync', true],
            ['graph_symbol', 'braille'], ['graph_symbol_cpu', 'default'], ['graph_symbol_gpu', 'default'],
            ['graph_symbol_mem', 'default'], ['graph_symbol_net', 'default'], ['graph_symbol_proc', 'default'],
            ['shown_boxes', 'cpu mem net proc'], ['update_ms', 2000], ['proc_sorting', 'cpu lazy'],
            ['proc_reversed', false], ['proc_tree', false], ['proc_colors', true], ['proc_gradient', true],
            ['proc_per_core', false], ['proc_mem_bytes', true], ['proc_cpu_graphs', true], ['proc_info_smaps', false],
            ['proc_left', false], ['proc_filter_kernel', false], ['proc_follow_detailed', true],
            ['proc_aggregate', false], ['proc_tree_auto_collapse', 0], ['keep_dead_proc_usage', false],
            ['cpu_graph_upper', 'Auto'], ['cpu_graph_lower', 'Auto'], ['show_gpu_info', 'Auto'],
            ['cpu_invert_lower', true], ['cpu_single_graph', false], ['cpu_bottom', false], ['show_uptime', true],
            ['show_cpu_watts', true], ['check_temp', true], ['cpu_sensor', 'Auto'], ['show_coretemp', true],
            ['cpu_core_map', ''], ['temp_scale', 'celsius'], ['base_10_sizes', false], ['show_cpu_freq', true],
            ['freq_mode', 'first'], ['clock_format', '%X'], ['background_update', true], ['custom_cpu_name', ''],
            ['disks_filter', ''], ['mem_graphs', true], ['mem_below_net', false], ['zfs_arc_cached', true],
            ['show_swap', true], ['swap_disk', true], ['show_disks', true], ['only_physical', true],
            ['use_fstab', true], ['zfs_hide_datasets', false], ['disk_free_priv', false], ['show_io_stat', true],
            ['io_mode', false], ['io_graph_combined', false], ['io_graph_speeds', ''],
            ['swap_upload_download', false], ['net_download', 100], ['net_upload', 100], ['net_auto', true],
            ['net_sync', true], ['net_iface', ''], ['base_10_bitrate', 'Auto'], ['show_battery', true],
            ['selected_battery', 'Auto'], ['show_battery_watts', true], ['log_level', 'WARNING'],
            ['save_config_on_exit', true], ['nvml_measure_pcie_speeds', true], ['rsmi_measure_pcie_speeds', true],
            ['gpu_mirror_graph', true], ['shown_gpus', 'nvidia amd intel apple'],
            ['custom_gpu_name0', ''], ['custom_gpu_name1', ''], ['custom_gpu_name2', ''],
            ['custom_gpu_name3', ''], ['custom_gpu_name4', ''], ['custom_gpu_name5', ''],
        ];
        $out = [];
        foreach ($rows as $row) {
            $out[$row[0]] = $row;
        }

        return $out;
    }

    #[DataProvider('btopDefaults')]
    public function testDefaultMatchesBtop(string $name, bool|int|string $default): void
    {
        $option = Schema::option($name);
        $this->assertNotNull($option, $name);
        $this->assertSame($default, $option->default);
        $this->assertTrue($option->persisted);
        $this->assertSame(match (true) {
            \is_bool($default) => OptionType::Bool,
            \is_int($default) => OptionType::Int,
            default => OptionType::String,
        }, $option->type);
    }

    public function testPersistedNamesAreBtopWriteOrder(): void
    {
        $this->assertSame(array_keys(self::btopDefaults()), Schema::persistedNames());
    }

    public function testRuntimeKeysExistButAreNotPersisted(): void
    {
        foreach (['tty_mode', 'lowcolor', 'proc_filter', 'proc_filtering', 'show_detailed', 'pause_proc_list', 'follow_process'] as $name) {
            $option = Schema::option($name);
            $this->assertInstanceOf(Option::class, $option, $name);
            $this->assertFalse($option->persisted, $name);
            $this->assertNotContains($name, Schema::persistedNames());
        }
        $this->assertSame('', Schema::defaults()['proc_filter']);
        $this->assertFalse(Schema::defaults()['tty_mode']);
    }

    public function testUnknownOptionIsNull(): void
    {
        $this->assertNull(Schema::option('no_such_key'));
    }

    public function testOptionsAndDefaultsShareKeys(): void
    {
        $this->assertSame(array_keys(Schema::options()), array_keys(Schema::defaults()));
        $this->assertSame(Schema::options(), Schema::options(), 'roster is memoised');
    }

    public function testEnumerationsMatchBtop(): void
    {
        $this->assertSame(['braille', 'block', 'tty'], Schema::GRAPH_SYMBOLS);
        $this->assertSame(['default', 'braille', 'block', 'tty'], Schema::GRAPH_SYMBOLS_DEF);
        $this->assertSame(['celsius', 'fahrenheit', 'kelvin', 'rankine'], Schema::TEMP_SCALES);
        $this->assertSame(['first', 'range', 'lowest', 'highest', 'average'], Schema::FREQ_MODES);
        $this->assertSame(['pid', 'name', 'command', 'threads', 'user', 'memory', 'cpu direct', 'cpu lazy'], Schema::PROC_SORTING);
        $this->assertSame(['Off', 'Default', 'Custom', 'All'], Schema::DISABLE_PRESET_OPTIONS);
        $this->assertSame(['DISABLED', 'ERROR', 'WARNING', 'INFO', 'DEBUG'], Schema::LOG_LEVELS);
        $this->assertSame(86_400_000, Schema::ONE_DAY_MILLIS);
        $this->assertContains('total', Schema::CPU_GRAPH_FIELDS);
    }

    public function testUpdateMsRangeIsBtops(): void
    {
        $option = Schema::option('update_ms');
        $this->assertSame(100, $option?->min);
        $this->assertSame(86_400_000, $option?->max);
        $collapse = Schema::option('proc_tree_auto_collapse');
        $this->assertSame(0, $collapse?->min);
        $this->assertSame(10000, $collapse?->max);
    }

    public function testEveryPersistedOptionButNetUploadHasADescription(): void
    {
        foreach (Schema::persistedNames() as $name) {
            $description = Schema::option($name)?->description();
            if ($name === 'net_upload') {
                $this->assertSame('', $description);
                continue;
            }
            $this->assertNotSame('', $description, $name);
            $this->assertStringNotContainsString('top.config.desc', (string) $description);
        }
    }
}
