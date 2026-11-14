<?php

/**
 * 'ar' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; the {placeholder} tokens are contract
 * (LOCALES.md named-token law — values may reorder them, never rename
 * them). '[y/n]' is key notation and stays untranslated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return array (
  'confirm.hint' => '[y/n]',
  'confirm.no' => 'لا',
  'confirm.yes' => 'نعم',
  'history.append_lock_failed' => 'لا يمكن الحصول على قفل الإضافة على {path}',
  'history.changed_under_us' => 'تغيّر مسار السجل تحت أقدامنا (احتمال تبديل رابط رمزي): {path}',
  'history.chmod_failed' => 'لا يمكن ضبط 0600 على ملف السجل {path}',
  'history.clear_temp_failed' => 'لا يمكن إنشاء ملف مؤقت لمسح السجل في {dir}',
  'history.create_failed' => 'لا يمكن إنشاء ملف السجل {path}',
  'history.lock_acquire_failed' => 'لا يمكن الحصول على قفل كتابة السجل {path}',
  'history.lock_changed' => 'تغيّر قفل السجل تحت أقدامنا (احتمال تبديل رابط رمزي): {path}',
  'history.lock_open_failed' => 'لا يمكن فتح قفل كتابة السجل {path}',
  'history.not_regular' => 'مسار السجل ليس ملفًا عاديًا (فُتح عبر تبديل؟): {path}',
  'history.open_failed' => 'لا يمكن فتح ملف السجل {path}',
  'history.replace_failed' => 'لا يمكن استبدال ملف السجل {path}',
  'history.symlink_refused' => 'رفض رابط رمزي في {what}: {path}',
  'history.temp_create_failed' => 'لا يمكن إنشاء ملف مؤقت لإعادة كتابة السجل في {dir}',
  'history.temp_lock_failed' => 'لا يمكن قفل الملف المؤقت لإعادة كتابة السجل: {path}',
  'history.temp_open_failed' => 'لا يمكن فتح الملف المؤقت لإعادة كتابة السجل: {path}',
  'multiselect.hint' => '[تحديد متعدد] محدد: {count}',
  'multiselect.hint_max' => '[تحديد متعدد] محدد: {count} (أقصى {max})',
  'multiselect.hint_min' => '[تحديد متعدد] محدد: {count} (أدنى {min})',
  'multiselect.hint_min_max' => '[تحديد متعدد] محدد: {count} (أدنى {min}) (أقصى {max})',
  'multiselect.notice_unmarked' => 'أزيل تحديد {item} للالتزام بالحد {max}',
  'multiselect.nothing_to_submit' => 'لا يوجد خيار مطابق للمرشح',
  'selection.filter_prefix' => '[مرشح] ',
  'selection.matches_one' => ' ({count} مطابقة)',
  'selection.matches_other' => ' ({count} مطابقة)',
  'selection.no_matches' => '(لا مطابقات)',
  'selection.page' => 'صفحة {page}/{of}',
  'textarea.notice_max_lines' => 'لا يمكن إضافة سطر: الحد الأقصى {max}',
  'text.invalid_input' => 'إدخال غير صالح',
  'text.notice_no_history' => 'السجل فارغ',
  'text.search_line_forward' => '{label}(i-بحث)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(فشل i-بحث)`{query}\': ',
  'text.search_line_reverse' => '{label}(بحث-i-عكسي)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(فشل بحث-i-عكسي)`{query}\': ',
);
