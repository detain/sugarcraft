<?php

/**
 * Arabic translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'سمة غير معروفة: {name}',
    'render.theme_config_unreadable' => 'إعدادات السمة غير قابلة للقراءة: {path}',
    'render.theme_config_too_large' => 'إعدادات السمة تتجاوز {limit} بايت: {path}',
    'glamour.json_invalid' => 'إعدادات السمة ليست JSON صالحًا',
    'glamour.json_object' => 'إعدادات السمة يجب أن تكون كائن JSON',
    'render.no_input_tty' => 'لا يوجد إدخال: حدد ملفًا أو أرسل Markdown إلى الإدخال القياسي',
    'render.no_input_empty' => 'لا يوجد إدخال: الإدخال القياسي فارغ',
    'render.file_missing' => 'تعذر فتح {path}: الملف غير موجود',
    'render.file_is_directory' => 'تعذر فتح {path}: إنه دليل',
    'render.file_unreadable' => 'تعذر فتح {path}: الملف غير قابل للقراءة',
    'render.binary_input' => 'رفض عرض {path}: ليس ملفًا نصيًا',
    'render.input_too_large' => 'حجم الإدخال يتجاوز {limit} بايت: {path}',
    'render.width_invalid' => 'قيمة --width غير صالحة: {value} (يُتوقع عدد صحيح بين 0 و9999؛ 0 يعطّل الالتفاف)',
];
