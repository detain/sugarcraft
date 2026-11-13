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
    'cli.deleted_n'             => 'تم حذف {count} مدخل.',
    'cli.unknown_command'       => 'أمر غير معروف: {cmd}',
    'cli.deleted_none'           => 'لم يتم حذف أي مدخل: لا توجد مدخلات تطابق "{key}".',
    'cli.dryrun_none'            => 'لن يتم حذف أي مدخل: لا توجد مدخلات تطابق "{key}".',
    'cli.pattern_not_supported'  => 'get يتطلب مفتاحًا دقيقًا وليس نمطًا. استخدم "{bin} list <pattern>" لمطابقة أنماط glob.',
    'cli.db_deleted'             => 'تم حذف قاعدة البيانات "{name}".',
    'cli.db_not_found'           => 'قاعدة البيانات غير موجودة: {db}',
    'cli.db_did_you_mean'        => 'هل قصدت قاعدة البيانات \'{db}\'؟',
    'store.did_you_mean'         => 'المفتاح غير موجود؛ هل قصدت \'{key}\'؟',
    'cli.key_not_found'          => 'المفتاح غير موجود: {key}',
    'cli.deleted_one'            => 'تم حذف مدخل واحد.',
    'cli.imported_one'           => 'تم استيراد مدخل واحد.',
    'cli.imported_n'             => 'تم استيراد {count} مدخل.',
    'cli.overwrote_one'          => 'تم استبدال مدخل واحد.',
    'cli.overwrote_n'            => 'تم استبدال {count} مدخل.',
    'cli.would_delete_one'       => 'سيتم حذف مدخل واحد.',
    'cli.would_delete_n'         => 'سيتم حذف {count} مدخل.',
    'cli.pruned_n'               => 'تمت إزالة {count} مدخل منتهي الصلاحية.',
    'cli.pruned_one'             => 'تمت إزالة مدخل واحد منتهي الصلاحية.',
    'cli.pruned_none'            => 'لا توجد مدخلات منتهية الصلاحية لإزالتها.',
    'cli.deleted_zero'           => 'لم يتم حذف أي مدخل.',
    'cli.imported_zero'          => 'لم يتم استيراد أي مدخل.',
    'cli.overwrote_zero'         => 'لم يتم استبدال أي مدخل.',
    'cli.would_delete_zero'      => 'لن يتم حذف أي مدخل.',
    'cli.pruned_zero'            => 'لم تتم إزالة أي مدخل منتهي الصلاحية.',
    'cli.deleted_two'            => 'تم حذف مدخلين.',
    'cli.imported_two'           => 'تم استيراد مدخلين.',
    'cli.overwrote_two'          => 'تم استبدال مدخلين.',
    'cli.would_delete_two'       => 'سيتم حذف مدخلين.',
    'cli.pruned_two'             => 'تمت إزالة مدخلين منتهيي الصلاحية.',
    'cli.deleted_few'            => 'تم حذف {count} مدخلات.',
    'cli.imported_few'           => 'تم استيراد {count} مدخلات.',
    'cli.overwrote_few'          => 'تم استبدال {count} مدخلات.',
    'cli.would_delete_few'       => 'سيتم حذف {count} مدخلات.',
    'cli.pruned_few'             => 'تمت إزالة {count} مدخلات منتهية الصلاحية.',
    'cli.deleted_many'           => 'تم حذف {count} مدخلًا.',
    'cli.imported_many'          => 'تم استيراد {count} مدخلًا.',
    'cli.overwrote_many'         => 'تم استبدال {count} مدخلًا.',
    'cli.would_delete_many'      => 'سيتم حذف {count} مدخلًا.',
    'cli.pruned_many'            => 'تمت إزالة {count} مدخلًا منتهي الصلاحية.',
];
