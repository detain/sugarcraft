<?php

/**
 * Japanese translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation：ファイルが見つかりません：{path}',
    'decoder.no_gd'              => 'sugar-animation：GIF をデコードするには GD 画像拡張機能（ext-gd）をインストールしてください - .ansi アートの再生には不要です',
    'decoder.not_gif'            => 'sugar-animation：GIF ファイルではありません',
    'decoder.unreadable'         => 'sugar-animation：ファイルを読み込めません：{path}',
    'decoder.input_too_large'    => 'sugar-animation：ファイルが大きすぎます（最大 {max}）',
'decoder.not_a_file' => 'sugar-animation：ファイルではありません：{path}',
'decoder.too_many_frames' => 'sugar-animation：フレーム数が多すぎます（最大 {max}）',
    'decoder.grid_too_large'     => 'sugar-animation：セルグリッドが大きすぎます（最大 {max} セル）',
    'decoder.grid_too_small'     => 'sugar-animation：セルグリッドの次元は正の値である必要があります',
    'decoder.truncated'          => 'sugar-animation：GIF が切り詰められています',
    'decoder.screen_too_large'   => 'sugar-animation：GIF の画面サイズが上限（{max} ピクセル）を超過',
    'decoder.screen_too_small'   => 'sugar-animation: GIF画面の寸法は正の値である必要があります',
    'artlibrary.not_a_directory' => 'sugar-animation：ディレクトリではありません：{dir}',
    'artlibrary.cannot_list'     => 'sugar-animation：ディレクトリを一覧できません：{dir}',
    'ansiart.cannot_read'        => 'sugar-animation：アートファイルを読み込めません：{path}',
    'ansiart.read_failed'        => 'sugar-animation：アートファイルの読み込みに失敗しました：{path}',
];
