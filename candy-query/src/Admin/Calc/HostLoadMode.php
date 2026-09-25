<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Which source feeds the CPU/Load column.
 *
 * Own file despite being minted only by HostLoadSampler: PHPUnit data
 * providers resolve type references BEFORE any class body runs, so a test
 * provider naming this enum through PSR-4 would not find it inside the
 * sampler's file (unlike GaugeType, which tests only touch from method
 * bodies after SidebarGauge autoloads).
 */
enum HostLoadMode: string
{
    /** OS counters read straight from /proc because the server is this box. */
    case HostProc = 'host-proc';

    /** Remote server: SQL carries no OS counters, activity is a proxy. */
    case BusyProxy = 'busy-proxy';
}

