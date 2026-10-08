<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Preset;
use SugarCraft\Top\Config\PresetBox;
use SugarCraft\Top\Config\Presets;
use SugarCraft\Top\Config\Schema;

/** btop Config::presetsValid + preset_list + the p/P cycle (btop_input.cpp). */
final class PresetsTest extends TestCase
{
    public function testBtopDefaultPresetsParse(): void
    {
        $presets = Presets::parse((string) Schema::option('presets')?->default);

        $this->assertSame(4, $presets->count());
        $this->assertSame(Presets::BUILTIN, $presets->at(0)->toString());
        $this->assertSame('cpu:1:default,proc:0:default', $presets->at(1)->toString());
        $this->assertSame(['cpu', 'mem', 'net'], $presets->at(2)->boxNames());

        $third = $presets->at(3)->boxes;
        $this->assertEquals([new PresetBox('cpu', false, 'block'), new PresetBox('net', false, 'tty')], $third);
        $this->assertTrue($presets->at(1)->boxes[0]->alternate);
    }

    public function testNewIsBuiltinOnly(): void
    {
        $presets = Presets::new();
        $this->assertSame(1, $presets->count());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $presets->at(0)->boxNames());
        $this->assertSame('', $presets->toString());
        $this->assertCount(1, $presets->all());
    }

    public function testEmptyFieldsCollapseLikeSsplit(): void
    {
        $presets = Presets::parse('  cpu:0:tty,,proc:1:braille,   mem:0:default ');
        $this->assertSame(3, $presets->count());
        $this->assertSame('cpu:0:tty,proc:1:braille', $presets->at(1)->toString(), 'empty box field dropped');
        $this->assertSame('mem:0:default', $presets->at(2)->toString(), 'space run separates presets');
    }

    public function testToStringRoundTrips(): void
    {
        $raw = 'cpu:1:default,proc:0:default cpu:0:block,net:0:tty';
        $this->assertSame($raw, Presets::parse($raw)->toString());
    }

    public function testNineCustomPresetsAllowedTenRejected(): void
    {
        $nine = implode(' ', array_fill(0, 9, 'cpu:0:default'));
        $this->assertSame(10, Presets::parse($nine)->count());

        $this->expectExceptionMessage('Too many presets entered!');
        Presets::parse($nine . ' mem:0:default');
    }

    /** @return array<string, array{string, string}> */
    public static function invalid(): array
    {
        return [
            'five boxes' => ['cpu:0:default,mem:0:default,net:0:default,proc:0:default,gpu0:0:default', 'Too many boxes entered for preset!'],
            'two fields' => ['cpu:0', 'Malformatted preset in config value presets!'],
            'empty P collapses' => ['cpu::default', 'Malformatted preset in config value presets!'],
            'four fields' => ['cpu:0:tty:x', 'Malformatted preset in config value presets!'],
            'box' => ['disk:0:default', 'Invalid box name in config value presets!'],
            'gpu6' => ['gpu6:0:default', 'Invalid box name in config value presets!'],
            'position' => ['cpu:2:default', 'Invalid position value in config value presets!'],
            'graph' => ['cpu:0:dots', 'Invalid graph name in config value presets!'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidUsesBtopWording(string $raw, string $message): void
    {
        try {
            Presets::parse($raw);
            $this->fail('expected InvalidOptionValue');
        } catch (InvalidOptionValue $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame('presets', $e->option);
        }
    }

    public function testAtOutOfRangeThrows(): void
    {
        $this->expectException(\OutOfRangeException::class);
        Presets::new()->at(1);
    }

    public function testPresetValueObjects(): void
    {
        $box = new PresetBox('gpu0', true, 'braille');
        $this->assertSame('gpu0:1:braille', $box->toString());
        $preset = new Preset([$box, new PresetBox('proc', false, 'default')]);
        $this->assertSame(['gpu0', 'proc'], $preset->boxNames());
        $this->assertSame('gpu0:1:braille,proc:0:default', $preset->toString());
    }

    /**
     * @return array<string, array{?int, bool, string, ?int}>
     */
    public static function cycles(): array
    {
        // 4 presets (0..3), btop default list.
        return [
            'first p from none' => [null, true, 'Off', 0],
            'first P from none' => [null, false, 'Off', 3],
            'forward' => [1, true, 'Off', 2],
            'forward wraps to 0' => [3, true, 'Off', 0],
            'backward wraps to last' => [0, false, 'Off', 3],
            'Default skips 0 on wrap' => [3, true, 'Default', 1],
            'Default first p' => [null, true, 'Default', 1],
            'Default backward floor' => [1, false, 'Default', 3],
            'Custom pins 0' => [2, true, 'Custom', 0],
            'Custom already 0' => [0, true, 'Custom', null],
            'All swallows' => [1, true, 'All', null],
        ];
    }

    #[DataProvider('cycles')]
    public function testCycleMirrorsBtopInput(?int $current, bool $forward, string $disable, ?int $expected): void
    {
        $presets = Presets::parse((string) Schema::option('presets')?->default);
        $this->assertSame($expected, $presets->cycle($current, $forward, $disable));
    }

    public function testCycleDefaultWithOnlyBuiltinDoesNothing(): void
    {
        $this->assertNull(Presets::new()->cycle(null, true, 'Default'));
        $this->assertNull(Presets::new()->cycle(0, true, 'Off'), 'single preset: 0 → 0 is no change');
    }
}
