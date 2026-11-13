<?php

/**
 * Japanese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => '盤面が小さすぎます',
    'board.minecount_out_of_range' => '地雷数 が範囲外です',
    'board.rows_shape_mismatch' => 'グリッドデータが {width}×{height} の盤面と一致しません',
    'custom.min_rows' => '行数は2以上である必要があります',
    'custom.min_cols' => '列数は2以上である必要があります',
    'custom.max_rows' => '行数は50以下である必要があります',
    'custom.max_cols' => '列数は50以下である必要があります',
    'custom.min_mines' => '地雷は1つ以上必要です',
    'custom.max_mines' => '地雷が多すぎます — 安全な開始領域を残してください',
    'custom.min_area'            => 'カスタム盤は最低10マス必要（行 × 列）',
    'custom.label' => '{width}×{height} · 地雷 {mines} 個',
    'ui.status' => '地雷: {mines}  ·  フラグ: {flags}  ·  残り: {remaining}  ·  時間: {time}',
    'ui.status_stats' => '{preset}  ·  フラグ: {flags}  ·  残り: {remaining}  ·  時間: {time}  ·  最高: {best}',
    'ui.preset_easy' => '初級',
    'ui.preset_standard' => '標準',
    'ui.preset_medium' => '中級',
    'ui.preset_expert' => 'エキスパート',
    'ui.boom' => '💥 ドカン — r で再スタート · n で盤面選択',
    'ui.cleared' => '★ クリア — r でもう一度 · n で盤面選択',
    'ui.help_rules' => '数字は周囲の地雷の数 · 怪しいマスに旗 · 安全なマスを開き尽くせば勝ち',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  移動  ·  space / enter  開く',
    'ui.help_board' => '左クリック  開く · f / 右クリック  旗 · c / 中クリック  周囲を開く',
    'ui.help_loss' => '{mark} 誤った旗 · * 取り逃した地雷',
    'ui.help_session' => 'r  リスタート  ·  S  保存  ·  L  読込  ·  q / Esc / Ctrl+C  終了',
    'ui.saved' => 'ゲームを保存しました',
    'ui.restarted' => '盤面を再開始しました',
    'ui.loaded' => 'セーブデータをロードしました',
    'ui.no_save' => 'ロードするセーブデータがありません',
    'ui.load_refused' => 'セーブデータを拒否しました（読込不能または改ざん）',
    'ui.save_failed' => '保存に失敗しました',
    'ui.chord_not_ready' => '周囲を開くには旗数が数字と一致する必要がある',
    'ui.confirm_quit' => 'もう一度 q で終了 — このゲームは未保存',
    'ui.resume' => 'セーブデータがあります — L でロード',
    'ui.new_best' => '新記録',
    'ui.beat_best' => '{prev} → 新記録',
    'ui.picker_title' => '盤面を選ぶ',
    'ui.picker_custom' => 'カスタム',
    'ui.picker_hint' => '1-4 で開始 · c でカスタム · q / Esc で戻る',
    'ui.form_width' => '幅',
    'ui.form_height' => '高さ',
    'ui.form_mines' => '地雷',
    'ui.form_hint' => '数字を入力 · Enter で次へ · 地雷で Enter が開始 · Esc で戻る',
    'ui.fit_notice' => 'candy-minesweeper には高さ {need} 行のターミナルが必要です。現在は {have} 行です。ウィンドウを広げると、収まり次第ゲームに復帰します。q で終了。',
];
