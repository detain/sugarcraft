<?php

/**
 * Arabic (ar) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'حذف {names}؟ (y/n)',
    'confirm.copy' => 'نسخ {names} إلى {dest}؟ (y/n)',
    'confirm.move' => 'نقل {names} إلى {dest}؟ (y/n)',
    'confirm.rename' => 'إعادة تسمية \'{name}\' إلى: ',
    'confirm.selected_entries' => '{count} عناصر محددة',
    'status.nothing_to_delete' => 'لا شيء للحذف',
    'status.nothing_to_copy' => 'لا شيء للنسخ',
    'status.nothing_to_move' => 'لا شيء للنقل',
    'status.nothing_to_rename' => 'لا شيء لإعادة تسميته',
    'status.cancelled' => 'تم الإلغاء',
    'status.refreshed' => 'تم التحديث',
    'status.copy_pending' => 'جارٍ نسخ {count} عناصر…',
    'status.deleted' => 'تم حذف {count} عناصر',
    'status.deleted_with_errors' => 'تم حذف {count} عناصر مع {errors} أخطاء',
    'status.deleted_skipped' => 'تم حذف {count} عناصر ({skipped} متجاهل)',
    'status.copied' => 'تم نسخ {count} عناصر',
    'status.copied_with_errors' => 'تم نسخ {count} عناصر مع {errors} أخطاء',
    'status.copied_skipped' => 'تم نسخ {count} عناصر ({skipped} متجاهل)',
    'status.copied_overwrote' => 'تم نسخ {count} عناصر ({overwrote} استبدال)',
    'status.moved' => 'تم نقل {count} عناصر',
    'status.moved_with_errors' => 'تم نقل {count} عناصر مع {errors} أخطاء',
    'status.moved_skipped' => 'تم نقل {count} عناصر ({skipped} متجاهل)',
    'status.renamed' => 'أُعيدت تسمية {old} إلى {new}',
    'status.rename_failed' => 'فشل إعادة التسمية: {name}',
    'status.nothing_to_undo' => 'لا شيء للتراجع عنه',
    'status.undone' => 'تم التراجع: {description}',
    'status.undo_with_errors' => 'التراجع عن {description} مع {errors} أخطاء',
    'status.cannot_close_last_tab' => 'لا يمكن إغلاق آخر تبويب',
    'status.redone' => 'تمت الإعادة: {description}',
    'status.redo_with_errors' => 'إعادة {description} مع {errors} أخطاء',
    'status.nothing_to_redo' => 'لا شيء لإعادته',
    'status.copy_undo_noop' => 'النسخة باقية (لا شيء للتراجع عنه)',
    'undo.delete' => 'حذف {count} عناصر',
    'undo.rename' => 'إعادة تسمية {count} عناصر',
    'undo.move' => 'نقل {count} عناصر',
    'undo.copy' => 'نسخ {count} عناصر',
    'keyhelp.default' => 'Tab تبديل · ↑↓ jk حركة · Enter فتح · ← h للأعلى · مسافة تحديد · s ترتيب · . المخفية · c نسخ · m نقل · R إعادة تسمية · d حذف · r تحديث · q خروج · / بحث · t تبويب جديد · ^w إغلاق التبويب · ^tab تنقّل',
    'search.no_match' => '(لا تطابقات)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[مجلد]',
    'search.type_file' => '[ملف]',
    'pane.hidden_suffix' => '+مخفية',
];
