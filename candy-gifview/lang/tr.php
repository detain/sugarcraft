<?php

/**
 * Turkish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Kullanım: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php bulunamadı',
    'cli.bad_flag'    => 'candy-gifview: bilinmeyen seçenek --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag} için geçersiz değer \'{value}\' (pozitif bir sayı bekleniyor; --speed ayrıca Nfps veya max kabul eder)',
    'cli.too_many'    => 'candy-gifview: beklenmeyen bağımsız değişken \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} değer almaz',
];
