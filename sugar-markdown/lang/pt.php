<?php

/**
 * Portuguese translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'não foi possível ler o ficheiro de tema: {path}',
    'theme.json_invalid'           => 'JSON inválido no arquivo de tema',
    'theme.json_object'            => 'o JSON do tema tem de descodificar para um objeto',
    'theme.bad_color'              => 'especificação de cor inválida: {spec}',
    'renderer.unknown_style'       => 'estilo padrão desconhecido: {name}',
    'renderer.stream_invalid'      => 'write() requer um recurso de fluxo',
    'renderer.stream_not_writable' => 'write() requer um fluxo gravável; modo recebido "{mode}"',
    'renderer.write_failed'        => 'falha ao gravar {bytes} bytes no fluxo de saída',
    'renderer.chunk_invalid'       => 'os fragmentos de stream() devem ser strings',
    'sink.closed'                  => 'o sink está fechado',
    'sink.parent_missing'          => 'o diretório de saída não existe: {dir}',
    'sink.path_unwritable'         => 'o caminho de saída não é gravável: {path}',
    'sink.open_failed'             => 'falha ao abrir o caminho de saída: {path}',
    'sink.flush_failed'            => 'falha ao liberar o fluxo de saída',
    'writer.feed_after_close'      => 'feed() chamado após close() — o writer terminou',
];
