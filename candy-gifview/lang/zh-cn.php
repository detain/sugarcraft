<?php

/**
 * Simplified Chinese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => '用法：candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview：无法找到 composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: 未知选项 --{flag}',
    'cli.empty_flag'  => 'candy-gifview: -- 后缺少选项名称',
    'cli.bad_value'   => 'candy-gifview: {flag} 的值 \'{value}\' 无效（需要正数）',
    'cli.too_many'    => 'candy-gifview: 多余参数 \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} 不接受值',
    'cli.bad_preset' => 'candy-gifview: 未知预设 \'{preset}\'（应为 solid 或 density）',
    'cli.no_frames' => 'candy-gifview: \'{path}\' 中没有可播放的帧',
    'cli.cannot_play' => 'candy-gifview: 无法播放 \'{path}\'：{reason}',
    'cli.reason_frames_over' => '动画超过 {max} 帧的加载上限；拒绝加载',
    'cli.reason_bytes_over' => '文件大于 {max} MiB 的加载上限',
    'cli.reason_cells_over' => '在此视口中动画超过 {max} 格的驻留预算',
    'cli.duration_static' => 'candy-gifview: --duration 至少需要两帧；此文件解码为 {count} 帧',
    'cli.need_gd' => 'candy-gifview: 解码 GIF 需要 ext-gd（请安装 php-gd）',
    'cli.decoding' => 'candy-gifview: 正在解码 \'{path}\'...',
    'cli.truncated' => 'candy-gifview: 仅显示前 {max} 帧——本查看器不读取更长的动画',
];
