<?php

/**
 * Japanese UI strings for candy-tetris.
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
    'score_card' => 'スコア: %d
ライン: %d
レベル: %d',
    'help_card'  => '← →  移動
↑ x  右回転
z    左回転
↓    ソフトドロップ
spc  ハードドロップ
c    ホールド
p    一時停止
r    リスタート
q    終了',
    'next_label' => '次:',
    'hold_label' => 'ホールド:',
    'paused'     => '[ 一時停止中 — p で再開 ]',

    // Game-over banner
    'over.title'                => 'ゲームオーバー',
    'over.final_score'          => '最終スコア: {points}',
    'over.restart_hint'         => 'r でリスタート — q で終了',
    'over.reason'               => '理由: {reason}',
    'over.reason.spawn_blocked' => '次のテトリミノを置けません',
    'over.reason.top_out'       => '積みが天井に達しました',
    'over.reason.crushed'       => 'ガベージに潰されました',

    // On-frame cues
    'hold_used'  => '(使用済)',
    'combo_line' => 'コンボ x%d',
    'b2b_line'   => 'B2B 準備完了',

    // Versus renderer
    'label.player'   => 'プレイヤー',
    'label.computer' => 'コンピューター',
    'win.player'     => 'あなたの勝ち!',
    'win.computer'   => 'コンピューターの勝ち!',
    'over.quit_hint' => 'q で終了',
    'vs_keys'        => 'spc 落とす  c ホールド  p 停止  r 再開  q 終了',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 不明なモード "{flag}"',
    'help_text'    => 'candy-tetris - テトリスのクローン

使い方: candy-tetris [オプション]
  (なし)     ワンプレーヤーを開始
  -v, --vs   コンピューター対戦
  -h, --help このヘルプを表示
  --version  バージョンを表示
'
];
