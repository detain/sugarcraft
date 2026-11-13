<?php

/**
 * Simplified Chinese translations for candy-flappy. Keys mirror en.php
 * exactly; {placeholder} tokens, key names and separators are contract
 * (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => '得分: {score}',
    'ui.best'                => ' 最佳: {best}',
    'ui.new_best'            => ' 🏆 新纪录: {best}',
    'ui.plain_new_best'      => ' 新纪录: {best}',
    'ui.crash_full'          => '💥 撞上了 — 按 r 再试一次，按 q 退出',
    'ui.crash_short'         => '💥 撞了 — r 重试 · q 退出',
    'ui.ready_full'          => '空格/⏎/↑/w 开始 · r 重新开始 · q 退出',
    'ui.ready_short'         => '空格开始 · q 退出',
    'ui.live_full'           => '空格/⏎/↑/w 拍翅 · r 重新开始 · q 退出',
    'ui.live_short'          => '空格拍翅 · q 退出',
    'ui.plain_crash_full'    => 'x 撞上了 - 按 r 再试一次，按 q 退出',
    'ui.plain_crash_short'   => 'x 撞了 - r 重试 - q 退出',
    'ui.plain_ready_full'    => '空格/Enter/^/w 开始 . r 重新开始 . q 退出',
    'ui.plain_ready_short'   => '空格开始 . q 退出',
    'ui.plain_live_full'     => '空格/Enter/^/w 拍翅 . r 重新开始 . q 退出',
    'ui.plain_live_short'    => '空格拍翅 . q 退出',
    'ui.quit_hint' => '按 q 退出。',
    'notty.line1'            => 'candy-flappy 需要交互式终端。',
    'notty.line2'            => '未检测到可用的颜色能力（stdout 非 TTY 或 TERM=dumb）。',
    'size.notice'            => 'candy-flappy 需要 {need} 的终端；当前是 {have}。',
    'size.resume'            => '调整窗口大小，游戏就会在此继续。',
];
