<?php

/**
 * Spanish translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "entrada registrada: {project} ({language}) +{duration}s",
    'cli.usage' => "Uso: candy-codetime [--backend=file|sqlite] <command> [args]\n\nComandos:\n  dashboard              Registrar un heartbeat\n  push                   <project> <language> <file> [duration]  Gestionar hitos (solo sqlite)\n  milestone              <add|list> [name] [description]  Exportar heartbeats (7 días por defecto)\n  export                 <csv|json|ics> [days]  Mostrar huecos sin seguimiento de más de 300 segundos (7 días por defecto)\n  gaps                   [days]  Archivar datos de más de 30 días (copia; nunca borra)\n  backup                 Mostrar este uso\n  help, --help           Imprimir la versión\n  --version, -v          por defecto: file\n\nOpciones:\n  --backend=file|sqlite  Backend de almacenamiento para push/export/gaps/dashboard (por defecto: file)\n  CANDY_CODETIME_DIR     Directorio de datos (por defecto: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Uso: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: faltan argumentos obligatorios (project, language, file)",
    'cli.push_ignored' => "push: {file} coincidió con .sugartrackignore — omitido",
    'cli.push_invalid_duration' => "push: la duración debe ser un entero no negativo de segundos, se recibió \"{value}\"",
    'cli.backend_invalid' => "backend no válido (use file o sqlite)",
    'cli.backend_unavailable' => "backend sqlite no disponible: la extensión sqlite3 de PHP no está cargada",
    'cli.milestone_usage' => "Uso: candy-codetime milestone <add|list> [name] [description]",
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
    'cli.backup_none' => "backup: nada nuevo que archivar",
    'cli.unknown_command' => "candy-codetime: comando desconocido: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} está obsoleto; defina CANDY_CODETIME_DIR en su lugar.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
