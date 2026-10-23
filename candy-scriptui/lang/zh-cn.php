<?php

/**
 * Simplified Chinese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '颜色为空',
    'style.unrecognised_color' => '无法识别的颜色：{value}',
    'style.hex_digit_count' => "十六进制颜色必须有 3 或 6 位十六进制数字（例如 '#f80' 或 '#ff8800'）；获得：'{value}'",
    'style.padding_token_int' => "padding/margin 标记必须是整数；实际：'{token}'",
    'style.padding_count'     => 'padding/margin 需要 1、2 或 4 个整数；实际：{count}',
    'style.bad_entry'         => "--style 条目必须是 'key=value' 或 'element.prop=value'；实际：'{raw}'",
    'style.unknown_prop'      => "未知样式属性：'{prop}'",
    'process.spawn_failed'    => '启动子进程失败',
    'border.unknown'          => '未知边框样式：{name}',
    'log.unknown_level'       => '未知日志级别：{name}',
    'log.encode_failed' => '将日志条目编码为 json 失败',
    'log.bad_format' => '无效的 --format 字符串：{format}',
    'spinner.unknown_style'   => '未知微调器样式：{name}',
    'format.unknown_type'     => '未知的 --type：{type}',
    'format.unknown_theme'    => '未知主题：{name}',
    'io.read_failed' => '无法读取文件：{path}',
    'io.write_failed' => '无法写入文件：{path}',
    'log.unknown_formatter' => '未知 --formatter：{name}',
    'completion.unknown_shell' => '不支持的 shell：{shell}',
    'confirm.unknown_default' => '未知 --default 值：{value}',
    'cursor_mode.unknown' => '未知光标模式：{mode}',
];
