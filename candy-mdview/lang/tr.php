<?php

/**
 * Turkish translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'bilinmeyen tema: {name}',
    'render.theme_config_unreadable' => 'tema yapılandırması okunamıyor: {path}',
    'render.theme_config_too_large' => 'tema yapılandırması {limit} baytı aşıyor: {path}',
    'glamour.json_invalid' => 'tema yapılandırması geçerli bir JSON değil',
    'glamour.json_object' => 'tema yapılandırması bir JSON nesnesi olmalı',
    'glamour.decoration_unsafe' => 'tema alanı {slot} yalnızca satır sonu, sekme ve boşluk içerebilir',
    'glamour.value_too_large' => 'tema anahtarı {key}, {limit} sınırını aşıyor',
    'render.no_input_tty' => 'girdi yok: bir dosya belirtin veya Markdown\'ı standart girdiye yönlendirin',
    'render.no_input_empty' => 'girdi yok: standart girdi boş',
    'render.pager_needs_tty' => 'sayfalayıcı bir terminal ister; -p olmadan standart çıktıya işleyin',
    'render.file_missing' => '{path} açılamadı: dosya yok',
    'render.file_is_directory' => '{path} açılamadı: bir dizin',
    'render.file_unreadable' => '{path} açılamadı: dosya okunamıyor',
    'render.binary_input' => '{path} işlemesi reddedildi: metin dosyası değil',
    'render.input_too_large' => 'girdi {limit} baytı aşıyor: {path}',
    'render.width_invalid' => 'geçersiz --width değeri: {value} (0 ile 9999 arasında tam sayı beklendi; 0 sarmalamayı kapatır)',
];
