<?php

/**
 * Arabic translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "تم تسجيل الإدخال: {project} ({language}) +{duration}s",
    'cli.usage' => "الاستخدام: candy-codetime [--backend=file|sqlite] <command> [args]\n\nالأوامر:\n  dashboard              تسجيل نبضة\n  push                   <project> <language> <file> [duration]  إدارة الإنجازات (sqlite فقط)\n  milestone              <add|list> [name] [description]  تصدير النبضات (7 أيام افتراضيًا)\n  export                 <csv|json|ics> [days]  عرض فترات الفراغ غير المتتبَّعة لأكثر من 300 ثانية (7 أيام افتراضيًا)\n  gaps                   [days]  أرشفة ملفات أقدم من 30 يومًا (نسخ فقط؛ لا حذف أبدًا)\n  backup                 عرض هذا الاستخدام\n  help, --help           طباعة الإصدار\n  --version, -v          افتراضيًا: file\n\nالخيارات:\n  --backend=file|sqlite  واجهة تخزين البيانات لـ push/export/gaps/dashboard (افتراضيًا: file)\n  CANDY_CODETIME_DIR     دليل البيانات (افتراضيًا: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "الاستخدام: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: الوسائط المطلوبة مفقودة (project, language, file)",
    'cli.push_ignored' => "push: تطابق {file} مع .sugartrackignore — تم تخطيه",
    'cli.push_invalid_duration' => "push: يجب أن تكون المدة عددًا صحيحًا غير سالب بالثواني، الوارد: \"{value}\"",
    'cli.backend_invalid' => "واجهة تخزين غير صالحة (استخدم file أو sqlite)",
    'cli.backend_unavailable' => "واجهة تخزين sqlite غير متاحة: امتداد sqlite3 لـ PHP غير محمّل",
    'cli.milestone_usage' => "الاستخدام: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: الوسيط المطلوب مفقود (name)",
    'cli.milestone_added' => "تمت إضافة الإنجاز: {name}",
    'cli.milestone_list_header' => "الإنجازات:",
    'cli.milestone_list_empty' => "لا توجد إنجازات مسجلة.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "الاستخدام: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: صيغة غير صالحة (استخدم csv أو json أو ics)",
    'cli.gaps_no_data' => "لم يتم العثور على فترات فراغ.",
    'cli.gaps_header' => "فترات زمنية غير متتبَّعة:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: تمت أرشفة {count} ملفات",
    'cli.backup_none' => "backup: لا جديد للأرشفة",
    'cli.unknown_command' => "candy-codetime: أمر غير معروف: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: المتغير {variable} مهمل؛ استخدم CANDY_CODETIME_DIR بدلاً منه.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
