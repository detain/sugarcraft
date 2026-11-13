<?php

/**
 * Arabic translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'النقاط: {score}',
    'ui.best'                => ' الأفضل: {best}',
    'ui.new_best'            => ' 🏆 رقم قياسي جديد: {best}',
    'ui.plain_new_best'      => ' رقم قياسي جديد: {best}',
    'ui.crash_full'          => '💥 هوت! — اضغط r للمحاولة مرة أخرى، q للخروج',
    'ui.crash_short'         => '💥 هوت — r إعادة · q خروج',
    'ui.crash_floor'         => 'r إعادة · q خروج',
    'ui.ready_full'          => 'اضغط مسافة/⏎/↑/w للبدء · r إعادة · q خروج',
    'ui.ready_short'         => 'المسافة للبدء · q خروج',
    'ui.live_full'           => 'مسافة/⏎/↑/w رفرفة · r إعادة · q خروج',
    'ui.live_short'          => 'المسافة ترفرف · q خروج',
    'ui.plain_crash_full'    => 'x هوت — اضغط r للمحاولة مرة أخرى، q للخروج',
    'ui.plain_crash_short'   => 'x هوت - r إعادة - q خروج',
    'ui.plain_crash_floor'   => 'r إعادة - q خروج',
    'ui.plain_ready_full'    => 'اضغط مسافة/Enter/^/w للبدء . r إعادة . q خروج',
    'ui.plain_ready_short'   => 'المسافة للبدء . q خروج',
    'ui.plain_live_full'     => 'مسافة/Enter/^/w رفرفة . r إعادة . q خروج',
    'ui.plain_live_short'    => 'المسافة ترفرف . q خروج',
    'ui.quit_hint' => 'اضغط على q للخروج.',
    'ui.quarantine'           => '⚠ تعذرت قراءة لوحة الصدارة السابقة — حُفظت نسخة أرشيفية باسم {file}',
    'ui.plain_quarantine'     => 'x تعذرت قراءة لوحة الصدارة السابقة - حُفظت نسخة أرشيفية باسم {file}',
    'notty.line1'            => 'يحتاج candy-flappy إلى طرفية قادرة على عرض مخرجاته.',
    'notty.line2'            => 'stdout ليست طرفية تفاعلية، أو تم اكتشاف TERM=dumb.',
    'size.notice'            => 'يحتاج candy-flappy إلى طرفية بمقاس {need}؛ هذه بمقاس {have}.',
    'size.resume'            => 'غيّر حجم النافذة وستستمر اللعبة من هنا تمامًا.',
];
