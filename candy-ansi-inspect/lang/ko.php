<?php

/**
 * Korean translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "사용법: candy-ansi-inspect [파일]\n  또는:  cmd | candy-ansi-inspect\n  또는:  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: 읽을 수 없습니다: {path}",
    'cli.wrapper_note' => "  (php:// 또는 data:// 같은 스트림 래퍼는 허용되지 않습니다)",
    'cli.pty_unavailable' => "candy-ansi-inspect: 가상 터미널을 시작할 수 없습니다: {reason}",
];
