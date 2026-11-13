<?php

/**
 * Arabic translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'الاستخدام: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: تعذر العثور على composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: خيار غير معروف --{flag}',
    'cli.bad_value'   => 'candy-gifview: قيمة غير صالحة \'{value}\' الخاص بـ {flag} (المطلوب رقم موجب)',
    'cli.too_many'    => 'candy-gifview: وسيط غير متوقع \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: لا يقبل --{flag} أي قيمة',
    'cli.bad_preset' => 'candy-gifview: إعداد غير معروف \'{preset}\' (المطلوب solid أو density)',
    'cli.no_frames' => 'candy-gifview: لا توجد لقطات للتشغيل في \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: تعذّر تشغيل \'{path}\': {reason}',
    'cli.reason_frames_over' => 'الرسوم المتحركة تتجاوز حد {max} لقطة؛ يتم رفض التحميل',
    'cli.reason_bytes_over' => 'الملف أكبر من حد {max} MiB للتحميل',
    'cli.reason_cells_over' => 'تحتاج الرسوم المتحركة إلى أكثر من ميزانية {max} خانة لهذه النافذة',
    'cli.duration_static' => 'candy-gifview: --duration تتطلب لقطتين على الأقل؛ يُفكّ هذا الملف إلى {count} لقطة',
    'cli.need_gd' => 'candy-gifview: امتداد gd مطلوب لفك ترميز ملفات GIF (ثبّت php-gd)',
    'cli.decoding' => 'candy-gifview: جارٍ فك ترميز \'{path}\'...',
    'cli.truncated' => 'candy-gifview: عرض أول {max} لقطة — لا يقرأ هذا العارض رسومًا متحركة أطول',
];
