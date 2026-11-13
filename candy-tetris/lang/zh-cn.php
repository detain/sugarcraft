<?php

/**
 * Chinese (Simplified) UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => '分数: {score}
行数: {lines}
等级: {level}',
    'help_card'  => '移动
顺时针旋转
逆时针旋转
软降
硬降
暂存
暂停
重开(终局后)
退出',
    'next_label' => '下一个:',
    'hold_label' => '暂存:',
    'paused'     => '[ 已暂停 — 按 {keys} 继续 ]',

    // Game-over banner
    'over.title'                => '游戏结束',
    'over.final_score'          => '最终分数: {points}',
    'over.restart_hint'         => '按 {restart} 重新开始 — 按 {quit} 退出',
    'over.reason'               => '原因: {reason}',
    'over.reason.spawn_blocked' => '没有空间放下下一块',
    'over.reason.top_out'       => '堆叠到达顶部',
    'over.reason.crushed'       => '被垃圾行压碎',

    // On-frame cues
    'hold_used'  => '(已用)',
    'combo_line' => '连击 x%d',
    'b2b_line'   => 'B2B 就绪',
    'over.best'    => '最佳: {points}',
    'over.new_best' => '新纪录！',
    'over.games' => '已完成游戏: {games}',

    // Versus renderer
    'label.player'   => '玩家',
    'label.computer' => '电脑',
    'win.player'     => '你赢了!',
    'win.computer'   => '电脑赢了!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => '消一行就向对手发送一行垃圾（每行一个洞）',
    'vs.record' => '战绩: {wins} 胜, {losses} 负',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => '你',
    'vs.strip.computer' => '电脑',
    'vs.strip.score' => '分数:',
    'vs.strip.lines' => '行数:',
    'vs.strip.level' => '等级:',
    'intro.title' => '欢迎游玩 candy-tetris',
    'intro.goal' => '消除行 — 别堆到顶部',
    'intro.press' => '按 {keys} 开始',
    'intro.vs' => '对战模式: candy-tetris -v',
    'key_or' => ' 或 ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 未知模式 "{flag}"',
    'help_text'    => 'candy-tetris - 俄罗斯方块

用法: candy-tetris [选项]
  (无参数)   开始单人游戏
  -v, --vs   电脑对战模式
  -h, --help 显示此帮助
  --version  显示版本
',
    'cli.no_tty' => 'candy-tetris 需要交互式终端：请在 TTY 中运行（不要通过管道或重定向），并将 TERM 设为 dumb 以外的值。',
    'launch.extras' => 'candy-tetris: 只接受一个参数，多出 {count} 个：{list}',
    'score.save.failed' => 'candy-tetris: 无法保存成绩 ({problem})',
    'keys.ignored' => 'candy-tetris: 忽略无法读取的按键文件 ({problem})',
    'scores.ignored' => 'candy-tetris: 忽略无法读取的成绩文件 ({problem})',
    'config.problem' => '{path} 中：{detail}',
    'cli.refusal' => 'candy-tetris 需要交互式终端 (TTY)。
无色也能玩：设置 NO_COLOR。',
    'cli.too_small' => 'candy-tetris 需要 {size} 的终端；当前为 {actual}。
调整窗口大小，游戏就在这里继续。',
    'cli.too_small_vs' => 'candy-tetris VS 需要 {size} 的终端；当前为 {actual}。
调整窗口大小，对战就在这里继续。',
];
