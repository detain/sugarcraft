<?php

/**
 * Japanese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '{duration}s を記録: {project} ({language})',
    'cli.usage' => "使用法: candy-codetime [--backend=file|sqlite] <command> [args]\n\nコマンド:\n  dashboard              TUI ダッシュボードを開始 (選択中のバックエンドを読み取ります)\n  push                   <project> <language> <file> [duration] | --  ハートビートを記録\n  milestone              add [name] [description] | list  マイルストーン管理 (sqlite のみ)\n  export                 <csv|json|ics> [days]  ハートビートをエクスポート (既定 7 日)\n  gaps                   [days]  300 秒以上の未追跡ギャップを表示 (既定 7 日)\n  backup                 30 日より古いデータファイルをアーカイブ (コピーのみ。削除しません)\n  help, --help, -h       この使用法を表示\n  --version, -v          バージョンを表示\n\nオプション:\n  --backend=file|sqlite  push/export/gaps/dashboard のストレージバックエンド (既定: file)\n  --time=<unix>          push: 現在ではなく指定の Unix 秒でハートビートを記録\n  CANDY_CODETIME_DIR     データディレクトリ (既定: \$XDG_DATA_HOME/candy-codetime、未設定なら \$HOME/.local/share/candy-codetime、HOME も未設定なら ./.local/share/candy-codetime)",
    'cli.push_usage' => '使用法: candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (stdin からの JSON 一括)',
    'cli.push_missing_args' => "push: 必須引数が不足しています (project, language, file)",
    'cli.push_ignored' => "push: {file} は .sugartrackignore に一致したためスキップしました",
    'cli.push_ignored_wholesale' => 'push: {ignore_file} 内の全一致パターン（{patterns} 件）により {file} をスキップ；プロジェクト「{project}」は何も記録されません — ファイルを削除するか範囲を狭めてください',
    'cli.push_invalid_duration' => 'push: 持続時間は秒単位の整数である必要があります。受け取った値: "{value}"',
    'cli.push_invalid_time' => 'push: --time は Unix エポック秒の整数である必要があります。受け取った値: "{value}"',
    'cli.push_time_stdin_conflict' => 'push: stdin バッチモードでは --time を使用できません。各行にそれぞれの時刻を指定してください',
    'cli.push_stdin_malformed' => 'push: stdin の行 {line} は有効なハートビートレコードではありません — スキップ',
    'cli.push_stdin_done' => 'push: {count} 件のハートビートを記録しました',
    'cli.push_stdin_done_one' => 'push: {count} 件のハートビートを記録しました',
    'cli.invalid_days' => '日数は整数である必要があります。受け取った値: "{value}"',
    'cli.backend_invalid' => "無効なバックエンド (file または sqlite を使用)",
    'cli.backend_unavailable' => "sqlite バックエンドは利用できません: PHP 拡張機能 sqlite3 が読み込まれていません",
    'cli.backend_other_hint' => '注意: {other} バックエンドにも記録があります。--backend={other} で選択してください',
    'cli.storage_error' => 'candy-codetime: ストレージエラー: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: ダッシュボードには対話型ターミナルが必要です (stdin と stdout が TTY でありません)。非対話の出力は candy-codetime export <csv|json|ics> を使ってください',
    'cli.milestone_usage' => "使用法: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: 必須引数が不足しています (name)",
    'cli.milestone_added' => "マイルストーンを追加しました: {name}",
    'cli.milestone_list_header' => "マイルストーン:",
    'cli.milestone_list_empty' => "記録されたマイルストーンはありません。",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'マイルストーン: {name} は sqlite バックエンドに保存しました - ファイルストアはマイルストーンを保存できないためここにデータベースを作成しました; ダッシュボードでは --backend=sqlite で表示します',

    'cli.export_usage' => "使用法: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: 無効な形式 (csv、json または ics を使用)",
    'cli.gaps_no_data' => "ギャップは見つかりませんでした。",
    'cli.gaps_header' => "未追跡の時間ギャップ:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} 個のファイルをアーカイブしました",
    'cli.backup_done_one' => 'backup: {count} 個のファイルをアーカイブしました',
    'cli.backup_none' => "backup: アーカイブする新しいファイルはありません",
    'cli.backup_sqlite_notice' => 'backup: SQLite データベース {db} はこのコマンドではアーカイブされません — 破損しないコピーには sqlite3 <db> ".backup <dest>" または VACUUM INTO を使用してください',
    'cli.unknown_command' => "candy-codetime: 不明なコマンド: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} は非推奨です。代わりに CANDY_CODETIME_DIR を設定してください。",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => '主なプロジェクト',
    'ui.by_language' => '言語別',
    'ui.help_row' => '  ←  前日  ·  →  翌日  ·  r  再読み込み  ·  q/Esc  終了',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => '合計:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(活動なし)',
    'ui.more_suffix' => 'ほか +{count}',
    'ui.earlier_suffix' => '+{count} 以前',
    'ui.activity_minutes_heading' => '日次アクティビティ（分）',
    'ui.activity_heading' => '日次アクティビティ',
    'ui.no_data' => '(データなし)',
    'ui.diagnostics_dropped' => '日次ファイルから破損行を {count} 行除外しました',
    'ui.diagnostics_unreadable' => '日次ファイルを {count} 件読み込めませんでした',
];
