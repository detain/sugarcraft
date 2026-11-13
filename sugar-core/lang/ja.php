<?php

/**
 * Japanese translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB コンポーネントが範囲外 [0,255]：{value}',
    'color.invalid_hex'           => '無効な16進カラー：{hex}',
    'color.ansi_out_of_range'     => 'ANSI インデックスが範囲外 [0,15]：{index}',
    'color.ansi256_out_of_range'  => 'ANSI256 インデックスが範囲外 [0,255]：{index}',
    'ansi.invalid_fg_code'        => '無効な16色前景色コード：{code}',
    'ansi.invalid_bg_code'        => '無効な16色背景色コード：{code}',
    'ansi.component_out_of_range' => '{label} が範囲外 [0,255]：{value}',
    'program.proc_open_failed'    => 'proc_open 失敗：{cmd}',
    'color.parse_empty' => '空の色名を解析できません',
    'color.parse_unknown' => '不明な色名: {name}',
    'ansi.invalid_scs_slot' => '無効な SCS スロット: {slot}（G0-G3 は 0-3 を想定）',
    'ansi.invalid_scs_designator' => '無効な SCS デザイネータ: {designator}（0x30-0x7E の 1 バイトを想定）',
    'ansi.invalid_sixel_repeat' => 'sixel 繰り返し回数は負の値にできません: {count}',
    'semaphore.limit_invalid' => 'セマフォの上限は 1 以上である必要があります。取得値: {limit}',
    'semaphore.limit_busy' => 'セマフォがビジーです: {held} 実行中、{waiting} 待機中',
    'semaphore.shutdown' => '許可が与えられる前にセマフォが閉じられました',
    'semaphore.closed' => 'セマフォは閉じられており、これ以上許可を与えられません',
    'semaphore.derive_closed' => '閉じられたセマフォから新しいプールを派生できません',
    'lru_map.capacity_invalid' => 'LRU 容量は 1 以上である必要があります。取得値: {capacity}',
    'lru_map.capacity_too_large' => 'LRU 容量 {capacity} が成長ガード {max} を超えています',
    'lru_map.evict_from_empty' => '空の LRU マップから退避できません',
    'errors.negative_not_allowed' => '{name} に負の値は使用できません',
    'errors.value_must_be_positive' => '{name} は正の値である必要があります。取得値: {value}',
    'errors.value_out_of_range' => '{name} は {min} と {max} の間である必要があります。取得値: {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
];
