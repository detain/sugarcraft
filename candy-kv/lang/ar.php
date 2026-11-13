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
    'cli.usage_set'             => 'الاستخدام: {bin} set <مفتاح> [قيمة] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'الاستخدام: {bin} get <مفتاح> [--sanitize]',
    'cli.usage_delete'          => 'الاستخدام: {bin} delete <مفتاح>',
    'cli.usage_import'           => 'الاستخدام: {bin} import <json|yaml|yml> <مسار> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'الاستخدام: {bin} export <json|yaml|yml> [قاعدة بيانات] [نمط]',
    'cli.deleted_n'             => 'تم حذف {count} إدخالات.',
    'cli.unknown_command'       => 'أمر غير معروف: {cmd}',
    'cli.deleted_none'           => 'لم يتم حذف أي عنصر: لا توجد إدخالات تطابق "{key}".',
    'cli.pattern_not_supported'  => 'get يتطلب مفتاحًا دقيقًا وليس نمطًا. استخدم "{bin} list <pattern>" لمطابقة أنماط glob.',
    'cli.db_deleted'             => 'تم حذف قاعدة البيانات "{name}".',
    'cli.db_not_found'           => 'قاعدة البيانات غير موجودة: {db}',
    'cli.db_did_you_mean'        => 'هل قصدتم قاعدة البيانات \'{db}\'؟',
    'store.did_you_mean'         => 'المفتاح غير موجود؛ هل قصدت \'{key}\'؟',
    'cli.key_not_found'          => 'المفتاح غير موجود: {key}',
    'cli.deleted_one'            => 'تم حذف مدخل واحد.',
    'cli.imported_one'           => 'تم استيراد مدخل واحد.',
    'cli.imported_n'             => 'تم استيراد {count} مدخلًا.',
    'cli.overwrote_one'          => 'تم استبدال مدخل واحد.',
    'cli.overwrote_n'            => 'تم استبدال {count} مدخلًا.',
    'cli.would_delete_one'       => 'سيتم حذف مدخل واحد.',
    'cli.would_delete_n'         => 'سيتم حذف {count} مدخلًا.',
    'cli.pruned_n'               => 'تمت إزالة {count} مدخلًا منتهي الصلاحية.',
    'cli.pruned_one'             => 'تمت إزالة مدخل واحد منتهي الصلاحية.',
    'cli.pruned_none'            => 'لا توجد مدخلات منتهية الصلاحية لإزالتها.',
];
