<?php

/**
 * Turkish translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'puan: {score}',
    'ui.best'                => ' rekor: {best}',
    'ui.new_best'            => ' 🏆 YENİ REKOR: {best}',
    'ui.plain_new_best'      => ' YENI REKOR: {best}',
    'ui.crash_full'          => '💥 {why} — yeniden başlatmak için r · çıkmak için q',
    'ui.crash_short'         => '💥 {why} — r yeniden · q çık',
    'ui.crash_floor'         => 'r yeniden · q çık',
    'ui.ready_full'          => 'başlamak için boşluk/⏎/↑/w · q çık',
    'ui.ready_short'         => 'başlamak için boşluk · q çık',
    'ui.live_full'           => 'çırpma için boşluk/⏎/↑/w · r yeniden · q çık',
    'ui.live_short'          => 'çırpma için boşluk · q çık',
    'ui.plain_crash_full'    => 'x {why} - yeniden baslatmak icin r - cikmak icin q',
    'ui.plain_crash_short'   => 'x {why} - r yeniden - q cik',
    'ui.plain_crash_floor'   => 'r yeniden - q cik',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'tavana çarptın',
    'ui.why_ground'           => 'yere çarptın',
    'ui.why_pipe'             => 'boruya çarptın',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'tavana carptin',
    'ui.plain_why_ground'     => 'yere carptin',
    'ui.plain_why_pipe'       => 'boruya carptin',
    'ui.plain_ready_full'    => 'baslamak icin bosluk/Enter/up/w . q cik',
    'ui.plain_ready_short'   => 'baslamak icin bosluk . q cik',
    'ui.plain_live_full'     => 'cirpma icin bosluk/Enter/up/w . r yeniden . q cik',
    'ui.plain_live_short'    => 'cirpma icin bosluk . q cik',
    'ui.quit_hint' => 'çıkmak için q tuşuna basın.',
    'ui.quarantine'           => '⚠ Önceki puan tablosu okunamadı — {file} olarak arşivlendi',
    'ui.plain_quarantine'     => 'x Önceki puan tablosu okunamadı - {file} olarak arşivlendi',
    'notty.line1'            => 'candy-flappy, çıktısını gösterebilen bir terminal gerektirir.',
    'notty.line2'            => 'stdout etkileşimli bir terminal değil veya TERM=dumb algılandı.',
    'size.notice'            => 'candy-flappy {need} bir terminal ister; bu {have}.',
    'size.resume'            => 'Pencereyi büyütün, oyun tam burada devam eder.',
    'config.no_dir'          => 'yapılandırmanızın konumu bulunamıyor — $HOME veya $XDG_CONFIG_HOME ortam değişkenini yapılandırma dizininize ayarlayın.',
];
