<?php

/**
 * Simplified Chinese translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => '致命日志：{message}',
    'logger.invalid_stream' => '流必须是有效的资源',
    'psrbridge.not_string' => '日志级别必须是 PSR-3 LogLevel 常量之一；收到 {got}',
    'psrbridge.unknown_level' => '未知日志级别 \'{level}\'；期望 PSR-3 LogLevel 常量之一',
    'parts.unknown' => '未知的日志行部件：{parts}',
    'parts.duplicate' => '重复的日志行部件：{parts}',
    'log.fatal_error' => '致命错误',
    'log.fatal_parse' => '解析错误',
    'log.fatal_core' => '核心错误',
    'log.fatal_compile' => '编译错误',
    'log.fatal_user' => '用户错误',
    'log.fatal_shutdown' => '关闭错误',
    'logger.unopenable_stream' => '无法打开日志文件 \'{path}\'',
];
