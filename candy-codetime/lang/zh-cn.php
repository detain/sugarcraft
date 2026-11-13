<?php

/**
 * Simplified Chinese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '已记录 {duration}s：{project} ({language})',
    'cli.usage' => "用法: candy-codetime [--backend=file|sqlite] <command> [args]\n\n命令:\n  dashboard              启动 TUI 仪表盘（读取所选后端）\n  push                   [<project> <language>] <file> [duration] [tags] | --  记录心跳\n  milestone              add [name] [description] | list  管理里程碑 (仅 sqlite)\n  export                 <csv|json|ics> [days]  导出心跳 (默认 7 天)\n  gaps                   [days]  显示至少 300 秒的未跟踪空档 (默认 7 天)\n  backup                 归档 30 天前的数据文件 (仅复制; 绝不删除)\n  help, --help, -h       显示此用法\n  --version, -v          打印版本\n\n选项:\n  --backend=file|sqlite  push/export/gaps/dashboard 的存储后端 (默认: file)\n  --time=<unix>          push: 将心跳记录到指定的 unix 秒，而不是现在\n  --sqlite               backup：用 VACUUM INTO 归档 SQLite 数据库（崩溃安全快照）\n  --theme=<name>         仪表盘：使用命名调色板绘制 (default, light, dracula, solarized, gruvbox, high-contrast)
  CANDY_CODETIME_THEME   以环境变量给出同样选择（命令行开关优先）\n  CANDY_CODETIME_DIR     数据目录 (默认: \$XDG_DATA_HOME/candy-codetime，未设置时用 \$HOME/.local/share/candy-codetime，HOME 也未设置时用 ./.local/share/candy-codetime)",
    'cli.push_usage' => '用法: candy-codetime push [<project> <language>] <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (stdin 批量 JSON 行)',
    'cli.push_missing_args' => "candy-codetime: push: 缺少必需参数 (project, language, file)",
    'cli.push_ignored' => "push: {file} 匹配 .sugartrackignore — 已跳过",
    'cli.push_ignored_wholesale' => 'candy-codetime: push：{file} 被 {ignore_file} 中的全匹配模式跳过（共 {patterns} 个模式）；项目“{project}”没有记录任何内容——请删除该文件或缩小其范围',
    'cli.push_invalid_duration' => 'candy-codetime: push: 时长必须为整数秒，收到 "{value}"',
    'cli.push_empty_field' => 'candy-codetime: push：{field} 不能为空',
    'cli.push_invalid_time' => 'candy-codetime: push: --time 必须为 unix 秒整数，收到 "{value}"',
    'cli.push_time_stdin_conflict' => 'candy-codetime: push: stdin 批量模式不接受 --time；请为每行提供自己的时间',
    'cli.push_stdin_malformed' => 'candy-codetime: push: stdin 第 {line} 行不是有效的心跳记录——已跳过',
    'cli.push_stdin_done' => 'push: 已记录 {count} 条心跳',
    'cli.push_stdin_done_one' => 'push: 已记录 {count} 条心跳',
    'cli.invalid_days' => 'candy-codetime: 天数必须为整数，收到 "{value}"',
    'cli.invalid_goal' => 'candy-codetime: CANDY_CODETIME_GOAL 必须是秒数的整数,或像 6h 这样的整小时数,收到的值 "{value}"',
    'cli.invalid_theme' => 'candy-codetime: 主题必须是 {names} 之一 - 收到 "{value}"',
    'cli.backend_invalid' => "candy-codetime: 无效的后端 (请使用 file 或 sqlite)",
    'cli.backend_unavailable' => "candy-codetime: sqlite 后端不可用: 未加载 PHP 扩展 sqlite3",
    'cli.backend_other_hint' => '注意：{other} 后端也存有记录 - 请使用 --backend={other} 选择它',
    'cli.storage_error' => 'candy-codetime: 存储错误：{message}',
    'cli.ignore_too_large' => 'candy-codetime: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime：仪表板需要交互式终端（stdin 与 stdout 不是 TTY）。无终端输出请使用：candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "用法: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "candy-codetime: milestone add: 缺少必需参数 (name)",
    'cli.milestone_added' => "里程碑已添加: {name}",
    'cli.milestone_list_header' => "里程碑:",
    'cli.milestone_list_empty' => "没有已记录的里程碑。",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'candy-codetime: 里程碑: {name} 已存入 sqlite 后端 - 文件存储无法保存里程碑，其数据库已在此创建；仪表盘通过 --backend=sqlite 显示这些里程碑',

    'cli.export_usage' => "用法: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "candy-codetime: export: 无效格式 (请使用 csv、json 或 ics)",
    'cli.gaps_no_data' => "未发现空档。",
    'cli.gaps_header' => "未跟踪的时间空档:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: 已归档 {count} 个文件",
    'cli.backup_done_one' => 'backup: 已归档 {count} 个文件',
    'cli.backup_none' => "backup: 没有可归档的新文件",
    'cli.backup_sqlite_notice' => 'candy-codetime: backup：此命令仅归档每日文件 — SQLite 数据库 {db} 请用 backup --sqlite 以获得崩溃安全副本',
    'cli.backup_sqlite_done' => 'backup：数据库已归档到 {file}',
    'cli.unknown_command' => "candy-codetime: 未知命令: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} 已弃用；请改为设置 CANDY_CODETIME_DIR。",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => '热门项目',
    'ui.by_language' => '按语言',
    'ui.by_tags' => '按标签',
    'ui.goal_today' => '今天',
    'ui.help_row' => '  ←  前一天  ·  →  后一天  ·  r  重新加载  ·  q/Esc  退出',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => '总计：',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '（无活动）',
    'ui.more_suffix' => '另有 +{count}',
    'ui.earlier_suffix' => '早先 +{count}',
    'ui.activity_minutes_heading' => '每日活动（分钟）',
    'ui.activity_heading' => '每日活动',
    'ui.no_data' => '（无数据）',
    'ui.diagnostics_dropped' => '从日文件中丢弃了 {count} 个损坏行',
    'ui.diagnostics_unreadable' => '无法读取 {count} 个日文件',
];
