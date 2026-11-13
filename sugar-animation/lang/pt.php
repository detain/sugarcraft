<?php

/**
 * Portuguese translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation: ficheiro não encontrado: {path}',
    'decoder.no_gd'              => 'sugar-animation: instale a extensão GD (ext-gd) para decodificar GIFs - a reprodução de arte .ansi não precisa dela',
    'decoder.not_gif'            => 'sugar-animation: não é um GIF',
    'decoder.unreadable'         => 'sugar-animation: não é possível ler o ficheiro: {path}',
    'decoder.input_too_large'    => 'sugar-animation: ficheiro demasiado grande (máximo {max})',
    'decoder.not_a_file' => 'sugar-animation: não é um ficheiro: {path}',
    'decoder.too_many_frames' => 'sugar-animation: demasiados fotogramas (máximo {max})',
    'decoder.total_cells_too_large' => 'sugar-animation: orçamento total de células excedido (máximo {max} células)',
    'decoder.grid_too_large'     => 'sugar-animation: grelha de células grande demais (máximo {max} células)',
    'decoder.grid_too_small'     => 'sugar-animation: as dimensões da grelha devem ser positivas',
    'decoder.truncated'          => 'sugar-animation: GIF truncado',
    'decoder.screen_too_large'   => 'sugar-animation: as dimensões da tela do GIF excedem o máximo ({max} pixels)',
    'decoder.screen_too_small'   => 'sugar-animation: as dimensões de tela GIF devem ser positivas',
    'artlibrary.not_a_directory' => 'sugar-animation: não é um diretório: {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: não é possível listar o diretório: {dir}',
    'ansiart.cannot_read'        => 'sugar-animation: não é possível ler o ficheiro de arte: {path}',
    'ansiart.read_failed'        => 'sugar-animation: falha ao ler o ficheiro de arte: {path}',
];
