<?php

/**
 * Japanese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '用法：candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview：composer autoload.php が見つかりません',
    'cli.bad_flag'    => 'candy-gifview: 不明なオプション --{flag}',
    'cli.bad_value'   => 'candy-gifview: {flag} の値 \'{value}\' は無効です（正の数を指定してください。--speed は Nfps または max も指定できます）',
    'cli.too_many'    => 'candy-gifview: 予期しない引数 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} は値を取りません',
];
