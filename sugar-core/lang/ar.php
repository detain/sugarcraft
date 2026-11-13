<?php

/**
 * Arabic translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'مكون RGB خارج النطاق [0,255]: {value}',
    'color.invalid_hex'           => 'لون سداسي غير صالح: {hex}',
    'color.ansi_out_of_range'     => 'فهرس ANSI خارج النطاق [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'فهرس ANSI256 خارج النطاق [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'رمز اللون الأمامي 16 غير صالح: {code}',
    'ansi.invalid_bg_code'        => 'رمز اللون الخلفي 16 غير صالح: {code}',
    'ansi.component_out_of_range' => '{label} خارج النطاق [0,255]: {value}',
    'program.proc_open_failed'    => 'فشل proc_open لـ: {cmd}',
    'color.parse_empty' => 'لا يمكن تحليل اسم لون فارغ',
    'color.parse_unknown' => 'اسم لون غير معروف: {name}',
    'ansi.invalid_scs_slot' => 'فتحة SCS غير صالحة: {slot} (المتوقع 0-3 لـ G0-G3)',
    'ansi.invalid_scs_designator' => 'محدد SCS غير صالح: {designator} (مطلوب بايت واحد في 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'عدد تكرار sixel يجب ألا يكون سالبًا: {count}',
    'semaphore.limit_invalid' => 'حد السيمافور يجب أن يكون 1 على الأقل، تم تلقي {limit}',
    'semaphore.limit_busy' => 'السيمافور مشغول: {held} قيد التشغيل، {waiting} في الانتظار',
    'semaphore.shutdown' => 'تم إغلاق السيمافور قبل منح التصريح',
    'semaphore.closed' => 'السيمافور مغلق ولا يمكنه منح تصريح آخر',
    'semaphore.derive_closed' => 'لا يمكن اشتقاق مجموعة جديدة من سيمافور مغلق',
    'lru_map.capacity_invalid' => 'سعة LRU يجب أن تكون 1 على الأقل، تم تلقي {capacity}',
    'lru_map.capacity_too_large' => 'سعة LRU {capacity} تتجاوز حاجز النمو {max}',
    'lru_map.evict_from_empty' => 'لا يمكن الإخراج من خريطة LRU فارغة',
    'errors.negative_not_allowed' => 'القيمة السالبة غير مسموح بها لـ {name}',
    'errors.value_must_be_positive' => 'يجب أن تكون {name} موجبة، تم تلقي {value}',
    'errors.value_out_of_range' => 'يجب أن تكون {name} بين {min} و {max}، تم تلقي {value}',

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
