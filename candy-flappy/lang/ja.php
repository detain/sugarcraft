<?php

/**
 * Japanese translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'スコア: {score}',
    'ui.best'                => ' ベスト: {best}',
    'ui.new_best'            => ' 🏆 ハイスコア更新: {best}',
    'ui.plain_new_best'      => ' ハイスコア更新: {best}',
    'ui.crash_full'          => '💥 激突 — r で再挑戦、q で終了',
    'ui.crash_short'         => '💥 激突 — r 再挑戦 · q 終了',
    'ui.crash_floor'         => 'r 再挑戦 · q 終了',
    'ui.ready_full'          => 'スペース/⏎/↑/w でスタート · r 再起動 · q 終了',
    'ui.ready_short'         => 'スペースでスタート · q 終了',
    'ui.live_full'           => 'スペース/⏎/↑/w はばたく · r 再起動 · q 終了',
    'ui.live_short'          => 'スペースではばたく · q 終了',
    'ui.plain_crash_full'    => 'x 激突 - r で再挑戦、q で終了',
    'ui.plain_crash_short'   => 'x 激突 - r 再挑戦 - q 終了',
    'ui.plain_crash_floor'   => 'r 再挑戦 - q 終了',
    'ui.plain_ready_full'    => 'スペース/Enter/^/w でスタート . r 再起動 . q 終了',
    'ui.plain_ready_short'   => 'スペースでスタート . q 終了',
    'ui.plain_live_full'     => 'スペース/Enter/^/w はばたく . r 再起動 . q 終了',
    'ui.plain_live_short'    => 'スペースではばたく . q 終了',
    'ui.quit_hint' => 'q キーで終了します。',
    'ui.quarantine'           => '⚠ 前回のリーダーボードを読み取れませんでした — {file} としてアーカイブしました',
    'ui.plain_quarantine'     => 'x 前回のリーダーボードを読み取れませんでした - {file} としてアーカイブしました',
    'notty.line1'            => 'candy-flappy は出力を描画できるターミナルが必要です。',
    'notty.line2'            => 'stdout が対話型ターミナルでないか、TERM=dumb が検出されました。',
    'size.notice'            => 'candy-flappy には {need} のターミナルが必要です。現在は {have} です。',
    'size.resume'            => 'ウィンドウを広げると、このままゲームが再開します。',
];
