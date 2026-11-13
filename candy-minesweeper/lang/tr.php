<?php

/**
 * Turkish translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tahta çok küçük',
    'board.minecount_out_of_range' => 'mayın sayısı aralık dışında',
    'board.rows_shape_mismatch' => 'ızgara verileri {width}×{height} tahtasıyla eşleşmiyor',
    'custom.min_rows' => 'satırlar en az 2 olmalı',
    'custom.min_cols' => 'sütunlar en az 2 olmalı',
    'custom.max_rows' => 'satırlar en fazla 50 olmalı',
    'custom.max_cols' => 'sütunlar en fazla 50 olmalı',
    'custom.min_mines' => 'en az 1 mayın gerekli',
    'custom.max_mines' => 'çok fazla mayın — güvenli bir başlangıç alanı bırakın',
    'ui.status' => 'mayın: {mines}  ·  bayrak: {flags}  ·  kalan: {remaining}  ·  süre: {time}',
    'ui.boom' => '💥 bum — yeniden başlatmak için r tuşuna basın',
    'ui.cleared' => '★ tamamlandı — tekrar oynamak için r tuşuna basın',
    'ui.help_rules' => 'sayı yakındaki mayınları sayar',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  hareket  ·  space / enter  aç',
    'ui.help_board' => 'sol tık  aç · f / sağ tık  bayrak · c / orta tık  çevreyi aç',
    'ui.help_session' => 'r  yeniden  ·  S  kaydet  ·  L  yükle  ·  q / Esc / Ctrl+C  çık',
    'ui.saved' => 'Oyun kaydedildi',
    'ui.restarted' => 'Tahta yeniden başlatıldı',
    'ui.loaded' => 'Oyun yüklendi',
    'ui.no_save' => 'Yüklenecek kayıt yok',
    'ui.load_refused' => 'Kayıt reddedildi (okunamıyor veya değiştirilmiş)',
    'ui.save_failed' => 'Kaydetme başarısız',
    'ui.resume' => 'Bir kayıt bekliyor — yüklemek için L tuşuna bas',
    'ui.new_best' => 'YENİ REKOR',
    'ui.fit_notice' => 'candy-minesweeper {need} satır yüksekliğinde bir terminal ister; bu terminal {have} satır. Pencereyi büyütün — oyun sığdığı anda devam eder. q çıkar.',
];
