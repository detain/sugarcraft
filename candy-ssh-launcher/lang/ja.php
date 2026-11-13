<?php

/**
 * Japanese translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '{bin} の実行に失敗',
    'config.not_found'         => 'wishlist 設定が見つかりません：{path}',
    'config.json_top_level'    => 'wishlist json：最上位の値は配列である必要があります',
    'config.json_entry_object' => 'wishlist json：各エントリはオブジェクトである必要があります',
    'config.yaml_continuation' => "wishlist yaml：'- name:' ブロックより前に継続行があります",
    'config.yaml_unparseable'  => 'wishlist yaml：解析できない行 {line}：{found}',
    'config.yaml_block_mapping' => 'wishlist yaml：`{field}:` の下のブロックマッピング（行 {line}：{found}）— このフィールドは `- value` アイテムのリストである必要があります',
    'config.yaml_unterminated_quote' => 'wishlist yaml：値に閉じられていない引用符があります：{found}',
    'config.entry_missing_field' => 'wishlist エントリ #{index}：必須フィールドがありません：{field}',
    'config.unknown_key' => '不明なフィールド `{key}` を無視しました — 文書化されたキーではありません',
    'config.unknown_key_nearmiss' => '不明なフィールド `{key}` を無視しました — `{field}` のことですか?',
    'config.duplicate_name' => 'エンドポイント名 `{name}` が重複 — 全行を読み込みます; 名前は識別子です',
    'config.field_not_scalar'  => 'wishlist エントリ：フィールド [{field}] はスカラーである必要があります。取得型：{type}',
    'config.too_large'          => 'wishlist 設定ファイルが上限 {limit} バイトを超えています：{path}',
    'config.list_too_long'      => 'wishlist エントリ：フィールド [{field}] はローダーが許可する上限 {limit} 件を超えています',
    'config.field_not_list'       => 'wishlist エントリ：フィールド [{field}] はキーと値のマップではなく値のリストでなければなりません',
    'config.encoding_utf16'       => '{path} は UTF-16 エンコードです — 設定を UTF-8 で保存し直してください',
    'config.unreadable'       => 'wishlist 設定を読み込めません：{path}',
    'config.home_unset'       => '$HOME がないため ~ を展開できません：{path}',
    'config.yaml_inline_flow' => 'wishlist yaml：インラインフロウコレクションは未対応：{value}（ネストされたリストを使用）',

    // endpoint security
    'endpoint.option_injection' => 'wishlist エンドポイント：フィールド [{field}] に先頭がダッシュの安全でない値 [{value}] があります',
    'endpoint.host_empty' => 'エンドポイント `{name}` のホストが空です — 選択可能な行にはホスト名が必要です',
    'endpoint.name_empty' => 'エンドポイント名が空です — 選択可能な行には名前が必要です',
    'endpoint.option_empty' => 'エンドポイント `{name}` に空の options エントリがあります — 空の -o 値は拒否されます',
    'endpoint.port_invalid'     => 'wishlist エンドポイント [{host}]：無効なポート [{port}] — 1 から 65535 の整数である必要があります',

    'cli.usage'                => '用法：candy-ssh-launcher [--config <パス>] [--ssh <sshバイナリ>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'          => 'candy-ssh-launcher：不明な引数：{arg}',
    'cli.no_config'            => 'candy-ssh-launcher：設定が見つかりません。~/.config/wishlist.yml を作成してください（README のクイックスタートを参照）。または --config <パス> を渡してください。',
    'cli.error'                => 'candy-ssh-launcher：{message}',
    'cli.cancelled'            => 'candy-ssh-launcher：キャンセルされました。',
    'cli.ssh_not_executable' => 'candy-ssh-launcher：ssh バイナリが見つからないか実行できません：{bin} — ssh が別の場所にある場合は --ssh <パス> を指定してください',
    'cli.missing_value'  => 'candy-ssh-launcher：{arg} には値が必要です',
    'cli.no_endpoints'   => 'candy-ssh-launcher：{path} にエンドポイントがありません',
    'cli.not_a_terminal' => 'ピッカーには対話型ターミナルが必要です — stdin と stdout は TTY である必要があります。機械可読の出力には --list を使用してください。',
    'cli.config_unowned'   => 'candy-ssh-launcher：他のユーザーが所有する設定は拒否します — {path}。設定は信頼される入力です（README の Security 参照）。',
    'cli.config_writable'  => 'candy-ssh-launcher：グループまたは全員が書き込める設定は拒否します — {path}。0644 か 0600 に絞るか、--config で明示的に指定してください。',
    'cli.project_local'    => 'candy-ssh-launcher：プロジェクトローカルの wishlist を使用中 — {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'フィルター: ',
    'picker.no_matches'  => '  (一致なし — Backspace または Esc)',
    'picker.help'        => '↑/↓ 選択 · Enter 接続 · Esc 終了 · 入力で絞り込み',
    'picker.help_keys'   => '⌫ 削除 · Ctrl+U/W クリア · PgUp/PgDn ページ · Home/End 先頭/末尾',
    'picker.count'       => '  （{shown}/{total}）',
];
