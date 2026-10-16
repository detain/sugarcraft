<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Reports;

/**
 * Column type enumeration for report columns.
 *
 * Mirrors mysql-workbench column type mapping:
 * Integer→int, LongInteger→bigint, Float→float, Time→picoseconds,
 * Bytes→bytes, String→string, StringLT→string (limited width).
 */
enum ColumnType: string
{
    case Int = 'int';
    case Bigint = 'bigint';
    case Float = 'float';
    case Time = 'time';
    case Bytes = 'bytes';
    case String = 'string';
}
