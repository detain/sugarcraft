<?php

/**
 * Turkish UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'skor:  %d
satır: %d
seviye: %d',
    'help_card'  => 'hareket
saat yönünde
ters yönde
yumuşak iniş
sert iniş
bekleme
duraklat
yeniden (sonra)
çık',
    'next_label' => 'sonraki:',
    'hold_label' => 'bekleme:',
    'paused'     => '[ duraklatıldı — devam için p ]',

    // Game-over banner
    'over.title'                => 'OYUN BİTTİ',
    'over.final_score'          => 'son skor: {points}',
    'over.restart_hint'         => 'yeniden başlatmak için r — çıkmak için q',
    'over.reason'               => 'sebep: {reason}',
    'over.reason.spawn_blocked' => 'sonraki parça için yer yok',
    'over.reason.top_out'       => 'yığın tavana ulaştı',
    'over.reason.crushed'       => 'çöp tarafından ezildi',

    // On-frame cues
    'hold_used'  => '(kullanıldı)',
    'combo_line' => 'kombo x%d',
    'b2b_line'   => 'B2B hazır',
    'over.best'    => 'en iyi: {points}',
    'over.new_best' => 'yeni rekor!',

    // Versus renderer
    'label.player'   => 'OYUNCU',
    'label.computer' => 'BİLGİSAYAR',
    'win.player'     => 'KAZANDIN!',
    'win.computer'   => 'BİLGİSAYAR KAZANDI!',
    'over.quit_hint' => 'çıkmak için q',
    'vs_keys'        => 'iniş
bekleme
duraklat
yeniden
çık',
    'vs.record' => 'rekor: {wins} galibiyet, {losses} mağlubiyet',
    'intro.title' => 'candy-tetris oyununa hoş geldin',
    'intro.goal' => 'satırları temizle - tepeye ulaşma',
    'intro.press' => 'oynamak için {keys} tuşuna bas',
    'intro.vs' => 'dövüş modu: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: bilinmeyen mod "{flag}"',
    'help_text'    => 'candy-tetris - Tetris klonu

Kullanım: candy-tetris [seçenekler]
  (yok)      Tek kişilik oyun
  -v, --vs   Bilgisayara karşı mod
  -h, --help Bu yardımı göster
  --version  Sürümü yazdır
',
    'cli.no_tty' => 'candy-tetris etkileşimli bir terminal gerektirir: onu bir boru veya yönlendirme yerine bir TTY\'den, TERM değeri dumb dışında olacak şekilde çalıştırın.',
];
