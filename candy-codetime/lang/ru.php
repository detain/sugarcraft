<?php

/**
 * Russian translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "запись добавлена: {project} ({language}) +{duration}s",
    'cli.usage' => "Использование: candy-codetime [--backend=file|sqlite] <command> [args]\n\nКоманды:\n  dashboard              Запустить TUI-дашборд (читает выбранный бэкенд)\n  push                   <project> <language> <file> [duration]  Записать heartbeat\n  milestone              <add|list> [name] [description]  Управление вехами (только sqlite)\n  export                 <csv|json|ics> [days]  Экспортировать heartbeats (по умолчанию 7 дней)\n  gaps                   [days]  Показать неотслеженные промежутки дольше 300 секунд (по умолчанию 7 дней)\n  backup                 Архивировать файлы старше 30 дней (копирует; никогда не удаляет)\n  help, --help, -h       Показать это использование\n  --version, -v          Вывести версию\n\nПараметры:\n  --backend=file|sqlite  Backend хранения для push/export/gaps/dashboard (по умолчанию: file)\n  CANDY_CODETIME_DIR     Каталог данных (по умолчанию: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Использование: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: отсутствуют обязательные аргументы (project, language, file)",
    'cli.push_ignored' => "push: {file} совпал с .sugartrackignore — пропущено",
    'cli.push_invalid_duration' => "push: длительность — целое неотрицательное число секунд, получено \"{value}\"",
    'cli.backend_invalid' => "недопустимый backend (используйте file или sqlite)",
    'cli.backend_unavailable' => "backend sqlite недоступен: расширение PHP sqlite3 не загружено",
    'cli.milestone_usage' => "Использование: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: отсутствует обязательный аргумент (name)",
    'cli.milestone_added' => "веха добавлена: {name}",
    'cli.milestone_list_header' => "Вехи:",
    'cli.milestone_list_empty' => "Вехи не записаны.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Использование: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: недопустимый формат (используйте csv, json или ics)",
    'cli.gaps_no_data' => "Промежутки не найдены.",
    'cli.gaps_header' => "Неотслеженные промежутки:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: заархивировано файлов: {count}",
    'cli.backup_none' => "backup: нечего архивировать",
    'cli.backup_sqlite_notice' => "backup: база данных SQLite {db} НЕ архивируется этой командой — используйте sqlite3 <db> \".backup <dest>\" или VACUUM INTO для безопасной копии",
    'cli.unknown_command' => "candy-codetime: неизвестная команда: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} устарело; установите вместо него CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
