<?php

/**
 * English (default) translations for candy-core.
 *
 * Keys are flat dot-paths under the `core.*` namespace registered by
 * {@see \SugarCraft\Core\Lang}. Values may use `{name}` placeholders that
 * are substituted at lookup time by {@see \SugarCraft\Core\I18n\T::translate()}.
 *
 * To add a new locale, copy this file to `lang/<locale>.php` (e.g.
 * `lang/fr.php`) and translate the values, leaving the keys and
 * placeholder names intact.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'rgb component out of range [0,255]: {value}',
    'color.invalid_hex'           => 'invalid hex color: {hex}',
    'color.ansi_out_of_range'     => 'ansi index out of range [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ansi256 index out of range [0,255]: {index}',
    'color.parse_empty'           => 'cannot parse empty color name',
    'color.parse_unknown'          => 'unknown color name: {name}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'invalid 16-color fg code: {code}',
    'ansi.invalid_bg_code'        => 'invalid 16-color bg code: {code}',
    'ansi.component_out_of_range' => '{label} out of range [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open failed for: {cmd}',

    // Util/Semaphore.php
    'semaphore.limit_invalid'     => 'semaphore limit must be at least 1, got {limit}',
    'semaphore.limit_busy'        => 'semaphore is busy: {held} running, {waiting} waiting',
    'semaphore.shutdown'          => 'semaphore closed before a permit could be granted',
    'semaphore.closed'            => 'semaphore is closed and cannot grant another permit',
    'semaphore.derive_closed'     => 'cannot derive a new pool from a closed semaphore',

    // Util/LruMap.php
    'lru_map.capacity_invalid'    => 'LRU capacity must be at least 1, got {capacity}',
    'lru_map.capacity_too_large'  => 'LRU capacity {capacity} exceeds growth guard {max}',
    'lru_map.evict_from_empty'    => 'cannot evict from an empty LRU map',

    // Util/Validation.php
    'errors.negative_not_allowed'  => 'negative not allowed for {name}',
    'errors.value_must_be_positive' => '{name} must be positive, got {value}',
    'errors.value_out_of_range'    => '{name} must be between {min} and {max}, got {value}',
];
