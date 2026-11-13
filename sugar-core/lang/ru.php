<?php

/**
 * Russian translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB-компонент вне диапазона [0,255]: {value}',
    'color.invalid_hex'           => 'недопустимый hex-цвет: {hex}',
    'color.ansi_out_of_range'     => 'ansi-индекс вне диапазона [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ansi256-индекс вне диапазона [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'недопустимый 16-цветный код переднего плана: {code}',
    'ansi.invalid_bg_code'        => 'недопустимый 16-цветный код фона: {code}',
    'ansi.component_out_of_range' => '{label} вне диапазона [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open не удалась для: {cmd}',
    'color.parse_empty' => 'невозможно разобрать пустое имя цвета',
    'color.parse_unknown' => 'неизвестное имя цвета: {name}',
    'ansi.invalid_scs_slot' => 'недопустимый слот SCS: {slot} (ожидалось 0-3 для G0-G3)',
    'ansi.invalid_scs_designator' => 'недопустимый обозначатель SCS: {designator} (ожидался один байт в 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'число повторов sixel не должно быть отрицательным: {count}',
    'semaphore.limit_invalid' => 'лимит семафора должен быть не меньше 1, получено {limit}',
    'semaphore.limit_busy' => 'семафор занят: {held} выполняется, {waiting} ожидает',
    'semaphore.shutdown' => 'семафор закрыт до выдачи разрешения',
    'semaphore.closed' => 'семафор закрыт и не может выдать новое разрешение',
    'semaphore.derive_closed' => 'невозможно создать новый пул из закрытого семафора',
    'lru_map.capacity_invalid' => 'ёмкость LRU должна быть не меньше 1, получено {capacity}',
    'lru_map.capacity_too_large' => 'ёмкость LRU {capacity} превышает ограничитель роста {max}',
    'lru_map.evict_from_empty' => 'невозможно вытеснить из пустой карты LRU',
    'errors.negative_not_allowed' => 'отрицательное значение недопустимо для {name}',
    'errors.value_must_be_positive' => '{name} должно быть положительным, получено {value}',
    'errors.value_out_of_range' => '{name} должно быть от {min} до {max}, получено {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
];
