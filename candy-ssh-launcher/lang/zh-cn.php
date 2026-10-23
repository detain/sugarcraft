<?php

/**
 * Simplified Chinese translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '执行 {bin} 失败',
    'config.not_found'         => '未找到 wishlist 配置：{path}',
    'config.json_top_level'    => 'wishlist json：顶层值必须是数组',
    'config.json_entry_object' => 'wishlist json：每个条目必须是对象',
    'config.yaml_continuation' => "wishlist yaml：在任何 '- name:' 块之前不能有续行",
    'config.yaml_unparseable'  => 'wishlist yaml：无法解析的行：{line}',
    'config.entry_missing_field' => 'wishlist 条目缺少必需字段：name 或 host',
    'config.field_not_scalar'  => 'wishlist 条目：字段 [{field}] 必须是标量，实际为 {type}',
    'config.unreadable'       => '无法读取 wishlist 配置：{path}',
    'config.home_unset'       => '没有 HOME 无法展开 ~：{path}',
    'config.yaml_inline_flow' => 'wishlist yaml：不支持内联流集合：{value}（使用嵌套列表）',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 端点：字段 [{field}] 含有以短横线开头的不安全值 [{value}]',
    'endpoint.port_invalid'     => 'wishlist 端点 [{host}]：无效端口 [{port}] — 必须是 1 到 65535 之间的整数',

    'cli.usage'                => '用法：candy-ssh-launcher [--config <路径>] [--ssh <ssh二进制>] [--list] [-h|--help]',
    'cli.unknown_arg'          => 'candy-ssh-launcher：未知参数：{arg}',
    'cli.no_config'            => 'candy-ssh-launcher：未找到配置。请传递 --config <路径>。',
    'cli.error'                => 'candy-ssh-launcher：{message}',
    'cli.cancelled'            => 'candy-ssh-launcher：已取消。',
    'cli.ssh_not_executable' => 'candy-ssh-launcher：找不到 ssh 程序或它不可执行：{bin}',
    'cli.missing_value'  => 'candy-ssh-launcher：{arg} 需要值',
    'cli.no_endpoints'   => 'candy-ssh-launcher：{path} 中没有端点',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => '筛选: ',
    'picker.no_matches'  => '  （无匹配）',
    'picker.help'        => '  ↑/↓ 选择 · Enter 连接 · Esc 退出 · 输入过滤',
    'picker.count'       => '  （{shown}/{total}）',
];
