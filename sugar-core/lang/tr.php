<?php

/**
 * Turkish translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB bileşeni aralık dışında [0,255]: {value}',
    'color.invalid_hex'           => 'Geçersiz onaltılık renk: {hex}',
    'color.ansi_out_of_range'     => 'ANSI dizini aralık dışında [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256 dizini aralık dışında [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'Geçersiz 16 renk ön plan kodu: {code}',
    'ansi.invalid_bg_code'        => 'Geçersiz 16 renk arka plan kodu: {code}',
    'ansi.component_out_of_range' => '{label} aralık dışında [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open başarısız: {cmd}',
    'color.parse_empty' => 'boş renk adı ayrıştırılamıyor',
    'color.parse_unknown' => 'bilinmeyen renk adı: {name}',
    'ansi.invalid_scs_slot' => 'geçersiz SCS yuvası: {slot} (G0-G3 için 0-3 bekleniyor)',
    'ansi.invalid_scs_designator' => 'geçersiz SCS gösterici: {designator} (0x30-0x7E aralığında bir bayt bekleniyor)',
    'ansi.invalid_sixel_repeat' => 'sixel yineleme sayısı negatif olmamalıdır: {count}',
    'semaphore.limit_invalid' => 'semafor sınırı en az 1 olmalı, alınan: {limit}',
    'semaphore.limit_busy' => 'semafor meşgul: {held} çalışıyor, {waiting} bekliyor',
    'semaphore.shutdown' => 'izin verilmeden önce semafor kapatıldı',
    'semaphore.closed' => 'semafor kapalı ve yeni izin veremiyor',
    'semaphore.derive_closed' => 'kapalı semafordan yeni havuz türetilemiyor',
    'lru_map.capacity_invalid' => 'LRU kapasitesi en az 1 olmalı, alınan: {capacity}',
    'lru_map.capacity_too_large' => 'LRU kapasitesi {capacity}, büyüme sınırını {max} aşıyor',
    'lru_map.evict_from_empty' => 'boş LRU haritasından çıkarma yapılamaz',
    'errors.negative_not_allowed' => '{name} için negatif değer kabul edilmez',
    'errors.value_must_be_positive' => '{name} pozitif olmalı, alınan: {value}',
    'errors.value_out_of_range' => '{name} {min} ile {max} arasında olmalı, alınan: {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
    'ansi.selection_invalid' => 'clipboard selection parameter {selection} is not an OSC 52 selector (c, p, s, 0-7, comma-separated combinations)',
    'ansi.clipboard_payload_too_large' => 'clipboard payload is {bytes} bytes, over the {limit}-byte OSC 52 bound',
];
