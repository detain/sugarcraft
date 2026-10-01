<?php

/**
 * Turkish translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'candy-flip: dosya bulunamadı: {path}',
    'decoder.no_gd'            => 'candy-flip: ext-gd gerekli',
    'decoder.not_gif'          => 'candy-flip: GIF dosyası değil',
    'decoder.grid_too_large'   => 'candy-flip: hücre ızgara çarpımı en yüksek değeri ({max}) aşıyor',
    'decoder.grid_too_small'   => 'candy-flip: hücre ızgara boyutları pozitif olmalı',
    'decoder.truncated'        => 'candy-flip: eksik GIF',
    'decoder.screen_too_large' => 'candy-flip: GIF ekran boyutları en yüksek değeri ({max} piksel) aşıyor',
    'cli.usage'                => 'Kullanım: candy-flip <gif> [solid|density]',
    'cli.no_autoload'          => 'candy-flip: composer autoload.php bulunamadı',
];
