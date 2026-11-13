<?php

/**
 * Traditional Chinese (Taiwan) translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "用法：candy-ansi-inspect [檔案]\n  或：  cmd | candy-ansi-inspect\n  或：  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: 無法讀取：{path}",
    'cli.wrapper_note' => "  (不接受 php:// 或 data:// 等流量包裝器)",
    'cli.pty_unavailable' => "candy-ansi-inspect: 無法啟動偽終端機：{reason}",
];
