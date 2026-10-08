<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\ContainerEngine;
use SugarCraft\Top\HostInfo;

/**
 * {@see HostInfo::detect()} for FreeBSD, where there is no /proc/cpuinfo.
 *
 * Mirrors aristocratos/btop Shared::init / Cpu::get_cpuName
 * (src/freebsd/btop_collect.cpp): the cpu name is `hw.model` through
 * btop's trim_name ({@see HostInfo::trimName()}), the core count
 * `hw.ncpu`. hasSensors is {@see Temp}'s own discovery (so the layout's
 * temp columns and the readings can never disagree, as on Linux);
 * hasCpuHz is the presence of `dev.cpu.0.freq`. User and host name come
 * from posix_getpwuid / gethostname exactly as on Linux. The container
 * engine is "jail" when `security.jail.jailed` is 1 (fetched in the same
 * sysctl call; btop detects no engine on FreeBSD).
 */
final class HostDetect
{
    private function __construct()
    {
    }

    public static function detect(?Probe $probe = null): HostInfo
    {
        $probe ??= LiveProbe::new();
        $v = $probe->sysctl(['hw.model', 'hw.ncpu', 'dev.cpu.0.freq', 'security.jail.jailed']);
        [$temps] = Temp::new($probe)->sample();

        $user = getenv('USER');
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $user = is_array($pw) ? $pw['name'] : $user;
        }
        $host = gethostname();

        return HostInfo::new(
            HostInfo::trimName($v['hw.model'] ?? ''),
            max(1, Sysctl::int($v, 'hw.ncpu') ?? 1),
            is_string($user) ? $user : '',
            is_string($host) ? $host : '',
            $temps->sensors !== [],
            Sysctl::int($v, 'dev.cpu.0.freq') !== null,
            Sysctl::int($v, 'security.jail.jailed') === 1 ? ContainerEngine::JAIL : '',
        );
    }
}
