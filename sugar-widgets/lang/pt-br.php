<?php

/**
 * Brazilian Portuguese translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'A largura da coluna deve ser >= 0',
    'tabs.bad_index'              => 'índice de aba fora do intervalo',
    'tabs.neg_width'              => 'a largura das abas deve ser >= 0',
    'tabs.neg_padding'            => 'o preenchimento do rótulo da aba deve ser >= 0',
    'tabs.zone_key_empty'         => 'a chave de zona da aba não pode estar vazia',
    'tabs.close_glyph_empty'      => 'o símbolo de fechar aba não pode estar vazio',
    'table.dim_nonneg'            => 'a largura/altura da tabela deve ser >= 0',
    'table.page_size_nonneg'      => 'o tamanho da página deve ser >= 0',
    'table.set_columns_type'      => 'as colunas devem ser instâncias de coluna',
    'table.sort_unknown_column'   => 'coluna de ordenação não encontrada: {column}',
    'progress.width_nonneg'       => 'a largura do progresso deve ser >= 0',
    'tree.dim_nonneg'             => 'a largura/altura da árvore deve ser >= 0',
    'help.width_nonneg'           => 'a largura da ajuda deve ser >= 0',
    'stopwatch.interval_positive' => 'o intervalo do cronômetro deve ser > 0',
    'timer.duration_nonneg'       => 'a duração do temporizador deve ser >= 0',
    'timer.interval_positive'     => 'o intervalo do temporizador deve ser > 0',
    'paginator.arabic_format_invalid' => 'o formato arábico deve ser um formato válido de dois inteiros',
    'progress.bad_percent_format' => 'formato de porcentagem deve ser um formato válido de um inteiro',
    'progress.bad_value_format' => 'formato de valor deve ser um formato válido de dois inteiros',
    'progress.rune_cell_width' => 'os caracteres da barra devem ter exatamente uma célula de exibição',
    'progress.color_func_type' => 'a função de cor deve retornar uma cor',
    'tabs.next_tab' => 'próxima aba',
    'tabs.prev_tab' => 'aba anterior',
    'tabs.jump_to' => 'aba {index}',
    'tabs.close_tab' => 'fechar aba',
    'timer.paused_tag' => 'em pausa',
    'timer.timeout_tag' => 'tempo esgotado',
    'stopwatch.not_started_tag' => 'não iniciado',
    'stopwatch.paused_tag' => 'em pausa',
    'key.bad_binding' => 'combinação de teclas inválida {key}: use um nome de tecla em minúsculas (ctrl+c, shift+tab, pageup, f5) ou um caractere imprimível',
    'key.bad_binding_type' => 'as teclas da combinação devem ser cadeias de caracteres, recebido {key}',
    'tree.use_branch_for_children' => 'use a fábrica branch() quando um nó tiver filhos',
    'button.pressed' => '{label} ativado',
    'rule.length_nonneg' => 'o comprimento da régua deve ser >= 0',
    'rule.bad_rune' => 'o caractere da régua deve ocupar exatamente uma célula',

    // Help/HelpOverlay.php, Key/KeyView.php
    'help_overlay.height_positive' => 'a altura da sobreposição de ajuda deve ser >= 1',
    'key_view.bad_columns' => 'as colunas da visão de teclas devem ser uma lista de listas de nomes de ação',
    'key_view.column_size_nonneg' => 'o tamanho de coluna da visão de teclas deve ser >= 0',
];
