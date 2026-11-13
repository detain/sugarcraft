<?php

/**
 * Portuguese translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'a largura tem de ser >= 0',
    'tree.offset_nonneg'    => 'os limites de deslocamento da árvore têm de ser >= 0',
    'style.width_nonneg'    => 'a largura tem de ser >= 0',
    'style.height_nonneg'   => 'a altura tem de ser >= 0',
    'style.maxwidth_nonneg' => 'maxWidth tem de ser >= 0',
    'style.maxheight_nonneg' => 'maxHeight tem de ser >= 0',
    'style.tabwidth_nonneg' => 'tabWidth tem de ser >= 0',
    'style.spacing_nonneg' => 'spacing tem de ser >= 0',
    'style.slot_unresolved' => 'os slots de cor adaptativos {slots} nunca foram dobrados; chame resolveAdaptive(bool $isDark) antes de renderizar',
    'style.hyperlink_id_delimiter' => 'o id da hiperligação não deve conter ";" nem ":" (separadores de parâmetros OSC 8)',
    'style.markup_depth' => 'o aninhamento de marcação excede {levels} níveis',
    'style.write_invalid_stream' => 'não é possível escrever: {destination} não é um recurso de stream válido',
    'style.write_short' => 'falha de escrita no stream: escritos {written} de {total} bytes',
    'style.label_nonneg'    => 'os valores de {label} têm de ser >= 0',
    'style.border_toggles_count' => 'os toggles de lado de border() aceitam 0, 2 ou 4 booleanos; obtido: {count}',
    'style.expand_sides_count'   => '{label}() aceita 1, 2 ou 4 inteiros; obtido: {count}',
];
