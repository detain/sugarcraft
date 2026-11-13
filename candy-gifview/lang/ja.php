<?php

/**
 * Japanese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '用法：candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview：composer autoload.php が見つかりません',
    'cli.bad_flag'    => 'candy-gifview: 不明なオプション --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag} の値 \'{value}\' は無効です（正の数を指定してください）',
    'cli.too_many'    => 'candy-gifview: 予期しない引数 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} は値を取りません',
    'cli.bad_preset' => 'candy-gifview: 不明なプリセット \'{preset}\'（solid か density を指定してください）',
    'cli.no_frames' => 'candy-gifview: \'{path}\' に再生するフレームがありません',
    'cli.cannot_play' => 'candy-gifview: \'{path}\' を再生できませんでした: {reason}',
    'cli.reason_frames_over' => 'アニメーションが上限の {max} フレームを超えています。読み込みを拒否します',
    'cli.reason_bytes_over' => 'ファイルが上限の {max} MiB を超えています',
    'cli.reason_cells_over' => 'このビューポートではアニメーションが {max} セルのメモリ予算を超えています',
    'cli.duration_static' => 'candy-gifview: --duration には2フレーム以上必要です。このファイルは {count} フレームにデコードされます',
    'cli.need_gd' => 'candy-gifview: GIF のデコードには ext-gd が必要です（php-gd をインストールしてください）',
    'cli.decoding' => 'candy-gifview: \'{path}\' をデコード中...',
    'cli.truncated' => 'candy-gifview: 先頭の {max} フレームのみ表示 — これより長いアニメーションは読み込みません',
];
