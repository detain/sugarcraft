<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

use SugarCraft\Core\Util\Sanitize;

/**
 * Text parsers for the ipmitool commands the ipmi box runs. Written
 * against real captures from two very different BMCs (ASUS/AMI and HP
 * iLO 4, prompt_kit/findings/ipmi-reference/) and forgiving by design:
 * an unknown line is skipped, never fatal, because every vendor adds its
 * own rows, units and spacing.
 *
 * Every text is untrusted (FRU product names, sensor names and SEL text
 * are whatever the board vendor flashed), so each parser first runs it
 * through {@see Sanitize::untrustedForDisplay()}: 7-bit and 8-bit escape
 * sequences, lone C1 bytes (`\x9b`) and other controls are removed and
 * invalid UTF-8 is replaced, before any field reaches the Surface.
 */
final class IpmiParser
{
    /**
     * IANA enterprise numbers (`mc info` "Manufacturer ID") of the common
     * BMC makers → the short name the header shows. The BMC product line
     * is named where the number pins it (HP's 11 is always iLO).
     */
    private const VENDORS = [
        11 => 'iLO',
        47196 => 'iLO',
        674 => 'iDRAC',
        10876 => 'Supermicro',
        2623 => 'ASUS',
        343 => 'Intel',
        19046 => 'Lenovo XCC',
        20301 => 'IBM IMM',
        42 => 'Oracle ILOM',
        15370 => 'Gigabyte',
        7244 => 'Quanta',
        2 => 'IBM',
        5771 => 'Cisco CIMC',
        6569 => 'Inventec',
        40981 => 'Tyan',
        6653 => 'Tyan',
        48482 => 'ASRock Rack',
        49622 => 'Ampere',
        3704 => 'Fujitsu iRMC',
        10368 => 'Fujitsu iRMC',
    ];

    private function __construct()
    {
    }

    /**
     * `ipmitool sensor list` → one {@see IpmiSensor} per row, in BMC order.
     * Rows with fewer than four columns (warnings, blank lines) are skipped.
     *
     * @return list<IpmiSensor>
     */
    public static function sensorList(string $text): array
    {
        $text = self::clean($text);
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_contains($line, '|')) {
                continue;
            }
            $cols = array_map('trim', explode('|', $line));
            if (\count($cols) < 4 || $cols[0] === '') {
                continue;
            }
            [$name, $raw, $unit, $status] = $cols;
            $kind = SensorKind::classify($unit, $name);
            $state = null;
            $value = null;
            if ($kind === SensorKind::Discrete) {
                $state = self::hex($raw);
            } else {
                $value = self::number($raw);
            }
            $t = array_map(self::number(...), array_pad(\array_slice($cols, 4, 6), 6, 'na'));
            $out[] = new IpmiSensor(
                $name,
                $value,
                $unit,
                $kind,
                SensorStatus::parse($status),
                $t[0],
                $t[1],
                $t[2],
                $t[3],
                $t[4],
                $t[5],
                $state,
            );
        }

        return $out;
    }

    /**
     * `ipmitool sdr elist` → one {@see IpmiSensor} per row:
     * `name | id | status | entity | reading`, where the reading is
     * "45 degrees C", "19.60 percent, Transition to Running", a discrete
     * state ("Fully Redundant", "Device Absent"), "Disabled", "No Reading"
     * or empty. No thresholds — {@see merge()} adds those from one
     * `sensor list`. Against a local SDR cache (`-S`) this walk takes
     * ~0.5 s where `sensor list` takes 4-29 s (measured on HP iLO 4 and
     * ASUS/AMI).
     *
     * @return list<IpmiSensor>
     */
    public static function sdrElist(string $text): array
    {
        $text = self::clean($text);
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (substr_count($line, '|') < 4) {
                continue;
            }
            $cols = array_map('trim', explode('|', $line));
            $name = $cols[0];
            if ($name === '') {
                continue;
            }
            $status = SensorStatus::parse($cols[2]);
            $reading = trim(implode('|', \array_slice($cols, 4)));
            $first = trim(explode(',', $reading, 2)[0]);
            if (preg_match('/^(-?\d+(?:\.\d+)?)\s+(.+)$/', $first, $m) === 1) {
                $kind = SensorKind::classify($m[2], $name);
                $tail = trim(explode(',', $reading, 2)[1] ?? '');
                $out[] = new IpmiSensor($name, (float) $m[1], $m[2], $kind, $status, text: $tail);
                continue;
            }
            $none = preg_match('/^(disabled|no reading|not readable|na)$/i', $first) === 1;
            $out[] = new IpmiSensor(
                $name,
                null,
                $none ? '' : 'discrete',
                $none ? SensorKind::Other : SensorKind::Discrete,
                $none ? SensorStatus::NoSensor : $status,
                text: $none ? '' : $reading,
            );
        }

        return $out;
    }

    /**
     * Values from `$values` (`sdr elist`) with the thresholds of the same
     * name from `$thresholds` (`sensor list`), in `$values`' order.
     *
     * @param list<IpmiSensor> $values
     * @param list<IpmiSensor> $thresholds
     * @return list<IpmiSensor>
     */
    public static function merge(array $values, array $thresholds): array
    {
        $by = [];
        foreach ($thresholds as $t) {
            $by[$t->name] ??= $t;
        }

        return array_map(static fn (IpmiSensor $v): IpmiSensor => isset($by[$v->name]) && $by[$v->name]->hasThresholds() ? $v->withThresholds($by[$v->name]) : $v, $values);
    }

    /** `ipmitool dcmi power reading` → the reading, or null when the BMC gave none. */
    public static function dcmi(string $text): ?IpmiPower
    {
        $text = self::clean($text);
        $grab = static fn (string $re): ?float => preg_match($re, $text, $m) === 1 ? (float) $m[1] : null;
        $now = $grab('/Instantaneous power reading:\s*([\d.]+)\s*Watts/i');
        if ($now === null) {
            return null;
        }
        $min = $grab('/Minimum during sampling period:\s*([\d.]+)/i') ?? $now;
        $max = $grab('/Maximum during sampling period:\s*([\d.]+)/i') ?? $now;
        $avg = $grab('/Average power reading over sample period:\s*([\d.]+)/i') ?? $now;
        $period = (int) ($grab('/Sampling period:\s*(\d+)\s*Seconds/i') ?? 0);
        $active = preg_match('/Power reading state is:\s*(\S+)/i', $text, $m) !== 1 || strtolower($m[1]) === 'activated';

        return new IpmiPower($now, $min, $max, $avg, $period, $active);
    }

    /** `ipmitool chassis status` → its flags, or null when there was no "System Power" line. */
    public static function chassis(string $text): ?IpmiChassis
    {
        $text = self::clean($text);
        $kv = self::pairs($text);
        if (!isset($kv['System Power'])) {
            return null;
        }
        $true = static fn (string $key): bool => strtolower($kv[$key] ?? '') === 'true';
        $active = static fn (string $key): bool => strtolower($kv[$key] ?? '') === 'active';

        return new IpmiChassis(
            strtolower($kv['System Power']) === 'on',
            $true('Power Overload'),
            $true('Main Power Fault'),
            $true('Power Control Fault'),
            $active('Chassis Intrusion'),
            $true('Drive Fault'),
            $true('Cooling/Fan Fault'),
            $active('Power Interlock'),
            $kv['Power Restore Policy'] ?? '',
            $kv['Last Power Event'] ?? '',
        );
    }

    /** `ipmitool sel info` → the fill level, or null without an "Entries" line. */
    public static function selInfo(string $text): ?IpmiSel
    {
        $text = self::clean($text);
        $kv = self::pairs($text);
        if (!isset($kv['Entries']) || preg_match('/^\d+/', $kv['Entries'], $m) !== 1) {
            return null;
        }
        $pct = preg_match('/^(\d+)\s*%/', $kv['Percent Used'] ?? '', $p) === 1 ? (int) $p[1] : null;
        if ($pct === null && preg_match('/^(\d+)/', $kv['Free Space'] ?? '', $f) === 1 && (int) $f[1] === 0 && (int) $m[0] > 0) {
            $pct = 100;
        }
        $lastAdd = $kv['Last Add Time'] ?? '';

        return new IpmiSel(
            (int) $m[0],
            $pct,
            strtolower($kv['Overflow'] ?? '') === 'true',
            strcasecmp($lastAdd, 'Not Available') === 0 ? '' : $lastAdd,
        );
    }

    /**
     * The newest line of `ipmitool sel elist last 1` (or `sel list`):
     * `id | date | time | sensor | event | direction` → "date time ·
     * sensor · event · direction" with the record id dropped.
     */
    public static function selLatest(string $text): string
    {
        $text = self::clean($text);
        $last = '';
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (substr_count($line, '|') >= 3) {
                $last = $line;
            }
        }
        if ($last === '') {
            return '';
        }
        $cols = array_values(array_filter(array_map('trim', \array_slice(explode('|', $last), 1)), static fn (string $c): bool => $c !== ''));
        if ($cols === []) {
            return '';
        }
        $when = $cols[0] . (isset($cols[1]) && preg_match('/\d:\d|^\d+$/', $cols[1]) === 1 ? ' ' . $cols[1] : '');
        $rest = \array_slice($cols, $when === $cols[0] ? 1 : 2);

        return trim(implode(' · ', [$when, ...$rest]));
    }

    /**
     * `mc info` + `fru print 0` + `lan print <channel>` → the header facts.
     * Any of the three may be '' (that command failed).
     */
    public static function info(string $mc, string $fru, string $lan = '', ?int $channel = null): IpmiInfo
    {
        [$mc, $fru, $lan] = [self::clean($mc), self::clean($fru), self::clean($lan)];
        $m = self::pairs($mc);
        $f = self::pairs($fru);
        $l = self::pairs($lan);
        $id = isset($m['Manufacturer ID']) && preg_match('/^(\d+)/', $m['Manufacturer ID'], $x) === 1 ? (int) $x[1] : null;
        $serials = [];
        foreach (['chassis' => 'Chassis Serial', 'board' => 'Board Serial', 'product' => 'Product Serial', 'asset' => 'Product Asset Tag'] as $slug => $key) {
            if (($f[$key] ?? '') !== '') {
                $serials[$slug] = $f[$key];
            }
        }
        $ip = self::lanAddress($lan);

        return new IpmiInfo(
            $f['Product Name'] ?? '',
            $f['Product Manufacturer'] ?? ($f['Board Mfg'] ?? ''),
            $f['Board Product'] ?? '',
            self::vendor($id, $m['Manufacturer Name'] ?? ''),
            $m['Firmware Revision'] ?? '',
            $m['IPMI Version'] ?? '',
            $id,
            $ip,
            $l['IP Address Source'] ?? '',
            $channel,
            $serials,
        );
    }

    /** The address `lan print` answered with; '' when none or unconfigured (0.0.0.0). */
    public static function lanAddress(string $lan): string
    {
        $lan = self::clean($lan);
        $ip = self::pairs($lan)['IP Address'] ?? '';

        return filter_var($ip, FILTER_VALIDATE_IP) !== false && $ip !== '0.0.0.0' ? $ip : '';
    }

    /** The BMC maker's short name: a known IANA number, else the first word of "Manufacturer Name". */
    public static function vendor(?int $id, string $name): string
    {
        if ($id !== null && isset(self::VENDORS[$id])) {
            return self::VENDORS[$id];
        }
        $name = trim(preg_replace('/\b(Computer|Inc\.?|Corp\.?|Corporation|Co\.?|Ltd\.?|Technology|International)\b|,/i', '', $name) ?? '');

        return $name === '' || str_starts_with(strtolower($name), 'unknown') ? '' : (string) strtok($name, ' ');
    }

    /**
     * "Key : value" lines → map, first occurrence wins; continuation
     * lines (`  : User : MD5`, bare list items) are skipped.
     *
     * @return array<string, string>
     */
    public static function pairs(string $text): array
    {
        $text = self::clean($text);
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $at = strpos($line, ':');
            if ($at === false) {
                continue;
            }
            $key = trim(substr($line, 0, $at));
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = trim(substr($line, $at + 1));
        }

        return $out;
    }

    /** The display-safe form of a BMC text ({@see Sanitize::untrustedForDisplay()}). */
    public static function clean(string $text): string
    {
        return Sanitize::untrustedForDisplay($text);
    }

    private static function number(string $raw): ?float
    {
        $raw = trim($raw);

        return is_numeric($raw) ? (float) $raw : null;
    }

    private static function hex(string $raw): ?int
    {
        $raw = trim($raw);

        return preg_match('/^0x([0-9a-f]+)$/i', $raw, $m) === 1 ? (int) hexdec($m[1]) : null;
    }
}
