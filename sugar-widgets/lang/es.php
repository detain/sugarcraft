<?php

/**
 * Spanish translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'El ancho de columna debe ser >= 0',
    'tabs.bad_index'              => 'índice de pestaña fuera de rango',
    'tabs.neg_width'              => 'el ancho de pestañas debe ser >= 0',
    'table.dim_nonneg'            => 'el ancho/alto de la tabla debe ser >= 0',
    'table.page_size_nonneg'      => 'el tamaño de página debe ser >= 0',
    'table.set_columns_type'      => 'setColumns espera instancias de Column',
    'table.sort_unknown_column'   => 'columna de ordenación no encontrada: {column}',
    'progress.width_nonneg'       => 'el ancho del progreso debe ser >= 0',
    'tree.dim_nonneg'             => 'el ancho/alto del árbol debe ser >= 0',
    'help.width_nonneg'           => 'el ancho de la ayuda debe ser >= 0',
    'stopwatch.interval_positive' => 'el intervalo del cronómetro debe ser > 0',
    'timer.duration_nonneg'       => 'la duración del temporizador debe ser >= 0',
    'timer.interval_positive'     => 'el intervalo del temporizador debe ser > 0',
    'paginator.arabic_format_invalid' => 'el formato arábigo debe ser un sprintf válido de dos enteros',
    'progress.bad_percent_format' => 'el formato de porcentaje debe ser un sprintf válido de un entero',
    'progress.bad_value_format' => 'el formato de valor debe ser un sprintf válido de dos enteros',
    'progress.rune_cell_width' => 'los caracteres de la barra deben medir exactamente una celda',
    'progress.color_func_type' => 'colorFunc debe devolver un Color',
    'tabs.next_tab' => 'siguiente pestaña',
    'tabs.prev_tab' => 'anterior pestaña',
    'tabs.jump_to' => 'pestaña {index}',
    'timer.paused_tag' => 'en pausa',
    'timer.timeout_tag' => 'tiempo agotado',
    'stopwatch.not_started_tag' => 'sin iniciar',
    'stopwatch.paused_tag' => 'en pausa',
    'key.bad_binding' => 'combinación de teclas no válida {key}: use un nombre de tecla en minúsculas (ctrl+c, shift+tab, pageup, f5) o un carácter imprimible',
    'key.bad_binding_type' => 'las teclas de la combinación deben ser cadenas, se obtuvo {key}',
];
