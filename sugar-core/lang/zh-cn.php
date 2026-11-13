<?php

/**
 * Simplified Chinese translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB 分量超出范围 [0,255]：{value}',
    'color.invalid_hex'           => '无效的十六进制颜色：{hex}',
    'color.ansi_out_of_range'     => 'ANSI 索引超出范围 [0,15]：{index}',
    'color.ansi256_out_of_range'  => 'ANSI256 索引超出范围 [0,255]：{index}',
    'ansi.invalid_fg_code'        => '无效的 16 色前景色代码：{code}',
    'ansi.invalid_bg_code'        => '无效的 16 色背景色代码：{code}',
    'ansi.component_out_of_range' => '{label} 超出范围 [0,255]：{value}',
    'program.proc_open_failed'    => 'proc_open 失败，命令：{cmd}',
    'color.parse_empty' => '无法解析空的颜色名称',
    'color.parse_unknown' => '未知颜色名称：{name}',
    'ansi.invalid_scs_slot' => '无效的 SCS 槽位：{slot}（G0-G3 应为 0-3）',
    'ansi.invalid_scs_designator' => '无效的 SCS 设计符：{designator}（应为 0x30-0x7E 范围内的单个字节）',
    'ansi.invalid_sixel_repeat' => 'sixel 重复次数不得为负：{count}',
    'semaphore.limit_invalid' => '信号量上限至少为 1，实际为 {limit}',
    'semaphore.limit_busy' => '信号量繁忙：{held} 个运行中，{waiting} 个等待中',
    'semaphore.shutdown' => '信号量在授予许可前已关闭',
    'semaphore.closed' => '信号量已关闭，无法再授予许可',
    'semaphore.derive_closed' => '无法从已关闭的信号量派生新池',
    'lru_map.capacity_invalid' => 'LRU 容量至少为 1，实际为 {capacity}',
    'lru_map.capacity_too_large' => 'LRU 容量 {capacity} 超出增长上限 {max}',
    'lru_map.evict_from_empty' => '无法从空 LRU 映射中逐出',
    'errors.negative_not_allowed' => '{name} 不允许为负值',
    'errors.value_must_be_positive' => '{name} 必须为正数，实际为 {value}',
    'errors.value_out_of_range' => '{name} 必须在 {min} 和 {max} 之间，实际为 {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
    'ansi.selection_invalid' => 'clipboard selection parameter {selection} is not an OSC 52 selector (c, p, s, 0-7, comma-separated combinations)',
    'ansi.clipboard_payload_too_large' => 'clipboard payload is {bytes} bytes, over the {limit}-byte OSC 52 bound',
];
