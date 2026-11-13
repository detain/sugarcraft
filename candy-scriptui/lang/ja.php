<?php

/**
 * Japanese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '色が空です',
    'style.unrecognised_color' => '認識できない色：{value} — allowed: {allowed}',
    'style.hex_digit_count' => "16進色の桁数は3または6である必要があります（例: '#f80' または '#ff8800'）。取得: '{value}'",
    'style.padding_token_int' => "padding/margin トークンは整数である必要があります；取得：'{token}'",
    'style.padding_count'     => 'padding/margin は 1、2、または 4 つの整数が必要です；取得：{count}',
    'style.bad_entry'         => "--style エントリは 'key=value' または 'element.prop=value' である必要があります；取得：'{raw}'",
    'style.unknown_prop'      => "不明なスタイルプロパティ：'{prop}'",
    'process.spawn_failed'    => '子プロセスの起動に失敗',
    'border.unknown'          => '不明なボーダー 스타일：{name}',
    'log.unknown_level'       => '不明なログレベル：{name}',
    'log.encode_failed' => 'ログエントリのjsonエンコードに失敗しました',
    'log.bad_format' => '無効な --format 文字列: {format}',
    'spinner.unknown_style'   => '不明なスピナースタイル：{name}',
    'spinner.windows_unsupported' => 'spin はネイティブ Windows ではコマンドを実行できません。プロセスバックエンドはサポートされていません。代わりに WSL を使用してください。',
    'format.unknown_theme'    => '不明なテーマ：{name}',
    'io.read_failed' => 'ファイルを読み込めません: {path}',
    'io.write_failed' => 'ファイルに書き込めません: {path}',
    'log.unknown_formatter' => '不明な --formatter: {name}',
    'completion.unknown_shell' => 'サポートされていないシェル: {shell}',
    'confirm.unknown_default' => '不明な --default 値: {value}',
    'cursor_mode.unknown' => '不明なカーソルモード: {mode}',
    'runtime.no_terminal' => '{command} にはインタラクティブな端末が必要です。制御端末を利用できません。',
    'runtime.bad_timeout' => '--timeout の値 \'{value}\' が不正です — 非負の秒数を指定してください (0 で期限無効)。',
    'runtime.unknown_choice' => '{option} の値 \'{value}\' が不正です。使用可能: {allowed}。',
    'hints.choose' => '↑/↓ 移動 · space 切替 (複数) · enter 選択 · esc キャンセル',
    'hints.filter' => '入力でフィルタ · ↑/↓ 移動 · tab 切替 (複数) · enter 選択 · esc キャンセル',
    'hints.pager' => 'space/PgDn ページ 下 · PgUp/b ページ 上 · g 最初 · G 最後 · q 終了',
    'format.template_unresolved' => 'テンプレート: {count} 個のプレースホルダーが空に展開されました: {names} — 変数を設定し --allow-env で許可する必要があります。',
    'runtime.empty_input' => '入力行がありません。操作何もしません。',
];
