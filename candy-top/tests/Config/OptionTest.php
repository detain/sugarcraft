<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Option;
use SugarCraft\Top\Config\Schema;

/**
 * Value law per btop Config::load (isbool/isint gates), intValid and
 * stringValid — including btop's exact warning wording.
 */
final class OptionTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function boolTokens(): array
    {
        return ['true' => ['true', true], 'True' => ['True', true], 'false' => ['false', false], 'False' => ['False', false]];
    }

    #[DataProvider('boolTokens')]
    public function testBoolAcceptsBtopSpellings(string $raw, bool $expected): void
    {
        $this->assertSame($expected, Schema::option('vim_keys')?->parse($raw));
    }

    /** @return array<string, array{string}> */
    public static function badBoolTokens(): array
    {
        return ['TRUE' => ['TRUE'], 'yes' => ['yes'], '1' => ['1'], 'empty' => [''], 'quoted' => ['"true"']];
    }

    #[DataProvider('badBoolTokens')]
    public function testBoolRejectsEverythingElse(string $raw): void
    {
        $this->expectExceptionMessage('Got an invalid bool value for config name: vim_keys');
        Schema::option('vim_keys')?->parse($raw);
    }

    public function testIntParsesDigits(): void
    {
        $this->assertSame(1500, Schema::option('update_ms')?->parse('1500'));
        $this->assertSame(100, Schema::option('update_ms')?->parse('0100'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function badInts(): array
    {
        return [
            'negative' => ['update_ms', '-5', 'Got an invalid integer value for config name: update_ms'],
            'alpha' => ['update_ms', 'fast', 'Got an invalid integer value for config name: update_ms'],
            'quoted' => ['update_ms', '"2000"', 'Got an invalid integer value for config name: update_ms'],
            'empty' => ['update_ms', '', 'Invalid numerical value!'],
            'overflow' => ['net_download', '99999999999', 'Value out of range!'],
            'just over int32' => ['net_download', '2147483648', 'Value out of range!'],
            'too low' => ['update_ms', '99', 'Config value update_ms set too low (<100).'],
            'too high' => ['update_ms', '86400001', 'Config value update_ms set too high (>86400000).'],
            'collapse high' => ['proc_tree_auto_collapse', '10001', 'Config value proc_tree_auto_collapse set too high (>10000).'],
        ];
    }

    #[DataProvider('badInts')]
    public function testIntRejectsWithBtopWording(string $name, string $raw, string $message): void
    {
        try {
            Schema::option($name)?->parse($raw);
            $this->fail('expected InvalidOptionValue');
        } catch (InvalidOptionValue $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($name, $e->option);
        }
    }

    public function testIntBoundsAreInclusive(): void
    {
        $this->assertSame(100, Schema::option('update_ms')?->parse('100'));
        $this->assertSame(86_400_000, Schema::option('update_ms')?->parse('86400000'));
        $this->assertSame(2147483647, Schema::option('net_download')?->parse('2147483647'));
    }

    public function testCheckNegativeIntUsesBtopCollapseWording(): void
    {
        $this->expectExceptionMessage('Config value proc_tree_auto_collapse must be >= 0.');
        Schema::option('proc_tree_auto_collapse')?->check(-1);
    }

    public function testCheckRejectsTypeMismatch(): void
    {
        $this->expectExceptionMessage('Config value update_ms expects a value of type int.');
        Schema::option('update_ms')?->check('2000');
    }

    public function testCheckReturnsValidValue(): void
    {
        $this->assertTrue(Schema::option('vim_keys')?->check(true));
        $this->assertSame('tty', Schema::option('graph_symbol')?->check('tty'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function badStrings(): array
    {
        return [
            'graph_symbol' => ['graph_symbol', 'default', 'Invalid graph symbol identifier: default'],
            'graph_symbol_cpu' => ['graph_symbol_cpu', 'dots', 'Invalid graph symbol identifier for graph_symbol_cpu: dots'],
            'log_level' => ['log_level', 'TRACE', 'Invalid log_level: TRACE'],
            'temp_scale' => ['temp_scale', 'Celsius', 'Invalid value for temp_scale: Celsius'],
            'proc_sorting legacy' => ['proc_sorting', 'program', 'Invalid value for proc_sorting: program'],
            'freq_mode' => ['freq_mode', 'max', 'Invalid value for freq_mode: max'],
            'show_gpu_info' => ['show_gpu_info', 'on', 'Invalid value for show_gpu_info: on'],
            'base_10_bitrate' => ['base_10_bitrate', 'true', 'Invalid value for base_10_bitrate: true'],
            'disable_presets' => ['disable_presets', 'None', 'Invalid value for disable_presets: None'],
            'shown_boxes empty' => ['shown_boxes', '   ', 'No boxes selected!'],
            'shown_boxes bad' => ['shown_boxes', 'cpu disk', 'Invalid box name(s) in shown_boxes!'],
            'core map shape' => ['cpu_core_map', '4:0 5', 'Invalid formatting of cpu_core_map!'],
            'core map alpha' => ['cpu_core_map', 'a:1', 'Invalid formatting of cpu_core_map!'],
            'io speeds alpha' => ['io_graph_speeds', '/:fast', 'Invalid formatting of io_graph_speeds!'],
            'io speeds shape' => ['io_graph_speeds', '/mnt', 'Invalid formatting of io_graph_speeds!'],
            'presets' => ['presets', 'cpu:2:default', 'Invalid position value in config value presets!'],
            'quote' => ['clock_format', 'a"b', 'Config value clock_format cannot contain a double quote or a line break.'],
            'newline' => ['custom_cpu_name', "a\nb", 'Config value custom_cpu_name cannot contain a double quote or a line break.'],
        ];
    }

    #[DataProvider('badStrings')]
    public function testStringLaw(string $name, string $raw, string $message): void
    {
        $this->expectException(InvalidOptionValue::class);
        $this->expectExceptionMessage($message);
        Schema::option($name)?->parse($raw);
    }

    /** @return array<string, array{string, string}> */
    public static function goodStrings(): array
    {
        return [
            'shown_boxes gpu' => ['shown_boxes', 'cpu  gpu0 proc'],
            'core map' => ['cpu_core_map', '4:0 5:1 6:3'],
            'core map empty' => ['cpu_core_map', ''],
            'io speeds' => ['io_graph_speeds', '/mnt/media:100 /:20 /boot:1'],
            'free string' => ['clock_format', '%H:%M /host'],
            'cpu field unvalidated' => ['cpu_graph_upper', 'gpu-totals'],
            'proc_sorting' => ['proc_sorting', 'cpu direct'],
        ];
    }

    #[DataProvider('goodStrings')]
    public function testStringAccepts(string $name, string $raw): void
    {
        $this->assertSame($raw, Schema::option($name)?->parse($raw));
    }

    public function testShownBoxesLawSkippedOnlyWhenParsingFromFile(): void
    {
        $option = Schema::option('shown_boxes');
        $this->assertFalse($option?->validateOnLoad);
        $this->assertSame('', $option?->parse('', true));
        $this->assertSame('disk', $option?->parse('disk', true));
        $this->assertTrue(Schema::option('graph_symbol')?->validateOnLoad);

        $this->expectExceptionMessage('No boxes selected!');
        $option?->parse('');
    }

    public function testFromFileStillValidatesOtherOptions(): void
    {
        $this->expectExceptionMessage('Invalid graph symbol identifier: dots');
        Schema::option('graph_symbol')?->parse('dots', true);
    }

    public function testFactoriesCarryShape(): void
    {
        $bool = Option::bool('x', true, persisted: false);
        $this->assertFalse($bool->persisted);
        $int = Option::int('y', 5, 1, 9);
        $this->assertSame([1, 9], [$int->min, $int->max]);
        $string = Option::string('z', 'a', ['a', 'b']);
        $this->assertSame(['a', 'b'], $string->allowed);
        $this->assertSame('', $string->description(), 'no lang key → no description');
    }

    public function testHasValidatorIsTrueOnlyForStringsWithAnExtraLaw(): void
    {
        foreach (['presets', 'shown_boxes', 'cpu_core_map', 'io_graph_speeds'] as $name) {
            $this->assertTrue(Schema::option($name)?->hasValidator(), $name);
        }
        foreach (['color_theme', 'graph_symbol', 'update_ms', 'vim_keys'] as $name) {
            $this->assertFalse(Schema::option($name)?->hasValidator(), $name);
        }
        $this->assertTrue(Option::string('x', '', validator: static function (string $v): void {
        })->hasValidator());
        $this->assertFalse(Option::string('x', '')->hasValidator());
    }
}
