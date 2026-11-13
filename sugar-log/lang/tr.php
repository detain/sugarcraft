<?php

/**
 * Turkish translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'öldürücü günlük: {message}',
    'logger.invalid_stream' => 'Akış geçerli bir kaynak olmalıdır',
    'psrbridge.not_string' => 'Günlük seviyesi PSR-3 LogLevel sabitlerinden biri olmalı; alınan {got}',
    'psrbridge.unknown_level' => 'Bilinmeyen günlük seviyesi \'{level}\'; PSR-3 LogLevel sabitlerinden biri bekleniyor',
    'parts.unknown' => 'bilinmeyen günlük satırı bölüm(ler)i: {parts}',
    'parts.duplicate' => 'yinelenen günlük satırı bölüm(ler)i: {parts}',
    'log.fatal_error' => 'Ölümcül hata',
    'log.fatal_parse' => 'Ayrıştırma hatası',
    'log.fatal_core' => 'Çekirdek hatası',
    'log.fatal_compile' => 'Derleme hatası',
    'log.fatal_user' => 'Kullanıcı hatası',
    'log.fatal_shutdown' => 'Kapatma hatası',
];
