<?php

/**
 * Arabic translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'لون فارغ.',
    'style.unrecognised_color' => 'لون غير معروف: {value} — المسموح: {allowed}.',
    'style.hex_digit_count' => "يجب أن يحتوي اللون السداسي على 3 أو 6 أرقام سداسية عشرية (مثال: '#f80' أو '#ff8800')؛ تم الحصول على '{value}'.",
    'style.padding_token_int' => "رمز padding/margin يجب أن يكون صحيحًا; تم الحصول على: '{token}'.",
    'style.padding_count'     => 'Padding/margin يتطلب 1 أو 2 أو 4 أعداد صحيحة; تم الحصول على: {count}.',
    'style.bad_entry'         => "--style يجب أن تكون الإدخالات 'key=value' أو 'element.prop=value'; تم الحصول على: '{raw}'.",
    'style.unknown_prop'      => "خاصية نمط غير معروفة: '{prop}'.",
    'process.spawn_failed'    => 'فشل بدء العملية الفرعية',
    'border.unknown'          => 'نمط حدود غير معروف: {name}.',
    'log.unknown_level'       => 'مستوى سجل غير معروف: {name}.',
    'log.encode_failed' => 'فشلت ترميز سجل الإدخال إلى json.',
    'log.bad_format' => 'سلسلة --format غير صالحة: {format}.',
    'spinner.unknown_style'   => 'نمط spinner غير معروف: {name}.',
    'spinner.windows_unsupported' => 'لا يمكن لـ spin تشغيل الأوامر على Windows الأصلي؛ واجهة العمليات غير مدعومة هناك — استخدم WSL بدلاً من ذلك.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'سمة غير معروفة: {name}.',
    'io.read_failed' => 'تعذرت قراءة الملف: {path}.',
    'io.write_failed' => 'تعذرت الكتابة إلى الملف: {path}.',
    'runtime.no_terminal' => '{command} يحتاج إلى طرفية تفاعلية؛ لا يوجد طرف تحكّم متاح.',
    'runtime.bad_timeout' => "قيمة --timeout غير صالحة '{value}' — يُتوقَّع عدد ثوانٍ غير سالب أو مدة مثل 5s/250ms/1m30s (0 يعطّل المهلة).",
    'runtime.bad_number' => "قيمة {flag} غير صالحة: '{value}' — مطلوب عدد صحيح غير سالب.",
    'runtime.pcntl_missing' => 'ext-pcntl غير محمّل: SIGINT/SIGTERM سيقتل هذا البرنامج دون تعيين رموز الخروج 130/143.',
    'runtime.unknown_choice' => "قيمة غير صالحة للخيار {option}: '{value}'. المسموح: {allowed}.",
    'hints.choose' => '↑/↓ تحريك · / تصفية · Enter تحديد · Esc إلغاء',
    'hints.choose.multi' => '↑/↓ تحريك · مسافة تبديل · / تصفية · Enter تحديد · Esc إلغاء',
    'hints.filter' => 'اكتب للتصفية · ↑/↓ تحرّك · tab تبديل (متعدد) · enter اختيار · esc إلغاء',
    'hints.pager' => '↑/↓ j/k تحريك · ←/→ h/l تمرير · PgUp/b PgDn/مسافة صفحة · Ctrl+U/D نصف',
    'hints.pager.more' => 'g/G أو Home/End بداية/نهاية · q/Esc خروج',
    'format.template_unresolved' => 'القالب: {count} عنصر نائب توسّع إلى فراغ: {names} — يجب ضبط المتغير والسماح به عبر --allow-env.',
    'runtime.empty_input' => 'المدخل لا يحتوي على أسطر؛ لا شيء للقيام به.',
    'runtime.empty_input_filter' => 'لا شيء للتصفية؛ لم يُنتج الدخل أي أسطر.',
    'runtime.empty_input_pager' => 'لا شيء للتصفح؛ لم يُنتج الدخل أي أسطر.',
    'runtime.empty_items' => 'لم يتم توفير عناصر؛ مررها كوسائط أو عبر --item.',
    'runtime.conflicting_flags' => 'لا يمكن دمج الخيارين {first} و{second} بقيم مختلفة.',
    'runtime.empty_selection' => 'لم يتم تحديد أي عنصر — اضغط مسافة لتحديد عنصر واحد على الأقل قبل الإدخال.',
    'runtime.legacy_env'        => '{var} أصبح قديمًا؛ استخدم {replacement} بدلاً منه.',
];
