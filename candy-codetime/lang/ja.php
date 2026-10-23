<?php

/**
 * Japanese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "エントリを登録しました: {project} ({language}) +{duration}s",
    'cli.usage' => "使用法: candy-codetime [--backend=file|sqlite] <command> [args]\n\nコマンド:\n  dashboard              ハートビートを記録\n  push                   <project> <language> <file> [duration]  マイルストーン管理 (sqlite のみ)\n  milestone              <add|list> [name] [description]  ハートビートをエクスポート (既定 7 日)\n  export                 <csv|json|ics> [days]  300 秒以上の未追跡ギャップを表示 (既定 7 日)\n  gaps                   [days]  30 日より古いデータファイルをアーカイブ (コピーのみ。削除しません)\n  backup                 この使用法を表示\n  help, --help           バージョンを表示\n  --version, -v          既定: file\n\nオプション:\n  --backend=file|sqlite  push/export/gaps/dashboard のストレージバックエンド (既定: file)\n  CANDY_CODETIME_DIR     データディレクトリ (既定: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "使用法: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: 必須引数が不足しています (project, language, file)",
    'cli.push_ignored' => "push: {file} は .sugartrackignore に一致したためスキップしました",
    'cli.push_invalid_duration' => "push: 持続時間は秒単位の非負整数である必要があります。受け取った値: \"{value}\"",
    'cli.backend_invalid' => "無効なバックエンド (file または sqlite を使用)",
    'cli.backend_unavailable' => "sqlite バックエンドは利用できません: PHP 拡張機能 sqlite3 が読み込まれていません",
    'cli.milestone_usage' => "使用法: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: 必須引数が不足しています (name)",
    'cli.milestone_added' => "マイルストーンを追加しました: {name}",
    'cli.milestone_list_header' => "マイルストーン:",
    'cli.milestone_list_empty' => "記録されたマイルストーンはありません。",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "使用法: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: 無効な形式 (csv、json または ics を使用)",
    'cli.gaps_no_data' => "ギャップは見つかりませんでした。",
    'cli.gaps_header' => "未追跡の時間ギャップ:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} 個のファイルをアーカイブしました",
    'cli.backup_none' => "backup: アーカイブする新しいファイルはありません",
    'cli.unknown_command' => "candy-codetime: 不明なコマンド: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} は非推奨です。代わりに CANDY_CODETIME_DIR を設定してください。",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
