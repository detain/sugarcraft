<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Schema;

/**
 * btop PR #1730's gpu box slots, ported from its tests/gpu_boxes.cpp
 * (parse, set_boxes, presets, switch, toggle) plus the candy-top storage
 * of the runtime slots in `gpu_panel_slots`.
 */
final class GpuPanelsTest extends TestCase
{
    private static function boxes(string $boxes, string $slots = ''): Config
    {
        $config = Config::new()->with('shown_boxes', $boxes);

        return $slots === '' ? $config : $config->with(GpuPanels::SLOTS_KEY, $slots);
    }

    /** @param list<int> $slots */
    private function assertState(Config $config, string $boxes, array $slots): void
    {
        $this->assertSame($boxes, $config->string('shown_boxes'));
        $this->assertSame($slots, GpuPanels::slots($config));
    }

    public function testParseGpuBoxIndex(): void
    {
        $this->assertSame(0, GpuPanels::index('gpu0'));
        $this->assertSame(7, GpuPanels::index('gpu7'));
        $this->assertSame(10, GpuPanels::index('gpu10'));
        foreach (['gpu', 'gpu-1', 'gpu1x', 'cpu', 'gpu 1', 'GPU1', 'gpu1234567890'] as $bad) {
            $this->assertNull(GpuPanels::index($bad), $bad);
        }
        $this->assertSame('gpu12', GpuPanels::name(12));
        $this->assertTrue(GpuPanels::valid('gpu9'));
        $this->assertTrue(GpuPanels::valid('proc'));
        $this->assertFalse(GpuPanels::valid('disks'));
        $this->assertSame([7, 0], GpuPanels::targets(['cpu', 'gpu7', 'mem', 'gpu0']));
    }

    public function testSlotKeysAreFiveToZero(): void
    {
        $this->assertSame([0, 1, 2, 3, 4, 5], array_map(GpuPanels::slotFromKey(...), ['5', '6', '7', '8', '9', '0']));
        $this->assertNull(GpuPanels::slotFromKey('1'));
        $this->assertNull(GpuPanels::slotFromKey('a'));
        $this->assertSame([5, 6, 7, 8, 9, 0], array_map(GpuPanels::key(...), range(0, 5)));
    }

    public function testShownBoxesAcceptAnyGpuIndexUpToSixBoxes(): void
    {
        $this->assertState(self::boxes('cpu gpu6 gpu7'), 'cpu gpu6 gpu7', [0, 1]);
        $this->assertSame(['cpu', 'gpu6', 'gpu7'], self::boxes('cpu gpu6 gpu7')->shownBoxes());
        $this->expectException(InvalidOptionValue::class);
        $this->expectExceptionMessage('Too many GPU boxes');
        Config::new()->with('shown_boxes', 'gpu0 gpu1 gpu2 gpu3 gpu4 gpu5 gpu6');
    }

    public function testInvalidGpuNamesAreRejected(): void
    {
        foreach (['gpu', 'gpu1x', 'gpu-1'] as $bad) {
            try {
                Config::new()->with('shown_boxes', 'cpu ' . $bad);
                $this->fail($bad . ' accepted');
            } catch (InvalidOptionValue $e) {
                $this->assertSame('Invalid box name(s) in shown_boxes!', $e->getMessage());
            }
        }
    }

    public function testSettleChecksTheDetectedCountAndTheSixBoxCap(): void
    {
        $this->assertSame(['cpu', 'gpu7'], Config::new()->withParsed('shown_boxes', 'cpu gpu7', true)->withShownBoxesSettled(8)->shownBoxes());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], Config::new()->withParsed('shown_boxes', 'cpu gpu8', true)->withShownBoxesSettled(8)->shownBoxes());
        $this->assertSame(['cpu', 'gpu8'], Config::new()->withParsed('shown_boxes', 'cpu gpu8', true)->withShownBoxesSettled(null)->shownBoxes(), 'count unknown: index unchecked');
        $seven = Config::new()->withParsed('shown_boxes', 'gpu0 gpu1 gpu2 gpu3 gpu4 gpu5 gpu6', true);
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $seven->withShownBoxesSettled(null)->shownBoxes(), 'more than 6 gpu boxes');
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], Config::new()->withParsed('shown_boxes', 'cpu gpu1x', true)->withShownBoxesSettled(null)->shownBoxes());
    }

    public function testSwitchWrapsAndSkipsVisibleGpus(): void
    {
        $config = self::boxes('cpu gpu0 gpu1 gpu2');
        $next = GpuPanels::switchTarget($config, 0, -1, 8);
        $this->assertNotNull($next);
        $this->assertState($next, 'cpu gpu7 gpu1 gpu2', [0, 1, 2]);
        $next = GpuPanels::switchTarget($next, 1, 1, 8);
        $this->assertNotNull($next);
        $this->assertState($next, 'cpu gpu7 gpu3 gpu2', [0, 1, 2]);
    }

    public function testSwitchFailsWhenEveryOtherGpuIsVisible(): void
    {
        $config = self::boxes('gpu0 gpu1 gpu2');
        $this->assertNull(GpuPanels::switchTarget($config, 0, 1, 3));
        $this->assertNull(GpuPanels::switchTarget($config, 0, 1, 1), 'one gpu: nothing to switch to');
        $this->assertNull(GpuPanels::switchTarget($config, 3, 1, 8), 'no fourth gpu box');
        $this->assertNull(GpuPanels::switchTarget($config, -1, 1, 8));
    }

    public function testToggleClosesTheSlotAfterATargetSwitch(): void
    {
        $config = self::boxes('gpu0 gpu1');
        $this->assertState($config, 'gpu0 gpu1', [0, 1]);
        $config = GpuPanels::switchTarget($config, 1, -1, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'gpu0 gpu7', [0, 1]);
        $config = GpuPanels::toggle($config, 1, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'gpu0', [0]);
    }

    public function testToggleOpensTheRequestedSlotInSlotOrder(): void
    {
        $config = GpuPanels::toggle(self::boxes('gpu0'), 2, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'gpu0 gpu2', [0, 2]);
        $config = GpuPanels::toggle($config, 1, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'gpu0 gpu1 gpu2', [0, 1, 2]);
    }

    public function testToggleKeepsSlotOrderWhenOpenedOutOfOrder(): void
    {
        $config = GpuPanels::toggle(self::boxes('cpu'), 2, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'cpu gpu2', [2]);
        $config = GpuPanels::toggle($config, 0, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'cpu gpu0 gpu2', [0, 2]);
    }

    public function testToggleRemovesTheBoxOfTheRequestedSlot(): void
    {
        $config = GpuPanels::toggle(self::boxes('gpu0 gpu1 gpu2'), 1, 8);
        $this->assertNotNull($config);
        $this->assertState($config, 'gpu0 gpu2', [0, 2]);
    }

    public function testToggleOpensTheNextFreeGpuWrapping(): void
    {
        // Slot 1 starts at GPU 1, which slot 0 already shows: GPU 2 is next.
        $config = GpuPanels::toggle(self::boxes('cpu gpu1'), 1, 3);
        $this->assertNotNull($config);
        $this->assertState($config, 'cpu gpu1 gpu2', [0, 1]);
        // Slot 2 on a 3-GPU host with gpu1+gpu2 shown wraps to GPU 0.
        $config = GpuPanels::toggle($config, 2, 3);
        $this->assertNotNull($config);
        $this->assertState($config, 'cpu gpu1 gpu2 gpu0', [0, 1, 2]);
    }

    public function testToggleRefusals(): void
    {
        $this->assertNull(GpuPanels::toggle(self::boxes('cpu'), 2, 2), 'no GPU 2 for a free slot');
        $this->assertNull(GpuPanels::toggle(self::boxes('gpu0'), 0, 1), 'closing the only box would leave none');
        $this->assertNull(GpuPanels::toggle(self::boxes('cpu gpu0'), 1, 1), 'every GPU already boxed');
        $this->assertNull(GpuPanels::toggle(self::boxes('cpu'), 6, 8), 'no slot 6');
        $this->assertNull(GpuPanels::toggle(self::boxes('cpu'), -1, 8));
        $six = self::boxes('gpu0 gpu1 gpu2 gpu3 gpu4 gpu5');
        $this->assertNull(GpuPanels::toggle($six->with(GpuPanels::SLOTS_KEY, '0 1 2 3 4 5'), 6, 8));
    }

    public function testShownBoxesWrittenAloneResetsTheSlots(): void
    {
        $config = GpuPanels::toggle(self::boxes('cpu'), 2, 8);
        $this->assertNotNull($config);
        $this->assertSame('2', $config->string(GpuPanels::SLOTS_KEY));
        // btop set_boxes clears current_gpu_panel_slots (options menu, presets, reload).
        $edited = $config->with('shown_boxes', 'cpu gpu3');
        $this->assertSame('', $edited->string(GpuPanels::SLOTS_KEY));
        $this->assertSame([0], GpuPanels::slots($edited));
        $this->assertSame('2', $config->with('shown_boxes', 'cpu gpu2')->string(GpuPanels::SLOTS_KEY), 'an unchanged value keeps them');
    }

    public function testStaleOrInvalidSlotsReadAsTheDefault(): void
    {
        $this->assertSame([0, 1], GpuPanels::slots(self::boxes('gpu0 gpu1')->with(GpuPanels::SLOTS_KEY, '3')), 'count mismatch');
        $this->assertSame([3, 4], GpuPanels::slots(self::boxes('gpu0 gpu1')->with(GpuPanels::SLOTS_KEY, '3 4')));
        $this->assertSame([], GpuPanels::slots(Config::new()));
    }

    #[DataProvider('badSlots')]
    public function testSlotsLaw(string $value): void
    {
        $this->expectException(InvalidOptionValue::class);
        Config::new()->with(GpuPanels::SLOTS_KEY, $value);
    }

    /** @return iterable<string, array{string}> */
    public static function badSlots(): iterable
    {
        yield 'slot 6' => ['6'];
        yield 'duplicate' => ['1 1'];
        yield 'word' => ['a'];
        yield 'negative' => ['-1'];
    }

    public function testWithBoxesSortsGpuBoxesBySlot(): void
    {
        $config = GpuPanels::withBoxes(Config::new(), ['cpu', 'gpu3', 'mem', 'gpu1'], [4, 2]);
        $this->assertState($config, 'cpu gpu1 mem gpu3', [2, 4]);
        $config = GpuPanels::withBoxes(Config::new(), ['gpu3', 'gpu1'], [1, 1]);
        $this->assertState($config, 'gpu3 gpu1', [0, 1]);
    }

    #[DataProvider('gpuBoxColumns')]
    public function testGpuBoxColumnsCoercion(string $value, ?int $expected): void
    {
        if ($expected === -1) {
            $this->expectException(InvalidOptionValue::class);
            $this->expectExceptionMessage('gpu_box_columns');
        }
        $config = Config::new()->with('gpu_box_columns', $value);
        $this->assertSame($expected, $config->gpuBoxColumns());
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function gpuBoxColumns(): iterable
    {
        yield 'Auto' => ['Auto', null];
        yield 'one' => ['1', 1];
        yield 'six' => ['6', 6];
        yield 'zero' => ['0', -1];
        yield 'seven' => ['7', -1];
        yield 'word' => ['two', -1];
        yield 'fraction' => ['2.5', -1];
        yield 'lowercase auto' => ['auto', -1];
    }

    public function testSchemaDefaults(): void
    {
        $this->assertSame('Auto', Schema::defaults()['gpu_box_columns']);
        $this->assertSame(['cpu', 'mem', 'net', 'proc', 'ctr'], Schema::BOXES);
        $this->assertFalse(Schema::option(GpuPanels::SLOTS_KEY)?->persisted);
    }
}
