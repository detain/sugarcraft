<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Presets;
use SugarCraft\Top\Config\Schema;

final class ConfigTest extends TestCase
{
    public function testNewHoldsBtopDefaults(): void
    {
        $config = Config::new();
        $this->assertSame(Schema::defaults(), $config->toArray());
        $this->assertSame(2000, $config->updateMs());
        $this->assertSame('pastel', $config->colorTheme(), 'candy-top default (btop: Default)');
        $this->assertSame('braille', $config->graphSymbol());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $config->shownBoxes());
        $this->assertSame('cpu lazy', $config->procSorting());
        $this->assertSame('celsius', $config->tempScale());
        $this->assertSame('%X', $config->clockFormat());
        $this->assertFalse($config->ttyMode());
        $this->assertTrue($config->roundedCorners());
        $this->assertSame(4, $config->presets()->count());
    }

    public function testTypedGetters(): void
    {
        $config = Config::new();
        $this->assertTrue($config->has('vim_keys'));
        $this->assertFalse($config->has('nope'));
        $this->assertFalse($config->bool('vim_keys'));
        $this->assertSame(100, $config->int('net_download'));
        $this->assertSame('WARNING', $config->string('log_level'));
        $this->assertSame(2000, $config->value('update_ms'));
    }

    public function testGetterTypeMismatchThrows(): void
    {
        $this->expectException(InvalidOptionValue::class);
        Config::new()->bool('update_ms');
    }

    public function testIntAndStringGetterMismatchThrow(): void
    {
        try {
            Config::new()->int('vim_keys');
            $this->fail('int() on a bool');
        } catch (InvalidOptionValue) {
        }
        $this->expectException(InvalidOptionValue::class);
        Config::new()->string('update_ms');
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectExceptionMessage('Unknown config option: nope');
        Config::new()->value('nope');
    }

    public function testWithIsImmutableAndValidated(): void
    {
        $base = Config::new();
        $next = $base->with('vim_keys', true)->with('temp_scale', 'kelvin');
        $this->assertFalse($base->bool('vim_keys'));
        $this->assertTrue($next->bool('vim_keys'));
        $this->assertSame('kelvin', $next->tempScale());

        $this->expectException(InvalidOptionValue::class);
        $base->with('temp_scale', 'Kelvin');
    }

    public function testWithUnknownKeyThrows(): void
    {
        $this->expectExceptionMessage('Unknown config option: bogus');
        Config::new()->with('bogus', true);
    }

    public function testWithParsedUsesFileSpelling(): void
    {
        $config = Config::new()->withParsed('proc_tree', 'True')->withParsed('update_ms', '500');
        $this->assertTrue($config->bool('proc_tree'));
        $this->assertSame(500, $config->updateMs());

        $this->expectExceptionMessage('Config value update_ms set too low (<100).');
        $config->withParsed('update_ms', '50');
    }

    public function testWithParsedUnknownKeyThrows(): void
    {
        $this->expectException(InvalidOptionValue::class);
        Config::new()->withParsed('bogus', '1');
    }

    public function testFlipped(): void
    {
        $this->assertTrue(Config::new()->flipped('io_mode')->bool('io_mode'));
        $this->assertFalse(Config::new()->flipped('net_auto')->bool('net_auto'));
    }

    public function testWithUpdateMsClampsLikeThePlusMinusKeys(): void
    {
        $this->assertSame(100, Config::new()->withUpdateMs(0)->updateMs());
        $this->assertSame(86_400_000, Config::new()->withUpdateMs(PHP_INT_MAX)->updateMs());
        $this->assertSame(2100, Config::new()->withUpdateMs(2100)->updateMs());
    }

    public function testWithPresetAppliesPlacementSymbolsAndBoxes(): void
    {
        $preset = Presets::parse('cpu:1:block,mem:1:tty,proc:1:default,gpu0:0:braille')->at(1);
        $config = Config::new()->withPreset($preset);

        $this->assertTrue($config->bool('cpu_bottom'));
        $this->assertTrue($config->bool('mem_below_net'));
        $this->assertTrue($config->bool('proc_left'));
        $this->assertSame('block', $config->string('graph_symbol_cpu'));
        $this->assertSame('tty', $config->string('graph_symbol_mem'));
        $this->assertSame('default', $config->string('graph_symbol_proc'));
        $this->assertSame('braille', $config->string('graph_symbol_gpu'));
        $this->assertSame(['cpu', 'mem', 'proc', 'gpu0'], $config->shownBoxes());
    }

    public function testWithPresetPositionZeroResetsPlacement(): void
    {
        $config = Config::new()->with('cpu_bottom', true)->withPreset(Presets::new()->at(0));
        $this->assertFalse($config->bool('cpu_bottom'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $config->shownBoxes());
        $this->assertSame('default', $config->string('graph_symbol_net'));
    }

    public function testWithPresetAppliesProcWidthOrResetsItToDefault(): void
    {
        $presets = Presets::parse('cpu:0:default,proc:0:default:80 proc:1:tty:default net:0:default proc:0:default:300');
        $nudged = Config::new()->withProcBoxWidthPercent(30);

        $this->assertSame(80, $nudged->withPreset($presets->at(1))->procBoxWidthPercent());
        $this->assertSame(55, $nudged->withPreset($presets->at(2))->procBoxWidthPercent(), '`default` W');
        $this->assertSame(30, $nudged->withPreset($presets->at(3))->procBoxWidthPercent(), 'no proc box → untouched');
        $this->assertSame(100, $nudged->withPreset($presets->at(4))->procBoxWidthPercent(), 'W clamped like btop');
        $this->assertSame(55, $nudged->withPreset($presets->at(0))->procBoxWidthPercent(), '3-field proc → default, as btop PR #1476');
    }

    public function testWithProcBoxWidthPercentClampsLikeTheShiftArrowKeys(): void
    {
        $this->assertSame(55, Config::new()->procBoxWidthPercent());
        $this->assertSame(0, Config::new()->withProcBoxWidthPercent(-10)->procBoxWidthPercent());
        $this->assertSame(100, Config::new()->withProcBoxWidthPercent(110)->procBoxWidthPercent());
        $this->assertSame(65, Config::new()->withProcBoxWidthPercent(65)->procBoxWidthPercent());
    }

    public function testWithProcBoxWidthPercentOutOfRangeViaWithThrows(): void
    {
        $this->expectException(InvalidOptionValue::class);
        Config::new()->with('proc_box_width_percent', 101);
    }

    public function testShowCoreFreqAccessor(): void
    {
        $this->assertSame('off', Config::new()->showCoreFreq());
        $this->assertSame('graph', Config::new()->with('show_core_freq', 'graph')->showCoreFreq());
        $this->expectException(InvalidOptionValue::class);
        Config::new()->with('show_core_freq', 'on');
    }

    public function testMemSelectedAccessor(): void
    {
        $this->assertSame('default', Config::new()->memSelected());
        $this->assertSame('swap_used', Config::new()->withParsed('mem_selected', 'swap_used')->memSelected());
        $this->expectException(InvalidOptionValue::class);
        Config::new()->with('mem_selected', 'swap');
    }

    public function testDisksOrderParsesWhitespaceListAndDropsDuplicates(): void
    {
        $this->assertSame([], Config::new()->disksOrder());
        $config = Config::new()->with('disks_order', "  / swap\t/home  / /mnt/data ");
        $this->assertSame(['/', 'swap', '/home', '/mnt/data'], $config->disksOrder());
    }

    public function testWaveUBoolDefaults(): void
    {
        $config = Config::new();
        $this->assertTrue($config->bool('show_zswap'));
        $this->assertFalse($config->bool('net_hide_ip'));
        $this->assertFalse($config->bool('proc_command_basename'));
        $this->assertFalse($config->bool('proc_filter_containers'));
        $this->assertTrue($config->flipped('proc_filter_containers')->bool('proc_filter_containers'), 'O key toggles it');
    }

    public function testBlock2GraphSymbolResolvesButTtyModeStillForcesTty(): void
    {
        $config = Config::new()->with('graph_symbol', 'block2')->with('graph_symbol_net', 'block2');
        $this->assertSame('block2', $config->graphSymbol());
        $this->assertSame('block2', $config->graphSymbolFor('cpu'));
        $this->assertSame('block2', $config->graphSymbolFor('net'));
        $tty = $config->with('tty_mode', true);
        foreach (['cpu', 'mem', 'net', 'proc', 'gpu0'] as $box) {
            $this->assertSame('tty', $tty->graphSymbolFor($box), $box);
        }
        $forced = $config->with('force_tty', true)->withTtyModeResolved(null, false);
        $this->assertSame('tty', $forced->graphSymbolFor('net'), 'force_tty → tty_mode → tty');
    }

    public function testGraphSymbolForResolvesDefaultAndOverrides(): void
    {
        $config = Config::new()->with('graph_symbol', 'block')->with('graph_symbol_net', 'tty');
        $this->assertSame('block', $config->graphSymbolFor('cpu'));
        $this->assertSame('tty', $config->graphSymbolFor('net'));
        $this->assertSame('block', $config->graphSymbolFor('gpu3'));
        $this->assertSame('block', $config->graphSymbolFor('unknown'));
        $config = $config->with('graph_symbol_gpu', 'braille');
        $this->assertSame('braille', $config->graphSymbolFor('gpu0'));
    }

    public function testTtyModeForcesTtySymbolsAndSquareCorners(): void
    {
        $config = Config::new()->with('graph_symbol_cpu', 'braille')->with('tty_mode', true);
        $this->assertTrue($config->ttyMode());
        $this->assertSame('tty', $config->graphSymbolFor('cpu'));
        $this->assertFalse($config->roundedCorners());
    }

    public function testWithTtyModeResolvedFollowsBtopPrecedence(): void
    {
        $base = Config::new();
        $this->assertFalse($base->withTtyModeResolved(null, false)->ttyMode());
        $this->assertTrue($base->withTtyModeResolved(null, true)->ttyMode(), 'real /dev/tty auto-detect');
        $this->assertTrue($base->withTtyModeResolved(false, true)->bool('tty_console'), 'the console fact is kept even when the CLI turns tty mode off');
        $this->assertFalse($base->withTtyModeResolved(true, false)->bool('tty_console'));
        $forced = $base->with('force_tty', true);
        $this->assertTrue($forced->withTtyModeResolved(null, false)->ttyMode(), 'config force_tty');
        $this->assertFalse($forced->withTtyModeResolved(false, true)->ttyMode(), 'CLI wins');
        $this->assertTrue($base->withTtyModeResolved(true, false)->ttyMode());
    }

    public function testWithShownBoxesSettledMirrorsBtopPostLoadCheck(): void
    {
        $loaded = Config::new()->withParsed('shown_boxes', 'cpu gpu1 proc', true);
        $this->assertSame(['cpu', 'gpu1', 'proc'], $loaded->withShownBoxesSettled(2)->shownBoxes());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $loaded->withShownBoxesSettled(1)->shownBoxes(), 'gpu1 needs 2 GPUs');
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $loaded->withShownBoxesSettled(0)->shownBoxes());

        $bogus = Config::new()->withParsed('shown_boxes', 'cpu disk', true);
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $bogus->withShownBoxesSettled(0)->shownBoxes());

        $empty = Config::new()->withParsed('shown_boxes', '', true);
        $this->assertSame([], $empty->withShownBoxesSettled(0)->shownBoxes(), 'empty is kept, as btop');

        $fine = Config::new();
        $this->assertSame($fine, $fine->withShownBoxesSettled(0), 'no change → same instance');
    }

    public function testShownBoxesSkipsRepeatedSpaces(): void
    {
        $this->assertSame(['cpu', 'proc'], Config::new()->with('shown_boxes', ' cpu   proc ')->shownBoxes());
    }
}
