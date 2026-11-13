<?php

/**
 * Japanese translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => '幅は >= 0 である必要があります',
    'tree.offset_nonneg'    => 'ツリーオフセット境界は >= 0 である必要があります',
    'style.width_nonneg'    => '幅は >= 0 である必要があります',
    'style.height_nonneg'   => '高さは >= 0 である必要があります',
    'style.maxwidth_nonneg' => 'maxWidth は >= 0 である必要があります',
    'style.maxheight_nonneg' => 'maxHeight は >= 0 である必要があります',
    'style.tabwidth_nonneg' => 'tabWidth は >= 0 である必要があります',
    'style.spacing_nonneg' => 'spacing は >= 0 である必要があります',
    'style.slot_unresolved' => 'アダプティブカラーンスロット {slots} がまだ畳まれていません。レンダリング前に resolveAdaptive(bool $isDark) を呼び出してください',
    'style.hyperlink_id_delimiter' => 'hyperlink id には ";" や ":" を含めないでください (OSC 8 パラメータ区切り)',
    'style.markup_depth' => 'markup の階層が {levels} レベルを超えています',
    'style.write_invalid_stream' => '書き込めません: {destination} は有効なストリームリソースではありません',
    'style.write_short' => 'ストリーム書き込み失敗: {total} バイト中 {written} バイト書き込まれました',
    'style.label_nonneg'    => '{label} の値は >= 0 である必要があります',
    'style.border_toggles_count' => 'border() のサイドトグルは 0、2、または 4 つのブール値を取ります；取得：{count}',
    'style.expand_sides_count'   => '{label}() は 1、2、または 4 つの整数を取ります；取得：{count}',
];
