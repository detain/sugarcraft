<?php

/**
 * Arabic translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'لون فارغ',
    'style.unrecognised_color' => 'لون غير معروف: {value}',
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
    'format.unknown_type'     => '--type غير معروف: {type}',
    'format.unknown_theme'    => 'سمة غير معروفة: {name}',
    'io.read_failed' => 'تعذرت قراءة الملف: {path}',
    'io.write_failed' => 'تعذرت الكتابة إلى الملف: {path}',
    'log.unknown_formatter' => 'أداة تنسيق --formatter غير معروفة: {name}',
    'completion.unknown_shell' => 'صدفة غير مدعومة: {shell}',
    'confirm.unknown_default' => 'قيمة --default غير معروفة: {value}',
    'cursor_mode.unknown' => 'وضع مؤشر غير معروف: {mode}',
];
