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
    'config.not_found'         => '配置文件未找到：{path}',
    'config.read_failed'       => '读取配置失败：{path}',
    'ssh.user_cannot_create'   => '用户 {viewer} 无法创建仓库',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => '正在启动服务器...',
    'cli.note_ssh2_required'   => '（完整守护进程模式需要 ssh2 扩展和运行中的 SSH 守护进程。）',
    'cli.note_run_init'        => "（请先运行 'candy-git-server init' 初始化数据目录。）",
    'cli.repos_header'         => '仓库：',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => '尚未以守护进程模式运行（守护进程模式需要进程管理器。）',
    'cli.note_http_help'       => '要通过 HTTP 提供 Git 服务，请将 Web 服务器指向此脚本。',
    'cli.note_ssh_help'        => '如需 SSH 访问，请使用反向隧道或配置 sshd 的 ForceCommand。',
    'cli.already_initialized'  => '已初始化：{path}',
    'cli.initializing'         => '正在初始化 CandyGitServer 数据目录：{path}',
    'cli.done'                 => '完成。',
    'cli.next_steps'           => '下一步：',
    'cli.next_step_1'          => '  1. 编辑 {path}/config.yaml',
    'cli.next_step_2'          => '  2. 生成 SSH 主机密钥：ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. 设置 CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=您的SSH公钥',
    'cli.next_step_4'          => '  4. 运行：candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => '用法：candy-git-server user add|key|list',
    'cli.usage_user_add'       => '用法：candy-git-server user add <username>',
    'cli.usage_user_key'       => '用法：candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "用户 '{username}' 已创建（admin: true）",
    'cli.user_key_hint'        => "使用 'candy-git-server user key {username} < key.pub' 添加 SSH 公钥。",
    'cli.user_key_read_failed' => '读取密钥失败：{file}',
    'cli.user_key_added'       => "已为用户 '{username}' 添加密钥",
    'cli.user_keys_header'     => '授权密钥：',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "用户：\n  （尚无注册用户。请使用 'candy-git-server user add <username>'）",
    'cli.usage_repo_root'      => '用法：candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => '用法：candy-git-server repo create <name>',
    'cli.usage_repo_info'      => '用法：candy-git-server repo info <name>',
    'cli.no_repos'             => '尚无仓库。',
    'cli.repo_listing_none'    => '  （尚无仓库）',
    'cli.repo_invalid_name'    => '无效的仓库名称：{name}（仅限字母、数字、点、下划线、连字符）',
    'cli.repo_created'         => "仓库 '{name}' 已创建于 {path}",
    'cli.repo_clone_url'       => '克隆 URL：ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => '仓库未找到：{name}',
    'cli.repo_info'            => "名称：         {name}\n描述：  {description}\n公开：       {is_public}\n私有：      {is_private}\n允许推送：   {allow_push}\n路径：         {path}\n分支：     {branches}\n标签：         {tags}",
    'cli.bool_yes'             => '是',
    'cli.bool_no'              => '否',
    'cli.none_value'           => '（无）',
    'cli.unknown_command'      => "未知命令：{cmd}\n  运行 'candy-git-server help' 获取帮助。",
    'cli.help'                 => "CandyGitServer — 自托管 Git 服务器\n\n用法：\n  candy-git-server <命令> [选项]\n\n命令：\n  serve [--config path]    启动 Git 服务器\n  init [data-path]         初始化新的数据目录\n  user add <username>      创建用户\n  user key <username> <file>  为用户添加 SSH 公钥（使用 - 表示标准输入）\n  user list                列出用户\n  repo list [data-path]    列出仓库\n  repo create <name> [data-path]  创建仓库\n  repo info <name> [data-path]    显示仓库信息\n  help, --help, -h         显示此帮助\n  version, --version, -v   显示版本\n\n环境变量：\n  CANDY_GIT_SERVER_DATA_PATH    数据目录（默认：/tmp/candy-git-server）\n\n示例：\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
