<?php

/**
 * Korean translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => '너비는 >= 0이어야 합니다',
    'tree.offset_nonneg'    => '트리 오프셋 경계는 >= 0이어야 합니다',
    'style.width_nonneg'    => '너비는 >= 0이어야 합니다',
    'style.height_nonneg'   => '높이는 >= 0이어야 합니다',
    'style.maxwidth_nonneg' => 'maxWidth는 >= 0이어야 합니다',
    'style.maxheight_nonneg' => 'maxHeight는 >= 0이어야 합니다',
    'style.tabwidth_nonneg' => 'tabWidth는 >= 0이어야 합니다',
    'style.spacing_nonneg' => 'spacing는 >= 0이어야 합니다',
    'style.slot_unresolved' => 'adaptive 색상 슬롯 {slots}이(가) 아직 접히지 않았습니다. 렌더링 전에 resolveAdaptive(bool $isDark)를 호출하십시오',
    'style.hyperlink_id_delimiter' => 'hyperlink id에는 ";" 또는 ":"을 들여서는 안 됩니다 (OSC 8 매개변수 구부자)',
    'style.markup_depth' => 'markup 중댁이 {levels} 단계를 초과했습니다',
    'style.write_invalid_stream' => '쓰기 불가: {destination}이(가) 유효한 스트림 리소스가 아닙니다',
    'style.write_short' => '스트림 쓰기 실패: {total}바이트 중 {written}바이트 기록됨',
    'style.label_nonneg'    => '{label}의 값은 >= 0이어야 합니다',
    'style.border_toggles_count' => 'border()는 0, 2 또는 4개의 불리언을 사용합니다; 받음: {count}',
    'style.expand_sides_count'   => '{label}()는 1, 2 또는 4개의 정수를 사용합니다; 받음: {count}',
];
