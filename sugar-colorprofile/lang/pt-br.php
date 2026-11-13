<?php

/**
 * Brazilian Portuguese translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'O índice de cor ANSI 16 deve ser 0–15, obtido: {index}',
    'standard.ansi256_out_of_range' => 'O índice de cor ANSI 256 deve ser 0–255, obtido: {index}',
    'standard.color_name_unknown' => 'nome de cor desconhecido: {name}',
    'standard.ansi8_out_of_range' => 'O índice de cor ANSI 8 deve ser 0-7, obtido: {index}',
    'color.invalid_hex' => 'cor hexadecimal inválida: {hex}',
    'colormath.invalid_xyz' => 'O componente XYZ {key} está ausente ou não é um número finito',
    'deltae.invalid_lab' => 'A matriz Lab {label} não possui o componente numérico "{key}"',
    'deltae.non_finite_lab' => 'A matriz Lab {label} tem um componente "{key}" não finito',
    'distance.euclidean_needs_rgb' => 'a métrica euclidiana compara bytes RGB — use between() em vez de betweenLab()',
    'nearest.empty_palette' => 'não é possível combinar uma cor com uma paleta vazia',
    'probe.unknown_capability' => 'chave(s) de capacidade desconhecida(s): {keys}',
    'nearest.error_shape' => 'withError() espera uma lista de 3 elementos [r, g, b]; recebidos {count} elemento(s)',
    'nearest.error_channel' => 'os canais do acumulador withError() devem ser int ou float; recebido {type}',
    'palette_index.metric' => 'PaletteIndex requer uma métrica que preserva a distância (Euclidean ou Cie76); {metric} não tem base PCA fixa - use NearestColor::closest() em vez disso',
    'stream.invalid_resource' => 'o fluxo {label} deve ser um recurso válido',
    'palette.describe' => 'Seu terminal suporta {label} ({description}).',
    'probe.color_description_truecolor' => 'TrueColor de 24 bits (16,7 milhões de cores)',
    'probe.color_description_ansi256' => 'ANSI de 256 cores',
    'probe.color_description_ansi16' => 'ANSI de 16 cores',
    'probe.color_description_no_color' => 'Sem suporte a cores',
    'probe.color_description_basic_ascii' => 'ASCII básico',
];
