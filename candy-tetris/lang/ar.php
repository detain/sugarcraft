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
    'score_card' => 'النقاط: %d
الأسطر: %d
المستوى: %d',
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
    'paused'     => '[ متوقف مؤقتًا — اضغط p للمتابعة ]',

    // Game-over banner
    'over.title'                => 'انتهت اللعبة',
    'over.final_score'          => 'النتيجة النهائية: {points}',
    'over.restart_hint'         => 'اضغط r لإعادة التشغيل — اضغط q للخروج',
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

    // Versus renderer
    'label.player'   => 'اللاعب',
    'label.computer' => 'الحاسوب',
    'win.player'     => 'لقد فزت!',
    'win.computer'   => 'فاز الحاسوب!',
    'over.quit_hint' => 'اضغط q للخروج',
    'vs_keys'        => 'إنزال
تخزين
إيقاف
إعادة
خروج',
    'vs.record' => 'السجل: {wins} فوز، {losses} خسارة',
    'intro.title' => 'مرحبًا بك في candy-tetris',
    'intro.goal' => 'امسح الأسطر - لا تصل إلى القمة',
    'intro.press' => 'اضغط {keys} للعب',
    'intro.vs' => 'وضع المبارزة: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: وضع غير معروف "{flag}"',
    'help_text'    => 'candy-tetris - نسخة من تتريس

الاستخدام: candy-tetris [خيارات]
  (بدون)     بدء لعبة فردية
  -v, --vs   وضع المنافسة مع الحاسوب
  -h, --help عرض هذه المساعدة
  --version  طباعة الإصدار
'
];
