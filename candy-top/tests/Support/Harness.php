<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Theme\ThemeConfig;

/**
 * A deterministic App: fake sources, 8-core host, fixed clock
 * (2023-11-14 22:13:20 UTC + 1h uptime), truecolor Default theme.
 */
final class Harness
{
    public const TIME = 1_700_000_000.0;

    public static function host(): HostInfo
    {
        return HostInfo::new('Ryzen 7 5800X', 8, 'joe', 'box');
    }

    public static function app(?Config $config = null): App
    {
        $host = self::host();
        $config ??= Config::new();

        return App::start(
            $config,
            ThemeConfig::new(),
            $host,
            Panels::standard($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(self::TIME, 3600.0),
            ColorProfile::TrueColor,
        );
    }

    /** Sized, clock-ticked and sampled once — a fully painted first frame. */
    public static function running(int $cols, int $rows, ?Config $config = null): App
    {
        [$app] = self::app($config)->update(new WindowSizeMsg($cols, $rows));
        [$app] = $app->update(new ClockTickMsg(self::TIME, 3600.0));
        foreach (Cmds::of(SampledMsg::class, (static fn (App $a): ?\Closure => $a->init())($app)) as $msg) {
            [$app] = $app->update($msg);
        }

        return $app;
    }
}
