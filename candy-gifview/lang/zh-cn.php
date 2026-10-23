<?php

/**
 * Simplified Chinese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '用法：candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview：无法找到 composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: 未知选项 --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag} 的值 \'{value}\' 无效（需要正数；--speed 也接受 Nfps 或 max）',
    'cli.too_many'    => 'candy-gifview: 多余参数 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} 不接受值',
];
