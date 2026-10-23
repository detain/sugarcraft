<?php

/**
 * Turkish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'boş renk',
    'style.unrecognised_color' => 'tanınmayan renk: {value}',
    'style.hex_digit_count' => "hex rengi 3 veya 6 haneli olmalıdır (örn. '#f80' veya '#ff8800'); alınan: '{value}'",
    'style.padding_token_int' => "padding/margin belirteci integer olmalıdır; alınan: '{token}'",
    'style.padding_count'     => 'padding/margin 1, 2 veya 4 integer gerektirir; alınan: {count}',
    'style.bad_entry'         => "--style girdileri 'anahtar=değer' veya 'öğe.özellik=değer' olmalıdır; alınan: '{raw}'",
    'style.unknown_prop'      => "bilinmeyen stil özelliği: '{prop}'",
    'process.spawn_failed'    => 'alt süreç başlatılamadı',
    'border.unknown'          => 'bilinmeyen kenarlık stili: {name}',
    'log.unknown_level'       => 'bilinmeyen günlük düzeyi: {name}',
    'log.encode_failed' => 'log kaydı json olarak kodlanamadı',
    'log.bad_format' => 'geçersiz --format dizisi: {format}',
    'spinner.unknown_style'   => 'bilinmeyen spinner stili: {name}',
    'format.unknown_type'     => 'bilinmeyen --type: {type}',
    'format.unknown_theme'    => 'bilinmeyen tema: {name}',
    'io.read_failed' => 'dosya okunamadı: {path}',
    'io.write_failed' => 'dosyaya yazılamadı: {path}',
    'log.unknown_formatter' => 'bilinmeyen --formatter: {name}',
    'completion.unknown_shell' => 'desteklenmeyen kabuk: {shell}',
    'confirm.unknown_default' => 'bilinmeyen --default değeri: {value}',
    'cursor_mode.unknown' => 'bilinmeyen imleç modu: {mode}',
];
