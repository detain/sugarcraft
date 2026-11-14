<?php

/**
 * Portuguese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'não é possível abrir o destino das métricas: {target}',
    'jsonstream.cannot_open_stderr'   => 'não é possível abrir php://stderr',
    'jsonstream.invalid_target'       => 'o destino tem de ser um caminho, recurso ou null',
    'jsonstream.write_failed'         => 'gravação jsonstream falhou: {name}',
    'statsd.socket_not_resource'      => 'existingSocket tem de ser um recurso',
    'statsd.connect_failed'           => 'ligação StatsD a {endpoint} falhou: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: não é possível abrir {path}',
    'prom.rename_failed'              => 'prometheus textfile: rename falhou: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} backends secundários falharam. Erros: {errors}',
    'descriptor.help_empty'           => 'a ajuda do descritor não pode estar vazia',
    'descriptor.help_invalid'         => 'a ajuda do descritor não pode conter caracteres de controlo',
    'descriptor.label_empty'          => 'as chaves de etiqueta do descritor devem ser cadeias não vazias',
    'descriptor.label_invalid'        => 'a chave de etiqueta do descritor não é um nome de etiqueta Prometheus válido: {key}',
    'descriptor.name_empty'           => 'o nome do descritor não pode estar vazio',
    'descriptor.name_invalid'         => 'o nome do descritor não é um nome de métrica Prometheus válido: {name}',
    'descriptor.type_invalid'         => 'o tipo do descritor deve ser um entre counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'o jsonstream não conseguiu codificar o registo da métrica: {name}',
    'jsonstream.flush_failed'         => 'a descarga do jsonstream falhou',
    'memory.max_samples'              => 'maxSamplesPerKey tem de ser >= 1, obtido {value}',
    'memory.max_series'               => 'maxSeries tem de ser >= 0, obtido {value}',
    'multibackend.no_children'        => 'o MultiBackend exige pelo menos um backend secundário',
    'prom.buckets_invalid'            => 'os limites do histograma têm de ser finitos e estritamente crescentes; primeira violação no índice {index}',
    'prom.duplicate_series'           => 'prometheus textfile: série duplicada {name} com etiquetas {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily tem de ser >= 1, obtido {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" e "{second}" são saneados para o mesmo nome de métrica "{folded}"',
    'prom.reserved_label'             => 'a métrica de histograma "{name}" não pode receber uma etiqueta "le" do chamador',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" é emitido como histograma e como {kind}; um nome de métrica não pode carregar dois tipos',
    'prom.write_incomplete'           => 'prometheus textfile: escrita incompleta em {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit tem de ser >= 1, obtido {limit}',
    'registry.negative_monotonic'     => '{kind} monotónico "{name}" recusou valor negativo {value}',
    'registry.register_conflict'      => 'a métrica "{name}" já está registada como {existing}; não pode ser registada como {incoming}',
    'statsd.write_failed'             => 'a escrita StatsD em {endpoint} falhou',
];
