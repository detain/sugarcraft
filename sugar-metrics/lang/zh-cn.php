<?php

/**
 * Simplified Chinese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => '无法打开指标目标：{target}',
    'jsonstream.cannot_open_stderr'   => '无法打开 php://stderr',
    'jsonstream.invalid_target'       => '目标必须是路径、资源或 null',
    'jsonstream.write_failed'         => 'JsonStream 写入失败：{name}',
    'statsd.socket_not_resource'      => 'existingSocket 必须是资源',
    'statsd.connect_failed'           => 'StatsD 连接 {endpoint} 失败：{errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile：无法打开 {path}',
    'prom.rename_failed'              => 'prometheus textfile：重命名失败：{tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend：{count} 个子后端失败。错误：{errors}',
    'descriptor.help_empty'           => '描述符 help 不能为空',
    'descriptor.help_invalid'         => '描述符 help 不能包含控制字符',
    'descriptor.label_empty'          => '描述符标签键必须为非空字符串',
    'descriptor.label_invalid'        => '描述符标签键不是有效的 Prometheus 标签名称：{key}',
    'descriptor.name_empty'           => '描述符名称不能为空',
    'descriptor.name_invalid'         => '描述符名称不是有效的 Prometheus 指标名称：{name}',
    'descriptor.type_invalid'         => '描述符类型必须是 counter、gauge、histogram、summary 之一：{type}',
    'jsonstream.encode_failed'        => 'JsonStream 无法编码指标记录：{name}',
    'jsonstream.flush_failed'         => 'JsonStream 刷新失败',
    'memory.max_samples'              => 'maxSamplesPerKey 必须 >= 1，实际为 {value}',
    'memory.max_series'               => 'maxSeries 必须 >= 0，实际为 {value}',
    'multibackend.no_children'        => 'MultiBackend 至少需要一个子后端',
    'prom.buckets_invalid'            => '直方图边界必须有限且严格递增；首个违规位于索引 {index}',
    'prom.duplicate_series'           => 'prometheus textfile：重复序列 {name}，标签 {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily 必须 >= 1，实际为 {limit}',
    'prom.name_collision'             => 'prometheus textfile："{first}" 与 "{second}" 被净化为同一指标名 "{folded}"',
    'prom.reserved_label'             => '直方图指标 "{name}" 不能接受调用方提供的 "le" 标签',
    'prom.two_kinds'                  => 'prometheus textfile："{name}" 同时作为直方图和 {kind} 输出；一个指标名称不能承载两种类型',
    'prom.write_incomplete'           => 'prometheus textfile：写入 {path} 不完整：{reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit 必须 >= 1，实际为 {limit}',
    'registry.negative_monotonic'     => '单调 {kind} "{name}" 拒绝负值 {value}',
    'registry.register_conflict'      => '指标 "{name}" 已注册为 {existing}；无法重新注册为 {incoming}',
    'statsd.write_failed'             => 'StatsD 写入 {endpoint} 失败',
];
