<?php

/**
 * Brazilian Portuguese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "entrada registrada: {project} ({language}) +{duration}s",
    'cli.usage' => "Uso: candy-codetime [--backend=file|sqlite] <command> [args]\n\nComandos:\n  dashboard              Registrar um heartbeat\n  push                   <project> <language> <file> [duration]  Gerenciar marcos (apenas sqlite)\n  milestone              <add|list> [name] [description]  Exportar heartbeats (7 dias por padrão)\n  export                 <csv|json|ics> [days]  Mostrar intervalos não rastreados acima de 300 segundos (7 dias por padrão)\n  gaps                   [days]  Arquivar dados com mais de 30 dias (copia; nunca exclui)\n  backup                 Mostrar este uso\n  help, --help           Imprimir a versão\n  --version, -v          padrão: file\n\nOpções:\n  --backend=file|sqlite  Backend de armazenamento para push/export/gaps/dashboard (padrão: file)\n  CANDY_CODETIME_DIR     Diretório de dados (padrão: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Uso: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: faltam argumentos obrigatórios (project, language, file)",
    'cli.push_ignored' => "push: {file} correspondeu a .sugartrackignore — ignorado",
    'cli.push_invalid_duration' => "push: a duração deve ser um inteiro não negativo de segundos, recebido \"{value}\"",
    'cli.backend_invalid' => "backend inválido (use file ou sqlite)",
    'cli.backend_unavailable' => "backend sqlite indisponível: a extensão PHP sqlite3 não está carregada",
    'cli.milestone_usage' => "Uso: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: falta um argumento obrigatório (name)",
    'cli.milestone_added' => "marco adicionado: {name}",
    'cli.milestone_list_header' => "Marcos:",
    'cli.milestone_list_empty' => "Nenhum marco registrado.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Uso: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: formato inválido (use csv, json ou ics)",
    'cli.gaps_no_data' => "Nenhum intervalo encontrado.",
    'cli.gaps_header' => "Intervalos não rastreados:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} arquivos arquivados",
    'cli.backup_none' => "backup: nada novo para arquivar",
    'cli.unknown_command' => "candy-codetime: comando desconhecido: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} está obsoleto; defina CANDY_CODETIME_DIR em vez disso.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
