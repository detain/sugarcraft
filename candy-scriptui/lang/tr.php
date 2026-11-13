<?php

/**
 * Turkish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Boş renk.',
    'style.unrecognised_color' => 'Tanınmayan renk: {value} — İzin verilenler: {allowed}.',
    'style.hex_digit_count' => "Hex rengi 3 veya 6 haneli olmalıdır (örn. '#f80' veya '#ff8800'); alınan: '{value}'.",
    'style.padding_token_int' => "Padding/margin belirteci integer olmalıdır; alınan: '{token}'.",
    'style.padding_count'     => 'Padding/margin 1, 2 veya 4 integer gerektirir; alınan: {count}.',
    'style.bad_entry'         => "--style girdileri 'anahtar=değer' veya 'öğe.özellik=değer' olmalıdır; alınan: '{raw}'.",
    'style.unknown_prop'      => "Bilinmeyen stil özelliği: '{prop}'.",
    'process.spawn_failed'    => 'Alt süreç başlatılamadı',
    'border.unknown'          => 'Bilinmeyen kenarlık stili: {name}.',
    'log.unknown_level'       => 'Bilinmeyen günlük düzeyi: {name}.',
    'log.encode_failed' => 'Log kaydı json olarak kodlanamadı.',
    'log.bad_format' => 'Geçersiz --format dizisi: {format}.',
    'spinner.unknown_style'   => 'Bilinmeyen spinner stili: {name}.',
    'spinner.windows_unsupported' => 'Spin, yerel Windows üzerinde komut çalıştıramaz: işlem arkaucu burada desteklenmiyor — bunun yerine WSL kullanın.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Bilinmeyen tema: {name}.',
    'io.read_failed' => 'Dosya okunamadı: {path}.',
    'io.write_failed' => 'Dosyaya yazılamadı: {path}.',
    'runtime.no_terminal' => '{command} etkileşimli bir uçbirim gerektirir; denetim uçbirimi yok.',
    'runtime.bad_timeout' => "Geçersiz --timeout değeri '{value}' — negatif olmayan bir saniye sayısı veya 5s/250ms/1m30s gibi bir süre bekleniyor (0 son tarihi kapatır).",
    'runtime.bad_number' => "{flag} değeri '{value}' geçersiz — negatif olmayan bir tam sayı bekleniyor.",
    'runtime.pcntl_missing' => 'ext-pcntl yüklü değil: SIGINT/SIGTERM, 130/143 çıkış eşlemesi olmadan bu programı sonlandırır.',
    'runtime.unknown_choice' => "{option} için geçersiz değer: '{value}'. İzin verilenler: {allowed}.",
    'hints.choose' => '↑/↓ gez · / filtrele · Enter seç · Esc iptal',
    'hints.choose.multi' => '↑/↓ gez · Boşluk değiştir · / filtrele · Enter seç · Esc iptal',
    'hints.filter' => 'filtrele · ↑/↓ gez · Tab seç · Enter · Esc',
    'hints.pager' => '↑/↓ j/k gez · ←/→ h/l kaydır · PgUp/b PgDn/Boşluk sayfa · Ctrl+U/D yarım',
    'hints.pager.more' => 'g/G veya Home/End baş/son · q/Esc çık',
    'format.template_unresolved' => 'Şablon: {count} yer tutucu boş olarak genişletildi: {names} — bir değişken ayarlanmalı ve --allow-env ile izin verilmelidir.',
    'runtime.empty_input' => 'Girdi satır üretmedi; yapılacak bir şey yok.',
    'runtime.empty_input_filter' => 'Filtrelenecek bir şey yok; standart girdi satır üretmedi.',
    'runtime.empty_input_pager' => 'Sayfalayacak bir şey yok; standart girdi satır üretmedi.',
    'runtime.conflicting_flags' => '{first} ve {second} bayrakları farklı değerlerle birleştirilemez.',
    'runtime.empty_selection' => 'Öğe seçilmedi — Enter öncesi en az bir öğeyi işaretlemek için Space tuşuna basın.',
];
