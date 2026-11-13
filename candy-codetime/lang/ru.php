<?php

/**
 * Russian translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'записано {duration}s: {project} ({language})',
    'cli.usage' => "Использование: candy-codetime [--backend=file|sqlite] <command> [args]\n\nКоманды:\n  dashboard              Запустить TUI-дашборд (читает выбранный бэкенд)\n  push                   [<project> <language>] <file> [duration] [tags] | --  Записать heartbeat\n  milestone              add [name] [description] | list  Управление вехами (только sqlite)\n  export                 <csv|json|ics> [days]  Экспортировать heartbeats (по умолчанию 7 дней)\n  gaps                   [days]  Показать неотслеженные промежутки не менее 300 секунд (по умолчанию 7 дней)\n  backup                 Архивировать файлы старше 30 дней (копирует; никогда не удаляет)\n  help, --help, -h       Показать это использование\n  --version, -v          Вывести версию\n\nПараметры:\n  --backend=file|sqlite  Backend хранения для push/export/gaps/dashboard (по умолчанию: file)\n  --time=<unix>          push: записать событие на эти секунды unix, а не сейчас\n  --sqlite               backup: архивировать базу SQLite командой VACUUM INTO (надёжная копия)\n  --theme=<name>         панель: рисовать именованной палитрой (default, light, dracula, solarized, gruvbox, high-contrast)
  CANDY_CODETIME_THEME   тот же выбор через переменную окружения (флаг важнее)\n  CANDY_CODETIME_DIR     Каталог данных (по умолчанию: \$XDG_DATA_HOME/candy-codetime, иначе \$HOME/.local/share/candy-codetime, или ./.local/share/candy-codetime если HOME не задан)",
    'cli.push_usage' => 'Использование: candy-codetime push [<project> <language>] <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (поток JSON-строк из stdin)',
    'cli.push_missing_args' => "candy-codetime: push: отсутствуют обязательные аргументы (project, language, file)",
    'cli.push_ignored' => "push: {file} совпал с .sugartrackignore — пропущено",
    'cli.push_ignored_wholesale' => 'candy-codetime: push: {file} пропущен из-за всепокрывающего шаблона в {ignore_file} ({patterns} шаблонов); проект "{project}" ничего не записывает — удалите этот файл или сузьте его',
    'cli.push_invalid_duration' => 'candy-codetime: push: длительность — целое число секунд, получено "{value}"',
    'cli.push_empty_field' => 'candy-codetime: push: поле {field} не может быть пустым',
    'cli.push_invalid_time' => 'candy-codetime: push: --time — целое число секунд unix, получено "{value}"',
    'cli.push_time_stdin_conflict' => 'candy-codetime: push: --time не принимается в пакетном режиме stdin; у каждой строки своё время',
    'cli.push_stdin_malformed' => 'candy-codetime: push: строка {line} из stdin не является корректной записью активности — пропущена',
    'cli.push_stdin_done' => 'push: записано {count} записей активности',
    'cli.push_stdin_done_one' => 'push: записана {count} запись активности',
    'cli.invalid_days' => 'candy-codetime: количество дней — целое число, получено "{value}"',
    'cli.invalid_goal' => 'candy-codetime: CANDY_CODETIME_GOAL должно быть целым числом секунд или целым числом часов как 6h, получено "{value}"',
    'cli.invalid_theme' => 'candy-codetime: тема должна быть одной из {names} — получено "{value}"',
    'cli.backend_invalid' => "candy-codetime: недопустимый backend (используйте file или sqlite)",
    'cli.backend_unavailable' => "candy-codetime: backend sqlite недоступен: расширение PHP sqlite3 не загружено",
    'cli.backend_other_hint' => 'примечание: в backend {other} тоже есть записи - выберите его через --backend={other}',
    'cli.storage_error' => 'candy-codetime: ошибка хранилища: {message}',
    'cli.ignore_too_large' => 'candy-codetime: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: дашборду нужен интерактивный терминал (stdin и stdout не являются TTY). Для вывода без терминала: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Использование: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "candy-codetime: milestone add: отсутствует обязательный аргумент (name)",
    'cli.milestone_added' => "веха добавлена: {name}",
    'cli.milestone_list_header' => "Вехи:",
    'cli.milestone_list_empty' => "Вехи не записаны.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'candy-codetime: веха: {name} сохранена в sqlite-бэкенде - файловое хранилище не поддерживает вехи, его база данных создана здесь; дашборд показывает их с --backend=sqlite',

    'cli.export_usage' => "Использование: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "candy-codetime: export: недопустимый формат (используйте csv, json или ics)",
    'cli.gaps_no_data' => "Промежутки не найдены.",
    'cli.gaps_header' => "Неотслеженные промежутки:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: заархивировано файлов: {count}",
    'cli.backup_done_one' => 'backup: заархивирован {count} файл',
    'cli.backup_none' => "backup: нечего архивировать",
    'cli.backup_sqlite_notice' => 'candy-codetime: backup: эта команда архивирует только дневные файлы — для базы SQLite {db} используйте backup --sqlite (надёжная копия)',
    'cli.backup_sqlite_done' => 'backup: база данных заархивирована в {file}',
    'cli.unknown_command' => "candy-codetime: неизвестная команда: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} устарело; установите вместо него CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Лучшие проекты',
    'ui.by_language' => 'По языку',
    'ui.by_tags' => 'По тегу',
    'ui.goal_today' => 'сегодня',
    'ui.help_row' => '  ←  пред. день  ·  →  след. день  ·  r  обновить  ·  q/Esc  выход',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'всего:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(нет активности)',
    'ui.more_suffix' => '+{count} ещё',
    'ui.earlier_suffix' => '+{count} ранее',
    'ui.activity_minutes_heading' => 'Дневная активность (минуты)',
    'ui.activity_heading' => 'Дневная активность',
    'ui.no_data' => '(нет данных)',
    'ui.diagnostics_dropped' => 'отброшено {count} повреждённых строк из дневных файлов',
    'ui.diagnostics_unreadable' => 'не удалось прочитать {count} дневных файлов',
];
