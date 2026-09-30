<?php

/**
 * Japanese translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec が利用できません；ext-pcntl が必要です',
    'launcher.exec_failed'     => '{bin} の実行に失敗',
    'config.not_found'         => 'wishlist 設定が見つかりません：{path}',
    'config.json_top_level'    => 'wishlist json：最上位の値は配列である必要があります',
    'config.json_entry_object' => 'wishlist json：各エントリはオブジェクトである必要があります',
    'config.yaml_continuation' => "wishlist yaml：'- name:' ブロックの前には Continuation を設定できません",
    'config.yaml_unparseable'  => 'wishlist yaml：解析できない行：{line}',
    'config.entry_missing_field' => 'wishlist エントリに必要なフィールドが不足しています：name または host',
    'config.field_not_scalar'  => 'wishlist エントリー: フィールド [{field}] はスカラーである必要があります。取得型: {type}',

    // endpoint security
    'endpoint.option_injection' => 'wishlist エンドポイント: フィールド [{field}] に先頭がダッシュの安全でない値 [{value}] があります',
    'endpoint.port_invalid'     => 'wishlist エンドポイント [{host}]: 無効なポート [{port}] — 1 から 65535 の整数である必要があります',

    'cli.usage'                => '用法：wishlist [--config <パス>] [--ssh <sshバイナリ>]',
    'cli.unknown_arg'          => 'wishlist：不明な引数：{arg}',
    'cli.no_config'            => 'wishlist：設定が見つかりません。--config <パス> を渡してください。',
    'cli.error'                => 'wishlist：{message}',
    'cli.cancelled'            => 'wishlist：キャンセルされました。',
    'cli.ssh_not_executable' => 'wishlist: ssh バイナリが見つからないか実行できません: {bin}',
];
