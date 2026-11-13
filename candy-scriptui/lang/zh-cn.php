<?php

/**
 * Simplified Chinese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '颜色为空',
    'style.unrecognised_color' => '无法识别的颜色：{value} — allowed: {allowed}',
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
    'spinner.windows_unsupported' => 'spin 无法在原生 Windows 上运行命令：该平台的进程后端不受支持——请改用 WSL。',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => '未知主题：{name}',
    'io.read_failed' => '无法读取文件：{path}',
    'io.write_failed' => '无法写入文件：{path}',
    'log.unknown_formatter' => '未知 --formatter：{name}',
    'completion.unknown_shell' => '不支持的 shell：{shell}',
    'confirm.unknown_default' => '未知 --default 值：{value}',
    'cursor_mode.unknown' => '未知光标模式：{mode}',
    'runtime.no_terminal' => '{command} 需要交互式终端；没有可用的控制终端。',
    'runtime.bad_timeout' => '--timeout 值“{value}”无效——需要非负秒数（0 表示不限）。',
    'runtime.bad_number' => '{flag} 值 \'{value}\' 无效 — 需要非负整数。',
    'runtime.unknown_choice' => '{option} 的值“{value}”无效。允许：{allowed}。',
    'hints.choose' => '↑/↓ 移动 · space 切换 (多选) · enter 选择 · esc 取消',
    'hints.filter' => '输入以筛选 · ↑/↓ 移动 · tab 切换 (多选) · enter 选择 · esc 取消',
    'hints.pager' => 'space/PgDn 下翻 · PgUp/b 上翻 · g 开头 · G 结尾 · q 退出',
    'format.template_unresolved' => '模板：{count} 个占位符已展开为空：{names} — 必须设置变量并通过 --allow-env 允许。',
    'runtime.empty_input' => '输入没有产生任何行；不执行操作。',
    'runtime.conflicting_flags' => '不能将 {first} 与 {second} 以不同的值同时使用。',
];
