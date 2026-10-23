<?php

/**
 * Simplified Chinese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => '棋盘太小',
    'board.minecount_out_of_range' => 'mineCount 超出范围',
    'board.rows_shape_mismatch' => '行的形状与宽/高不匹配',
    'custom.min_rows' => '行数至少为 2',
    'custom.min_cols' => '列数至少为 2',
    'custom.max_rows' => '行数最多为 50',
    'custom.max_cols' => '列数最多为 50',
    'custom.min_mines' => '至少需要 1 个地雷',
    'custom.max_mines' => '地雷太多——请保留一个安全的起始区域',
    'ui.status' => '地雷: {mines}  ·  旗帜: {flags}  ·  剩余: {remaining}  ·  时间: {time}',
    'ui.boom' => '💥 砰 — 按 r 重新开始',
    'ui.cleared' => '★ 通关 — 按 r 再玩一局',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  移动  ·  space / enter  翻开',
    'ui.help_board' => 'f  插旗  ·  c / 中键  和弦  ·  右键  插旗',
    'ui.help_session' => 'r  重开  ·  S  保存  ·  L  读取  ·  q / Esc / Ctrl+C  退出',
];
