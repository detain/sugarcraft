<?php

/**
 * Japanese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => '盤面が小さすぎます',
    'board.minecount_out_of_range' => 'mineCount が範囲外です',
    'board.rows_shape_mismatch' => '行の形状が幅/高さと一致しません',
    'custom.min_rows' => '行数は2以上である必要があります',
    'custom.min_cols' => '列数は2以上である必要があります',
    'custom.max_rows' => '行数は50以下である必要があります',
    'custom.max_cols' => '列数は50以下である必要があります',
    'custom.min_mines' => '地雷は1つ以上必要です',
    'custom.max_mines' => '地雷が多すぎます — 安全な開始領域を残してください',
    'ui.status' => '地雷: {mines}  ·  フラグ: {flags}  ·  残り: {remaining}  ·  時間: {time}',
    'ui.boom' => '💥 ドカン — r で再スタート',
    'ui.cleared' => '★ クリア — r でもう一度',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  移動  ·  space / enter  開く',
    'ui.help_board' => 'f  旗  ·  c / 中クリック  和音  ·  右クリック  旗',
    'ui.help_session' => 'r  リスタート  ·  S  保存  ·  L  読込  ·  q / Esc / Ctrl+C  終了',
];
