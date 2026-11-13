<?php

/**
 * Brazilian Portuguese translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'registrados {duration}s de {project} ({language})',
    'cli.usage' => "Uso: candy-codetime [--backend=file|sqlite] <command> [args]\n\nComandos:\n  dashboard              Inicia o painel TUI (lê o backend selecionado)\n  push                   <project> <language> <file> [duration] | --  Registrar um heartbeat\n  milestone              add [name] [description] | list  Gerenciar marcos (apenas sqlite)\n  export                 <csv|json|ics> [days]  Exportar heartbeats (7 dias por padrão)\n  gaps                   [days]  Mostrar intervalos não rastreados de pelo menos 300 segundos (7 dias por padrão)\n  backup                 Arquivar dados com mais de 30 dias (copia; nunca exclui)\n  help, --help, -h       Mostrar este uso\n  --version, -v          Imprimir a versão\n\nOpções:\n  --backend=file|sqlite  Backend de armazenamento para push/export/gaps/dashboard (padrão: file)\n  --time=<unix>          push: registra o batimento nestes segundos unix, não agora\n  CANDY_CODETIME_DIR     Diretório de dados (padrão: \$XDG_DATA_HOME/candy-codetime, senão \$HOME/.local/share/candy-codetime, ou ./.local/share/candy-codetime quando HOME não está definido)",
    'cli.push_usage' => 'Uso: candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (lote de linhas JSON pelo stdin)',
    'cli.push_missing_args' => "push: faltam argumentos obrigatórios (project, language, file)",
    'cli.push_ignored' => "push: {file} correspondeu a .sugartrackignore — ignorado",
    'cli.push_ignored_wholesale' => 'push: {file} ignorado por um padrão total em {ignore_file} ({patterns} padrões); o projeto "{project}" não registra nada — exclua ou restrinja esse arquivo',
    'cli.push_invalid_duration' => 'push: a duração deve ser um número inteiro de segundos, recebido "{value}"',
    'cli.push_invalid_time' => 'push: --time deve ser um número inteiro de segundos unix, recebido "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time não é aceito no modo de lote pelo stdin; cada linha traz o seu próprio tempo',
    'cli.push_stdin_malformed' => 'push: a linha {line} do stdin não é um registro de batimento válido — ignorada',
    'cli.push_stdin_done' => 'push: {count} batimentos registrados',
    'cli.push_stdin_done_one' => 'push: {count} batimento registrado',
    'cli.invalid_days' => 'os dias devem ser um número inteiro, recebido "{value}"',
    'cli.backend_invalid' => "backend inválido (use file ou sqlite)",
    'cli.backend_unavailable' => "backend sqlite indisponível: a extensão PHP sqlite3 não está carregada",
    'cli.backend_other_hint' => 'nota: o backend {other} também tem registros - selecione-o com --backend={other}',
    'cli.storage_error' => 'candy-codetime: erro de armazenamento: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: o painel precisa de um terminal interativo (stdin e stdout não são TTY). Para saída sem terminal: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Uso: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: falta um argumento obrigatório (name)",
    'cli.milestone_added' => "marco adicionado: {name}",
    'cli.milestone_list_header' => "Marcos:",
    'cli.milestone_list_empty' => "Nenhum marco registrado.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'marco: {name} salvo no backend sqlite - o repositório de arquivos não suporta marcos, o banco de dados foi criado aqui; o painel mostra os marcos com --backend=sqlite',

    'cli.export_usage' => "Uso: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: formato inválido (use csv, json ou ics)",
    'cli.gaps_no_data' => "Nenhum intervalo encontrado.",
    'cli.gaps_header' => "Intervalos não rastreados:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} arquivos arquivados",
    'cli.backup_done_one' => 'backup: {count} arquivo arquivado',
    'cli.backup_none' => "backup: nada novo para arquivar",
    'cli.backup_sqlite_notice' => 'backup: o banco de dados SQLite {db} NÃO é arquivado por este comando — use sqlite3 <db> ".backup <dest>" ou VACUUM INTO para uma cópia segura',
    'cli.unknown_command' => "candy-codetime: comando desconhecido: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} está obsoleto; defina CANDY_CODETIME_DIR em vez disso.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Principais projetos',
    'ui.by_language' => 'Por idioma',
    'ui.help_row' => '  ←  dia anterior  ·  →  dia seguinte  ·  r  recarregar  ·  q/Esc  sair',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'total:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(sem atividade)',
    'ui.more_suffix' => '+{count} mais',
    'ui.earlier_suffix' => '+{count} anteriores',
    'ui.activity_minutes_heading' => 'Atividade diária (minutos)',
    'ui.activity_heading' => 'Atividade diária',
    'ui.no_data' => '(sem dados)',
    'ui.diagnostics_dropped' => '{count} linhas corrompidas descartadas dos arquivos diários',
    'ui.diagnostics_unreadable' => 'não foi possível ler {count} arquivos diários',
];
