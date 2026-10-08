<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Braille;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Gradient101;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;

/**
 * Golden battery from btop itself: tests/fixtures/btop-graph-oracle.json is
 * the output of btop's Graph ctor + _create compiled from source
 * (prompt_kit/tools/btop-graph-oracle.cpp), with terminal plumbing stubbed
 * to tokens: "~" = Mv::r(1) skip, "{k}" = gradient index k, "{R}" = reset.
 *
 * Translating tokens to this port's documented rendering ("~" → space,
 * one reset per row instead of per frame) must reproduce render() exactly.
 */
final class DualSampleGraphOracleTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function oracleCases(): iterable
    {
        $json = file_get_contents(__DIR__ . '/../../fixtures/btop-graph-oracle.json');
        $cases = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $i => $case) {
            yield sprintf(
                '#%d %s h%d w%d%s%s max%d off%d%s n%d',
                $i,
                $case['family'],
                $case['height'],
                $case['width'],
                $case['invert'] ? ' inv' : '',
                $case['noZero'] ? ' nz' : '',
                $case['maxValue'],
                $case['offset'],
                $case['gradient'] ? ' grad' : '',
                count($case['data']),
            ) => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('oracleCases')]
    public function testMatchesBtop(array $case): void
    {
        $stops = [Color::rgb(0, 0, 0), Color::rgb(100, 0, 0)];
        $ramp = Gradient101::expand($stops);

        $graph = DualSampleGraph::new(
            $case['width'],
            $case['height'],
            $case['family'],
            $case['invert'],
            $case['noZero'],
            $case['maxValue'],
            $case['offset'],
        )->withData(...$case['data']);
        if ($case['gradient']) {
            $graph = $graph->withGradient($stops);
        }

        $out = $case['out'];
        if ($case['gradient']) {
            $this->assertStringEndsWith('{R}', $out);
            $out = substr($out, 0, -3);
        }
        $expected = [];
        foreach (explode("\n", $out) as $row) {
            $row = str_replace('~', ' ', $row);
            $row = (string) preg_replace_callback(
                '/\{(\d+)\}/',
                static fn(array $m): string => $ramp[(int) $m[1]]->toFg(ColorProfile::TrueColor),
                $row,
            );
            $expected[] = $case['gradient'] ? $row . Ansi::reset() : $row;
        }

        $this->assertSame(implode("\n", $expected), $graph->render(ColorProfile::TrueColor));
    }
}
