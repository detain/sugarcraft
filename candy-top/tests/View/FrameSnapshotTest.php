<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\TtyTheme;

/**
 * Golden frames. Cell-grid goldens live in tests/fixtures/frames/*.txt;
 * regenerate with `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit` and
 * review the diff before committing.
 */
final class FrameSnapshotTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    /** @return iterable<string, array{int, int, array<string, bool>}> */
    public static function frames(): iterable
    {
        yield '80x24' => [80, 24, []];
        yield '120x40' => [120, 40, []];
        yield '120x40-alternate' => [120, 40, ['cpu_bottom' => true, 'mem_below_net' => true, 'proc_left' => true]];
        yield '100x30-square-no-disks' => [100, 30, ['rounded_corners' => false, 'show_disks' => false]];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('frames')]
    public function testCellGridGolden(int $cols, int $rows, array $options): void
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $grid = implode("\n", Harness::running($cols, $rows, $config)->surface()?->plainLines() ?? []) . "\n";
        $name = $cols . 'x' . $rows . ($options === [] ? '' : '-' . substr(md5(serialize($options)), 0, 6));
        $this->assertGolden(__DIR__ . "/../fixtures/frames/{$name}.txt", $grid);
    }

    public function testTtyTopRowSgrGolden(): void
    {
        $host = Harness::host();
        $config = Config::new()->with('rounded_corners', false);
        $app = App::start(
            $config,
            TtyTheme::new(),
            $host,
            Panels::placeholders($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME),
            ColorProfile::Ansi,
        );
        [$app] = $app->update(new WindowSizeMsg(80, 24));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME));
        foreach (Cmds::of(SampledMsg::class, $app->init()) as $msg) {
            [$app] = $app->update($msg);
        }
        $top = explode("\n", (string) $app->view())[0];

        // One canonical escape per style change: base folds to 37;40, and a
        // later fg replaces the earlier one instead of stacking on it.
        $b = "\x1b[0m\x1b[37;40m";
        $expected = $b . "\x1b[32m┌─┐"
            . $b . "\x1b[1;91m¹" . $b . "\x1b[1;97mcpu"
            . $b . "\x1b[32m┌──┐"
            . $b . "\x1b[1;91mm" . $b . "\x1b[1;97menu"
            . $b . "\x1b[32m┌┐"
            . $b . "\x1b[1;91mp" . $b . "\x1b[1;97mreset *"
            . $b . "\x1b[32m┌──────────┐"
            . $b . "\x1b[1;97m22:13:20"
            . $b . "\x1b[32m┌────────────────────┐"
            . $b . "\x1b[1;91m- " . $b . "\x1b[1;97m2000ms" . $b . "\x1b[1;91m +"
            . $b . "\x1b[32m┌─┐\x1b[0m";
        $this->assertSame($expected, $top);
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        // A missing golden is a failure, never a silent first-run pass: a
        // renamed provider key or deleted fixture must go red in CI.
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
