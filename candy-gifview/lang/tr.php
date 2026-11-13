<?php

/**
 * Turkish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Kullanım: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php bulunamadı',
    'cli.bad_flag'    => 'candy-gifview: bilinmeyen seçenek --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag} için geçersiz değer \'{value}\' (pozitif bir sayı bekleniyor)',
    'cli.too_many'    => 'candy-gifview: beklenmeyen bağımsız değişken \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} değer almaz',
    'cli.bad_preset' => 'candy-gifview: bilinmeyen ön ayar \'{preset}\' (solid veya density bekleniyor)',
    'cli.no_frames' => 'candy-gifview: \'{path}\' içinde oynatılacak kare yok',
    'cli.cannot_play' => 'candy-gifview: \'{path}\' oynatılamadı: {reason}',
    'cli.reason_frames_over' => 'animasyon {max} karelik alma sınırını aşıyor; yükleme reddedildi',
    'cli.reason_bytes_over' => 'dosya {max} MiB alma sınırından büyük',
    'cli.reason_cells_over' => 'animasyon bu pencere için {max} hücrelik bellek bütçesini aşıyor',
    'cli.duration_static' => 'candy-gifview: --duration en az iki kare ister; bu dosya {count} kareye çözülüyor',
    'cli.need_gd' => 'candy-gifview: GIF çözmek için ext-gd gerekli (php-gd yükleyin)',
    'cli.decoding' => 'candy-gifview: \'{path}\' çözülüyor...',
    'cli.truncated' => 'candy-gifview: ilk {max} kare gösteriliyor — bu görüntüleyici daha uzun animasyonları okumaz',
];
