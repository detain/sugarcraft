<?php

/**
 * Arabic UI strings for candy-tetris.
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
    'score_card' => 'النقاط: {score}
الأسطر: {lines}
المستوى: {level}',
    'help_card'  => 'تحريك
تدوير مع العقارب
تدوير عكس العقارب
إنزال بطيء
إنزال فوري
تخزين
إيقاف مؤقت
إعادة (نهاية)
خروج',
    'next_label' => 'التالي:',
    'hold_label' => 'التخزين:',
    'paused'     => '[ متوقف مؤقتًا — اضغط {keys} للمتابعة ]',

    // Game-over banner
    'over.title'                => 'انتهت اللعبة',
    'over.final_score'          => 'النتيجة النهائية: {points}',
    'over.restart_hint'         => 'اضغط {restart} لإعادة التشغيل — اضغط {quit} للخروج',
    'over.reason'               => 'السبب: {reason}',
    'over.reason.spawn_blocked' => 'لا توجد مساحة للقطعة التالية',
    'over.reason.top_out'       => 'وصل الركام إلى السقف',
    'over.reason.crushed'       => 'سحِقته الأسطر الزائدة',

    // On-frame cues
    'hold_used'  => '(مستخدم)',
    'combo_line' => 'تتابع x%d',
    'b2b_line'   => 'B2B جاهز',
    'over.best'    => 'أفضل: {points}',
    'over.new_best' => 'رقم قياسي جديد!',
    'over.games' => 'ألعاب مكتملة: {games}',

    // Versus renderer
    'label.player'   => 'اللاعب',
    'label.computer' => 'الحاسوب',
    'win.player'     => 'لقد فزت!',
    'win.computer'   => 'فاز الحاسوب!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'مسح الخطوط يرسل صفوف قمامة للخصم — ثقب واحد في كل صف',
    'vs.record' => 'السجل: {wins} فوز، {losses} خسارة',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'أنت',
    'vs.strip.computer' => 'الحاسوب',
    'vs.strip.score' => 'نقاط:',
    'vs.strip.lines' => 'أسطر:',
    'vs.strip.level' => 'المستوى:',
    'intro.title' => 'مرحبًا بك في candy-tetris',
    'intro.goal' => 'امسح الأسطر — لا تصل إلى القمة',
    'intro.press' => 'اضغط {keys} للعب',
    // candy-tetris-006/-095 (ar): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'اضغط {keys} في أي وقت لعرض المفاتيح',
    'intro.vs' => 'وضع المبارزة: candy-tetris -v',
    'key_or' => ' أو ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: وضع غير معروف "{flag}"',
    'help_text'    => 'candy-tetris - نسخة من تتريس

الاستخدام: candy-tetris [خيارات]
  (بدون)     بدء لعبة فردية
  -v, --vs   وضع المنافسة مع الحاسوب
  -h, --help عرض هذه المساعدة
  --version  طباعة الإصدار
',
    // candy-tetris-006/-095 (ar): --help keys section header.
    'help.keys' => 'المفاتيح:',
    // candy-tetris-006/-095 (ar): the press that closes the key reference.
    'help.close' => 'اضغط {keys} مرة أخرى للإغلاق',
    // candy-tetris-006/-095 (ar): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'يحتاج الإطار إلى طرفية {cols}x{rows} على الأقل',
    // candy-tetris-006/-095 (ar): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'المفاتيح: {keys}',
    'cli.no_tty' => 'يحتاج candy-tetris إلى طرفية تفاعلية: شغّله من TTY (وليس من أنبوب أو إعادة توجيه) مع ضبط TERM على قيمة غير dumb.',
    'launch.extras' => 'candy-tetris: يُقبل وسيط واحد فقط، لكن هناك {count} زائد: {list}',
    'score.save.failed' => 'candy-tetris: تعذر حفظ النتائج ({problem})',
    'keys.ignored' => 'candy-tetris: تجاهل ملف المفاتيح غير المقروء ({problem})',
    'scores.ignored' => 'candy-tetris: تجاهل ملف النتائج غير المقروء ({problem})',
    'config.problem' => '{detail} في {path}',
    'cli.refusal' => 'candy-tetris يحتاج طرفية تفاعلية (TTY).
يعمل بلا ألوان: اضبط NO_COLOR.',
    'cli.too_small' => 'candy-tetris يتطلب طرفية بمقاس {size}؛ الحالية {actual}.
غيّر حجم النافذة وستستأنف اللعبة من هنا.',
    'cli.too_small_vs' => 'candy-tetris VS يتطلب طرفية بمقاس {size}؛ الحالية {actual}.
غيّر حجم النافذة وستستأنف المواجهة من هنا.',
];
