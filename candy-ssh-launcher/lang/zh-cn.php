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
    'config.yaml_unterminated_quote' => 'wishlist yaml：值中有未闭合的引号：{found}',
    'config.entry_missing_field' => 'wishlist 第 {index} 项缺少必填字段：{field}',
    'config.unknown_key' => '未知字段 `{key}` 已忽略 —— 并非文档化的键',
    'config.unknown_key_nearmiss' => '未知字段 `{key}` 已忽略 — 是否指 `{field}`？',
    'config.duplicate_name' => '端点名称 `{name}` 重复 — 所有行仍会加载；名称是标识符',
    'config.field_not_scalar'  => 'wishlist 条目：字段 [{field}] 必须是标量，实际为 {type}',
    'config.too_large'          => '心愿单配置文件超过 {limit} 字节上限：{path}',
    'config.list_too_long'      => '心愿单项：字段 [{field}] 包含的条目超过加载器允许的 {limit} 项上限',
    'config.field_not_list'       => '心愿单项：字段 [{field}] 必须是值的列表，而不是键值映射',
    'config.encoding_utf16'       => '{path} 采用 UTF-16 编码 —— 请将配置重新保存为 UTF-8',
    'config.unreadable'       => '无法读取 wishlist 配置：{path}',
    'config.home_unset'       => '没有 $HOME 无法展开 ~：{path}',
    'config.yaml_inline_flow' => 'wishlist yaml：不支持内联流集合：{value}（使用嵌套列表）',

    // endpoint security
    'endpoint.option_injection' => 'wishlist 端点：字段 [{field}] 含有以短横线开头的不安全值 [{value}]',
    'endpoint.host_empty' => '端点 `{name}` 的 host 为空——可选行需要主机名',
    'endpoint.name_empty' => '端点名称为空 —— 可选择的一行需要名称',
    'endpoint.option_empty' => '端点 `{name}` 含空选项条目——空的 -o 值会被拒绝',
    'endpoint.port_invalid'     => 'wishlist 端点 [{host}]：无效端口 [{port}] —— 必须是 1 到 65535 之间的整数',

    'cli.usage'                => '用法：candy-ssh-launcher [--config <路径>] [--ssh <ssh二进制>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'          => 'candy-ssh-launcher：未知参数：{arg}',
    'cli.no_config'            => 'candy-ssh-launcher：未找到配置。请创建 ~/.config/wishlist.yml（参见 README 的快速开始），或传递 --config <路径>。',
    'cli.error'                => 'candy-ssh-launcher：{message}',
    'cli.cancelled'            => 'candy-ssh-launcher：已取消。',
    'cli.ssh_not_executable' => 'candy-ssh-launcher：找不到 ssh 程序或它不可执行：{bin} — 如果 ssh 位于其他位置，请传递 --ssh <路径>',
    'cli.missing_value'  => 'candy-ssh-launcher：{arg} 需要值',
    'cli.no_endpoints'   => 'candy-ssh-launcher：{path} 中没有端点',
    'cli.not_a_terminal' => '选择器需要交互式终端 —— stdin 和 stdout 必须是 TTY。如需机器可读输出，请使用 --list。',
    'cli.config_unowned'   => 'candy-ssh-launcher：拒绝属于其他用户的配置：{path} —— 配置属于可信输入；见 README 的 Security 一节。',
    'cli.config_writable'  => 'candy-ssh-launcher：拒绝组或其他用户可写的配置：{path} —— 请收紧为 0644 或 0600，或通过 --config 显式传入。',
    'cli.project_local'    => 'candy-ssh-launcher：正在使用项目本地 wishlist：{path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: 端点请求 -o {option} —— {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => '主机密钥校验被降级——服务器密钥可能未经校验',
    'cli.unsafe_option_effect_user_known_hosts_file' => '用户的已知主机信任库被重定向',
    'cli.unsafe_option_effect_global_known_hosts_file' => '系统级信任库被重定向',
    'cli.unsafe_option_effect_log_level' => 'ssh 警告与提示被隐藏',
    'cli.unsafe_option_effect_identity_agent' => '身份认证被委托给另一个代理套接字',
    'cli.unsafe_option_effect_certificate_file' => '提供了额外的身份认证证书',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => '筛选: ',
    'picker.no_matches'  => '  （无匹配 — 退格或 Esc）',
    'picker.help'        => '↑/↓ 选择 · Enter 连接 · Esc 退出 · 输入过滤',
    'picker.help_keys'   => '⌫ 删除 · Ctrl+U/W 清除 · PgUp/PgDn 翻页 · Home/End 跳转',
    'picker.count'       => '  （{shown}/{total}）',
];
