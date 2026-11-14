<?php

/**
 * Korean translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => '메트릭 대상을 열 수 없음: {target}',
    'jsonstream.cannot_open_stderr'   => 'php://stderr을(를) 열 수 없음',
    'jsonstream.invalid_target'       => '대상은 경로, 리소스 또는 null이어야 합니다',
    'jsonstream.write_failed'         => 'JsonStream 쓰기 실패: {name}',
    'statsd.socket_not_resource'      => 'existingSocket은 리소스여야 합니다',
    'statsd.connect_failed'           => 'StatsD의 {endpoint}(으)로 연결 실패: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: 열 수 없음 {path}',
    'prom.rename_failed'              => 'prometheus textfile: 이름 변경 실패: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: 하위 백엔드 {count}개 실패. 오류: {errors}',
    'descriptor.help_empty'           => '설명자 help는 비어 있을 수 없습니다',
    'descriptor.help_invalid'         => '설명자 help에 제어 문자를 포함할 수 없습니다',
    'descriptor.label_empty'          => '설명자 레이블 키는 비어 있지 않은 문자열이어야 합니다',
    'descriptor.label_invalid'        => '설명자 레이블 키가 유효한 Prometheus 레이블 이름이 아닙니다: {key}',
    'descriptor.name_empty'           => '설명자 이름은 비어 있을 수 없습니다',
    'descriptor.name_invalid'         => '설명자 이름이 유효한 Prometheus 메트릭 이름이 아닙니다: {name}',
    'descriptor.type_invalid'         => '기술자 유형은 counter, gauge, histogram, summary 중 하나여야 합니다: {type}',
    'jsonstream.encode_failed'        => 'JsonStream이 메트릭 레코드를 인코딩할 수 없습니다: {name}',
    'jsonstream.flush_failed'         => 'JsonStream 플러시 실패',
    'memory.max_samples'              => 'maxSamplesPerKey는 1 이상이어야 합니다. 받은 값: {value}',
    'memory.max_series'               => 'maxSeries는 0 이상이어야 합니다. 받은 값: {value}',
    'multibackend.no_children'        => 'MultiBackend에는 최소 하나의 하위 백엔드가 필요합니다',
    'prom.buckets_invalid'            => '히스토그램 경계는 유한하며 엄격히 증가해야 합니다. 첫 위반 인덱스: {index}',
    'prom.duplicate_series'           => 'prometheus textfile: 시리즈 {name}이(가) 레이블 {labels}(으)로 중복되었습니다',
    'prom.max_series'                 => 'maxSeriesPerFamily는 1 이상이어야 합니다. 받은 값: {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}"과(와) "{second}"이(가) 동일한 메트릭 이름 "{folded}"(으)로 정리됩니다',
    'prom.reserved_label'             => '히스토그램 메트릭 "{name}"은(는) 호출자가 제공한 "le" 레이블을 허용하지 않습니다',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}"이 히스토그램과 {kind} 두 유형으로 모두 출력됩니다; 한 메트릭 이름은 두 유형을 가질 수 없습니다',
    'prom.write_incomplete'           => 'prometheus textfile: {path}에 대한 쓰기가 불완전합니다: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit은 1 이상이어야 합니다. 받은 값: {limit}',
    'registry.negative_monotonic'     => '단조 {kind} "{name}"에서 음수 값 {value} 거부됨',
    'registry.register_conflict'      => '"{name}" 메트릭은 이미 {existing}(으)로 등록되어 있습니다. {incoming}(으)로 재등록할 수 없습니다',
    'statsd.write_failed'             => 'StatsD의 {endpoint} 쓰기 실패',
];
