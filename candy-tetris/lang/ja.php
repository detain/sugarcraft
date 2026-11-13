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
    'score_card' => 'スコア: {score}
ライン: {lines}
レベル: {level}',
    'help_card'  => '移動
右回転
左回転
180度回転
ソフトドロップ
ハードドロップ
ホールド
一時停止
リスタート(終)
終了',
    'next_label' => '次:',
    'hold_label' => 'ホールド:',
    'paused'     => '[ 一時停止中 — {keys} で再開 ]',

    // Game-over banner
    'over.title'                => 'ゲームオーバー',
    'over.final_score'          => '最終スコア: {points}',
    'over.restart_hint'         => '{restart} でリスタート — {quit} で終了',
    'over.reason'               => '理由: {reason}',
    'over.reason.spawn_blocked' => '次のテトリミノを置けません',
    'over.reason.top_out'       => '積みが天井に達しました',
    'over.reason.crushed'       => 'ガベージに潰されました',

    // On-frame cues
    'hold_used'  => '(使用済)',
    'combo_line' => 'コンボ x%d',
    'b2b_line'   => 'B2B 準備完了',
    'over.best'    => 'ベスト: {points}',
    'over.new_best' => '新記録！',
    'over.games' => '終了したゲーム: {games}',

    // Versus renderer
    'label.player'   => 'プレイヤー',
    'label.computer' => 'コンピューター',
    'win.player'     => 'あなたの勝ち!',
    'win.computer'   => 'コンピューターの勝ち!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'ライン消去で相手にゴミ行が届く（各行に穴が一つ）',
    'vs.record' => '通算: {wins}勝 {losses}敗',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => '自分',
    'vs.strip.computer' => 'コンピュータ',
    'vs.strip.score' => 'スコア:',
    'vs.strip.lines' => '行数:',
    'vs.strip.level' => 'レベル:',
    'intro.title' => 'candy-tetris へようこそ',
    'intro.goal' => '行を消す — 上まで積んだら終わり',
    'intro.press' => '{keys} で開始',
    // candy-tetris-006/-095 (ja): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'いつでも {keys} でキーを表示',
    'intro.vs' => '対戦モード: candy-tetris -v',
    'key_or' => ' または ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 不明なモード "{flag}"',
    'help_text'    => 'candy-tetris - テトリスのクローン

使い方: candy-tetris [オプション]
  (なし)     ワンプレーヤーを開始
  -v, --vs   コンピューター対戦
  -h, --help このヘルプを表示
  --version  バージョンを表示
',
    // candy-tetris-006/-095 (ja): --help keys section header.
    'help.keys' => 'キー:',
    // candy-tetris-006/-095 (ja): the press that closes the key reference.
    'help.close' => 'もう一度 {keys} で閉じる',
    // candy-tetris-006/-095 (ja): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => '枠には最低 {cols}x{rows} の端末が必要',
    // candy-tetris-006/-095 (ja): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => '{keys} でキー',
    // candy-tetris-054/-083 (ja): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.quit' => 'もう一度 {keys} で終了',
    // candy-tetris-054/-083 (ja): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.restart' => 'もう一度 {keys} でやり直し',
    // candy-tetris-092/-025 (ja): game-feel moments and the on-frame slot copy.
    'moment.lines.one' => '1列消去!',
    'moment.lines.many' => '{count}列消去!',
    'moment.level' => 'レベル {level}!',
    'moment.garbage' => 'ゴミが来る!',
    'cli.no_tty' => 'candy-tetris には対話型ターミナルが必要です。パイプやリダイレクトではなく TTY から、TERM を dumb 以外にして起動してください。',
    'launch.extras' => 'candy-tetris: 引数は1つまでです。超過 {count} 個: {list}',
    'score.save.failed' => 'candy-tetris: スコアを保存できませんでした ({problem})',
    'keys.ignored' => 'candy-tetris: 読み込めないキーファイルを無視しました ({problem})',
    'scores.ignored' => 'candy-tetris: 読み込めないスコアファイルを無視しました ({problem})',
    'config.problem' => '{path} — {detail}',
    'cli.refusal' => 'candy-tetris は対話型端末 (TTY) が必要です。
色なしでもプレイできます: NO_COLOR を設定。',
    'cli.too_small' => 'candy-tetris には {size} の端末が必要です。現在は {actual} です。
ウィンドウサイズを変更するとここから再開します。',
    'cli.too_small_vs' => 'candy-tetris VS には {size} の端末が必要です。現在は {actual} です。
ウィンドウサイズを変更すると対戦をここから再開します。',
];
