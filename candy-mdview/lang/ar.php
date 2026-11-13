<?php

/**
 * Arabic translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'سمة غير معروفة: {name} — ملفات الأنماط JSON تُحمَّل عبر --theme-config',
    'render.style_overrides_theme' => 'ملاحظة: --style "{style}" يتجاوز --theme "{theme}"',
    'render.config_unreadable' => 'ملف الإعدادات غير قابل للقراءة: {path}',
    'render.config_too_large' => 'ملف الإعدادات يتجاوز {limit} بايت: {path}',
    'render.config_invalid' => 'ملف الإعدادات ليس كائن JSON صالحًا: {path} ({error})',
    'render.config_unknown_key' => 'مفاتيح غير معروفة في ملف الإعدادات {path}: {keys}',
    'render.config_bad_value' => 'قيمة غير صالحة لـ {key} في {path}: المطلوب {expected}',
    'render.theme_config_unreadable' => 'إعدادات السمة غير قابلة للقراءة: {path}',
    'render.theme_config_too_large' => 'إعدادات السمة تتجاوز {limit} بايت: {path}',
    'render.theme_config_unrecognized' => 'تكوين السمة لا يطابق المخطط المسطح ولا مخطط غلامور: {path}',
    'glamour.json_invalid' => 'إعدادات السمة ليست JSON صالحًا',
    'glamour.json_object' => 'إعدادات السمة يجب أن تكون كائن JSON',
    'glamour.decoration_unsafe' => 'حقل السمة {slot} يجب ألّا يحتوي سوى على أسطر جديدة ومحاذات وفراغات',
    'glamour.value_too_large' => 'مفتاح السمة {key} يتجاوز الحد الأقصى {limit}',
    'render.no_input_tty' => 'لا يوجد إدخال: حدد ملفًا أو أرسل Markdown إلى الإدخال القياسي أو شغّل بـ --help',
    'render.no_input_empty' => 'لا يوجد إدخال: الإدخال القياسي فارغ',
    'render.pager_needs_tty' => 'المُستعرض يحتاج إلى طرفية؛ اعرض في الإخراج القياسي بدلاً من ذلك (بدون -p)',
    'render.file_missing' => 'تعذر فتح {path}: الملف غير موجود',
    'render.file_is_directory' => 'تعذر فتح {path}: إنه دليل',
    'render.file_unreadable' => 'تعذر فتح {path}: الملف غير قابل للقراءة',
    'render.binary_input' => 'رفض عرض {path}: ليس ملفًا نصيًا',
    'render.input_too_large' => 'حجم الإدخال يتجاوز {limit} بايت: {path}',
    'render.fetch_failed' => 'يتعذر جلب {url}: {reason}',
    'render.directory_empty' => 'لا مستند Markdown في {path}: تم البحث عن README.md وREADME.markdown وindex.md وأي *.md',
    'render.directory_ambiguous' => 'لا مستند واحد في {path}: وُجد {found} — مرر أحدهما صراحةً',
    'render.directory_chosen' => 'جارٍ عرض {chosen} من وسيط الدليل {path}',
    'render.width_invalid' => 'قيمة --width غير صالحة: {value} (يُتوقع عدد صحيح بين 0 و9999؛ 0 يعطّل الالتفاف)',
];
