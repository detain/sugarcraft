<?php

/**
 * Simplified Chinese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '已记录 {duration}s：{project} ({language})',
    'cli.usage' => "用法: candy-codetime [--backend=file|sqlite] <command> [args]\n\n命令:\n  dashboard              启动 TUI 仪表盘（读取所选后端）\n  push                   <project> <language> <file> [duration]  记录心跳\n  milestone              add [name] [description] | list  管理里程碑 (仅 sqlite)\n  export                 <csv|json|ics> [days]  导出心跳 (默认 7 天)\n  gaps                   [days]  显示至少 300 秒的未跟踪空档 (默认 7 天)\n  backup                 归档 30 天前的数据文件 (仅复制; 绝不删除)\n  help, --help, -h       显示此用法\n  --version, -v          打印版本\n\n选项:\n  --backend=file|sqlite  push/export/gaps/dashboard 的存储后端 (默认: file)\n  CANDY_CODETIME_DIR     数据目录 (默认: \$XDG_DATA_HOME/candy-codetime，未设置时用 \$HOME/.local/share/candy-codetime，HOME 也未设置时用 ./.local/share/candy-codetime)",
    'cli.push_usage' => "用法: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: 缺少必需参数 (project, language, file)",
    'cli.push_ignored' => "push: {file} 匹配 .sugartrackignore — 已跳过",
    'cli.push_ignored_wholesale' => 'push：{file} 被 {ignore_file} 中的全匹配模式跳过（共 {patterns} 个模式）；项目“{project}”没有记录任何内容——请删除该文件或缩小其范围',
    'cli.push_invalid_duration' => 'push: 时长必须为整数秒，收到 "{value}"',
    'cli.invalid_days' => '天数必须为整数，收到 "{value}"',
    'cli.backend_invalid' => "无效的后端 (请使用 file 或 sqlite)",
    'cli.backend_unavailable' => "sqlite 后端不可用: 未加载 PHP 扩展 sqlite3",
    'cli.storage_error' => 'candy-codetime: 存储错误：{message}',
    'cli.dashboard_needs_tty' => 'candy-codetime：仪表板需要交互式终端（stdin 与 stdout 不是 TTY）。无终端输出请使用：candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "用法: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: 缺少必需参数 (name)",
    'cli.milestone_added' => "里程碑已添加: {name}",
    'cli.milestone_list_header' => "里程碑:",
    'cli.milestone_list_empty' => "没有已记录的里程碑。",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "用法: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: 无效格式 (请使用 csv、json 或 ics)",
    'cli.gaps_no_data' => "未发现空档。",
    'cli.gaps_header' => "未跟踪的时间空档:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: 已归档 {count} 个文件",
    'cli.backup_done_one' => 'backup: 已归档 {count} 个文件',
    'cli.backup_none' => "backup: 没有可归档的新文件",
    'cli.backup_sqlite_notice' => 'backup：SQLite 数据库 {db} 不会由此命令归档 — 请使用 sqlite3 <db> ".backup <dest>" 或 VACUUM INTO 以获得可崩溃安全的副本',
    'cli.unknown_command' => "candy-codetime: 未知命令: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} 已弃用；请改为设置 CANDY_CODETIME_DIR。",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
