<?php

/**
 * Arabic translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'تمت كتابة الإدخال ولكنه لم يعد قابلاً للقراءة (منتهي أو محذوف أو تالف)',
    'store.cannot_read'         => 'تعذر قراءة الملف: {path}',
    'cli.usage_set'             => 'الاستخدام: {bin} set <مفتاح> [قيمة] [--ttl SECONDS]',
    'cli.usage_get'             => 'الاستخدام: {bin} get <مفتاح>',
    'cli.usage_delete'          => 'الاستخدام: {bin} delete <مفتاح>',
    'cli.usage_import'           => 'الاستخدام: {bin} import <json|yaml|yml> <مسار>',
    'cli.usage_export'            => 'الاستخدام: {bin} export <json|yaml|yml> [قاعدة بيانات] [نمط]',
    'cli.deleted_n'             => 'تم حذف {count} إدخالات.',
    'cli.unknown_command'       => 'أمر غير معروف: {cmd}',
    'cli.deleted_none'           => 'لم يتم حذف أي عنصر: لا توجد إدخالات تطابق "{key}".',
    'cli.pattern_not_supported'  => 'get يتطلب مفتاحًا دقيقًا وليس نمطًا. استخدم "{bin} list <pattern>" لمطابقة أنماط glob.',
    'store.did_you_mean'         => 'المفتاح غير موجود؛ هل قصدت \'{key}\'؟',
    'database.query_failed'      => 'فشل استعلام قاعدة البيانات.',
];
