<?php

/**
 * Russian translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'записано {duration}s: {project} ({language})',
    'cli.usage' => "Использование: candy-codetime [--backend=file|sqlite] <command> [args]\n\nКоманды:\n  dashboard              Запустить TUI-дашборд (читает выбранный бэкенд)\n  push                   <project> <language> <file> [duration] | --  Записать heartbeat\n  milestone              add [name] [description] | list  Управление вехами (только sqlite)\n  export                 <csv|json|ics> [days]  Экспортировать heartbeats (по умолчанию 7 дней)\n  gaps                   [days]  Показать неотслеженные промежутки не менее 300 секунд (по умолчанию 7 дней)\n  backup                 Архивировать файлы старше 30 дней (копирует; никогда не удаляет)\n  help, --help, -h       Показать это использование\n  --version, -v          Вывести версию\n\nПараметры:\n  --backend=file|sqlite  Backend хранения для push/export/gaps/dashboard (по умолчанию: file)\n  --time=<unix>          push: записать событие на эти секунды unix, а не сейчас\n  CANDY_CODETIME_DIR     Каталог данных (по умолчанию: \$XDG_DATA_HOME/candy-codetime, иначе \$HOME/.local/share/candy-codetime, или ./.local/share/candy-codetime если HOME не задан)",
    'cli.push_usage' => 'Использование: candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (поток JSON-строк из stdin)',
    'cli.push_missing_args' => "push: отсутствуют обязательные аргументы (project, language, file)",
    'cli.push_ignored' => "push: {file} совпал с .sugartrackignore — пропущено",
    'cli.push_ignored_wholesale' => 'push: {file} пропущен из-за всепокрывающего шаблона в {ignore_file} ({patterns} шаблонов); проект "{project}" ничего не записывает — удалите этот файл или сузьте его',
    'cli.push_invalid_duration' => 'push: длительность — целое число секунд, получено "{value}"',
    'cli.push_invalid_time' => 'push: --time — целое число секунд unix, получено "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time не принимается в пакетном режиме stdin; у каждой строки своё время',
    'cli.push_stdin_malformed' => 'push: строка {line} из stdin не является корректной записью активности — пропущена',
    'cli.push_stdin_done' => 'push: записано {count} записей активности',
    'cli.push_stdin_done_one' => 'push: записана {count} запись активности',
    'cli.invalid_days' => 'количество дней — целое число, получено "{value}"',
    'cli.backend_invalid' => "недопустимый backend (используйте file или sqlite)",
    'cli.backend_unavailable' => "backend sqlite недоступен: расширение PHP sqlite3 не загружено",
    'cli.storage_error' => 'candy-codetime: ошибка хранилища: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: дашборду нужен интерактивный терминал (stdin и stdout не являются TTY). Для вывода без терминала: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Использование: candy-codetime milestone add [name] [description] | list",
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
    'cli.backup_done_one' => 'backup: заархивирован {count} файл',
    'cli.backup_none' => "backup: нечего архивировать",
    'cli.backup_sqlite_notice' => 'backup: база данных SQLite {db} НЕ архивируется этой командой — используйте sqlite3 <db> ".backup <dest>" или VACUUM INTO для безопасной копии',
    'cli.unknown_command' => "candy-codetime: неизвестная команда: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} устарело; установите вместо него CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Лучшие проекты',
    'ui.by_language' => 'По языку',
    'ui.help_row' => '  ←  пред. день  ·  →  след. день  ·  r  обновить  ·  q/Esc  выход',
    'ui.total' => 'всего:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(нет активности)',
    'ui.more_suffix' => '+{count} ещё',
    'ui.earlier_suffix' => '+{count} ранее',
    'ui.activity_minutes_heading' => 'Дневная активность (минуты)',
    'ui.activity_heading' => 'Дневная активность',
    'ui.no_data' => '(нет данных)',
];
