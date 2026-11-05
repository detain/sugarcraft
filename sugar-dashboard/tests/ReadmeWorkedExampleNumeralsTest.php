<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Foundation\GradientStore;
use SugarCraft\Dashboard\Foundation\NetAutoScale;

/**
 * Durability pins for the worked-example numerals in README.md (audit n-14,
 * plan_review step 28).
 *
 * The NetAutoScale and GradientStore walkthroughs are the README's only
 * copy-paste examples whose printed results are real computed values, not
 * decoration. Until now nothing tied those printed numerals to behaviour, so
 * either side could drift silently: the algorithm could change and the docs
 * would keep teaching stale numbers, or an edit to the prose could break the
 * example with nobody noticing. Each pin has two legs — one asserts the exact
 * literal is still present in the README text, the other re-executes the
 * documented call sequence against the live classes and asserts the computed
 * value — so drift on either side reddens this file.
 *
 * Fail-loud: an unreadable README means the guard cannot do its job, which is
 * itself a failure, never a silent pass.
 */
final class ReadmeWorkedExampleNumeralsTest extends TestCase
{
    /** The first offer rescales: 50 kB down snaps up to the 65000 ceiling tier. */
    private const README_DOWNLOAD_CEILING = '=== 65000, $scale->uploadMax() === 10240 (floor)';

    /** Five sustained 200 kB samples grow the ceiling once past the hysteresis. */
    private const README_GROWN_CEILING = '=== 260000';

    /** btop cpu ramp (green→yellow→red stops) at 73 % lands on this hex. */
    private const README_GRADIENT_HEX = '// #d99868';

    public function testTheNetAutoScaleOpeningExampleNumeralsMatchBehaviour(): void
    {
        $readme = self::readme();
        self::assertStringContainsString(
            self::README_DOWNLOAD_CEILING,
            $readme,
            'README opening NetAutoScale example lost its printed ceilings'
        );

        $scale = NetAutoScale::new()->offer(50_000, 4_000);
        self::assertSame(65000, $scale->downloadMax(), 'README teaches a download ceiling the live scaler does not produce');
        self::assertSame(10240, $scale->uploadMax(), 'README teaches an upload floor the live scaler does not produce');
    }

    public function testTheNetAutoScaleGrowthExampleNumeralMatchesBehaviour(): void
    {
        $readme = self::readme();
        self::assertStringContainsString(
            self::README_GROWN_CEILING,
            $readme,
            'README sustained-sample example lost its printed grown ceiling'
        );

        $scale = NetAutoScale::new()->offer(50_000, 4_000);
        for ($i = 0; $i < 5; $i++) {
            $scale = $scale->offer(200_000, 4_000);
        }
        self::assertSame(260000, $scale->downloadMax(), 'README teaches a growth ceiling the live scaler does not reach');
    }

    public function testTheGradientStoreExampleNumeralMatchesBehaviour(): void
    {
        $readme = self::readme();
        self::assertStringContainsString(
            self::README_GRADIENT_HEX,
            $readme,
            'README GradientStore example lost its printed interpolation hex'
        );

        $store = GradientStore::fromStops([
            'cpu_start' => Color::hex('#80d0a3'),
            'cpu_mid'   => Color::hex('#dcd179'),
            'cpu_end'   => Color::hex('#d45454'),
        ])->withGradient('proc', Color::hex('#cccccc'), end: Color::hex('#404040'));
        self::assertSame('#d99868', $store->at('cpu', 73)->toHex(), 'README teaches an interpolation the live ramp does not produce');
    }

    private static function readme(): string
    {
        $readme = @file_get_contents(__DIR__ . '/../README.md');
        if ($readme === false) {
            throw new \RuntimeException('README.md unreadable — worked-example numeral guard cannot run');
        }

        return $readme;
    }
}
