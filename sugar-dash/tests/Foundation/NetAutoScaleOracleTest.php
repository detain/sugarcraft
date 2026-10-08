<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\NetAutoScale;

/**
 * Replays tick sequences recorded from a transcription of btop's own
 * Net::collect autoscale block (prompt_kit/tools/btop-netscale-oracle.cpp)
 * and asserts every counter and ceiling matches tick-for-tick.
 */
final class NetAutoScaleOracleTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, list<list<int>>}>
     */
    public static function runs(): iterable
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/btop-netscale-oracle.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['runs'] as $run) {
            $label = sprintf('sync=%d seed=%d', $run['sync'] ? 1 : 0, $run['seed']);
            yield $label => [$run['sync'], $run['ticks']];
        }
    }

    /**
     * @param list<list<int>> $ticks
     */
    #[DataProvider('runs')]
    public function testMatchesBtopTickForTick(bool $sync, array $ticks): void
    {
        $s = NetAutoScale::new($sync);
        $rescales = 0;
        foreach ($ticks as $i => [$forced, $down, $up, $dlFast, $dlSlow, $ulFast, $ulSlow, $dlMax, $ulMax]) {
            if ($forced === 1) {
                $s = $s->forceRescale();
            }
            $s = $s->offer($down, $up);
            $rescales += $s->rescaled ? 1 : 0;
            $this->assertSame(
                [$dlFast, $dlSlow, $ulFast, $ulSlow, $dlMax, $ulMax],
                [...$s->counters('download'), ...$s->counters('upload'), $s->downloadMax(), $s->uploadMax()],
                "tick $i",
            );
        }
        // Guard against a fixture that never exercises the 5-count trigger.
        $this->assertGreaterThan(10, $rescales);
    }
}
