<?php

/**
 * Turkish translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Kayıt yazıldı ancak artık okunamıyor (süresi doldu, silindi veya bozuk)',
    'store.cannot_read'         => 'Dosya okunamıyor: {path}',
    'cli.usage_set'             => 'Kullanım: {bin} set <anahtar> [değer] [--ttl SECONDS]',
    'cli.usage_get'             => 'Kullanım: {bin} get <anahtar>',
    'cli.usage_delete'          => 'Kullanım: {bin} delete <anahtar>',
    'cli.usage_import'           => 'Kullanım: {bin} import <json|yaml|yml> <yol>',
    'cli.usage_export'            => 'Kullanım: {bin} export <json|yaml|yml> [veritabanı] [desen]',
    'cli.deleted_n'             => '{count} giriş silindi.',
    'cli.unknown_command'       => 'Bilinmeyen komut: {cmd}',
    'cli.deleted_none'           => 'Hiçbir şey silinmedi: "{key}" ile eşleşen kayıt yok.',
    'cli.pattern_not_supported'  => 'get tam bir anahtar ister, desen değil. Glob desenleri için "{bin} list <pattern>" kullanın.',
    'store.did_you_mean'         => 'anahtar bulunamadı; \'{key}\' mi demek istediniz?',
    'database.query_failed'      => 'Veritabanı sorgusu başarısız oldu.',
];
