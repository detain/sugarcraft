<?php

/**
 * Simplified Chinese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "条目已记录: {project} ({language}) +{duration}s",
    'cli.usage' => "用法: candy-codetime [--backend=file|sqlite] <command> [args]\n\n命令:\n  dashboard              记录心跳\n  push                   <project> <language> <file> [duration]  管理里程碑 (仅 sqlite)\n  milestone              <add|list> [name] [description]  导出心跳 (默认 7 天)\n  export                 <csv|json|ics> [days]  显示超过 300 秒的未跟踪空档 (默认 7 天)\n  gaps                   [days]  归档 30 天前的数据文件 (仅复制; 绝不删除)\n  backup                 显示此用法\n  help, --help           打印版本\n  --version, -v          默认: file\n\n选项:\n  --backend=file|sqlite  push/export/gaps/dashboard 的存储后端 (默认: file)\n  CANDY_CODETIME_DIR     数据目录 (默认: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "用法: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: 缺少必需参数 (project, language, file)",
    'cli.push_ignored' => "push: {file} 匹配 .sugartrackignore — 已跳过",
    'cli.push_invalid_duration' => "push: 时长必须为非负整数秒，收到 \"{value}\"",
    'cli.backend_invalid' => "无效的后端 (请使用 file 或 sqlite)",
    'cli.backend_unavailable' => "sqlite 后端不可用: 未加载 PHP 扩展 sqlite3",
    'cli.milestone_usage' => "用法: candy-codetime milestone <add|list> [name] [description]",
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
    'cli.backup_none' => "backup: 没有可归档的新文件",
    'cli.unknown_command' => "candy-codetime: 未知命令: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} 已弃用；请改为设置 CANDY_CODETIME_DIR。",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
