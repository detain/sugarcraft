<?php

/**
 * Japanese translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => '無効な SSH 公開鍵形式',
    'repo.create_dir_failed'   => 'リポジトリディレクトリの作成に失敗：{path}',
    'repo.git_init_failed'     => 'git init 失敗：{output}',
    'config.not_found'         => '設定ファイルが見つかりません：{path}',
    'config.read_failed'       => '設定の読み取りに失敗：{path}',
    'ssh.user_cannot_create'   => 'ユーザー {viewer} はリポジトリを作成できません',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'サーバーを起動中...',
    'cli.note_ssh2_required'   => '（完全デーモンモードには ssh2 拡張と実行中の SSH デーモンが必要です。）',
    'cli.note_run_init'        => "（最初に 'candy-git-server init' を実行してデータディレクトリを初期化してください。）",
    'cli.repos_header'         => 'リポジトリ：',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'まだデーモンとして実行されていません（デーモンモードにはプロセスマネージャーが必要です。）',
    'cli.note_http_help'       => 'Git を HTTP で提供服务するには、Web サーバーをこのスクリプトにポイントしてください。',
    'cli.note_ssh_help'        => 'SSH アクセスにはリバーストンネルを使用するか、sshd を ForceCommand で設定してください。',
    'cli.already_initialized'  => 'すでに初期化済み：{path}',
    'cli.initializing'         => 'CandyGitServer データディレクトリを初期化中：{path}',
    'cli.done'                 => '完了。',
    'cli.next_steps'           => '次のステップ：',
    'cli.next_step_1'          => '  1. {path}/config.yaml を編集',
    'cli.next_step_2'          => '  2. SSH ホスト鍵を生成：ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=あなたのSSH公開鍵 を設定',
    'cli.next_step_4'          => '  4. 実行：candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => '用法：candy-git-server user add|key|list',
    'cli.usage_user_add'       => '用法：candy-git-server user add <username>',
    'cli.usage_user_key'       => '用法：candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "ユーザー '{username}' を作成（admin: true）",
    'cli.user_key_hint'        => "SSH 公開鍵を追加するには 'candy-git-server user key {username} < key.pub' を使用してください。",
    'cli.user_key_read_failed' => '鍵の読み取りに失敗：{file}',
    'cli.user_key_added'       => "ユーザー '{username}' に鍵を追加しました",
    'cli.user_keys_header'     => '承認済み鍵：',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "ユーザー：\n  （登録ユーザーがいません。'candy-git-server user add <username>' を使用してください）",
    'cli.usage_repo_root'      => '用法：candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => '用法：candy-git-server repo create <name>',
    'cli.usage_repo_info'      => '用法：candy-git-server repo info <name>',
    'cli.no_repos'             => 'リポジトリはまだありません。',
    'cli.repo_listing_none'    => '  （リポジトリはまだありません）',
    'cli.repo_invalid_name'    => '無効なリポジトリ名：{name}（英数字、ピリオド、アンダースコア、ハイフンのみ）',
    'cli.repo_created'         => "リポジトリ '{name}' を作成しました（{path}）",
    'cli.repo_clone_url'       => 'クローン URL：ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'リポジトリが見つかりません：{name}',
    'cli.repo_info'            => "名前：         {name}\n説明：  {description}\n公開：       {is_public}\n非公開：      {is_private}\nプッシュを許可：   {allow_push}\nパス：         {path}\nブランチ：     {branches}\nタグ：         {tags}",
    'cli.bool_yes'             => 'はい',
    'cli.bool_no'              => 'いいえ',
    'cli.none_value'           => '（なし）',
    'cli.unknown_command'      => "不明なコマンド：{cmd}\n  帮助は 'candy-git-server help' を実行してください。",
    'cli.help'                 => "CandyGitServer — セルフホスト Git サーバー\n\n用法：\n  candy-git-server <コマンド> [オプション]\n\nコマンド：\n  serve [--config path]    Git サーバーを起動\n  init [data-path]         新しいデータディレクトリを初期化\n  user add <username>      ユーザーを作成\n  user key <username> <file>  ユーザーの SSH 公開鍵を追加（stdin は - を使用）\n  user list                ユーザー一覧\n  repo list [data-path]    リポジトリ一覧\n  repo create <name> [data-path]  リポジトリを作成\n  repo info <name> [data-path]    リポジトリ情報を表示\n  help, --help, -h         このヘルプを表示\n  version, --version, -v   バージョンを表示\n\n環境変数：\n  CANDY_GIT_SERVER_DATA_PATH    データディレクトリ（デフォルト：/tmp/candy-git-server）\n\n例：\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
