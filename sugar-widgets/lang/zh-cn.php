<?php

/**
 * Simplified Chinese translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => '列宽必须 >= 0',
    'tabs.bad_index'              => '标签索引超出范围',
    'tabs.neg_width'              => '标签宽度必须 >= 0',
    'table.dim_nonneg'            => '表格宽/高必须 >= 0',
    'table.page_size_nonneg'      => '页面大小必须 >= 0',
    'table.set_columns_type'      => '列必须是列实例',
    'table.sort_unknown_column'   => '找不到排序列: {column}',
    'progress.width_nonneg'       => '进度条宽度必须 >= 0',
    'tree.dim_nonneg'             => '树宽/高必须 >= 0',
    'help.width_nonneg'           => '帮助宽度必须 >= 0',
    'stopwatch.interval_positive' => '秒表间隔必须 > 0',
    'timer.duration_nonneg'       => '计时器持续时间必须 >= 0',
    'timer.interval_positive'     => '计时器间隔必须 > 0',
    'paginator.arabic_format_invalid' => '阿拉伯数字格式必须是包含两个整数的有效格式',
    'progress.bad_percent_format' => '百分比格式必须是接受一个整数的有效格式',
    'progress.bad_value_format' => '值格式必须是接受两个整数的有效格式',
    'progress.rune_cell_width' => '进度条字符必须恰好占一个显示单元',
    'progress.color_func_type' => '颜色函数必须返回颜色值',
    'tabs.next_tab' => '下一个标签页',
    'tabs.prev_tab' => '上一个标签页',
    'tabs.jump_to' => '标签页 {index}',
    'timer.paused_tag' => '已暂停',
    'timer.timeout_tag' => '超时',
    'stopwatch.not_started_tag' => '未开始',
    'stopwatch.paused_tag' => '已暂停',
    'key.bad_binding' => '无效的按键绑定 {key}：请使用小写按键名 (ctrl+c, shift+tab, pageup, f5) 或单个可打印字符',
    'key.bad_binding_type' => '按键绑定的键必须是字符串，收到的类型为 {key}',
];
