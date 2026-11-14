<?php

/**
 * Brazilian Portuguese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'arquivo SQLite não encontrado: {path}',
    'complete.in_literal' => 'nada a completar dentro de uma string ou comentário',
    'complete.nothing' => 'nada a completar — digite o início de uma palavra-chave',
    'complete.none_keyword' => 'nenhuma palavra-chave completa "{word}"',
    'complete.none_table' => 'nenhuma tabela completa "{word}"',
    'complete.none_column' => 'nenhuma coluna completa "{word}"',
    'complete.none_qualified' => 'nenhuma coluna de {qualifier} completa "{word}"',
    'complete.list' => '{count} completam "{word}": {list}{more}',
    'complete.done' => 'completado {word} → {insert}',
    'complete.inserted' => 'inserido {insert}',
    'edit.refuse_readonly' => 'sessão somente leitura — a edição está desativada',
    'edit.refuse_no_table' => 'abra primeiro a prévia de uma tabela — resultados de consulta não podem ser editados no local',
    'edit.refuse_no_rows' => 'nenhuma linha sob o cursor',
    'edit.refuse_no_pk' => 'esta tabela não tem chave primária — as linhas não podem ser endereçadas com segurança; escreva o comando no painel de consulta',
    'edit.refuse_pk_hidden' => 'a chave primária desta linha é NULL ou um marcador binário — não pode ser endereçada',
    'edit.refuse_binary' => 'células binárias ou grandes não são editáveis aqui — use um comando no painel de consulta',
    'edit.refuse_schema' => 'não foi possível ler o esquema de {table} — edição recusada',
    'edit.prompt_cell' => 'editar {table}.{column} = {value}  (enter pré-visualiza · ctrl+n NULL · esc cancela)',
    'edit.prompt_insert' => 'nova linha em {table} — {column} ({type}) [{n}/{total}] = {value}  (enter próximo · vazio = DEFAULT · ctrl+n NULL · esc cancela)',
    'edit.prompt_confirm' => '{sql}  (enter confirma · esc cancela)',
    'edit.cancelled' => 'edição cancelada',
    'edit.unchanged' => 'valor inalterado — nada gravado',
    'edit.done_update' => '1 linha atualizada em {table}',
    'edit.done_insert' => '1 linha inserida em {table}',
    'edit.done_delete' => '1 linha excluída de {table}',
    'edit.none_changed' => 'nenhuma linha alterada — o valor já estava lá ou a linha sumiu; prévia atualizada',
    'edit.failed' => 'falha na gravação — revertida, nada mudou: {reason}',
    'edit.too_many' => 'recusado: o comando afetaria {count} linhas — revertido, nada mudou',
    'ui.title.tables' => ' tabelas ',
    'ui.title.rows' => ' linhas ',
    'ui.title.query' => ' consulta ',
    'ui.title.admin' => ' admin ',
    'ui.title.explain' => ' plano ',
    'ui.help.query' => 'tab  alternar painel  ·  ctrl+r / ctrl+e  executar  ·  enter  nova linha  ·  ↑/↓  histórico  ·  ctrl+c  sair',
    'ui.help.rows' => 'tab  alternar painel  ·  h/l  célula  ·  e  editar  ·  o  nova linha  ·  D  excluir  ·  x  exportar  ·  q / esc  sair',
    'ui.help.browse' => 'tab  alternar painel  ·  enter  carregar tabela  ·  x  exportar  ·  i  importar  ·  q / esc  sair',
    'ui.help.admin' => '1-{count}  escolher página  ·  j/k  navegar  ·  p  pausar  ·  r  redefinir  ·  tab  alternar painel  ·  q  voltar  ·  esc  cancelar diálogo',
    'ui.no_tables' => '(sem tabelas)',
    'ui.no_match' => '(sem correspondência)',
    'ui.loading' => 'carregando…',
    'ui.empty' => '(vazio)',
    'ui.admin_unavailable' => 'página de administração indisponível: {reason}',
    'ui.error' => 'erro: {message}',
    'io.prompt_export' => 'para CSV → {path}  (enter grava · esc cancela)',
    'io.prompt_replace' => 'SUBSTITUIR {path}?  (enter confirma · esc cancela)',
    'io.prompt_import_path' => 'importar CSV ← {path}  (enter · esc cancela)',
    'io.prompt_import_table' => 'importando {source} → tabela {table}  (enter · esc cancela)',
    'bar.tables' => 'Tabelas: {count}',
    'bar.cycle' => 'Tab:alternar',
    'bar.navigate' => '↑↓:navegar',
    'bar.load' => 'Enter:carregar',
    'bar.run' => 'Ctrl+R:executar',
    'bar.select' => '1-{count}:escolher',
    'bar.nav' => 'j/k:nav',
    'bar.quit' => 'q:sair',
    'bar.paused' => '[PAUSADO]',
    'bar.tx' => '[TX]',
    'picker.title' => 'conexões salvas',
    'picker.help' => '[↑↓/j/k] escolher   [Enter] conectar   [q] cancelar',
    'result.empty' => '(conjunto de resultados vazio)',
    'explain.no_plan' => '(sem plano de consulta — a consulta não retornou linhas)',
    'explain.title' => ' PLANO DE CONSULTA ',
    'status.live_refresh_off' => 'atualização ao vivo da administração desativada — ssl-mode verificado por TLS (somente transporte síncrono)',
    'status.async_failed' => 'O comando assíncrono falhou: {message}',
    'status.file_cancelled' => 'linha de arquivo cancelada',
    'status.path_required' => 'um caminho é obrigatório — esc cancela',
    'status.no_such_file' => 'arquivo não encontrado — continue digitando ou esc cancela',
    'status.table_required' => 'informe a tabela de destino — esc cancela',
    'status.export_failed' => 'falha na exportação: {message}',
    'status.exported' => '{count} linhas exportadas para {path}',
    'status.import_failed' => 'falha na importação: {message}',
    'status.imported' => '{path} importado para {table}',
    'status.history' => 'histórico {pos}/{count}',
    'status.conn_lost' => 'conexão perdida; tente novamente',
    'status.destructive' => '⚠ instrução destrutiva — ctrl+r novamente (sem alterações) para confirmar',
    'status.running' => 'executando consulta…',
    'status.explain_failed' => 'EXPLAIN falhou: {message}',
    'status.explain_plan' => 'plano de execução — qualquer consulta ou pré-visualização de tabela o substitui',
    'status.end_of_table' => 'fim da tabela — sem próxima página',
    'status.first_page' => 'já está na primeira página',
    'status.nothing_to_save' => 'nada para salvar',
    'status.saved' => 'salvo ({count})',
    'status.removed' => 'removido ({count})',
    'status.row_one' => '1 linha',
    'status.rows' => '{count} linhas',
    'status.page' => 'página {page} (linhas {first}–{last})',
    'editor.placeholder' => '-- digite SQL, ctrl+r para executar --',
    'err.async_query_unsupported' => 'Este driver não oferece suporte a consultas assíncronas',
    'err.async_browse_unsupported' => 'Navegação assíncrona não suportada por este driver',
    'dash.title' => 'Painel de desempenho',
    'dash.fetching' => 'Obtendo métricas do servidor...',
    'dash.back_hint' => 'Pressione [q] para voltar à visão de tabelas',
    'dash.recent_queries' => 'Consultas recentes',
    'dash.no_queries' => '(ainda sem consultas)',
    'dash.header_pg' => 'Painel de desempenho{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Painel de desempenho{status} | MySQL {version} | Tempo ativo: {uptime}',
    'dash.uptime_segment' => ' | Tempo ativo: {uptime}',
    'pane.process_list' => 'Lista de processos',
    'pane.variables' => 'Variáveis',
    'pane.status' => 'Status',
    'pane.query_stats' => 'Estatísticas de consultas',
    'pane.dashboard' => 'Painel',
    'pane.table_stats' => 'Estatísticas de tabelas',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Depuração',
    'page.no_data' => 'Ainda não há dados disponíveis.',
    'page.error' => 'Erro: {message}',
    'page.loading' => 'Carregando…',
    'page.fetching' => 'Obtendo dados do servidor.',
    'debug.title' => 'Log de depuração',
    'debug.entries' => ' ({count} entradas)',
    'debug.errors_only' => '[somente erros]',
    'debug.empty' => '(sem entradas de log)',
    'debug.more' => '↑ mais {count} entradas',
    'debug.help' => '[j/k] nav  [f] só erros  [c] limpar  [q] voltar',
    'reports.cannot_access' => 'Não foi possível acessar os relatórios de desempenho: {message}',
    'reports.title' => 'Relatórios de desempenho',
    'reports.categories' => 'Categorias',
    'reports.no_data' => '(sem dados)',
    'reports.loading' => 'Carregando relatórios…',
    'reports.loading_one' => 'Carregando {caption}…',
    'reports.no_data_for' => 'Não há dados disponíveis para {caption}',
    'reports.help' => '[j/k] linhas  [h/l] categoria  [[]/[]] relatório  [,/.] coluna  [c] unidade  [r] atualizar  [x] exportar  [q] voltar',
    'reports.unavailable' => 'Relatórios de desempenho indisponíveis',
    'reports.ensure' => 'Verifique se há MySQL 5.6.6+ com performance_schema=enabled e o esquema sys instalado.',
    'conn.kill_cancelled' => 'Kill cancelado',
    'conn.none_selected' => 'Nenhuma conexão selecionada',
    'conn.refuse_background' => 'Recusa em encerrar uma thread em segundo plano',
    'conn.verb_kill_query' => 'Kill da consulta',
    'conn.verb_kill' => 'Kill',
    'conn.sent' => '{verb} enviado para a conexão {id}',
    'conn.failed' => '{verb} falhou para a conexão {id}',
    'conn.confirm_verb_query' => 'Cancelar consulta em execução em',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} conexão {id} ({user}@{host})?  [y] confirmar   [outra tecla] cancelar',
    'conn.help_empty' => '[j/k] nav  [f] filtro  [r] atualizar',
    'conn.help_full' => '[j/k] nav  [K] kill  [X] kill de consulta  [f] filtro  [r] atualizar',
    'conn.vars_fail' => 'Não foi possível obter as variáveis do servidor',
    'vars.load_fail' => 'Não foi possível carregar as variáveis: {message}',
    'vars.unknown_error' => 'Erro desconhecido',
    'vars.not_dynamic' => 'Erro 1238: \'{name}\' não é dinâmica — pressione [p] para usar PERSIST_ONLY',
    'vars.new_value' => 'novo valor > ',
    'vars.edit_head' => 'Editar ({mode}) {name} (atual: {current})',
    'vars.scope_explain' => 'GLOBAL = somente em execução (perdido ao reiniciar) · PERSIST = execução + arquivo de configuração · PERSIST_ONLY = somente arquivo de configuração (vale na próxima reinicialização)',
    'vars.edit_help' => 'Digite o novo valor, [p] alternar modo, [Enter] confirmar, [Esc] cancelar',
    'reports.back' => '[q] voltar',
    'err.dsn_empty' => 'O DSN não pode estar vazio',
    'err.dsn_no_separator' => 'DSN inválido: separador "://" ausente',
    'err.driver_unsupported' => 'Driver não suportado: {driver}',
    'err.dsn_sqlite_invalid' => 'DSN do SQLite inválido',
    'err.dsn_parse_failed' => 'DSN inválido: parse_url() falhou',
    'err.dsn_no_host' => 'DSN inválido: host ausente',
    'err.driver_choose' => 'Driver não suportado: {driver} — escolha um de {list}',
    'err.args_missing' => 'Argumentos obrigatórios ausentes: passe --driver e --db (ou use --dsn)',
    'err.opt_needs_value' => 'A opção --{name} requer um valor',
    'err.opt_unknown' => 'Opção desconhecida: --{name}',
    'err.csv_not_found' => 'Arquivo CSV não encontrado: {path}',
    'err.csv_unreadable' => 'Não foi possível ler o arquivo CSV \'{path}\': verifique se ele existe e é legível',
    'err.csv_no_headers' => 'Não foi possível ler os cabeçalhos CSV de: {path}',
    'err.csv_no_columns' => 'O arquivo CSV não tem colunas: {path}',
    'err.dest_unwritable' => 'Não foi possível abrir o destino \'{path}\' para gravação: verifique se o diretório existe e é gravável',
    'err.export_buffer' => 'Não foi possível criar o buffer de exportação',
    'err.csv_partial' => 'A exportação CSV de {table} gravou {written} de {expected} linhas — a tabela mudou durante a exportação ou o driver ignorou a janela de paginação; recusando apresentar um arquivo parcial como completo',
    'err.sql_partial' => 'O dump SQL de {table} gravou {written} de {expected} linhas — a tabela mudou durante o dump ou o driver ignorou a janela de paginação; recusando apresentar um arquivo parcial como completo',
    'err.table_unreadable' => 'Tabela não encontrada ou ilegível: {table} ({message})',
    'err.dml_no_prepare' => 'esta conexão não pode executar instruções preparadas',
    'err.dml_refused' => 'o servidor recusou a instrução',
    'alert.connection_critical' => 'Uso de conexões criticamente alto',
    'alert.connection_elevated' => 'Uso de conexões elevado',
    'alert.aborted_elevated' => 'Taxa de conexões abortadas elevada',
    'alert.thread_elevated' => 'Contagem de threads em execução elevada',
    'alert.slow_query_time' => 'Tempo de consulta lenta definido para {seconds}s',
    'alert.connections_at' => 'Conexões em {pct}% do máximo ({connected}/{max})',
    'alert.connection_error_one' => '{count} erro de conexão detectado',
    'alert.connection_error_other' => '{count} erros de conexão detectados',
    'alert.threshold_ratio_connection_warning' => 'O limite de aviso de conexões deve estar entre 0.0 e 1.0',
    'alert.threshold_ratio_connection_critical' => 'O limite crítico de conexões deve estar entre 0.0 e 1.0',
    'alert.threshold_ratio_thread_running' => 'O limite de threads em execução deve estar entre 0.0 e 1.0',
    'alert.threshold_aborted_rate' => 'O limite da taxa de abortos deve ser >= 0.0',
    'alert.threshold_slow_query' => 'O limite de consulta lenta deve ser >= 0.0',
    'alert.threshold_connection_errors' => 'O limite de erros de conexão deve ser >= 0',
    'history.err_mkdir' => 'Não foi possível criar o diretório do histórico: {dir}',
    'history.err_chmod' => 'Não foi possível proteger o arquivo do histórico: {path} (modo {mode})',
    'history.err_unusable' => 'O armazenamento do histórico está inutilizável: {error} — exclua o arquivo do histórico ou corrija suas permissões para recuperar.',
    'query.cancelled' => 'Consulta assíncrona cancelada',
    'tx.unknown' => 'Comando desconhecido — :tx inicia, confirma ou desfaz uma transação',
    'tx.async_refusal' => ':tx cobre a sessão síncrona; o transporte assíncrono ainda não tem perna de transação coordenada — execute BEGIN/COMMIT explícitos ou use o caminho síncrono',
    'tx.already_open' => 'Já existe uma transação aberta — :tx commit ou :tx rollback',
    'tx.no_tx' => 'Nenhuma transação aberta — :tx inicia uma',
    'tx.opened' => 'Transação iniciada — os comandos correm nela até :tx commit ou :tx rollback',
    'tx.committed' => 'Transação confirmada',
    'tx.rolled_back' => 'Transação desfeita',
    'tx.arm_rollback' => 'ROLLBACK descarta todas as alterações desde BEGIN — pressione novamente a tecla de execução para confirmar',
    'tx.failed' => 'Comando de transação falhou: {message}',
    'history.none' => 'Nada a ler — o histórico de status registra apenas sessões da família MySQL (opcional com --history)',
    'history.empty' => 'Sem amostras nos últimos {minutes} minutos — a tendência precisa de pelo menos duas',
    'history.unreadable' => 'Armazenamento de histórico ilegível: {message}',
    'history.status' => 'Tendência do histórico — {samples} linhas nos últimos {minutes} minutos',
    'query.timed_out' => 'A consulta assíncrona expirou após {seconds}s',
    'reports.err_meta_missing' => 'Arquivo de metadados de relatórios não encontrado: {path}',
    'reports.err_meta_read' => 'Falha ao ler o arquivo de metadados de relatórios: {path}',
    'reports.err_not_in_catalog' => 'Relatório não encontrado no catálogo: {name}',
    'reports.err_view_unavailable' => 'Visão não disponível neste servidor: {name}',
    'reports.err_loading' => 'Erro ao carregar o relatório: {error}',
    'variables.err_meta_missing' => 'Arquivo de metadados de variáveis não encontrado: {path}',
    'variables.err_meta_read' => 'Falha ao ler o arquivo de metadados de variáveis: {path}',
    'resilience.statement_timeout_default' => 'A execução da instrução expirou',
    'resilience.statement_timeout_after' => 'A execução da instrução expirou após {seconds} s',
    'variables.err_prepare' => 'Não foi possível preparar a instrução nesta conexão',
    'variables.err_not_editable' => 'Variável não editável',
    'variables.err_persist_mariadb' => 'Persistir requer MariaDB 11.1+ — este servidor não implementa SET PERSIST.',
    'variables.err_persist_mysql' => 'Persistir requer MySQL 8.0.16+ — este servidor não implementa SET PERSIST.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: nome de usuário vazio — recusando conectar ao MySQL com a identidade presumida "root"; informe um usuário explícito',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: nome de usuário vazio — recusando conectar ao PostgreSQL com a identidade presumida "postgres"; informe um usuário explícito',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: sslmode "{mode}" não reconhecido; esperado um de {allowed} — descartar um erro de digitação silenciosamente rebaixaria para "prefer", que aceita texto simples',
    'store.err_read' => 'Não foi possível ler o arquivo de conexões: {path} (exclua-o ou corrija suas permissões)',
    'store.err_json' => 'O arquivo de conexões não é um JSON válido ({error}): {path}',
    'store.err_shape' => 'O arquivo de conexões deve ser um objeto JSON com um mapa "connections": {path}',
    'store.err_connections_type' => 'O valor "connections" deve ser um objeto JSON de nome -> configurações: {path}',
    'store.err_unknown' => 'Conexão desconhecida \'{name}\' — conexões salvas: {saved}',
    'store.none' => '(nenhuma)',
    'store.err_pass_env_unset' => 'A conexão \'{name}\' lê sua senha de ${var} — exporte-a antes de conectar',
    'store.err_mkdir' => 'Não foi possível criar o diretório de conexões: {dir}',
    'store.err_write' => 'Não foi possível gravar o arquivo de conexões: {path}',
    'store.err_replace' => 'Não foi possível substituir o arquivo de conexões: {path}',
    'store.err_entry_type' => 'A conexão \'{name}\' deve mapear para um objeto JSON de configurações',
    'store.err_plaintext_pass' => 'A conexão \'{name}\' armazena um \'pass\' em texto simples — connections.json nunca guarda segredos: substitua por "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'A conexão \'{name}\' tem uma configuração desconhecida \'{field}\' — permitidas: {allowed}',
    'store.err_setting_type' => 'A configuração \'{field}\' da conexão \'{name}\' deve ser uma string ou um número',
    'store.err_setting_empty' => 'A configuração \'{field}\' da conexão \'{name}\' não pode estar vazia',
    'store.err_needs_driver' => 'A conexão \'{name}\' precisa de um \'driver\'',
    'store.err_driver' => 'A conexão \'{name}\' usa um driver não suportado \'{driver}\' — escolha um de: {allowed}',
    'store.err_port' => 'A configuração \'port\' da conexão \'{name}\' deve ser um número',
    'store.err_pass_env' => 'A configuração \'pass-env\' da conexão \'{name}\' deve ser o nome de uma variável de ambiente',
    'store.err_name' => 'Nome de conexão inválido \'{name}\' — use letras, dígitos, ponto, hífen ou sublinhado (1-64 caracteres)',
    'store.err_secure' => 'Não foi possível proteger {path} (modo {mode})',
    'db.err_dsn_semicolon' => 'O host e o nome do banco de dados não podem conter \';\' — é o separador de opções do DSN do PDO',
    'db.err_unknown_pg_ssl' => 'ssl-mode do PostgreSQL desconhecido: {mode}',
    'db.err_unknown_mysql_ssl' => 'ssl-mode do MySQL desconhecido: {mode}',
    'db.err_pg_driver' => 'Não é possível conectar a um driver que não seja PostgreSQL usando o conector PostgreSQL',
    'db.err_mysql_driver' => 'O conector MySQL aceita apenas o driver mysql (recebido \'{driver}\')',
    'db.err_reconnect' => 'Falha ao reconectar após erro de conexão: {error}',
    'db.err_quote_no_connection' => 'Não é possível aplicar aspas sem conexão',
    'dml.err_key_required' => 'uma chave de linha é obrigatória: instrução que poderia afetar mais de uma linha recusada',
    'dml.err_key_null' => 'a coluna chave {column} é NULL: a linha não pode ser endereçada',
    'dml.err_empty_table' => 'o nome da tabela não pode estar vazio',
    'dml.err_empty_column' => 'o nome da coluna não pode estar vazio',
    'dml.err_empty_key_column' => 'o nome da coluna chave não pode estar vazio',
    'status.already_saved' => 'já salvo ({count})',
    'status.not_in_favorites' => 'não está nos favoritos ({count})',
    'status.gtid_mariadb' => 'GTID_MODE não está disponível: o MariaDB não tem a variável gtid_mode.',
    'status.gtid_version' => 'GTID_MODE requer MySQL 5.7.6 ou mais recente (o servidor é {version}).',
    'caption.blocks_hit' => 'Blocos em cache',
    'caption.blocks_read' => 'Blocos lidos',
    'caption.buffer_pool_read_reqs' => 'Solicitações de leitura do buffer pool',
    'caption.buffer_pool_usage' => 'Uso do buffer pool',
    'caption.buffer_pool_write_reqs' => 'Solicitações de gravação do buffer pool',
    'caption.bytes_in' => 'Bytes recebidos',
    'caption.bytes_out' => 'Bytes enviados',
    'caption.cache_hit_rate' => 'Taxa de acertos do cache',
    'caption.commits' => 'Commits',
    'caption.connections' => 'Conexões',
    'caption.deadlocks' => 'Deadlocks',
    'caption.disk_reads_not_from_pool' => 'Leituras de disco (fora do pool)',
    'caption.disk_write_requests' => 'Solicitações de gravação em disco',
    'caption.doublewrite_writes' => 'Gravações doublewrite',
    'caption.innodb_disk_reads' => 'Leituras de disco do InnoDB',
    'caption.innodb_disk_writes' => 'Gravações de disco do InnoDB',
    'caption.insert_buffer' => 'Buffer de inserção',
    'caption.pages_created' => 'Páginas criadas',
    'caption.pages_flushed' => 'Páginas descarregadas',
    'caption.pages_read' => 'Páginas lidas',
    'caption.read_ahead' => 'Leitura antecipada',
    'caption.redo_log_bytes_written' => 'Bytes gravados no redo log',
    'caption.redo_log_writes' => 'Gravações do redo log',
    'caption.rollbacks' => 'Rollbacks',
    'caption.row_lock_time' => 'Tempo de bloqueio de linhas',
    'caption.row_lock_waits' => 'Esperas de bloqueio de linhas',
    'caption.shared_buffers' => 'Buffers compartilhados',
    'caption.sql_statements' => 'Instruções SQL',
    'caption.table_open_cache' => 'Cache de tabelas abertas',
    'caption.temp_bytes' => 'Bytes temporários',
    'caption.temp_files' => 'Arquivos temporários',
    'caption.transactions' => 'Transações',
    'caption.tuples_fetched' => 'Tuplas buscadas',
    'caption.tuples_returned' => 'Tuplas retornadas',
    'caption.traffic' => 'Tráfego',
    'caption.key_efficiency' => 'Eficiência de chaves',
    'caption.selects_per_second' => 'Selects por segundo',
    'caption.innodb_buffer_usage' => 'Uso do buffer do InnoDB',
    'caption.innodb_reads_per_second' => 'Leituras do InnoDB por segundo',
    'caption.innodb_writes_per_second' => 'Gravações do InnoDB por segundo',
    'widget.tip.bytes_in' => 'Tráfego de entrada: {Bytes_received} bytes no total',
    'widget.tip.bytes_out' => 'Tráfego de saída: {Bytes_sent} bytes no total',
    'widget.tip.client_connections' => 'Conexões de clientes: {Threads_connected}',
    'widget.tip.connections_level' => 'Nível de conexões vs max_connections',
    'widget.tip.table_open_cache' => 'Taxa de acertos do cache de tabelas abertas',
    'widget.tip.sql_statements' => 'Taxas de instruções SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'Percentual de uso do buffer pool do InnoDB (baseado em bytes)',
    'widget.tip.buffer_pool_read_reqs' => 'Solicitações de leitura do buffer pool do InnoDB por segundo',
    'widget.tip.buffer_pool_write_reqs' => 'Solicitações de gravação do buffer pool do InnoDB por segundo',
    'widget.tip.disk_reads_not_from_pool' => 'Leituras do buffer pool do InnoDB do disco por segundo',
    'widget.tip.innodb_disk_writes' => 'Bytes de dados do InnoDB gravados em disco por segundo',
    'widget.tip.disk_write_requests' => 'Solicitações de gravação em arquivos de dados do InnoDB por segundo',
    'widget.tip.innodb_disk_reads' => 'Bytes de dados do InnoDB lidos do disco por segundo',
    'widget.tip.row_lock_waits' => 'Esperas de bloqueio de linhas do InnoDB por segundo',
    'widget.tip.row_lock_time' => 'Tempo de bloqueio de linhas do InnoDB por segundo (milissegundos)',
    'widget.tip.pages_flushed' => 'Páginas do InnoDB descarregadas por segundo',
    'widget.tip.pages_created' => 'Páginas do InnoDB criadas por segundo',
    'widget.tip.pages_read' => 'Páginas do InnoDB lidas por segundo',
    'widget.tip.insert_buffer' => 'Tamanho do buffer de inserção do InnoDB (páginas)',
    'widget.tip.read_ahead' => 'Leitura antecipada do buffer pool do InnoDB por segundo',
    'widget.tip.redo_log_bytes_written' => 'Bytes gravados no redo log do InnoDB por segundo',
    'widget.tip.redo_log_writes' => 'Gravações do redo log do InnoDB por segundo',
    'widget.tip.doublewrite_writes' => 'Gravações doublewrite do InnoDB por segundo',
    'widget.tip.alter_rate' => 'Taxa de comandos ALTER (todos os Com_alter_*)',
    'widget.tip.create_rate' => 'Taxa de comandos CREATE (todos os Com_create_*)',
    'widget.tip.drop_rate' => 'Taxa de comandos DROP (todos os Com_drop_*)',
    'widget.tip.select_rate' => 'Taxa de SELECT',
    'widget.tip.insert_rate' => 'Taxa de INSERT',
    'widget.tip.update_rate' => 'Taxa de UPDATE',
    'widget.tip.delete_rate' => 'Taxa de DELETE',
    'widget.tip.tuples_fetched_total' => 'Linhas buscadas do BD: {tup_fetched} no total',
    'widget.tip.tuples_fetched_rate' => 'Taxa de busca de linhas',
    'widget.tip.tuples_returned_total' => 'Linhas retornadas ao cliente: {tup_returned} no total',
    'widget.tip.tuples_returned_rate' => 'Taxa de retorno de linhas',
    'widget.tip.blocks_read_total' => 'Blocos de disco lidos: {blks_read} no total',
    'widget.tip.blocks_hit_total' => 'Acertos do cache de buffer: {blks_hit} no total',
    'widget.tip.active_backends' => 'Backends ativos: {numbackends}',
    'widget.tip.transaction_rates' => 'Taxas de transações: commits / rollbacks',
    'widget.tip.commit_rate' => 'Taxa de commits de transações',
    'widget.tip.rollback_rate' => 'Taxa de rollbacks de transações',
    'widget.tip.tuple_insert_rate' => 'Taxa de inserção de tuplas',
    'widget.tip.tuple_update_rate' => 'Taxa de atualização de tuplas',
    'widget.tip.tuple_delete_rate' => 'Taxa de exclusão de tuplas',
    'widget.tip.deadlock_rate' => 'Taxa de deadlocks',
    'widget.tip.shared_buffers' => 'Configuração shared_buffers do PostgreSQL (tamanho do buffer pool)',
    'widget.tip.cache_hit_rate' => 'Taxa de acertos do cache de buffer: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Taxa de criação de arquivos temporários',
    'widget.tip.temp_bytes' => 'Bytes gravados em arquivos temporários por segundo',
    'dash.data_written' => 'dados gravados {kb} kB/s',
    'dash.writes_ops' => 'gravações {n} ops/s',
    'dash.log_written' => 'log gravado {kb} kB/s',
    'dash.read_reqs' => 'solicitações de leitura {n} páginas/s',
    'dash.write_reqs' => 'solicitações de gravação {n} páginas/s',
    'dash.disk_reads' => 'leituras de disco {n} /s',
    'metrics.busy_proxy' => 'proxy de ocupação {pct}%',
    'metrics.cpu_load' => 'carga da CPU {load}',
    'metrics.no_samples' => 'ainda sem amostras',
    'metrics.connections_count' => '{count} conexões',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Métricas do servidor',
    'explain.query_block' => 'BLOCO DE CONSULTA #{id} (custo: {cost})',
    'variables.footer_tree' => '[j/k] árvore',
    'variables.footer_rows' => '[j/k] linhas',
    'variables.footer_categories' => '[c] categorias',
    'variables.footer_edit' => '[Enter/e] editar (dinâmica)',
    'variables.footer_rw_filter' => '[w] filtro rw',
    'variables.footer_search' => '[s] pesquisar',
    'variables.footer_back' => '[q] voltar',
    'srv.title' => 'Status do servidor',
    'srv.features' => 'Recursos',
    'srv.stored_programs' => 'Programas armazenados',
    'srv.directories' => 'Diretórios',
    'srv.data_directory' => 'Diretório de dados',
    'srv.temp_directory' => 'Diretório temporário',
    'srv.log_directory' => 'Diretório de logs',
    'srv.pid_file' => 'Arquivo PID',
    'srv.tls_active' => 'TLS ativo',
    'srv.tls_cipher' => 'Cifra TLS',
    'srv.tls_version' => 'Versão do TLS',
    'srv.ssl_support' => 'Suporte a SSL',
    'srv.ssl_ca' => 'CA do SSL',
    'srv.ssl_cert' => 'Certificado SSL',
    'srv.ssl_key' => 'Chave SSL',
    'srv.tls_secure' => 'TLS / conexão segura',
    'srv.replication' => 'Replicação',
    'srv.repl_denied' => 'Privilégios insuficientes (REPLICATION CLIENT)',
    'srv.repl_error' => 'Erro ao acessar o status da replicação',
    'srv.not_configured' => 'Não configurado',
    'srv.channel' => 'Canal',
    'srv.source_host' => 'Host de origem',
    'srv.source_port' => 'Porta de origem',
    'srv.replica_io' => 'IO da réplica em execução',
    'srv.replica_sql' => 'SQL da réplica em execução',
    'srv.seconds_behind' => 'Segundos atrás da origem',
    'srv.relay_log_file' => 'Arquivo de relay log',
    'srv.relay_pos' => 'Pos. do relay',
    'srv.repl_channel_title' => 'Replicação › {channel}',
    'srv.default' => 'Padrão',
    'srv.firewall' => 'Firewall',
    'srv.mysql_firewall' => 'Firewall do MySQL',
    'srv.caught_up' => '0 s (em dia)',
    'srv.host' => 'Host',
    'srv.socket' => 'Socket',
    'srv.port' => 'Porta',
    'srv.version' => 'Versão',
    'srv.uptime' => 'Tempo ativo',
    'srv.running_since' => 'Em execução desde',
    'srv.unknown' => 'Desconhecido',
    'srv.server_information' => 'Informações do servidor',
    'perf.tab_easy_setup' => 'Configuração fácil',
    'perf.tab_instruments' => 'Instrumentos',
    'perf.tab_consumers' => 'Consumidores',
    'perf.tab_actors' => 'Atores',
    'perf.tab_objects' => 'Objetos',
    'perf.tab_threads' => 'Threads',
    'perf.tab_options' => 'Opções',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[SOMENTE LEITURA]',
    'perf.nothing_to_commit' => 'Nada para confirmar',
    'perf.commit_planning_failed' => 'Falha no planejamento da confirmação: {error}',
    'perf.err_prepare' => 'Falha ao preparar a instrução: {sql}',
    'perf.committed_one' => '{count} instrução confirmada',
    'perf.committed_other' => '{count} instruções confirmadas',
    'perf.applied_one' => '{label} aplicado — {count} instrução',
    'perf.applied_other' => '{label} aplicado — {count} instruções',
    'perf.commit_failed' => '{label} falhou na instrução {position} de {total}: {error} — as instruções anteriores foram aplicadas; o estado do servidor é parcial.',
    'perf.err_access_denied' => 'Acesso negado: faltam privilégios necessários',
    'perf.err_not_enabled' => 'O Performance Schema não está habilitado',
    'perf.err_cannot_connect' => 'Não foi possível conectar ao banco de dados',
    'perf.state_fully' => 'Totalmente habilitado',
    'perf.state_default' => 'Configuração padrão',
    'perf.state_custom' => 'Configuração personalizada',
    'perf.state_disabled' => 'Desabilitado',
    'perf.state_unknown' => 'Desconhecido',
    'perf.current_state' => 'Estado atual: {state}',
    'perf.readonly_note' => '(Modo somente leitura - sem privilégios para modificar)',
    'perf.keys_hint' => 'Tecla "[" Habilitar Performance Schema completo   Tecla "]" Desabilitar   Tecla "." Restaurar padrões',
    'perf.default_instruments' => 'Instrumentos padrão: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Consumidores padrão: events_statements_history, events_waits_history, etc.',
    'perf.none_instruments' => '(nenhum instrumento disponível)',
    'perf.none_consumers' => '(nenhum consumidor disponível)',
    'perf.none_actors' => '(nenhum ator configurado)',
    'perf.none_objects' => '(nenhuma regra de objeto configurada)',
    'perf.none_threads' => '(nenhum thread disponível)',
    'perf.total_instruments_one' => 'Total: {count} instrumento',
    'perf.total_instruments_other' => 'Total: {count} instrumentos',
    'perf.total_consumers_one' => 'Total: {count} consumidor',
    'perf.total_consumers_other' => 'Total: {count} consumidores',
    'perf.total_actors_one' => 'Total: {count} ator',
    'perf.total_actors_other' => 'Total: {count} atores',
    'perf.total_objects_one' => 'Total: {count} regra de objeto',
    'perf.total_objects_other' => 'Total: {count} regras de objeto',
    'perf.total_threads_one' => 'Total: {count} thread',
    'perf.total_threads_other' => 'Total: {count} threads',
    'perf.col_thread_id' => 'ID do thread',
    'perf.col_name' => 'Nome',
    'perf.col_type' => 'Tipo',
    'perf.col_user' => 'Usuário',
    'perf.timer_options' => 'Opções do temporizador',
    'perf.none_timers' => '(nenhum temporizador disponível - somente leitura)',
    'perf.col_timer_name' => 'Nome do temporizador',
    'perf.col_implementation' => 'Implementação',
    'perf.col_scale_factor' => 'Fator de escala',
    'perf.timer_readonly_note' => 'A configuração do temporizador é somente leitura (determinada pela compilação do servidor)',
    'perf.footer_nav' => '[j/k] navegar  [Space] alternar',
    'perf.footer_actions' => '  [c] preparar confirmação ([y] executar)  [r] reverter',
    'perf.footer_back' => '  [q] voltar',
    'perf.pending_one' => 'Pendente: {count} alteração',
    'perf.pending_other' => 'Pendente: {count} alterações',
    'cli.err_not_tty' => 'este inicializador controla um terminal interativo e se recusa a enviar ANSI para um pipe (saída 2) — execute-o em um pty (p. ex. script -qec \'candy-sql app.sqlite\' /dev/null) ou renderize sem interface a partir do código da biblioteca (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'gravação do histórico DESATIVADA ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' exige TLS verificado — o transporte administrativo do ReactPHP não consegue atendê-lo, então a atualização ao vivo da administração está DESATIVADA e consultas/cargas de tabelas rodam de forma síncrona sobre a conexão TLS.',
    'cli.no_connection_selected' => 'nenhuma conexão selecionada — passe um caminho, um DSN ou --connect=<name>',
    'cli.usage_line' => 'USO: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # para informações de uso completas',
    'cli.tip_saved_connections' => '       Dica: salve conexões nomeadas em {path} e inicie com --connect=<name>.',
    'help.summary' => 'candy-sql - Cliente SQL de terminal para SQLite, MySQL e PostgreSQL',
    'help.usage' => 'USO
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTOS
  path-or-dsn
    Caminho do arquivo de banco de dados SQLite, p. ex.: app.sqlite
    Ou uma string DSN, p. ex.:  mysql://user:pass@localhost:3306/dbname

  Quando omitido por completo: em um terminal interativo com conexões
  salvas, um seletor as oferece; caso contrário, uma linha de uso curta
  é impressa e o processo termina com status 2 (chamada inválida) —
  execute --help para ver esta tela completa.',
    'help.options' => 'OPÇÕES
  --help, -h
    Mostra esta tela de ajuda.

  --version, -V
    Mostra o número da versão.

  --connect=<name>
    Conecta à entrada indicada do arquivo de conexões salvas
    ($XDG_CONFIG_HOME/candy-sql/connections.json, por padrão
    $HOME/.config/candy-sql/connections.json). Não pode ser combinada com
    argumentos de conexão explícitos. Senhas nunca são gravadas em disco:
    uma entrada indica seu segredo por meio de "pass-env" e a variável é
    lida no momento da conexão. Veja CONEXÕES SALVAS abaixo.',
    'help.option_dsn' => '  --dsn=<dsn>
    String de conexão DSN completa.
    Formato: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Exemplos:
      mysql://root:secret@localhost:3306/mydb?ssl-mode=require
      pgsql://postgres:pass@db.example.com:5432/production
      sqlite:///var/data/app.sqlite
      sqlite://:memory:',
    'help.option_params' => '  --driver=<driver>
  --db=<dbname>
  --host=<host>
  --port=<port>
  --user=<user>
  --pass=<pass>
  --ssl-mode=<mode>
    Parâmetros de conexão individuais (todos exceto --driver e --db são opcionais).
    --driver deve ser um de: sqlite, mysql, pgsql
    --ssl-mode é "prefer" por padrão no MySQL. "require" e "verify_*" exigem
    TLS com verificação do certificado do servidor; a confiança na CA deve
    ser configurada no servidor ou via my.cnf (veja o README). Nesses modos
    verificados, o transporte ReactPHP, capaz de texto simples, é recusado —
    as consultas rodam de forma síncrona sobre a conexão TLS e a atualização
    ao vivo da administração fica desativada (um aviso na inicialização e a
    linha de status do aplicativo informam isso).
    Prefira CANDY_SQL_PASS a --pass= para manter a senha fora da linha de
    comando do processo (visível no ps).',
    'help.examples' => 'EXEMPLOS
  # SQLite (padrão — caminho simples ou DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL via DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL via opções individuais
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'CONEXÕES SALVAS
  Liste os servidores nomeados uma vez e conecte-se a eles para sempre:

    $HOME/.config/candy-sql/connections.json  (pasta 0700, arquivo 0600)',
    'help.saved_fields' => '  Campos: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Qualquer outro campo é recusado, e um campo "pass" em texto
  simples é sempre recusado — aponte pass-env para uma variável. Uma
  inicialização sem argumentos em um terminal interativo apresenta essas
  entradas como seletor; --connect=<name> conecta a uma diretamente.',
    'help.editing' => 'EDIÇÃO
  O painel Rows de uma pré-visualização de TABLE é uma grade editável:
  h/l (ou ←/→) movem o cursor de célula, e três atalhos alteram dados —
  cada um mostra a instrução exata na linha de status e não grava nada
  até o Enter confirmar:

    e   editar a célula sob o cursor (Ctrl+N define NULL)
    o   inserir uma linha (Enter percorre as colunas; uma entrada vazia
        deixa a coluna no seu DEFAULT, Ctrl+N grava NULL)
    D   excluir a linha sob o cursor

  As linhas são endereçadas somente pela PRIMARY KEY completa: uma tabela
  sem ela, uma chave NULL/binária, uma célula binária e qualquer resultado
  de consulta são recusados com uma linha de status explícita. Os valores
  são parâmetros vinculados; cada gravação roda em uma transação revertida,
  a menos que exatamente uma linha seja afetada (um UPDATE que grava o valor
  já existente é informado como sem alteração). Esc cancela uma linha de
  edição aberta.',
    'help.editing_readonly' => '  --read-only
    Desativa os atalhos de edição na sessão. Protege contra um deslize; não
    filtra as instruções do painel Query — para uma garantia firme, conecte-se
    como usuário de banco de dados somente leitura.',
    'help.history' => 'HISTÓRICO
  Por padrão, cada instantâneo de sondagem de administração é gravado em
  $HOME/.candy-sql/history.db (pasta 0700 / arquivo 0600) como trilha de
  auditoria offline. As páginas Dashboard e Reports do aplicativo não leem
  este arquivo — consultam o servidor ao vivo.',
    'help.history_flags' => '  --no-history
    Desativa totalmente a gravação (nada é escrito em disco).

  --history=<path>
    Grava os instantâneos em <path> em vez do local padrão.',
    'help.keyboard' => 'ATALHOS DE TECLADO (TUI)
  Tab          Alterna o foco entre os painéis (Tables → Rows → Query → Admin)
  ↑ / ↓        Navega pelas linhas da lista (ou j/k) — no painel Tables o movimento é
               apenas local; nunca carrega uma pré-visualização
  /            (Painel Tables) abre uma linha de filtro ao vivo sobre os nomes das
               tabelas; letras a estendem, ↑/↓ movem o cursor, Enter confirma e
               carrega, Backspace edita, Esc abandona e limpa
  Enter        Carrega as linhas da tabela selecionada
  Space        Carrega as linhas da tabela selecionada (alias de Enter)
  PgDn / PgUp  (Painel Tables/Rows, em uma pré-visualização de tabela) pagina a
               pré-visualização em janelas de 100 linhas — o limite de navegação virou um tamanho de página
  Ctrl+Enter   Executa a consulta atual
  Ctrl+R       Executa a consulta atual
  Ctrl+E       Executa a consulta atual
  EXPLAIN …    (Editor Query, palavra-chave inicial) um EXPLAIN digitado passa pelo
               ExplainView: o painel Rows mostra a árvore do plano colorida, não a
               grade plana do provedor — e qualquer modificador ANALYZE é REMOVIDO,
               o inspetor nunca executa o que inspeciona
  e / o / D    (Painel Rows, pré-visualização de tabela) editar a célula / inserir
               uma linha / excluir a linha — veja EDIÇÃO; h/l movem o cursor de célula
  Ctrl+Space   Completa no cursor (editor Query): palavras-chave SQL, nomes de tabela
               após FROM/JOIN/INTO/UPDATE e colunas no contexto (WHERE/SELECT/ORDER BY,
               qualificadas `alias.` / `table.`, a lista INSERT INTO t (...)) a partir
               do esquema carregado. Um candidato é completado, um prefixo comum
               avança; caso contrário a linha de status lista os candidatos
  Ctrl+F       Adiciona a consulta atual à lista de favoritos (apenas estado de
               sessão — nenhum painel a exibe e ela não é gravada)
  Ctrl+Shift+F Remove a consulta atual da lista de favoritos
  1-8          Salta para um painel de administração (somente com o painel Admin em foco)
  q            Sai dos painéis Tables/Rows; volta a Tables a partir de Admin
  x            (Tables/Rows, resultado ao vivo) exporta as linhas atuais para um
               caminho CSV — a recusa de sobrescrita fica armada até o Enter confirmar
  i            (Tables/Rows) importa um arquivo CSV para uma tabela nomeada
  Ctrl+S       Exporta o resultado/pré-visualização ao vivo para CSV a partir de qualquer painel
  Esc          Sai dos painéis Tables/Rows; cancela uma caixa de diálogo Admin aberta
  Backspace    Apaga o caractere antes do cursor (editor Query)',
    'help.see_also' => 'VEJA TAMBÉM
  https://github.com/sugarcraft/candy-sql',
];
