<?php

/**
 * Brazilian Portuguese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: não é possível localizar composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opção desconhecida --{flag}',
    'cli.empty_flag'  => 'candy-gifview: nome de opção ausente após --',
    'cli.bad_value'   => 'candy-gifview: valor inválido \'{value}\' para {flag} (espera-se um número positivo)',
    'cli.too_many'    => 'candy-gifview: argumento inesperado \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} não aceita valor',
    'cli.bad_preset' => 'candy-gifview: preset desconhecido \'{preset}\' (esperado solid ou density)',
    'cli.no_frames' => 'candy-gifview: não há quadros para reproduzir em \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: não foi possível reproduzir \'{path}\': {reason}',
    'cli.reason_frames_over' => 'a animação excede o limite de {max} quadros; carregamento recusado',
    'cli.reason_bytes_over' => 'o arquivo é maior que o limite de {max} MiB',
    'cli.reason_cells_over' => 'a animação precisa de mais que o limite de {max} células para esta janela',
    'cli.duration_static' => 'candy-gifview: --duration precisa de pelo menos dois quadros; este arquivo decodifica para {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd é necessário para decodificar GIFs (instale o php-gd)',
    'cli.decoding' => 'candy-gifview: decodificando \'{path}\'...',
    'cli.truncated' => 'candy-gifview: exibindo os primeiros {max} quadros — este visualizador não lê animações mais longas',
];
