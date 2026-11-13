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
    'ui.best'                => ' رقم قياسي: {best}',
    'ui.new_best'            => ' 🏆 رقم قياسي جديد: {best}',
    'ui.plain_new_best'      => ' رقم قياسي جديد: {best}',
    'ui.crash_full'          => '💥 {why} — اضغط r لإعادة البدء · q للخروج',
    'ui.crash_short'         => '💥 {why} — r إعادة · q خروج',
    'ui.crash_floor'         => 'r إعادة · q خروج',
    'ui.ready_full'          => 'اضغط مسافة/⏎/↑/w للبدء · q خروج',
    'ui.ready_short'         => 'المسافة للبدء · q خروج',
    'ui.live_full'           => 'مسافة/⏎/↑/w رفرفة · r إعادة · q خروج',
    'ui.live_short'          => 'المسافة ترفرف · q خروج',
    'ui.plain_crash_full'    => 'x {why} - اضغط r لإعادة البدء - q للخروج',
    'ui.plain_crash_short'   => 'x {why} - r إعادة - q خروج',
    'ui.plain_crash_floor'   => 'r إعادة - q خروج',
    'ui.why_splat'            => 'هوت!',
    'ui.why_ceiling'          => 'ارتطمت بالسقف',
    'ui.why_ground'           => 'ارتطمت بالأرض',
    'ui.why_pipe'             => 'ارتطمت بأنبوب',
    'ui.plain_why_splat'      => 'هوت!',
    'ui.plain_why_ceiling'    => 'ارتطمت بالسقف',
    'ui.plain_why_ground'     => 'ارتطمت بالأرض',
    'ui.plain_why_pipe'       => 'ارتطمت بأنبوب',
    'ui.plain_ready_full'    => 'اضغط مسافة/Enter/up/w للبدء . q خروج',
    'ui.plain_ready_short'   => 'المسافة للبدء . q خروج',
    'ui.plain_live_full'     => 'مسافة/Enter/up/w رفرفة . r إعادة . q خروج',
    'ui.plain_live_short'    => 'المسافة ترفرف . q خروج',
    'ui.quit_hint' => 'اضغط على q للخروج.',
    'ui.quarantine'           => '⚠ تعذرت قراءة لوحة الصدارة السابقة — حُفظت نسخة أرشيفية باسم {file}',
    'ui.plain_quarantine'     => 'x تعذرت قراءة لوحة الصدارة السابقة - حُفظت نسخة أرشيفية باسم {file}',
    'notty.line1'            => 'يحتاج candy-flappy إلى طرفية قادرة على عرض مخرجاته.',
    'notty.line2'            => 'stdout ليست طرفية تفاعلية، أو تم اكتشاف TERM=dumb.',
    'size.notice'            => 'يحتاج candy-flappy إلى طرفية بمقاس {need}؛ هذه بمقاس {have}.',
    'size.resume'            => 'غيّر حجم النافذة وستستمر اللعبة من هنا تمامًا.',
];
