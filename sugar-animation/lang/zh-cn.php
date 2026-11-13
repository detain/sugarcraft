<?php

/**
 * Simplified Chinese translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation：文件不存在：{path}',
    'decoder.no_gd'              => 'sugar-animation: 解码 GIF 需要安装 GD 图像扩展（ext-gd）- .ansi 艺术播放不需要它',
    'decoder.not_gif'            => 'sugar-animation：不是 GIF 文件',
    'decoder.unreadable'         => 'sugar-animation: 无法读取文件：{path}',
    'decoder.input_too_large'    => 'sugar-animation: 文件过大（最多 {max}）',
'decoder.not_a_file' => 'sugar-animation: 不是文件：{path}',
'decoder.too_many_frames' => 'sugar-animation: 帧数过多（最多 {max}）',
    'decoder.grid_too_large'     => 'sugar-animation: 单元格网格过大（最多 {max} 个单元格）',
    'decoder.grid_too_small'     => 'sugar-animation：单元格网格尺寸必须为正数',
    'decoder.truncated'          => 'sugar-animation：GIF 文件被截断',
    'decoder.screen_too_large'   => 'sugar-animation：GIF 屏幕尺寸超过上限（{max} 像素）',
    'decoder.screen_too_small'   => 'sugar-animation: GIF 屏幕尺寸必须为正数',
    'artlibrary.not_a_directory' => 'sugar-animation: 不是目录：{dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: 无法列出目录：{dir}',
    'ansiart.cannot_read'        => 'sugar-animation: 无法读取艺术文件：{path}',
    'ansiart.read_failed'        => 'sugar-animation: 读取艺术文件失败：{path}',
];
