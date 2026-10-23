<?php

/**
 * Chinese (Simplified) translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => '无法读取主题文件：{path}',
    'theme.json_invalid'           => '主题文件中的 JSON 无效',
    'theme.json_object'            => '主题 JSON 必须解码为对象',
    'theme.bad_color'              => '无效的颜色指定: {spec}',
    'renderer.unknown_style'       => '未知标准样式：{name}',
    'renderer.stream_invalid'      => 'write() 需要流资源',
    'renderer.stream_not_writable' => 'write() 需要可写的流；获得的模式为 "{mode}"',
    'renderer.write_failed'        => '向输出流写入 {bytes} 字节失败',
    'renderer.chunk_invalid'       => 'stream() 的块必须是字符串',
    'sink.closed'                  => '接收器已关闭',
    'sink.parent_missing'          => '输出目录不存在: {dir}',
    'sink.path_unwritable'         => '输出路径不可写: {path}',
    'sink.open_failed'             => '打开输出路径失败: {path}',
    'sink.flush_failed'            => '刷新输出流失败',
    'writer.feed_after_close'      => '在 close() 之后调用了 feed() — 写入器已完成',
];
