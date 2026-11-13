<?php

/**
 * Turkish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'boş renk',
    'style.unrecognised_color' => 'tanınmayan renk: {value} — allowed: {allowed}',
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
    'spinner.windows_unsupported' => 'spin, yerel Windows üzerinde komut çalıştıramaz: işlem arkaucu burada desteklenmiyor — bunun yerine WSL kullanın.',
    'format.unknown_theme'    => 'bilinmeyen tema: {name}',
    'io.read_failed' => 'dosya okunamadı: {path}',
    'io.write_failed' => 'dosyaya yazılamadı: {path}',
    'log.unknown_formatter' => 'bilinmeyen --formatter: {name}',
    'completion.unknown_shell' => 'desteklenmeyen kabuk: {shell}',
    'confirm.unknown_default' => 'bilinmeyen --default değeri: {value}',
    'cursor_mode.unknown' => 'bilinmeyen imleç modu: {mode}',
    'runtime.no_terminal' => '{command} etkileşimli bir uçbirim gerektirir; denetim uçbirimi yok.',
    'runtime.bad_timeout' => 'Geçersiz --timeout değeri \'{value}\' — negatif olmayan bir saniye sayısı bekleniyor (0 son tarihi kapatır).',
    'runtime.unknown_choice' => '{option} için geçersiz değer: \'{value}\'. İzin verilenler: {allowed}.',
    'hints.choose' => '↑/↓ taşı · boşluk değiştir (çoklu) · enter seç · esc iptal',
    'hints.filter' => 'filtrelemek için yaz · ↑/↓ taşı · sekme değiştir (çoklu) · enter seç · esc iptal',
    'hints.pager' => 'space/PgDn aşağı · PgUp/b yukarı · g baş · G son · q çık',
    'format.template_unresolved' => 'şablon: {count} yer tutucu boş olarak genişletildi: {names} — bir değişken ayarlanmalı ve --allow-env ile izin verilmelidir.',
    'runtime.empty_input' => 'Girdi satır üretmedi; yapılacak bir şey yok.',
    'runtime.conflicting_flags' => '{first} ve {second} bayrakları farklı değerlerle birleştirilemez.',
];
