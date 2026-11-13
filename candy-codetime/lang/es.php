<?php

/**
 * Spanish translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'registrados {duration}s de {project} ({language})',
    'cli.usage' => "Uso: candy-codetime [--backend=file|sqlite] <command> [args]\n\nComandos:\n  dashboard              Inicia el panel TUI (lee el backend seleccionado)\n  push                   <project> <language> <file> [duration] | --  Registrar un heartbeat\n  milestone              add [name] [description] | list  Gestionar hitos (solo sqlite)\n  export                 <csv|json|ics> [days]  Exportar heartbeats (7 días por defecto)\n  gaps                   [days]  Mostrar huecos sin seguimiento de al menos 300 segundos (7 días por defecto)\n  backup                 Archivar datos de más de 30 días (copia; nunca borra)\n  help, --help, -h       Mostrar este uso\n  --version, -v          Imprimir la versión\n\nOpciones:\n  --backend=file|sqlite  Backend de almacenamiento para push/export/gaps/dashboard (por defecto: file)\n  --time=<unix>          push: registra el latido en estos segundos unix, no ahora\n  CANDY_CODETIME_DIR     Directorio de datos (por defecto: \$XDG_DATA_HOME/candy-codetime, si no \$HOME/.local/share/candy-codetime, o ./.local/share/candy-codetime cuando HOME no está definido)",
    'cli.push_usage' => 'Uso: candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (lote de líneas JSON por stdin)',
    'cli.push_missing_args' => "push: faltan argumentos obligatorios (project, language, file)",
    'cli.push_ignored' => "push: {file} coincidió con .sugartrackignore — omitido",
    'cli.push_ignored_wholesale' => 'push: {file} omitido por un patrón total en {ignore_file} ({patterns} patrones); el proyecto "{project}" no registra nada — elimina o restringe ese archivo',
    'cli.push_invalid_duration' => 'push: la duración debe ser un número entero de segundos, se recibió "{value}"',
    'cli.push_invalid_time' => 'push: --time debe ser un número entero de segundos unix, se recibió "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time no se acepta en el modo por lotes de stdin; cada línea lleva su propio tiempo',
    'cli.push_stdin_malformed' => 'push: la línea {line} de stdin no es un registro de latido válido — omitida',
    'cli.push_stdin_done' => 'push: {count} latidos registrados',
    'cli.push_stdin_done_one' => 'push: {count} latido registrado',
    'cli.invalid_days' => 'los días deben ser un número entero, se recibió "{value}"',
    'cli.backend_invalid' => "backend no válido (use file o sqlite)",
    'cli.backend_unavailable' => "backend sqlite no disponible: la extensión sqlite3 de PHP no está cargada",
    'cli.backend_other_hint' => 'nota: el backend {other} también tiene registros - selecciónelo con --backend={other}',
    'cli.storage_error' => 'candy-codetime: error de almacenamiento: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: el panel necesita una terminal interactiva (stdin y stdout no son TTY). Para salida sin terminal: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Uso: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: falta un argumento obligatorio (name)",
    'cli.milestone_added' => "hito añadido: {name}",
    'cli.milestone_list_header' => "Hitos:",
    'cli.milestone_list_empty' => "No hay hitos registrados.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Uso: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: formato no válido (use csv, json o ics)",
    'cli.gaps_no_data' => "No se encontraron huecos.",
    'cli.gaps_header' => "Huecos sin seguimiento:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} archivos archivados",
    'cli.backup_done_one' => 'backup: {count} archivo archivado',
    'cli.backup_none' => "backup: nada nuevo que archivar",
    'cli.backup_sqlite_notice' => 'backup: la base de datos SQLite {db} NO es archivada por este comando — use sqlite3 <db> ".backup <dest>" o VACUUM INTO para una copia segura',
    'cli.unknown_command' => "candy-codetime: comando desconocido: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} está obsoleto; defina CANDY_CODETIME_DIR en su lugar.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Proyectos principales',
    'ui.by_language' => 'Por idioma',
    'ui.help_row' => '  ←  día anterior  ·  →  día siguiente  ·  r  recargar  ·  q/Esc  salir',
    'ui.total' => 'total:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(sin actividad)',
    'ui.more_suffix' => '+{count} más',
    'ui.earlier_suffix' => '+{count} anteriores',
    'ui.activity_minutes_heading' => 'Actividad diaria (minutos)',
    'ui.activity_heading' => 'Actividad diaria',
    'ui.no_data' => '(sin datos)',
];
