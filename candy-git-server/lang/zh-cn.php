<?php

/**
 * Simplified Chinese translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'SSH 公钥格式无效',
    'repo.create_dir_failed'   => '创建仓库目录失败：{path}',
    'repo.git_init_failed'     => 'git init 失败：{output}',
    'repo.visibility_marker_unwritable' => '可见性标记写入失败：{file}',
    'config.not_found'         => '配置文件未找到：{path}',
    'config.read_failed'       => '读取配置失败：{path}',
    'ssh.user_cannot_create'   => '用户 {viewer} 无法创建仓库',
    'ssh.invalid_repo_name'    => '无效的仓库名称：{name}',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => '仓库：',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name}（公开）',
    'cli.already_initialized'  => '已初始化：{path}',
    'cli.initializing'         => '正在初始化 CandyGitServer 数据目录：{path}',
    'cli.done'                 => '完成。',
    'cli.next_steps'           => '下一步：',
    'cli.next_step_1'          => '  1. 创建账户: candy-git-server user add admin {path}',

    'cli.next_step_2'          => '  2. 创建仓库: candy-git-server repo create <名称> {path} (加 --public 公开匿名读取)',

    'cli.next_step_3'          => '  3. 通过 smart HTTP 服务: candy-git-server serve --http --repo-root {path}/repositories',
    'cli.usage_user_root'      => '用法：candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => '用法：candy-git-server user add <username> [data-path] [--password <pw>|-]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] mint an HTTP proxy token signature',
    'cli.usage_user_key'       => '用法：candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "用户 '{username}' 已创建（admin: true）",
    'cli.user_password_stored' => '密码已作为哈希存储。',
    'cli.user_key_hint'        => "使用 'candy-git-server user key {username} <key-file|->' 添加 SSH 公钥。",
    'cli.user_key_read_failed' => '读取密钥失败：{file}',
    'cli.user_key_added'       => "已为用户 '{username}' 添加密钥",
    'cli.user_keys_header'     => '授权密钥：',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "用户：\n  （尚无注册用户。请使用 'candy-git-server user add <username>'）",
    'cli.user_list_header' => "用户（{path}）:",
    'cli.usage_repo_root'      => '用法：candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => '用法：candy-git-server repo create <name>',
    'cli.usage_repo_info'      => '用法：candy-git-server repo info <name>',
    'cli.no_repos'             => '尚无仓库。',
    'cli.repo_listing_none'    => '  （尚无仓库）',
    'cli.repo_invalid_name'    => '无效的仓库名称：{name}（仅限字母、数字、点、下划线、连字符，不能以点或连字符开头）',
    'cli.repo_created'         => "仓库 '{name}' 已创建于 {path}",
    'cli.repo_not_found'       => '仓库未找到：{name}',
    'cli.repo_info'            => "名称：         {name}\n描述：  {description}\n公开：       {is_public}\n私有：      {is_private}\n允许推送：   {allow_push}\n路径：         {path}\n分支：     {branches}\n标签：         {tags}",
    'cli.bool_yes'             => '是',
    'cli.bool_no'              => '否',
    'cli.none_value'           => '（无）',
    'cli.unknown_command'      => "未知命令：{cmd}\n  运行 'candy-git-server help' 获取帮助。",
    'cli.help'                 => "CandyGitServer — 自托管 Git 服务器\n\n用法：\n  candy-git-server <命令> [选项]\n\n命令：\n  serve [--config path]    启动 Git 服务器\n  init [data-path]         初始化新的数据目录\n  user add <username>      创建用户\n  user key <username> <file>  为用户添加 SSH 公钥（使用 - 表示标准输入）\n  user list                列出用户\n  repo list [data-path]    列出仓库\n  repo create <name> [data-path]  创建仓库\n  repo info <name> [data-path]    显示仓库信息\n  help, --help, -h         显示此帮助\n  version, --version, -v   显示版本\n\n环境变量：\n  CANDY_GIT_SERVER_DATA_PATH    数据目录（默认：/tmp/candy-git-server）\n\n示例：\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => '{type} 的 SSH 公钥块太短: {len} < {min} base64 字符',
    'config.parse_failed'   => 'YAML 配置无效: {error}',
    'config.invalid_user_trust_mode' => 'http.user_trust_mode "{mode}" 无效: 期望 off、proxy 或 token',
    'config.tls_not_supported' => '已设置 http.tls_key_path/tls_cert_path，但 HTTPS 监听器尚未接通；设置它们并不会启用 TLS。请在支持 TLS 前移除。',
    'jobs.invalid_schedule' => '作业计划 "{schedule}" 无效: {reason}',
    'cli.option_needs_value' => '选项 {option} 需要一个值',
    'cli.serve_http_needs_repo_root' => 'serve --http 需要 --repo-root 目录 (其子目录被服务的目录)',
    'cli.serve_repo_root_not_dir' => '--repo-root 不是目录: {path}',
    'cli.serve_http_port_range' => '--http-port 超出范围: {port}',
    'cli.serve_http_banner' => 'smart-HTTP 监听器于 {addr} ({repos} 个仓库, {published} 个公开, {users} 个账户; 匿名读取={anon}; 推送也在服务 — 钩子拒绝随带内传输)',
];
