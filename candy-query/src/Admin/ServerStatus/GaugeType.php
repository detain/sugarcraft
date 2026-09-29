<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

/**
 * Gauge types supported in the sidebar.
 */
enum GaugeType: string
{
    case Connections   = 'connections';
    case Traffic       = 'traffic';
    case KeyEfficiency = 'key_efficiency';
    case Qps           = 'qps';
    case InnoDB        = 'innodb';
}
