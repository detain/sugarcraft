<?php

/**
 * Turkish translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'tema dosyası okunamadı: {path}',
    'theme.json_invalid'           => 'tema dosyasında geçersiz JSON',
    'theme.json_object'            => 'tema JSON\'u bir nesneye dönüştürülmelidir',
    'theme.bad_color'              => 'geçersiz renk tanımı: {spec}',
    'renderer.unknown_style'       => 'bilinmeyen standart stil: {name}',
    'renderer.stream_invalid'      => 'write() bir akış kaynağı gerektirir',
    'renderer.stream_not_writable' => 'write() yazılabilir bir akış gerektirir; "{mode}" kipi alındı',
    'renderer.write_failed'        => 'akışa {bytes} bayt yazılamadı',
    'renderer.chunk_invalid'       => 'stream() parçaları dize olmalıdır',
    'sink.closed'                  => 'sink kapatıldı',
    'sink.parent_missing'          => 'çıkış dizini mevcut değil: {dir}',
    'sink.path_unwritable'         => 'çıkış yolu yazılabilir değil: {path}',
    'sink.open_failed'             => 'çıkış yolu açılamadı: {path}',
    'sink.flush_failed'            => 'çıkış akışı boşaltılamadı',
    'writer.feed_after_close'      => 'close() sonrasında feed() çağrıldı — yazıcı tamamlandı',
];
