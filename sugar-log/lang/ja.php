<?php

/**
 * Japanese translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => '致命的ログ：{message}',
    'logger.invalid_stream' => 'ストリームは有効なリソースである必要があります',
    'psrbridge.not_string' => 'ログレベルは PSR-3 LogLevel 定数のいずれかでなければなりません。取得値: {got}',
    'psrbridge.unknown_level' => '不明なログレベル \'{level}\'。PSR-3 LogLevel 定数のいずれかを期待しています',
    'parts.unknown' => '不明なログ行の項目: {parts}',
    'parts.duplicate' => '重複したログ行の項目: {parts}',
    'log.fatal_error' => '致命的エラー',
    'log.fatal_parse' => '構文解析エラー',
    'log.fatal_core' => 'コアエラー',
    'log.fatal_compile' => 'コンパイルエラー',
    'log.fatal_user' => 'ユーザエラー',
    'log.fatal_shutdown' => 'シャットダウンエラー',
];
