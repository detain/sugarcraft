<?php

/**
 * Japanese translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => '列幅は >= 0 である必要があります',
    'tabs.bad_index'              => 'タブインデックスが範囲外です',
    'tabs.neg_width'              => 'タブの幅は >= 0 である必要があります',
    'table.dim_nonneg'            => 'テーブルの幅/高さは >= 0 である必要があります',
    'table.page_size_nonneg'      => 'ページサイズは >= 0 である必要があります',
    'table.set_columns_type'      => '列は列インスタンスである必要があります',
    'table.sort_unknown_column'   => 'ソート列が見つかりません: {column}',
    'progress.width_nonneg'       => 'プログレスの幅は >= 0 である必要があります',
    'tree.dim_nonneg'             => 'ツリーの幅/高さは >= 0 である必要があります',
    'help.width_nonneg'           => 'ヘルプの幅は >= 0 である必要があります',
    'stopwatch.interval_positive' => 'ストップウォッチの間隔は > 0 である必要があります',
    'timer.duration_nonneg'       => 'タイマーの持続時間は >= 0 である必要があります',
    'timer.interval_positive'     => 'タイマーの間隔は > 0 である必要があります',
    'paginator.arabic_format_invalid' => 'アラビア数字形式は2つの整数の有効な整形形式でなければなりません',
    'progress.bad_percent_format' => 'パーセント形式は整数1個の有効な整形形式でなければなりません',
    'progress.bad_value_format' => '値形式は整数2個の有効な整形形式でなければなりません',
    'progress.rune_cell_width' => 'バーの文字はちょうど1表示セル幅でなければなりません',
    'progress.color_func_type' => '色関数は色値を返す必要があります',
    'tabs.next_tab' => '次のタブ',
    'tabs.prev_tab' => '前のタブ',
    'tabs.jump_to' => 'タブ{index}',
    'timer.paused_tag' => '一時停止',
    'timer.timeout_tag' => 'タイムアウト',
    'stopwatch.not_started_tag' => '未開始',
    'stopwatch.paused_tag' => '一時停止',
    'key.bad_binding' => '無効なキーバインド {key}：小文字のキー名 (ctrl+c, shift+tab, pageup, f5) または印字可能文字1文字を使用してください',
    'key.bad_binding_type' => 'キーバインドのキーは文字列である必要があります。受け取った型: {key}',
];
