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
    'ui.best'                => ' en iyi: {best}',
    'ui.new_best'            => ' 🏆 YENİ REKOR: {best}',
    'ui.plain_new_best'      => ' YENI REKOR: {best}',
    'ui.crash_full'          => '💥 splat — yeniden denemek için r, çıkmak için q tuşuna basın',
    'ui.crash_short'         => '💥 splat — r yeniden · q çık',
    'ui.ready_full'          => 'başlamak için boşluk/⏎/↑/w · r yeniden · q çık',
    'ui.ready_short'         => 'başlamak için boşluk · q çık',
    'ui.live_full'           => 'çırpma için boşluk/⏎/↑/w · r yeniden · q çık',
    'ui.live_short'          => 'çırpma için boşluk · q çık',
    'ui.plain_crash_full'    => 'x splat - yeniden denemek icin r, cikmak icin q tusuna basin',
    'ui.plain_crash_short'   => 'x splat - r yeniden - q cik',
    'ui.plain_ready_full'    => 'baslamak icin bosluk/Enter/^/w . r yeniden . q cik',
    'ui.plain_ready_short'   => 'baslamak icin bosluk . q cik',
    'ui.plain_live_full'     => 'cirpma icin bosluk/Enter/^/w . r yeniden . q cik',
    'ui.plain_live_short'    => 'cirpma icin bosluk . q cik',
    'ui.quit_hint' => 'çıkmak için q tuşuna basın.',
    'notty.line1'            => 'candy-flappy etkileşimli bir terminal gerektirir.',
    'notty.line2'            => 'Kullanılabilir renk yeteneği algılanmadı (stdout TTY değil veya TERM=dumb).',
    'size.notice'            => 'candy-flappy {need} bir terminal ister; bu {have}.',
    'size.resume'            => 'Pencereyi büyütün, oyun tam burada devam eder.',
];
