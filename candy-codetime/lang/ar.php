<?php

/**
 * Arabic translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'تم تسجيل {duration}s من {project} ({language})',
    'cli.usage' => "الاستخدام: candy-codetime [--backend=file|sqlite] <command> [args]\n\nالأوامر:\n  dashboard              بدء لوحة التتبع TUI (يقرأ الواجهة الخلفية المحددة)\n  push                   [<project> <language>] <file> [duration] [tags] | --  تسجيل نبضة\n  milestone              add [name] [description] | list  إدارة الإنجازات (sqlite فقط)\n  export                 <csv|json|ics> [days]  تصدير النبضات (7 أيام افتراضيًا)\n  gaps                   [days]  عرض فترات الفراغ غير المتتبَّعة 300 ثانية أو أكثر (7 أيام افتراضيًا)\n  backup                 أرشفة ملفات أقدم من 30 يومًا (نسخ فقط؛ لا حذف أبدًا)\n  help, --help, -h       عرض هذا الاستخدام\n  --version, -v          طباعة الإصدار\n\nالخيارات:\n  --backend=file|sqlite  واجهة تخزين البيانات لـ push/export/gaps/dashboard (افتراضيًا: file)\n  --time=<unix>          push: سجّل النبض عند هذه الثواني يونكس بدل الآن\n  --sqlite               backup: أرشفة قاعدة بيانات SQLite عبر VACUUM INTO (نسخة آمنة من الأعطال)\n  --theme=<name>         لوحة: استخدم سمة ملونة مسماة (default, light, dracula, solarized, gruvbox, high-contrast)
  CANDY_CODETIME_THEME   نفس الاختيار كمتغير بيئة (الراية أعلى أولوية)\n  CANDY_CODETIME_DIR     دليل البيانات (افتراضيًا: \$XDG_DATA_HOME/candy-codetime، وإلا \$HOME/.local/share/candy-codetime، أو ./.local/share/candy-codetime عند عدم ضبط HOME)",
    'cli.push_usage' => 'الاستخدام: candy-codetime push [<project> <language>] <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (دفعة JSON من stdin)',
    'cli.push_missing_args' => "push: الوسائط المطلوبة مفقودة (project, language, file)",
    'cli.push_ignored' => "push: تطابق {file} مع .sugartrackignore — تم تخطيه",
    'cli.push_ignored_wholesale' => 'push: تم تخطي {file} بسبب نمط شامل في {ignore_file} ({patterns} أنماط)؛ لا يسجل المشروع "{project}" شيئًا — احذف هذا الملف أو قيّده',
    'cli.push_invalid_duration' => 'push: يجب أن تكون المدة عددًا صحيحًا بالثواني، الوارد: "{value}"',
    'cli.push_empty_field' => 'push: الحقل {field} يجب ألا يكون فارغًا',
    'cli.push_invalid_time' => 'push: يجب أن تكون --time عددًا صحيحًا بثوانٍ يونكس، الوارد: "{value}"',
    'cli.push_time_stdin_conflict' => 'push: لا يُقبل --time مع وضع الدفعات عبر stdin؛ امنح كل سطر وقته الخاص',
    'cli.push_stdin_malformed' => 'push: سطر stdin {line} ليس سجل نبض صالحًا — تم تخطيه',
    'cli.push_stdin_done' => 'push: تم تسجيل {count} نبضات',
    'cli.push_stdin_done_one' => 'push: تم تسجيل {count} نبضة',
    'cli.invalid_days' => 'يجب أن يكون عدد الأيام عددًا صحيحًا، الوارد: "{value}"',
    'cli.invalid_goal' => 'candy-codetime: يجب أن يكون CANDY_CODETIME_GOAL عددًا صحيحًا من الثواني أو عددًا صحيحًا من الساعات مثل 6h، والقيمة المعطاة "{value}"',
    'cli.invalid_theme' => 'يجب أن تكون السمة أحد {names} - تم استلام "{value}"',
    'cli.backend_invalid' => "واجهة تخزين غير صالحة (استخدم file أو sqlite)",
    'cli.backend_unavailable' => "واجهة تخزين sqlite غير متاحة: امتداد sqlite3 لـ PHP غير محمّل",
    'cli.backend_other_hint' => 'ملاحظة: واجهة تخزين {other} تحتوي أيضًا على سجلات - اخترها باستخدام --backend={other}',
    'cli.storage_error' => 'candy-codetime: خطأ التخزين: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: لوحة المتابعة تحتاج إلى طرفية تفاعلية (stdin وstdout ليستا TTY). للإخراج بدون طرفية: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "الاستخدام: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: الوسيط المطلوب مفقود (name)",
    'cli.milestone_added' => "تمت إضافة الإنجاز: {name}",
    'cli.milestone_list_header' => "الإنجازات:",
    'cli.milestone_list_empty' => "لا توجد إنجازات مسجلة.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'علامة: {name} حُفظت في تخزين sqlite - مخزن الملفات لا يحمل علامات وأُنشئت قاعدته هنا؛ تعرض اللوحة العلامات بـ --backend=sqlite',

    'cli.export_usage' => "الاستخدام: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: صيغة غير صالحة (استخدم csv أو json أو ics)",
    'cli.gaps_no_data' => "لم يتم العثور على فترات فراغ.",
    'cli.gaps_header' => "فترات زمنية غير متتبَّعة:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: تمت أرشفة {count} ملفات",
    'cli.backup_done_one' => 'backup: تمت أرشفة {count} ملف',
    'cli.backup_none' => "backup: لا جديد للأرشفة",
    'cli.backup_sqlite_notice' => 'backup: هذه الأرشفة تغطي ملفات اليوم فقط — قاعدة بيانات SQLite في {db} تتطلب backup --sqlite (نسخة آمنة من الأعطال)',
    'cli.backup_sqlite_done' => 'backup: تمت أرشفة قاعدة البيانات إلى {file}',
    'cli.unknown_command' => "candy-codetime: أمر غير معروف: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: المتغير {variable} مهمل؛ استخدم CANDY_CODETIME_DIR بدلاً منه.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'أبرز المشاريع',
    'ui.by_language' => 'حسب اللغة',
    'ui.by_tags' => 'حسب الوسم',
    'ui.goal_today' => 'اليوم',
    'ui.help_row' => '  ←  اليوم السابق  ·  →  اليوم التالي  ·  r  إعادة التحميل  ·  q/Esc  خروج',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'الإجمالي:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(لا نشاط)',
    'ui.more_suffix' => '+{count} أخرى',
    'ui.earlier_suffix' => '+{count} سابقة',
    'ui.activity_minutes_heading' => 'النشاط اليومي (دقائق)',
    'ui.activity_heading' => 'النشاط اليومي',
    'ui.no_data' => '(لا بيانات)',
    'ui.diagnostics_dropped' => 'تم إسقاط {count} سطرًا تالفًا من ملفات اليوم',
    'ui.diagnostics_unreadable' => 'لم يمكن قراءة {count} ملف يوم',
];
