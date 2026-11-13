<?php

/**
 * pt translations for candy-vcr.
 *
 * Placeholder names are identical across locales; only their order may move.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'app.unknown_subcommand' => 'candy-vcr: subcomando desconhecido \'{name}\'',
    'app.usage' => 'uso: candy-vcr <subcomando> [opções...]',
    'app.subcommands' => 'subcomandos:',
    'app.version_flag' => 'Imprime a versão e sai.',
    'cli.usage_label' => 'uso:',
    'cli.err' => 'candy-vcr {verb}: {message}',
    'cli.unknown_option' => 'candy-vcr {verb}: opção desconhecida {option}',
    'cli.idle_trim_positive' => '--idle-trim deve ser > 0 segundos',
    'cli.idle_trim_requires' => '--idle-trim exige um número positivo de segundos',
    'choice.invalid' => '{flag} deve ser um de {known}, recebido "{got}"',
    'choice.invalid_type' => '{flag} deve ser um de {known}, recebido {got}',
    'fps.not_numeric' => '--fps deve ser um número positivo, recebido {value}.',
    'fps.not_positive' => '--fps deve ser um número finito e positivo de fotogramas por segundo, recebido {value}.',
    'summary.record' => 'Grava a sessão PTY de um comando em uma fita',
    'summary.inspect' => 'Lista os eventos (ou os fotogramas com --frames) de uma fita',
    'summary.replay' => 'Envia para stdout a saída gravada de uma fita',
    'summary.diff' => 'Compara duas fitas evento por evento',
    'summary.stats' => 'Mostra estatísticas da fita',
    'summary.migrate' => 'Migra uma fita para a versão de formato atual',
    'desc.render_tape' => 'Renderiza um ficheiro .tape em um .gif',
    'desc.render_batch' => 'Renderiza todos os ficheiros .tape de um diretoria',
    'opt.tape' => 'Caminho do ficheiro .tape',
    'opt.dir' => 'Diretoria contendo ficheiros .tape',
    'opt.output_gif' => 'Caminho do .gif de saída (padrão: igual à entrada com extensão .gif)',
    'opt.output_dir' => 'Diretoria de saída dos .gif (padrão: o diretoria de origem)',
    'opt.font' => 'Nome da família de fonte TTF (padrão: JetBrainsMono)',
    'opt.recursive' => 'Buscar recursivamente',
    'opt.theme' => 'Nome do tema (padrão: TokyoNight)',
    'opt.fps' => 'Fotogramas por segundo (padrão: 30)',
    'opt.backend' => 'Backend de rasterização: gd|imagick (padrão: gd)',
    'opt.encoder' => 'Codificador GIF: ffmpeg|php (padrão: ffmpeg)',
    'opt.strict' => 'Falhar em diretivas desconhecidas em vez de as ignorar',
    'opt.shell' => 'Executar os comandos digitados neste shell sob um PTY real e capturar a saída do programa (modo exec). Substitui o Set Shell do tape.',
    'opt.exec' => 'Ativar o modo exec com um shell padrão ($SHELL ou /bin/sh) quando o tape não define Set Shell',
    'opt.dry_run' => 'Imprimir o fluxo de eventos compilado como JSONL em vez de gravar um GIF',
    'render.failed' => 'Falhou: {message}',
    'render.cwd_hint' => ' (cwd: {cwd})',
    'render.tape_not_found' => 'ficheiro tape não encontrado: {path}{hint}',
    'render.tape_too_large' => 'o ficheiro tape {path} excede o limite de {cap} bytes',
    'render.cannot_read_tape' => 'não foi possível ler o ficheiro tape: {path}',
    'render.rendering' => 'A renderizar {name} ...',
    'render.progress' => '  {frames} fotogramas renderizados (células de {w}x{h} px)',
    'render.warning' => 'Aviso: {warning}',
    'render.gif_written' => 'GIF gravado em {path}',
    'batch.not_directory' => 'Não é uma diretoria: {dir}',
    'batch.none_found' => 'Nenhum ficheiro .tape encontrado em {dir}',
    'batch.rendering' => 'A renderizar {total} tape(s) em {dir}',
    'batch.output_directory' => 'Diretoria de saída: {dir}',
    'batch.claimed' => 'ignorado — {gif} já reservado por {tape}',
    'batch.col_filename' => 'ficheiro',
    'batch.col_status' => 'status',
    'batch.col_time' => 'tempo',
    'batch.total' => 'Total: {total} | Bem-sucedidos: {ok} | Falhados: {failed} | Tempo: {seconds}s',
    'diff.identical' => 'as fitas são idênticas (campos do cabeçalho iguais; eventos iguais em tipo, conteúdo e timestamps dentro de 2 ms)',
    'diff.count' => '{count} diferença(s)',
    'diff.dimensions' => 'dimensões do cabeçalho: {a_cols}x{a_rows} != {b_cols}x{b_rows}',
    'diff.event_count' => 'número de eventos: {a} != {b}',
    'diff.missing_in_a' => 'event[{index}]: ausente em A, presente em B ({kind})',
    'diff.missing_in_b' => 'event[{index}]: presente em A ({kind}), ausente em B',
    'diff.kind_differs' => 'event[{index}]: tipo {a} != {b}',
    'diff.time_differs' => 'event[{index}]: t difere ({a} != {b})',
    'diff.payload_differs' => 'event[{index}] conteúdo difere ({kind})',
    'inspect.fps_requires_value' => '--fps exige um valor',
    'inspect.since_invalid' => '--since deve ser um número não negativo',
    'inspect.until_invalid' => '--until deve ser um número não negativo',
    'inspect.shown' => '{shown} / {total} evento(s) exibido(s)',
    'inspect.frames_footer' => 'frames: {total}  únicos: {unique}  deduplicados: {deduped}',
    'inspect.header' => 'fita v{version}  {cols}x{rows}  runtime={runtime}  created={created}  duração={duration}s  eventos={events}',
    'inspect.screenshot' => 'captura de ecrã: {path}',
    'migrate.help_dry_run' => 'Validar a migração sem gravar ficheiros',
    'migrate.help_info' => 'Mostrar os migradores registrados e sair',
    'migrate.help_input' => 'Entrada: somente fitas JSONL (.cas/.jsonl); entradas .cast/.yaml/.tape/.gz são recusadas',
    'migrate.too_many' => 'argumentos demais',
    'migrate.cannot_read' => 'não foi possível ler {path}',
    'migrate.refused_kind' => '{path} é um ficheiro {kind}; migrate lê somente fitas JSONL (.cas/.jsonl)',
    'migrate.kind_cast' => 'cast do asciinema',
    'migrate.kind_tape' => 'script tape',
    'migrate.already_latest' => 'a fita já está na versão de formato mais recente (v{version})',
    'migrate.dry_run' => 'dry-run: migraria v{from} → v{to}',
    'migrate.output_gz' => 'a saída não pode ser um caminho .gz; migrate grava JSONL simples',
    'migrate.backup_symlink' => 'o caminho de backup {path} é um link simbólico - a recusar gravar através dele',
    'migrate.backup_exists' => 'o backup {path} já existe - a recusar sobrescrevê-lo',
    'migrate.backup_create_failed' => 'não foi possível criar o backup em {path}',
    'migrate.chmod_failed' => 'não foi possível restringir as permissões de {path}',
    'migrate.copy_failed' => 'não foi possível copiar {input} para {backup}',
    'migrate.backed_up' => 'original copiado para {path}',
    'migrate.write_failed' => 'não foi possível gravar {path}: {reason}',
    'migrate.done' => 'v{from} → v{to} migrado ({count} eventos)',
    'migrate.info_title' => 'sistema de migração do candy-vcr',
    'migrate.info_none' => 'nenhum migrador registrado',
    'stats.unexpected_argument' => 'argumento inesperado {arg}',
    'stats.header' => 'fita v{version}  {cols}x{rows}',
    'stats.header_meta' => 'runtime={runtime}  created={created}',
    'stats.events' => 'Eventos: {count}',
    'stats.duration' => 'Duração: {seconds}s',
    'stats.raw_bytes' => 'bytes brutos({count})',
    'stats.input_msgs' => 'Msgs de entrada: {parts}',
    'stats.output_bytes' => 'Bytes de saída: {bytes}',
    'stats.avg_output' => 'Saída média/evento: {bytes} bytes',
    'replay.speed_invalid' => '--speed deve ser \'instant\' ou \'realtime\'',
    'replay.help_speed' => 'O padrão de --speed é instant. O multiplicador playbackSpeed do
cabeçalho vale para a renderização (render-tape/render-batch), não para a reprodução ao vivo.',
    'replay.interrupted' => 'interrompido após {played} de {total} eventos ({bytes} bytes de saída)',
    'replay.tape_no_output' => '{path} é uma fonte tape sem bytes de saída gravados — a reprodução não mostra nada; use \'candy-vcr render-tape\' para renderizar ficheiros .tape',
    'replay.played' => 'reproduzidos {played} de {total} eventos ({bytes} bytes de saída, {seconds} s de tempo gravado)',
    'record.output_gz' => '--output não pode ser um caminho .gz; record grava JSONL simples — use um nome .cas/.jsonl (gzip via CompressedJsonlFormat na API)',
    'record.refuse_overwrite' => 'a recusar sobrescrever a fita existente {path} — passe --force para substituí-la',
    'record.command_not_found' => 'comando não encontrado: {cmd}',
    'record.incomplete' => 'captura da fita INCOMPLETA ({count} gravação(ões) com falha) em {path}',
    'record.recorded' => 'candy-vcr: {path} gravado (saída {code})',
    'record.output_requires' => '--output exige um caminho',
    'record.env_regex_invalid' => '--env-regex=\'{regex}\' não é um padrão PCRE válido',
    'record.unknown_option' => 'opção desconhecida {option}',
    'record.shell_with_cmd' => '--shell não pode ser combinado com um <cmd> posicional',
    'record.dims_invalid' => '--cols e --rows devem ser inteiros positivos (cols={cols} rows={rows})',
    'record.env_all_warning' => 'candy-vcr record: AVISO --env-all capturou todo o ambiente do host SEM filtrar segredos ({count} variáveis): {names}',
    'record.none' => '(nenhuma)',
    'record.termios_skipped' => 'snapshot do termios ignorado ({reason})',
    'record.raw_failed' => 'falha ao aplicar o modo raw ({reason})',
    'record.help_output' => 'Ficheiro de fita a gravar (padrão: session-<timestamp>.cas)',
    'record.help_force' => 'Substitui um destino --output existente. Sem isso, record
recusa (saída 2) em vez de truncar em silêncio uma fita
já existente em disco.',
    'record.help_cols' => 'Colunas iniciais do terminal (padrão: 80)',
    'record.help_rows' => 'Linhas iniciais do terminal (padrão: 24)',
    'record.help_no_ctty' => 'Iniciar sem terminal de controle (Ctrl+C não chegará ao
programa gravado; padrão: ctty ativado)',
    'record.help_shell' => 'Iniciar $SHELL -l (ou /bin/sh -l) em vez de um <cmd> explícito.
Mutuamente exclusivo com um <cmd> posicional.',
    'record.help_env' => 'Capturar o ambiente do host no cabeçalho da fita. Desativado por
padrão — a captura é opcional para não vazar todo o ambiente do
shell de quem chama. Chaves que casam com o regex de segredos
(veja --env-regex) são removidas.',
    'record.help_env_regex' => 'Substituir o regex que remove segredos (padrão conservador:
/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i).
Um padrão vazio é recusado — ele não serve mais para
desativar a filtragem. Implica --env.',
    'record.help_env_all' => 'PERIGOSO: capturar TODO o ambiente do host sem filtrar segredos
— a fita conterá as credenciais em texto claro e cada nome de
variável capturado é impresso como aviso em stderr. Use somente em
ambientes isolados e confiáveis. Implica --env.
(--env-allow-secrets é um alias legado.)',
    'record.help_idle_trim' => 'Comprimir intervalos entre eventos maiores que N segundos.
Eventos aparados carregam `t` (comprimido) e `tRaw` (original)
para que `replay --no-trim` volte ao ritmo real.',
];
