<?php

/**
 * Japanese translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "用法：candy-ansi-inspect [ファイル]\n  または：  cmd | candy-ansi-inspect",
    'cli.not_a_file' => "candy-ansi-inspect: 読み取れません：{path}\n  (php:// や data:// などのストリームラッパーは使用できません)",
    'cli.pty_unavailable' => "candy-ansi-inspect: 疑似端末を開始できません：{reason}",
];
