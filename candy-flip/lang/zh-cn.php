<?php

/**
 * Simplified Chinese translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip：文件不存在：{path}',
    'decoder.no_gd'          => 'candy-flip：需要 ext-gd',
    'decoder.not_gif'        => 'candy-flip：不是 GIF 文件',
    'decoder.grid_too_large' => 'candy-flip：单元格网格乘积超过上限（{max}）',
    'decoder.grid_too_small' => 'candy-flip：单元格网格尺寸必须为正数',
    'decoder.truncated'      => 'candy-flip：GIF 文件被截断',
    'cli.usage'              => '用法：candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip：无法找到 composer autoload.php',
];
