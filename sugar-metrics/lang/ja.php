<?php

/**
 * Japanese translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'メトリクスのターゲットを開けません：{target}',
    'jsonstream.cannot_open_stderr'   => 'php://stderr を開けません',
    'jsonstream.invalid_target'       => 'ターゲットはパス、リソース、または null である必要があります',
    'jsonstream.write_failed'         => 'JsonStream の書き込みに失敗しました: {name}',
    'statsd.socket_not_resource'      => 'existingSocket はリソースである必要があります',
    'statsd.connect_failed'           => 'StatsD の {endpoint} への接続に失敗：{errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile：開けません {path}',
    'prom.rename_failed'              => 'prometheus textfile：リネーム失敗：{tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: 子バックエンド {count} 個が失敗しました。エラー: {errors}',
    'descriptor.help_empty'           => 'ディスクリプタの help は空にできません',
    'descriptor.help_invalid'         => 'ディスクリプタの help に制御文字を含めることはできません',
    'descriptor.label_empty'          => 'ディスクリプタのラベルキーは空でない文字列でなければなりません',
    'descriptor.label_invalid'        => 'ディスクリプタのラベルキーは有効な Prometheus ラベル名ではありません：{key}',
    'descriptor.name_empty'           => 'ディスクリプタ名は空にできません',
    'descriptor.name_invalid'         => 'ディスクリプタ名は有効な Prometheus メトリクス名ではありません：{name}',
    'descriptor.type_invalid'         => 'ディスクリプターの型は counter、gauge、histogram、summary のいずれかでなければなりません: {type}',
    'jsonstream.encode_failed'        => 'JsonStream はメトリクスのレコードをエンコードできませんでした：{name}',
    'jsonstream.flush_failed'         => 'JsonStream のフラッシュに失敗しました',
    'memory.max_samples'              => 'maxSamplesPerKey は 1 以上である必要があります。指定値: {value}',
    'memory.max_series'               => 'maxSeries は 0 以上である必要があります。指定値: {value}',
    'multibackend.no_children'        => 'MultiBackend には少なくとも 1 つの子バックエンドが必要です',
    'prom.buckets_invalid'            => 'ヒストグラム境界は有限かつ厳密に増加している必要があります。最初の違反はインデックス {index}',
    'prom.duplicate_series'           => 'prometheus textfile: シリーズ {name} がラベル {labels} で重複しています',
    'prom.max_series'                 => 'maxSeriesPerFamily は 1 以上である必要があります。指定値: {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" と "{second}" は同じメトリクス名 "{folded}" にサニタイズされます',
    'prom.reserved_label'             => 'ヒストグラムメトリクス "{name}" は呼び出し元が提供する "le" ラベルを受け付けられません',
    'prom.two_kinds'                  => 'prometheus textfile: 「{name}」はヒストグラムと{kind}の両方として出力されています。1つのメトリック名は2種類のタイプを持てません',
    'prom.write_incomplete'           => 'prometheus textfile: {path} への書き込みが不完全です: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit は 1 以上である必要があります。指定値: {limit}',
    'registry.negative_monotonic'     => '単調な {kind} "{name}" で負の値 {value} は拒否されました',
    'registry.register_conflict'      => 'メトリクス "{name}" は既に {existing} として登録されています。{incoming} として再登録できません',
    'statsd.write_failed'             => 'StatsD の {endpoint} への書き込みに失敗しました',
];
