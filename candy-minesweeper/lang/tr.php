<?php

/**
 * Turkish translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tahta çok küçük',
    'board.minecount_out_of_range' => 'mineCount aralık dışında',
    'board.rows_shape_mismatch' => 'satır şekli genişlik/yükseklik ile eşleşmiyor',
    'custom.min_rows' => 'satırlar en az 2 olmalı',
    'custom.min_cols' => 'sütunlar en az 2 olmalı',
    'custom.max_rows' => 'satırlar en fazla 50 olmalı',
    'custom.max_cols' => 'sütunlar en fazla 50 olmalı',
    'custom.min_mines' => 'en az 1 mayın gerekli',
    'custom.max_mines' => 'çok fazla mayın — güvenli bir başlangıç alanı bırakın',
    'ui.status' => 'mayın: {mines}  ·  bayrak: {flags}  ·  kalan: {remaining}  ·  süre: {time}',
    'ui.boom' => '💥 bum — yeniden başlatmak için r tuşuna basın',
    'ui.cleared' => '★ tamamlandı — tekrar oynamak için r tuşuna basın',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  hareket  ·  space / enter  aç',
    'ui.help_board' => 'f  bayrak  ·  c / orta tık  akor  ·  sağ tık  bayrak',
    'ui.help_session' => 'r  yeniden  ·  S  kaydet  ·  L  yükle  ·  q / Esc / Ctrl+C  çık',
];
