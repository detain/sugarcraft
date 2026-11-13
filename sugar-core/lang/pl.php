<?php

/**
 * Polish translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'Składowa RGB poza zakresem [0,255]: {value}',
    'color.invalid_hex'           => 'Nieprawidłowy kolor szesnastkowy: {hex}',
    'color.ansi_out_of_range'     => 'Indeks ANSI poza zakresem [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'Indeks ANSI256 poza zakresem [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'Nieprawidłowy kod koloru pierwszego planu 16 kolorów: {code}',
    'ansi.invalid_bg_code'        => 'Nieprawidłowy kod koloru tła 16 kolorów: {code}',
    'ansi.component_out_of_range' => '{label} poza zakresem [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open nie powiodło się dla: {cmd}',
    'color.parse_empty' => 'nie można przetworzyć pustej nazwy koloru',
    'color.parse_unknown' => 'nieznana nazwa koloru: {name}',
    'ansi.invalid_scs_slot' => 'nieprawidłowy slot SCS: {slot} (oczekiwano 0-3 dla G0-G3)',
    'ansi.invalid_scs_designator' => 'nieprawidłowy deskryptor SCS: {designator} (oczekiwano jednego bajtu w 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'liczba powtórzeń sixel nie może być ujemna: {count}',
    'semaphore.limit_invalid' => 'limit semafora musi wynosić co najmniej 1, otrzymano {limit}',
    'semaphore.limit_busy' => 'semafor zajęty: {held} działa, {waiting} czeka',
    'semaphore.shutdown' => 'semafor zamknięty przed przydziałem pozwolenia',
    'semaphore.closed' => 'semafor jest zamknięty i nie może przydzielić kolejnego pozwolenia',
    'semaphore.derive_closed' => 'nie można wyprowadzić nowej puli z zamkniętego semafora',
    'lru_map.capacity_invalid' => 'pojemność LRU musi wynosić co najmniej 1, otrzymano {capacity}',
    'lru_map.capacity_too_large' => 'pojemność LRU {capacity} przekracza strażnika wzrostu {max}',
    'lru_map.evict_from_empty' => 'nie można usunąć z pustej mapy LRU',
    'errors.negative_not_allowed' => 'wartość ujemna niedozwolona dla {name}',
    'errors.value_must_be_positive' => '{name} musi być dodatnia, otrzymano {value}',
    'errors.value_out_of_range' => '{name} musi być między {min} a {max}, otrzymano {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
];
