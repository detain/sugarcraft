<?php

/**
 * Japanese translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'テーマファイルを読み取れません：{path}',
    'theme.json_invalid'           => 'テーマファイルの JSON が無効です',
    'theme.json_object'            => 'テーマ JSON はオブジェクトにデコードされる必要があります',
    'theme.bad_color'              => '無効な色の指定: {spec}',
    'renderer.unknown_style'       => '不明な標準スタイル：{name}',
    'renderer.stream_invalid'      => 'write() にはストリームリソースが必要です',
    'renderer.stream_not_writable' => 'write() には書き込み可能なストリームが必要です (取得したモード: "{mode}")',
    'renderer.write_failed'        => '出力ストリームへの {bytes} バイトの書き込みに失敗しました',
    'renderer.chunk_invalid'       => 'stream() のチャンクは文字列でなければなりません',
    'sink.closed'                  => 'シンクは閉じられています',
    'sink.parent_missing'          => '出力ディレクトリが存在しません: {dir}',
    'sink.path_unwritable'         => '出力パスは書き込み可能ではありません: {path}',
    'sink.open_failed'             => '出力パスを開けませんでした: {path}',
    'sink.flush_failed'            => '出力ストリームのフラッシュに失敗しました',
    'writer.feed_after_close'      => 'close() の後に feed() が呼び出されました — ライターは完了済みです',
];
