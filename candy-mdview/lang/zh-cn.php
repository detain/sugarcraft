<?php

/**
 * Simplified Chinese translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => '未知主题：{name}',
    'render.theme_config_unreadable' => '主题配置不可读：{path}',
    'render.theme_config_too_large' => '主题配置超过 {limit} 字节：{path}',
    'glamour.json_invalid' => '主题配置不是有效的 JSON',
    'glamour.json_object' => '主题配置必须是 JSON 对象',
    'render.no_input_tty' => '无输入：请指定文件或向标准输入传入 Markdown',
    'render.no_input_empty' => '无输入：标准输入为空',
    'render.file_missing' => '无法打开 {path}：文件不存在',
    'render.file_is_directory' => '无法打开 {path}：这是一个目录',
    'render.file_unreadable' => '无法打开 {path}：文件不可读',
    'render.binary_input' => '拒绝渲染 {path}：不是文本文件',
    'render.input_too_large' => '输入超过 {limit} 字节：{path}',
    'render.width_invalid' => '无效的 --width 值：{value}（应为 0 到 9999 之间的整数；0 表示不换行）',
];
