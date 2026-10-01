<?php

/**
 * Japanese translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip：ファイルが見つかりません：{path}',
    'decoder.no_gd'          => 'candy-flip：ext-gd が必要です',
    'decoder.not_gif'        => 'candy-flip：GIF ファイルではありません',
    'decoder.grid_too_large' => 'candy-flip：セルグリッドの積が上限（{max}）を超過',
    'decoder.grid_too_small' => 'candy-flip：セルグリッドの次元は正の値である必要があります',
    'decoder.truncated'      => 'candy-flip：GIF が切り詰められています',
    'cli.usage'              => '用法：candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip：composer autoload.php が見つかりません',
];
