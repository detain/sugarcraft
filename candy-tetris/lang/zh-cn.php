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
    'score_card' => '分数: %d
行数: %d
等级: %d',
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
    'paused'     => '[ 已暂停 — 按 p 继续 ]',

    // Game-over banner
    'over.title'                => '游戏结束',
    'over.final_score'          => '最终分数: {points}',
    'over.restart_hint'         => '按 r 重新开始 — 按 q 退出',
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

    // Versus renderer
    'label.player'   => '玩家',
    'label.computer' => '电脑',
    'win.player'     => '你赢了!',
    'win.computer'   => '电脑赢了!',
    'over.quit_hint' => '按 q 退出',
    'vs_keys'        => '硬降
暂存
暂停
重开
退出',
    'vs.record' => '战绩: {wins} 胜, {losses} 负',
    'intro.title' => '欢迎游玩 candy-tetris',
    'intro.goal' => '消除行 - 别堆到顶部',
    'intro.press' => '按 {keys} 开始',
    'intro.vs' => '对战模式: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: 未知模式 "{flag}"',
    'help_text'    => 'candy-tetris - 俄罗斯方块

用法: candy-tetris [选项]
  (无参数)   开始单人游戏
  -v, --vs   电脑对战模式
  -h, --help 显示此帮助
  --version  显示版本
'
];
