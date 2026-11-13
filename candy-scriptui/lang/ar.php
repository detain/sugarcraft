<?php

/**
 * Arabic translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'لون فارغ',
    'style.unrecognised_color' => 'لون غير معروف: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "يجب أن يحتوي اللون السداسي على 3 أو 6 أرقام سداسية عشرية (مثال: '#f80' أو '#ff8800')؛ تم الحصول على '{value}'",
    'style.padding_token_int' => "رمز padding/margin يجب أن يكون صحيحًا; تم الحصول على: '{token}'",
    'style.padding_count'     => 'padding/margin يتطلب 1 أو 2 أو 4 أعداد صحيحة; تم الحصول على: {count}',
    'style.bad_entry'         => "--style يجب أن تكون الإدخالات 'key=value' أو 'element.prop=value'; تم الحصول على: '{raw}'",
    'style.unknown_prop'      => "خاصية نمط غير معروفة: '{prop}'",
    'process.spawn_failed'    => 'فشل بدء العملية الفرعية',
    'border.unknown'          => 'نمط حدود غير معروف: {name}',
    'log.unknown_level'       => 'مستوى سجل غير معروف: {name}',
    'log.encode_failed' => 'فشلت ترميز سجل الإدخال إلى json',
    'log.bad_format' => 'سلسلة --format غير صالحة: {format}',
    'spinner.unknown_style'   => 'نمط spinner غير معروف: {name}',
    'spinner.windows_unsupported' => 'لا يمكن لـ spin تشغيل الأوامر على Windows الأصلي؛ واجهة العمليات غير مدعومة هناك — استخدم WSL بدلاً من ذلك.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'سمة غير معروفة: {name}',
    'io.read_failed' => 'تعذرت قراءة الملف: {path}',
    'io.write_failed' => 'تعذرت الكتابة إلى الملف: {path}',
    'log.unknown_formatter' => 'أداة تنسيق --formatter غير معروفة: {name}',
    'completion.unknown_shell' => 'صدفة غير مدعومة: {shell}',
    'confirm.unknown_default' => 'قيمة --default غير معروفة: {value}',
    'cursor_mode.unknown' => 'وضع مؤشر غير معروف: {mode}',
    'runtime.no_terminal' => '{command} يحتاج إلى طرفية تفاعلية؛ لا يوجد طرف تحكّم متاح.',
    'runtime.bad_timeout' => 'قيمة --timeout غير صالحة \'{value}\' — يُتوقَّع عدد ثوانٍ غير سالب (0 يعطّل المهلة).',
    'runtime.bad_number' => 'قيمة {flag} غير صالحة: \'{value}\' — مطلوب عدد صحيح غير سالب.',
    'runtime.unknown_choice' => 'قيمة غير صالحة لـ{option}: \'{value}\'. المسموح: {allowed}.',
    'hints.choose' => '↑/↓ تحرّك · مسافة تبديل (متعدد) · enter اختيار · esc إلغاء',
    'hints.filter' => 'اكتب للتصفية · ↑/↓ تحرّك · tab تبديل (متعدد) · enter اختيار · esc إلغاء',
    'hints.pager' => 'space/PgDn نزول · PgUp/b صعود · g بداية · G نهاية · q خروج',
    'format.template_unresolved' => 'القالب: {count} عنصر نائب توسّع إلى فراغ: {names} — يجب ضبط المتغير والسماح به عبر --allow-env.',
    'runtime.empty_input' => 'المدخل لا يحتوي على أسطر؛ لا شيء للقيام به.',
    'runtime.conflicting_flags' => 'لا يمكن دمج الخيارين {first} و{second} بقيم مختلفة.',
];
