<?php

/**
 * Brazilian Portuguese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'não é possível abrir o destino das métricas: {target}',
    'jsonstream.cannot_open_stderr'   => 'não é possível abrir php://stderr',
    'jsonstream.invalid_target'       => 'o destino deve ser um caminho, recurso ou null',
    'jsonstream.write_failed'         => 'gravação jsonstream falhou: {name}',
    'statsd.socket_not_resource'      => 'existingSocket deve ser um recurso',
    'statsd.connect_failed'           => 'conexão StatsD com {endpoint} falhou: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: não é possível abrir {path}',
    'prom.rename_failed'              => 'prometheus textfile: rename falhou: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} backends filhos falharam. Erros: {errors}',
    'descriptor.help_empty'           => 'a ajuda do descritor não pode estar vazia',
    'descriptor.help_invalid'         => 'a ajuda do descritor não pode conter caracteres de controle',
    'descriptor.label_empty'          => 'as chaves de etiqueta do descritor devem ser strings não vazias',
    'descriptor.label_invalid'        => 'a chave de etiqueta do descritor não é um nome de etiqueta Prometheus válido: {key}',
    'descriptor.name_empty'           => 'o nome do descritor não pode estar vazio',
    'descriptor.name_invalid'         => 'o nome do descritor não é um nome de métrica Prometheus válido: {name}',
    'descriptor.type_invalid'         => 'o tipo do descritor deve ser um entre counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'o jsonstream não pôde codificar o registro da métrica: {name}',
    'jsonstream.flush_failed'         => 'o flush do jsonstream falhou',
    'memory.max_samples'              => 'maxSamplesPerKey deve ser >= 1, obtido {value}',
    'memory.max_series'               => 'maxSeries deve ser >= 0, obtido {value}',
    'multibackend.no_children'        => 'MultiBackend requer pelo menos um backend filho',
    'prom.buckets_invalid'            => 'os limites do histograma devem ser finitos e estritamente crescentes; primeira violação no índice {index}',
    'prom.duplicate_series'           => 'prometheus textfile: série duplicada {name} com rótulos {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily deve ser >= 1, obtido {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" e "{second}" são sanitizados para o mesmo nome de métrica "{folded}"',
    'prom.reserved_label'             => 'a métrica de histograma "{name}" não pode receber um rótulo "le" fornecido pelo chamador',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" é emitido como histograma e como {kind}; um nome de métrica não pode carregar dois tipos',
    'prom.write_incomplete'           => 'prometheus textfile: escrita incompleta em {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit deve ser >= 1, obtido {limit}',
    'registry.negative_monotonic'     => '{kind} monotônico "{name}" recusou valor negativo {value}',
    'registry.register_conflict'      => 'a métrica "{name}" já está registrada como {existing}; não é possível registrar como {incoming}',
    'statsd.write_failed'             => 'gravação StatsD em {endpoint} falhou',
];
