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
    'config.yaml_unparseable'  => 'wishlist yaml：无法解析的行 {line}：{found}',
    'config.yaml_block_mapping' => 'wishlist yaml：第 {line} 行在 `{field}:` 下的块映射：{found}——该字段必须是 `- value` 条目列表',
    'config.entry_missing_field' => 'wishlist 第 {index} 项缺少必填字段：{field}',
    'config.unknown_key' => '未知字段 `{key}` 已忽略 — 并非文档化的键',
    'config.unknown_key_nearmiss' => '未知字段 `{key}` 已忽略 — 是否指 `{field}`？',
    'config.duplicate_name' => '端点名称 `{name}` 重复 — 所有行仍会加载；名称是标识符',
    'config.field_not_scalar'  => 'wishlist 条目：字段 [{field}] 必须是标量，实际为 {type}',
    'config.unreadable'       => '无法读取 wishlist 配置：{path}',
    'config.home_unset'       => '没有 HOME 无法展开 ~：{path}',
    'config.yaml_inline_flow' => 'wishlist yaml：不支持内联流集合：{value}（使用嵌套列表）',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 端点：字段 [{field}] 含有以短横线开头的不安全值 [{value}]',
    'endpoint.host_empty' => '端点 `{name}` 的 host 为空——可选行需要主机名',
    'endpoint.name_empty' => '端点名称为空 — 可选择的一行需要名称',
    'endpoint.option_empty' => '端点 `{name}` 含空选项条目——空的 -o 值会被拒绝',
    'endpoint.port_invalid'     => 'wishlist 端点 [{host}]：无效端口 [{port}] — 必须是 1 到 65535 之间的整数',

    'cli.usage'                => '用法：candy-ssh-launcher [--config <路径>] [--ssh <ssh二进制>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'          => 'candy-ssh-launcher：未知参数：{arg}',
    'cli.no_config'            => 'candy-ssh-launcher：未找到配置。请创建 ~/.config/wishlist.yml（参见 README 的快速开始），或传递 --config <路径>。',
    'cli.error'                => 'candy-ssh-launcher：{message}',
    'cli.cancelled'            => 'candy-ssh-launcher：已取消。',
    'cli.ssh_not_executable' => 'candy-ssh-launcher：找不到 ssh 程序或它不可执行：{bin}',
    'cli.missing_value'  => 'candy-ssh-launcher：{arg} 需要值',
    'cli.no_endpoints'   => 'candy-ssh-launcher：{path} 中没有端点',
    'cli.not_a_terminal' => '选择器需要交互式终端 — stdin 和 stdout 必须是 TTY。如需机器可读输出，请使用 --list。',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => '筛选: ',
    'picker.no_matches'  => '  （无匹配）',
    'picker.help'        => '  ↑/↓ 或 j/k 选择 · Enter 连接 · Esc 退出 · 输入过滤',
    'picker.count'       => '  （{shown}/{total}）',
];
