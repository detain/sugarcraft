<?php

/**
 * Arabic translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'ملف SQLite غير موجود: {path}',
    'complete.in_literal' => 'لا شيء للإكمال داخل نص أو تعليق',
    'complete.nothing' => 'لا شيء للإكمال — اكتب بداية كلمة مفتاحية',
    'complete.none_keyword' => 'لا توجد كلمة مفتاحية تكمل "{word}"',
    'complete.none_table' => 'لا يوجد جدول يكمل "{word}"',
    'complete.none_column' => 'لا يوجد عمود يكمل "{word}"',
    'complete.none_qualified' => 'لا يوجد عمود في {qualifier} يكمل "{word}"',
    'complete.list' => '{count} تكمل "{word}": {list}{more}',
    'complete.done' => 'تم إكمال {word} ← {insert}',
    'complete.inserted' => 'تم إدراج {insert}',
    'edit.refuse_readonly' => 'جلسة للقراءة فقط — التحرير معطّل',
    'edit.refuse_no_table' => 'افتح معاينة جدول أولًا — لا يمكن تحرير نتائج الاستعلام مباشرة',
    'edit.refuse_no_rows' => 'لا يوجد صف تحت المؤشر',
    'edit.refuse_no_pk' => 'لا يملك هذا الجدول مفتاحًا أساسيًا — لا يمكن تحديد الصفوف بأمان؛ اكتب العبارة في لوحة الاستعلام',
    'edit.refuse_pk_hidden' => 'المفتاح الأساسي لهذا الصف NULL أو عنصر نائب ثنائي — لا يمكن تحديده',
    'edit.refuse_binary' => 'لا يمكن تحرير الخلايا الثنائية أو الكبيرة هنا — استخدم عبارة في لوحة الاستعلام',
    'edit.refuse_schema' => 'تعذّرت قراءة بنية {table} — تم رفض التحرير',
    'edit.prompt_cell' => 'تحرير {table}.{column} = {value}  (Enter للمعاينة · ctrl+n لـ NULL · Esc للإلغاء)',
    'edit.prompt_insert' => 'صف جديد في {table} — {column} ({type}) [{n}/{total}] = {value}  (Enter للتالي · فارغ = DEFAULT · ctrl+n لـ NULL · Esc للإلغاء)',
    'edit.prompt_confirm' => '{sql}  (Enter للتنفيذ · Esc للإلغاء)',
    'edit.cancelled' => 'تم إلغاء التحرير',
    'edit.unchanged' => 'القيمة لم تتغير — لم يُكتب شيء',
    'edit.done_update' => 'تم تحديث صف واحد في {table}',
    'edit.done_insert' => 'تم إدراج صف واحد في {table}',
    'edit.done_delete' => 'تم حذف صف واحد من {table}',
    'edit.none_changed' => 'لم يتغير أي صف — القيمة موجودة مسبقًا أو الصف اختفى؛ تم تحديث المعاينة',
    'edit.failed' => 'فشلت الكتابة — تم التراجع ولم يتغير شيء: {reason}',
    'edit.too_many' => 'تم الرفض: ستؤثر العبارة على {count} صفًا — تم التراجع ولم يتغير شيء',
    'ui.title.tables' => ' الجداول ',
    'ui.title.rows' => ' الصفوف ',
    'ui.title.query' => ' الاستعلام ',
    'ui.title.admin' => ' الإدارة ',
    'ui.title.explain' => ' خطة التنفيذ ',
    'ui.help.query' => 'tab  تبديل اللوحة  ·  ctrl+r / ctrl+e  تشغيل  ·  enter  سطر جديد  ·  ↑/↓  استدعاء السجل  ·  ctrl+c  خروج',
    'ui.help.rows' => 'tab  تبديل اللوحة  ·  h/l  خلية  ·  e  تحرير  ·  o  صف جديد  ·  D  حذف  ·  x  تصدير  ·  q / esc  خروج',
    'ui.help.browse' => 'tab  تبديل اللوحة  ·  enter  تحميل الجدول  ·  x  تصدير  ·  i  استيراد  ·  q / esc  خروج',
    'ui.help.admin' => '1-{count}  اختيار الصفحة  ·  j/k  تنقّل  ·  p  إيقاف مؤقت  ·  r  إعادة ضبط  ·  tab  تبديل اللوحة  ·  q  رجوع  ·  esc  إلغاء الحوار',
    'ui.no_tables' => '(لا توجد جداول)',
    'ui.no_match' => '(لا نتائج)',
    'ui.loading' => 'جارٍ التحميل…',
    'ui.empty' => '(فارغ)',
    'ui.admin_unavailable' => 'صفحة الإدارة غير متاحة: {reason}',
    'ui.error' => 'خطأ: {message}',
    'io.prompt_export' => 'إلى CSV ← {path}  (Enter للكتابة · Esc للإلغاء)',
    'io.prompt_replace' => 'استبدال {path}؟  (Enter للتأكيد · Esc للإلغاء)',
    'io.prompt_import_path' => 'استيراد CSV ← {path}  (Enter · Esc للإلغاء)',
    'io.prompt_import_table' => 'استيراد {source} ← الجدول {table}  (Enter · Esc للإلغاء)',
    'bar.tables' => 'الجداول: {count}',
    'bar.cycle' => 'Tab:تبديل',
    'bar.navigate' => '↑↓:تنقّل',
    'bar.load' => 'Enter:تحميل',
    'bar.run' => 'Ctrl+R:تشغيل',
    'bar.select' => '1-{count}:اختيار',
    'bar.nav' => 'j/k:تنقّل',
    'bar.quit' => 'q:خروج',
    'bar.paused' => '[متوقف مؤقتًا]',
    'bar.tx' => '[TX]',
    'picker.title' => 'الاتصالات المحفوظة',
    'picker.help' => '[↑↓/j/k] اختيار   [Enter] اتصال   [q] إلغاء',
    'result.empty' => '(مجموعة نتائج فارغة)',
    'explain.no_plan' => '(لا توجد خطة استعلام — لم يُرجع الاستعلام أي صفوف)',
    'explain.title' => ' خطة الاستعلام ',
    'status.live_refresh_off' => 'تم تعطيل التحديث المباشر للإدارة — وضع ssl الموثّق بـ TLS (النقل المتزامن فقط)',
    'status.async_failed' => 'فشل الأمر غير المتزامن: {message}',
    'status.file_cancelled' => 'تم إلغاء سطر الملف',
    'status.path_required' => 'المسار مطلوب — Esc للإلغاء',
    'status.no_such_file' => 'لا يوجد ملف بهذا الاسم — تابع الكتابة أو Esc للإلغاء',
    'status.table_required' => 'حدّد الجدول الهدف — Esc للإلغاء',
    'status.export_failed' => 'فشل التصدير: {message}',
    'status.exported' => 'تم تصدير {count} صف إلى {path}',
    'status.import_failed' => 'فشل الاستيراد: {message}',
    'status.imported' => 'تم استيراد {path} إلى {table}',
    'status.history' => 'السجل {pos}/{count}',
    'status.conn_lost' => 'انقطع الاتصال؛ أعد المحاولة',
    'status.destructive' => '⚠ عبارة مدمّرة — اضغط ctrl+r مرة أخرى (دون تعديل) للتأكيد',
    'status.running' => 'جارٍ تنفيذ الاستعلام…',
    'status.explain_failed' => 'فشل EXPLAIN: {message}',
    'status.explain_plan' => 'خطة التنفيذ — أي تشغيل استعلام أو معاينة جدول يستبدلها',
    'status.end_of_table' => 'نهاية الجدول — لا توجد صفحة أخرى',
    'status.first_page' => 'أنت في الصفحة الأولى بالفعل',
    'status.nothing_to_save' => 'لا شيء للحفظ',
    'status.saved' => 'تم الحفظ ({count})',
    'status.removed' => 'تمت الإزالة ({count})',
    'status.row_one' => 'صف واحد',
    'status.rows' => '{count} صفوف',
    'status.page' => 'الصفحة {page} (الصفوف {first}–{last})',
    'editor.placeholder' => '-- اكتب SQL، ctrl+r للتشغيل --',
    'err.async_query_unsupported' => 'هذا المشغّل لا يدعم الاستعلامات غير المتزامنة',
    'err.async_browse_unsupported' => 'التصفح غير المتزامن غير مدعوم لهذا المشغّل',
    'dash.title' => 'لوحة الأداء',
    'dash.fetching' => 'جارٍ جلب مقاييس الخادم...',
    'dash.back_hint' => 'اضغط [q] للعودة إلى عرض الجداول',
    'dash.recent_queries' => 'الاستعلامات الأخيرة',
    'dash.no_queries' => '(لا توجد استعلامات بعد)',
    'dash.header_pg' => 'لوحة الأداء{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'لوحة الأداء{status} | MySQL {version} | مدة التشغيل: {uptime}',
    'dash.uptime_segment' => ' | مدة التشغيل: {uptime}',
    'pane.process_list' => 'قائمة العمليات',
    'pane.variables' => 'المتغيرات',
    'pane.status' => 'الحالة',
    'pane.query_stats' => 'إحصاءات الاستعلام',
    'pane.dashboard' => 'لوحة المعلومات',
    'pane.table_stats' => 'إحصاءات الجداول',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'تصحيح الأخطاء',
    'page.no_data' => 'لا توجد بيانات بعد.',
    'page.error' => 'خطأ: {message}',
    'page.loading' => 'جارٍ التحميل…',
    'page.fetching' => 'جارٍ جلب البيانات من الخادم.',
    'debug.title' => 'سجل التصحيح',
    'debug.entries' => ' ({count} إدخالات)',
    'debug.errors_only' => '[الأخطاء فقط]',
    'debug.empty' => '(لا توجد إدخالات في السجل)',
    'debug.more' => '↑ {count} إدخالات أخرى',
    'debug.help' => '[j/k] تنقّل  [f] الأخطاء فقط  [c] مسح  [q] رجوع',
    'reports.cannot_access' => 'لا يمكن الوصول إلى تقارير الأداء: {message}',
    'reports.title' => 'تقارير الأداء',
    'reports.categories' => 'الفئات',
    'reports.no_data' => '(لا توجد بيانات)',
    'reports.loading' => 'جارٍ تحميل التقارير…',
    'reports.loading_one' => 'جارٍ تحميل {caption}…',
    'reports.no_data_for' => 'لا توجد بيانات متاحة لـ {caption}',
    'reports.help' => '[j/k] تنقّل بين الصفوف  [h/l] الفئة  [[]/[]] التقرير  [,/.] العمود  [c] الوحدة  [r] تحديث  [x] تصدير  [q] رجوع',
    'reports.unavailable' => 'تقارير الأداء غير متاحة',
    'reports.ensure' => 'تأكد من استخدام MySQL 5.6.6+ مع تفعيل performance_schema وتثبيت مخطط sys.',
    'conn.kill_cancelled' => 'تم إلغاء الإنهاء',
    'conn.none_selected' => 'لم يتم اختيار أي اتصال',
    'conn.refuse_background' => 'رفض إنهاء خيط في الخلفية',
    'conn.verb_kill_query' => 'إنهاء الاستعلام',
    'conn.verb_kill' => 'إنهاء',
    'conn.sent' => 'تم إرسال {verb} إلى الاتصال {id}',
    'conn.failed' => 'فشل {verb} للاتصال {id}',
    'conn.confirm_verb_query' => 'إلغاء الاستعلام الجاري على',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} الاتصال {id} ({user}@{host})؟  [y] تأكيد   [أي مفتاح آخر] إلغاء',
    'conn.help_empty' => '[j/k] تنقّل  [f] تصفية  [r] تحديث',
    'conn.help_full' => '[j/k] تنقّل  [K] إنهاء  [X] إنهاء الاستعلام  [f] تصفية  [r] تحديث',
    'conn.vars_fail' => 'تعذّر جلب متغيرات الخادم',
    'vars.load_fail' => 'تعذّر تحميل المتغيرات: {message}',
    'vars.unknown_error' => 'خطأ غير معروف',
    'vars.not_dynamic' => 'خطأ 1238: \'{name}\' غير ديناميكي — اضغط [p] لاستخدام PERSIST_ONLY',
    'vars.new_value' => 'القيمة الجديدة > ',
    'vars.edit_head' => 'تحرير ({mode}) {name} (الحالي: {current})',
    'vars.scope_explain' => 'GLOBAL = وقت التشغيل فقط (يُفقد عند إعادة التشغيل) · PERSIST = وقت التشغيل + ملف الإعداد · PERSIST_ONLY = ملف الإعداد فقط (يُطبَّق عند إعادة التشغيل التالية)',
    'vars.edit_help' => 'أدخل القيمة الجديدة، [p] تبديل الوضع، [Enter] تأكيد، [Esc] إلغاء',
    'reports.back' => '[q] رجوع',
    'err.dsn_empty' => 'لا يمكن أن يكون DSN فارغًا',
    'err.dsn_no_separator' => 'DSN غير صالح: الفاصل "://" مفقود',
    'err.driver_unsupported' => 'مشغّل غير مدعوم: {driver}',
    'err.dsn_sqlite_invalid' => 'DSN لـ SQLite غير صالح',
    'err.dsn_parse_failed' => 'DSN غير صالح: فشل parse_url()',
    'err.dsn_no_host' => 'DSN غير صالح: المضيف مفقود',
    'err.driver_choose' => 'مشغّل غير مدعوم: {driver} — اختر واحدًا من {list}',
    'err.args_missing' => 'وسيطات مطلوبة مفقودة: مرّر كلاً من --driver و --db (أو استخدم --dsn)',
    'err.opt_needs_value' => 'الخيار --{name} يتطلب قيمة',
    'err.opt_unknown' => 'خيار غير معروف: --{name}',
    'err.csv_not_found' => 'ملف CSV غير موجود: {path}',
    'err.csv_unreadable' => 'لا يمكن قراءة ملف CSV \'{path}\': تحقق من وجوده وإمكانية قراءته',
    'err.csv_no_headers' => 'تعذّرت قراءة رؤوس CSV من: {path}',
    'err.csv_no_columns' => 'ملف CSV لا يحتوي على أعمدة: {path}',
    'err.dest_unwritable' => 'لا يمكن فتح الوجهة \'{path}\' للكتابة: تحقق من وجود المجلد وإمكانية الكتابة فيه',
    'err.export_buffer' => 'تعذّر إنشاء مخزن التصدير المؤقت',
    'err.csv_partial' => 'كتب تصدير CSV للجدول {table} ‏{written} من {expected} صفًا — تغيّر الجدول أثناء التصدير أو تجاهل المشغّل نافذة الترحيل؛ يُرفض تقديم ملف جزئي على أنه كامل',
    'err.sql_partial' => 'كتب تفريغ SQL للجدول {table} ‏{written} من {expected} صفًا — تغيّر الجدول أثناء التفريغ أو تجاهل المشغّل نافذة الترحيل؛ يُرفض تقديم ملف جزئي على أنه كامل',
    'err.table_unreadable' => 'الجدول غير موجود أو غير قابل للقراءة: {table} ({message})',
    'err.dml_no_prepare' => 'لا يمكن لهذا الاتصال تنفيذ العبارات المُعدّة',
    'err.dml_refused' => 'رفض الخادم العبارة',
    'alert.connection_critical' => 'استخدام الاتصالات مرتفع للغاية',
    'alert.connection_elevated' => 'استخدام الاتصالات مرتفع',
    'alert.aborted_elevated' => 'معدل الاتصالات المجهضة مرتفع',
    'alert.thread_elevated' => 'عدد الخيوط قيد التشغيل مرتفع',
    'alert.slow_query_time' => 'زمن الاستعلام البطيء مضبوط على {seconds} ث',
    'alert.connections_at' => 'الاتصالات عند {pct}% من الحد الأقصى ({connected}/{max})',
    'alert.connection_error_one' => 'تم رصد {count} خطأ اتصال',
    'alert.connection_error_other' => 'تم رصد {count} أخطاء اتصال',
    'alert.threshold_ratio_connection_warning' => 'يجب أن يكون حد تحذير الاتصال بين 0.0 و1.0',
    'alert.threshold_ratio_connection_critical' => 'يجب أن يكون الحد الحرج للاتصال بين 0.0 و1.0',
    'alert.threshold_ratio_thread_running' => 'يجب أن يكون حد الخيوط قيد التشغيل بين 0.0 و1.0',
    'alert.threshold_aborted_rate' => 'يجب أن يكون حد معدل الإجهاض >= 0.0',
    'alert.threshold_slow_query' => 'يجب أن يكون حد الاستعلام البطيء >= 0.0',
    'alert.threshold_connection_errors' => 'يجب أن يكون حد أخطاء الاتصال >= 0',
    'history.err_mkdir' => 'تعذر إنشاء دليل السجل: {dir}',
    'history.err_chmod' => 'تعذر تأمين ملف مخزن السجل: {path} (الوضع {mode})',
    'history.err_unusable' => 'مخزن السجل غير صالح للاستخدام: {error} — احذف ملف السجل أو أصلح أذوناته للاسترداد.',
    'query.cancelled' => 'تم إلغاء الاستعلام غير المتزامن',
    'tx.unknown' => 'أمر غير معروف — ‎:tx‎ يبدأ المعاملة أو يثبتها أو يتراجع عنها',
    'tx.async_refusal' => '‎:tx‎ يغطي الجلسة المتزامنة فقط؛ لمسار غير المتزامن لا توجد بعد ذراع معاملة منسقة — نفّذ BEGIN/COMMIT صراحة أو استخدم المسار المتزامن',
    'tx.already_open' => 'هناك معاملة مفتوحة بالفعل — ‎:tx commit‎ أو ‎:tx rollback‎',
    'tx.no_tx' => 'لا توجد معاملة مفتوحة — ‎:tx‎ يبدأ واحدة',
    'tx.opened' => 'بدأت المعاملة — تجري عليها العبارات حتى ‎:tx commit‎ أو ‎:tx rollback‎',
    'tx.committed' => 'تم تثبيت المعاملة',
    'tx.rolled_back' => 'تم التراجع عن المعاملة',
    'tx.arm_rollback' => 'ROLLBACK يتخلص من كل تغيير منذ BEGIN — اضغط مفتاح التشغيل مرة أخرى للتأكيد',
    'tx.failed' => 'فشل أمر المعاملة: {message}',
    'query.timed_out' => 'انتهت مهلة الاستعلام غير المتزامن بعد {seconds} ث',
    'reports.err_meta_missing' => 'ملف بيانات التقرير الوصفية غير موجود: {path}',
    'reports.err_meta_read' => 'فشلت قراءة ملف بيانات التقرير الوصفية: {path}',
    'reports.err_not_in_catalog' => 'التقرير غير موجود في الفهرس: {name}',
    'reports.err_view_unavailable' => 'العرض غير متاح على هذا الخادم: {name}',
    'reports.err_loading' => 'خطأ في تحميل التقرير: {error}',
    'variables.err_meta_missing' => 'ملف بيانات المتغيرات الوصفية غير موجود: {path}',
    'variables.err_meta_read' => 'فشلت قراءة ملف بيانات المتغيرات الوصفية: {path}',
    'resilience.statement_timeout_default' => 'انتهت مهلة تنفيذ العبارة',
    'resilience.statement_timeout_after' => 'انتهت مهلة تنفيذ العبارة بعد {seconds} ث',
    'variables.err_prepare' => 'تعذر تحضير العبارة على هذا الاتصال',
    'variables.err_not_editable' => 'المتغير غير قابل للتحرير',
    'variables.err_persist_mariadb' => 'يتطلب الحفظ الدائم MariaDB 11.1+ — هذا الخادم لا ينفذ SET PERSIST.',
    'variables.err_persist_mysql' => 'يتطلب الحفظ الدائم MySQL 8.0.16+ — هذا الخادم لا ينفذ SET PERSIST.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: اسم المستخدم فارغ — رفض الاتصال بـ MySQL بهوية "root" المفترضة؛ مرّر مستخدماً صريحاً',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: اسم المستخدم فارغ — رفض الاتصال بـ PostgreSQL بهوية "postgres" المفترضة؛ مرّر مستخدماً صريحاً',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: قيمة sslmode غير معروفة "{mode}"؛ المتوقع أحد {allowed} — تجاهل الخطأ المطبعي بصمت سيخفض الأمان إلى "prefer" الذي يسمح بالنص الصريح',
    'store.err_read' => 'تعذرت قراءة ملف الاتصالات: {path} (احذفه أو أصلح أذوناته للاسترداد)',
    'store.err_json' => 'ملف الاتصالات ليس JSON صالحاً ({error}): {path}',
    'store.err_shape' => 'يجب أن يكون ملف الاتصالات كائن JSON يحتوي على خريطة "connections": {path}',
    'store.err_connections_type' => 'يجب أن تكون قيمة "connections" كائن JSON من الاسم -> الإعدادات: {path}',
    'store.err_unknown' => 'اتصال غير معروف \'{name}\' — الاتصالات المحفوظة: {saved}',
    'store.none' => '(لا شيء)',
    'store.err_pass_env_unset' => 'يقرأ الاتصال \'{name}\' كلمة مروره من ${var} — صدّرها قبل الاتصال',
    'store.err_mkdir' => 'تعذر إنشاء دليل الاتصالات: {dir}',
    'store.err_write' => 'تعذرت كتابة ملف الاتصالات: {path}',
    'store.err_replace' => 'تعذر استبدال ملف الاتصالات: {path}',
    'store.err_entry_type' => 'يجب أن يشير الاتصال \'{name}\' إلى كائن JSON من الإعدادات',
    'store.err_plaintext_pass' => 'يخزن الاتصال \'{name}\' قيمة \'pass\' كنص صريح — لا يحتوي connections.json على أسرار أبداً: استبدلها بـ "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'يحتوي الاتصال \'{name}\' على إعداد غير معروف \'{field}\' — المسموح: {allowed}',
    'store.err_setting_type' => 'يجب أن يكون الإعداد \'{field}\' للاتصال \'{name}\' نصاً أو رقماً',
    'store.err_setting_empty' => 'يجب ألا يكون الإعداد \'{field}\' للاتصال \'{name}\' فارغاً',
    'store.err_needs_driver' => 'يحتاج الاتصال \'{name}\' إلى \'driver\'',
    'store.err_driver' => 'يستخدم الاتصال \'{name}\' برنامج تشغيل غير مدعوم \'{driver}\' — اختر أحد: {allowed}',
    'store.err_port' => 'يجب أن يكون الإعداد \'port\' للاتصال \'{name}\' رقماً',
    'store.err_pass_env' => 'يجب أن يكون الإعداد \'pass-env\' للاتصال \'{name}\' اسم متغير بيئة',
    'store.err_name' => 'اسم اتصال غير صالح \'{name}\' — استخدم أحرفاً وأرقاماً ونقطة وشرطة أو شرطة سفلية (1-64 حرفاً)',
    'store.err_secure' => 'تعذر تأمين {path} (الوضع {mode})',
    'db.err_dsn_semicolon' => 'يجب ألا يحتوي مضيف قاعدة البيانات واسمها على \';\' — فهو فاصل خيارات PDO DSN',
    'db.err_unknown_pg_ssl' => 'وضع ssl-mode غير معروف لـ PostgreSQL: {mode}',
    'db.err_unknown_mysql_ssl' => 'وضع ssl-mode غير معروف لـ MySQL: {mode}',
    'db.err_pg_driver' => 'لا يمكن الاتصال ببرنامج تشغيل غير PostgreSQL باستخدام موصل PostgreSQL',
    'db.err_mysql_driver' => 'موصل MySQL يقبل فقط برنامج تشغيل mysql (تم استلام \'{driver}\')',
    'db.err_reconnect' => 'فشلت إعادة الاتصال بعد خطأ في الاتصال: {error}',
    'db.err_quote_no_connection' => 'لا يمكن تحديد النص بين علامات اقتباس بدون اتصال',
    'dml.err_key_required' => 'مفتاح الصف مطلوب: تم رفض عبارة قد تمس أكثر من صف واحد',
    'dml.err_key_null' => 'عمود المفتاح {column} قيمته NULL: لا يمكن تحديد الصف',
    'dml.err_empty_table' => 'يجب ألا يكون اسم الجدول فارغاً',
    'dml.err_empty_column' => 'يجب ألا يكون اسم العمود فارغاً',
    'dml.err_empty_key_column' => 'يجب ألا يكون اسم عمود المفتاح فارغاً',
    'status.already_saved' => 'تم الحفظ مسبقاً ({count})',
    'status.not_in_favorites' => 'ليس في المفضلة ({count})',
    'status.gtid_mariadb' => 'GTID_MODE غير متاح: لا يحتوي MariaDB على متغير gtid_mode.',
    'status.gtid_version' => 'يتطلب GTID_MODE‏ MySQL 5.7.6 أو أحدث (الخادم {version}).',
    'caption.blocks_hit' => 'الكتل المصابة',
    'caption.blocks_read' => 'الكتل المقروءة',
    'caption.buffer_pool_read_reqs' => 'طلبات قراءة مجمع المخزن المؤقت',
    'caption.buffer_pool_usage' => 'استخدام مجمع المخزن المؤقت',
    'caption.buffer_pool_write_reqs' => 'طلبات كتابة مجمع المخزن المؤقت',
    'caption.bytes_in' => 'البايتات الواردة',
    'caption.bytes_out' => 'البايتات الصادرة',
    'caption.cache_hit_rate' => 'معدل إصابة الذاكرة المؤقتة',
    'caption.commits' => 'عمليات الإيداع',
    'caption.connections' => 'الاتصالات',
    'caption.deadlocks' => 'الأقفال المتبادلة',
    'caption.disk_reads_not_from_pool' => 'قراءات القرص (ليست من المجمع)',
    'caption.disk_write_requests' => 'طلبات كتابة القرص',
    'caption.doublewrite_writes' => 'عمليات الكتابة المزدوجة',
    'caption.innodb_disk_reads' => 'قراءات قرص InnoDB',
    'caption.innodb_disk_writes' => 'كتابات قرص InnoDB',
    'caption.insert_buffer' => 'مخزن الإدراج المؤقت',
    'caption.pages_created' => 'الصفحات المنشأة',
    'caption.pages_flushed' => 'الصفحات المفرّغة',
    'caption.pages_read' => 'الصفحات المقروءة',
    'caption.read_ahead' => 'القراءة المسبقة',
    'caption.redo_log_bytes_written' => 'بايتات سجل الإعادة المكتوبة',
    'caption.redo_log_writes' => 'كتابات سجل الإعادة',
    'caption.rollbacks' => 'التراجعات',
    'caption.row_lock_time' => 'زمن قفل الصف',
    'caption.row_lock_waits' => 'انتظارات قفل الصف',
    'caption.shared_buffers' => 'المخازن المشتركة',
    'caption.sql_statements' => 'عبارات SQL',
    'caption.table_open_cache' => 'ذاكرة فتح الجداول المؤقتة',
    'caption.temp_bytes' => 'البايتات المؤقتة',
    'caption.temp_files' => 'الملفات المؤقتة',
    'caption.transactions' => 'المعاملات',
    'caption.tuples_fetched' => 'الصفوف المجلوبة',
    'caption.tuples_returned' => 'الصفوف المُعادة',
    'caption.traffic' => 'حركة البيانات',
    'caption.key_efficiency' => 'كفاءة المفاتيح',
    'caption.selects_per_second' => 'عمليات SELECT في الثانية',
    'caption.innodb_buffer_usage' => 'استخدام مخزن InnoDB المؤقت',
    'caption.innodb_reads_per_second' => 'قراءات InnoDB في الثانية',
    'caption.innodb_writes_per_second' => 'كتابات InnoDB في الثانية',
    'widget.tip.bytes_in' => 'الحركة الواردة: {Bytes_received} بايت إجمالاً',
    'widget.tip.bytes_out' => 'الحركة الصادرة: {Bytes_sent} بايت إجمالاً',
    'widget.tip.client_connections' => 'اتصالات العملاء: {Threads_connected}',
    'widget.tip.connections_level' => 'مستوى الاتصالات مقابل max_connections',
    'widget.tip.table_open_cache' => 'نسبة إصابة ذاكرة فتح الجداول المؤقتة',
    'widget.tip.sql_statements' => 'معدلات عبارات SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'نسبة استخدام مجمع مخزن InnoDB المؤقت (بالبايت)',
    'widget.tip.buffer_pool_read_reqs' => 'طلبات قراءة مجمع مخزن InnoDB في الثانية',
    'widget.tip.buffer_pool_write_reqs' => 'طلبات كتابة مجمع مخزن InnoDB في الثانية',
    'widget.tip.disk_reads_not_from_pool' => 'قراءات مجمع مخزن InnoDB من القرص في الثانية',
    'widget.tip.innodb_disk_writes' => 'بايتات بيانات InnoDB المكتوبة على القرص في الثانية',
    'widget.tip.disk_write_requests' => 'طلبات كتابة ملف بيانات InnoDB في الثانية',
    'widget.tip.innodb_disk_reads' => 'بايتات بيانات InnoDB المقروءة من القرص في الثانية',
    'widget.tip.row_lock_waits' => 'انتظارات قفل صفوف InnoDB في الثانية',
    'widget.tip.row_lock_time' => 'زمن قفل صفوف InnoDB في الثانية (بالميلي ثانية)',
    'widget.tip.pages_flushed' => 'صفحات InnoDB المفرّغة في الثانية',
    'widget.tip.pages_created' => 'صفحات InnoDB المنشأة في الثانية',
    'widget.tip.pages_read' => 'صفحات InnoDB المقروءة في الثانية',
    'widget.tip.insert_buffer' => 'حجم مخزن إدراج InnoDB المؤقت (صفحات)',
    'widget.tip.read_ahead' => 'القراءة المسبقة لمجمع مخزن InnoDB في الثانية',
    'widget.tip.redo_log_bytes_written' => 'بايتات سجل إعادة InnoDB المكتوبة في الثانية',
    'widget.tip.redo_log_writes' => 'كتابات سجل إعادة InnoDB في الثانية',
    'widget.tip.doublewrite_writes' => 'كتابات doublewrite في InnoDB في الثانية',
    'widget.tip.alter_rate' => 'معدل أوامر ALTER (جميع أفعال Com_alter_*)',
    'widget.tip.create_rate' => 'معدل أوامر CREATE (جميع أفعال Com_create_*)',
    'widget.tip.drop_rate' => 'معدل أوامر DROP (جميع أفعال Com_drop_*)',
    'widget.tip.select_rate' => 'معدل SELECT',
    'widget.tip.insert_rate' => 'معدل INSERT',
    'widget.tip.update_rate' => 'معدل UPDATE',
    'widget.tip.delete_rate' => 'معدل DELETE',
    'widget.tip.tuples_fetched_total' => 'الصفوف المجلوبة من قاعدة البيانات: {tup_fetched} إجمالاً',
    'widget.tip.tuples_fetched_rate' => 'معدل جلب الصفوف',
    'widget.tip.tuples_returned_total' => 'الصفوف المُعادة إلى العميل: {tup_returned} إجمالاً',
    'widget.tip.tuples_returned_rate' => 'معدل إرجاع الصفوف',
    'widget.tip.blocks_read_total' => 'كتل القرص المقروءة: {blks_read} إجمالاً',
    'widget.tip.blocks_hit_total' => 'إصابات ذاكرة المخزن المؤقت: {blks_hit} إجمالاً',
    'widget.tip.active_backends' => 'الخلفيات النشطة: {numbackends}',
    'widget.tip.transaction_rates' => 'معدلات المعاملات: عمليات الإيداع / التراجعات',
    'widget.tip.commit_rate' => 'معدل إيداع المعاملات',
    'widget.tip.rollback_rate' => 'معدل تراجع المعاملات',
    'widget.tip.tuple_insert_rate' => 'معدل إدراج الصفوف',
    'widget.tip.tuple_update_rate' => 'معدل تحديث الصفوف',
    'widget.tip.tuple_delete_rate' => 'معدل حذف الصفوف',
    'widget.tip.deadlock_rate' => 'معدل الأقفال المتبادلة',
    'widget.tip.shared_buffers' => 'إعداد shared_buffers في PostgreSQL (حجم مجمع المخزن المؤقت)',
    'widget.tip.cache_hit_rate' => 'نسبة إصابة ذاكرة المخزن المؤقت: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'معدل إنشاء الملفات المؤقتة',
    'widget.tip.temp_bytes' => 'البايتات المكتوبة في الملفات المؤقتة في الثانية',
    'dash.data_written' => 'البيانات المكتوبة {kb} ك.ب/ث',
    'dash.writes_ops' => 'الكتابات {n} عملية/ث',
    'dash.log_written' => 'السجل المكتوب {kb} ك.ب/ث',
    'dash.read_reqs' => 'طلبات القراءة {n} صفحة/ث',
    'dash.write_reqs' => 'طلبات الكتابة {n} صفحة/ث',
    'dash.disk_reads' => 'قراءات القرص {n} /ث',
    'metrics.busy_proxy' => 'وكيل الانشغال {pct}%',
    'metrics.cpu_load' => 'حمل المعالج {load}',
    'metrics.no_samples' => 'لا توجد عينات بعد',
    'metrics.connections_count' => '{count} اتصالات',
    'metrics.traffic_rate' => '{kb} ك.ب/ث',
    'metrics.server_metrics' => 'مقاييس الخادم',
    'explain.query_block' => 'كتلة الاستعلام #{id} (التكلفة: {cost})',
    'variables.footer_tree' => '[j/k] الشجرة',
    'variables.footer_rows' => '[j/k] الصفوف',
    'variables.footer_categories' => '[c] الفئات',
    'variables.footer_edit' => '[Enter/e] تحرير (ديناميكي)',
    'variables.footer_rw_filter' => '[w] مرشح القراءة/الكتابة',
    'variables.footer_search' => '[s] بحث',
    'variables.footer_back' => '[q] رجوع',
    'srv.title' => 'حالة الخادم',
    'srv.features' => 'الميزات',
    'srv.stored_programs' => 'البرامج المخزنة',
    'srv.directories' => 'الأدلة',
    'srv.data_directory' => 'دليل البيانات',
    'srv.temp_directory' => 'دليل الملفات المؤقتة',
    'srv.log_directory' => 'دليل السجلات',
    'srv.pid_file' => 'ملف PID',
    'srv.tls_active' => 'TLS نشط',
    'srv.tls_cipher' => 'شفرة TLS',
    'srv.tls_version' => 'إصدار TLS',
    'srv.ssl_support' => 'دعم SSL',
    'srv.ssl_ca' => 'CA الخاص بـ SSL',
    'srv.ssl_cert' => 'شهادة SSL',
    'srv.ssl_key' => 'مفتاح SSL',
    'srv.tls_secure' => 'TLS / اتصال آمن',
    'srv.replication' => 'النسخ المتماثل',
    'srv.repl_denied' => 'صلاحيات غير كافية (REPLICATION CLIENT)',
    'srv.repl_error' => 'خطأ في الوصول إلى حالة النسخ المتماثل',
    'srv.not_configured' => 'غير مهيأ',
    'srv.channel' => 'القناة',
    'srv.source_host' => 'مضيف المصدر',
    'srv.source_port' => 'منفذ المصدر',
    'srv.replica_io' => 'خيط IO للنسخة قيد التشغيل',
    'srv.replica_sql' => 'خيط SQL للنسخة قيد التشغيل',
    'srv.seconds_behind' => 'الثواني خلف المصدر',
    'srv.relay_log_file' => 'ملف سجل الترحيل',
    'srv.relay_pos' => 'موضع الترحيل',
    'srv.repl_channel_title' => 'النسخ المتماثل › {channel}',
    'srv.default' => 'افتراضي',
    'srv.firewall' => 'جدار الحماية',
    'srv.mysql_firewall' => 'جدار حماية MySQL',
    'srv.caught_up' => '0 ث (متزامن)',
    'srv.host' => 'المضيف',
    'srv.socket' => 'المقبس',
    'srv.port' => 'المنفذ',
    'srv.version' => 'الإصدار',
    'srv.uptime' => 'مدة التشغيل',
    'srv.running_since' => 'قيد التشغيل منذ',
    'srv.unknown' => 'غير معروف',
    'srv.server_information' => 'معلومات الخادم',
    'perf.tab_easy_setup' => 'الإعداد السهل',
    'perf.tab_instruments' => 'الأدوات',
    'perf.tab_consumers' => 'المستهلكون',
    'perf.tab_actors' => 'الفاعلون',
    'perf.tab_objects' => 'الكائنات',
    'perf.tab_threads' => 'الخيوط',
    'perf.tab_options' => 'الخيارات',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[للقراءة فقط]',
    'perf.nothing_to_commit' => 'لا شيء للإيداع',
    'perf.commit_planning_failed' => 'فشل تخطيط الإيداع: {error}',
    'perf.err_prepare' => 'فشل تحضير العبارة: {sql}',
    'perf.committed_one' => 'تم إيداع {count} عبارة',
    'perf.committed_other' => 'تم إيداع {count} عبارات',
    'perf.applied_one' => 'تم تطبيق {label} — {count} عبارة',
    'perf.applied_other' => 'تم تطبيق {label} — {count} عبارات',
    'perf.commit_failed' => 'فشل {label} عند العبارة {position} من {total}: {error} — تم تطبيق العبارات السابقة، وحالة الخادم جزئية.',
    'perf.err_access_denied' => 'تم رفض الوصول: الصلاحيات المطلوبة مفقودة',
    'perf.err_not_enabled' => 'Performance Schema غير مفعّل',
    'perf.err_cannot_connect' => 'تعذر الاتصال بقاعدة البيانات',
    'perf.state_fully' => 'مفعّل بالكامل',
    'perf.state_default' => 'الإعداد الافتراضي',
    'perf.state_custom' => 'إعداد مخصص',
    'perf.state_disabled' => 'معطّل',
    'perf.state_unknown' => 'غير معروف',
    'perf.current_state' => 'الحالة الحالية: {state}',
    'perf.readonly_note' => '(وضع القراءة فقط - لا صلاحيات للتعديل)',
    'perf.keys_hint' => 'المفتاح "[" تفعيل Performance Schema بالكامل   المفتاح "]" تعطيل   المفتاح "." إعادة التعيين إلى الافتراضي',
    'perf.default_instruments' => 'الأدوات الافتراضية: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'المستهلكون الافتراضيون: events_statements_history وevents_waits_history وغيرها.',
    'perf.none_instruments' => '(لا توجد أدوات متاحة)',
    'perf.none_consumers' => '(لا يوجد مستهلكون متاحون)',
    'perf.none_actors' => '(لا يوجد فاعلون مهيّؤون)',
    'perf.none_objects' => '(لا توجد قواعد كائنات مهيّأة)',
    'perf.none_threads' => '(لا توجد خيوط متاحة)',
    'perf.total_instruments_one' => 'الإجمالي: {count} أداة',
    'perf.total_instruments_other' => 'الإجمالي: {count} أدوات',
    'perf.total_consumers_one' => 'الإجمالي: {count} مستهلك',
    'perf.total_consumers_other' => 'الإجمالي: {count} مستهلكين',
    'perf.total_actors_one' => 'الإجمالي: {count} فاعل',
    'perf.total_actors_other' => 'الإجمالي: {count} فاعلين',
    'perf.total_objects_one' => 'الإجمالي: {count} قاعدة كائن',
    'perf.total_objects_other' => 'الإجمالي: {count} قواعد كائنات',
    'perf.total_threads_one' => 'الإجمالي: {count} خيط',
    'perf.total_threads_other' => 'الإجمالي: {count} خيوط',
    'perf.col_thread_id' => 'معرّف الخيط',
    'perf.col_name' => 'الاسم',
    'perf.col_type' => 'النوع',
    'perf.col_user' => 'المستخدم',
    'perf.timer_options' => 'خيارات المؤقت',
    'perf.none_timers' => '(لا توجد مؤقتات متاحة - للقراءة فقط)',
    'perf.col_timer_name' => 'اسم المؤقت',
    'perf.col_implementation' => 'التنفيذ',
    'perf.col_scale_factor' => 'عامل القياس',
    'perf.timer_readonly_note' => 'إعداد المؤقت للقراءة فقط (يحدده بناء الخادم)',
    'perf.footer_nav' => '[j/k] تنقل  [Space] تبديل',
    'perf.footer_actions' => '  [c] تجهيز الإيداع ([y] للتنفيذ)  [r] تراجع',
    'perf.footer_back' => '  [q] رجوع',
    'perf.pending_one' => 'قيد الانتظار: {count} تغيير',
    'perf.pending_other' => 'قيد الانتظار: {count} تغييرات',
    'cli.err_not_tty' => 'يشغّل هذا المشغّل طرفية تفاعلية ويرفض بث ANSI إلى أنبوب (الخروج 2) — شغّله ضمن pty (مثلاً script -qec \'candy-sql app.sqlite\' /dev/null) أو اعرض بدون واجهة من كود المكتبة (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'تسجيل السجل معطّل ({path}): {error}',
    'cli.tls_sync' => 'يتطلب ssl-mode \'{mode}\' اتصال TLS موثّقاً — لا يستطيع نقل ReactPHP الإداري تلبيته، لذا تم تعطيل التحديث المباشر للإدارة وتعمل الاستعلامات وتحميل الجداول بشكل متزامن عبر اتصال TLS.',
    'cli.no_connection_selected' => 'لم يتم اختيار اتصال — مرّر مساراً أو DSN أو --connect=<name>',
    'cli.usage_line' => 'الاستخدام: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # للحصول على معلومات الاستخدام الكاملة',
    'cli.tip_saved_connections' => '       نصيحة: احفظ الاتصالات المسماة في {path} وشغّل باستخدام --connect=<name>.',
    'help.summary' => 'candy-sql - عميل SQL للطرفية لقواعد SQLite وMySQL وPostgreSQL',
    'help.usage' => 'الاستخدام
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'المعاملات
  path-or-dsn
    مسار ملف قاعدة بيانات SQLite، مثال: app.sqlite
    أو سلسلة DSN، مثال:  mysql://user:pass@localhost:3306/dbname

  عند الحذف كليًا: في طرفية تفاعلية فيها اتصالات محفوظة يعرضها
  المنتقي؛ وإلا يُطبع سطر استخدام قصير وتنتهي العملية بالحالة 2
  (استدعاء خاطئ) — شغّل --help لعرض هذه الشاشة الكاملة.',
    'help.options' => 'الخيارات
  --help, -h
    عرض شاشة المساعدة هذه.

  --version, -V
    عرض رقم الإصدار.

  --connect=<name>
    الاتصال بالإدخال المسمى من ملف الاتصالات المحفوظة
    ($XDG_CONFIG_HOME/candy-sql/connections.json، وافتراضيًا
    $HOME/.config/candy-sql/connections.json). لا يمكن الجمع بينه وبين
    معاملات اتصال صريحة. لا تُخزَّن كلمات المرور على القرص أبدًا: يسمّي
    الإدخال سرّه عبر "pass-env" ويُقرأ المتغير وقت الاتصال.
    راجع الاتصالات المحفوظة (SAVED CONNECTIONS) أدناه.',
    'help.option_dsn' => '  --dsn=<dsn>
    سلسلة اتصال DSN كاملة.
    الصيغة: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    أمثلة:
      mysql://root:secret@localhost:3306/mydb?ssl-mode=require
      pgsql://postgres:pass@db.example.com:5432/production
      sqlite:///var/data/app.sqlite
      sqlite://:memory:',
    'help.option_params' => '  --driver=<driver>
  --db=<dbname>
  --host=<host>
  --port=<port>
  --user=<user>
  --pass=<pass>
  --ssl-mode=<mode>
    معاملات اتصال فردية (كلها اختيارية عدا --driver و--db).
    يجب أن يكون --driver أحد: sqlite, mysql, pgsql
    القيمة الافتراضية لـ --ssl-mode في MySQL هي "prefer". يفرض "require"
    و"verify_*" استخدام TLS مع التحقق من شهادة الخادم؛ ويجب ضبط الثقة
    بجهة الاعتماد على الخادم أو عبر my.cnf (انظر README). في هذه الأوضاع
    المتحقَّق منها يُرفض نقل ReactPHP القادر على النص الصريح — تعمل
    الاستعلامات بشكل متزامن عبر اتصال TLS ويُعطَّل التحديث المباشر
    للإدارة (يوضح ذلك إشعار البدء وسطر حالة التطبيق).
    فضّل CANDY_SQL_PASS على --pass= كي تبقى كلمة المرور خارج سطر أوامر
    العملية (المرئي في ps).',
    'help.examples' => 'أمثلة
  # SQLite (الافتراضي — مسار مجرد أو DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL عبر DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL عبر أعلام فردية
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'الاتصالات المحفوظة
  اسرد الخوادم المسماة مرة واحدة ثم اتصل بها دائمًا:

    $HOME/.config/candy-sql/connections.json  (المجلد 0700، الملف 0600)',
    'help.saved_fields' => '  الحقول: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. يُرفض أي حقل آخر، ويُرفض حقل "pass" النصي الصريح دائمًا —
  وجّه pass-env إلى متغير بدلًا منه. يعرض التشغيل بلا معاملات في طرفية
  تفاعلية هذه الإدخالات كمنتقٍ؛ ويتصل --connect=<name> بأحدها مباشرة.',
    'help.editing' => 'التحرير
  جزء Rows في معاينة TABLE شبكة قابلة للتحرير: يحرّك h/l (أو ←/→) مؤشر
  الخلية، وتغيّر ثلاثة اختصارات البيانات — يعرض كلٌّ منها العبارة الدقيقة
  في سطر الحالة ولا يكتب شيئًا حتى يؤكد Enter:

    e   تحرير الخلية تحت المؤشر (Ctrl+N يضبط NULL)
    o   إدراج صف (يتنقل Enter بين الأعمدة؛ الإدخال الفارغ يترك العمود
        على DEFAULT، وCtrl+N يخزّن NULL)
    D   حذف الصف تحت المؤشر

  تُحدَّد الصفوف بمفتاحها PRIMARY KEY الكامل فقط: يُرفض الجدول بلا مفتاح،
  والمفتاح NULL/الثنائي، والخلية الثنائية، وأي نتيجة استعلام، مع سطر حالة
  مسمّى. القيم معاملات مربوطة؛ وتعمل كل كتابة في معاملة تُلغى ما لم يُمَسّ
  صف واحد بالضبط (يُبلَّغ عن UPDATE الذي يكتب القيمة الموجودة أصلًا بأنه
  بلا تغيير). يلغي Esc سطر تحرير مفتوحًا.',
    'help.editing_readonly' => '  --read-only
    يعطّل اختصارات التحرير للجلسة. يحمي من الزلّات؛ ولا يرشّح عبارات جزء
    Query — لضمان صارم اتصل كمستخدم قاعدة بيانات للقراءة فقط.',
    'help.history' => 'السجل
  افتراضيًا تُسجَّل كل لقطة استطلاع إداري في
  $HOME/.candy-sql/history.db (المجلد 0700 / الملف 0600) كمسار تدقيق
  دون اتصال. لا تقرأ صفحتا Dashboard وReports في التطبيق هذا الملف —
  بل تستطلعان الخادم المباشر.',
    'help.history_flags' => '  --no-history
    تعطيل التسجيل كليًا (لا يُكتب شيء على القرص).

  --history=<path>
    تخزين اللقطات في <path> بدلًا من الموقع الافتراضي.',
    'help.keyboard' => 'اختصارات لوحة المفاتيح (TUI)
  Tab          تدوير التركيز بين الأجزاء (Tables → Rows → Query → Admin)
  ↑ / ↓        التنقل بين صفوف القائمة (أو j/k) — في جزء Tables الحركة محلية فقط؛
               ولا تجلب معاينة أبدًا
  /            (جزء Tables) فتح سطر ترشيح مباشر على أسماء الجداول؛ تمدّه الحروف،
               ويحرّك ↑/↓ المؤشر، ويثبّت Enter ويحمّل، ويعدّل Backspace، ويتخلى Esc
               ويمسح
  Enter        تحميل صفوف الجدول المحدد
  Space        تحميل صفوف الجدول المحدد (اسم بديل لـ Enter)
  PgDn / PgUp  (جزء Tables/Rows، على معاينة جدول) تصفّح المعاينة بنوافذ من 100 صف —
               أصبح حدّ التصفح حجم الصفحة
  Ctrl+Enter   تنفيذ الاستعلام الحالي
  Ctrl+R       تنفيذ الاستعلام الحالي
  Ctrl+E       تنفيذ الاستعلام الحالي
  EXPLAIN …    (محرر Query، الكلمة المفتاحية الأولى) يمر EXPLAIN المكتوب عبر
               ExplainView: يعرض جزء Rows شجرة الخطة الملونة لا شبكة المزوّد المسطحة —
               ويُزال أي معدِّل ANALYZE، فالمفتش لا ينفذ أبدًا ما يفحصه
  e / o / D    (جزء Rows، معاينة جدول) تحرير الخلية / إدراج صف /
               حذف الصف — انظر التحرير (EDITING)؛ يحرّك h/l مؤشر الخلية
  Ctrl+Space   إكمال عند المؤشر (محرر Query): كلمات SQL المفتاحية، وأسماء الجداول بعد
               FROM/JOIN/INTO/UPDATE، والأعمدة بحسب السياق (WHERE/SELECT/ORDER BY،
               المؤهلة بـ `alias.` / `table.`، وقائمة INSERT INTO t (...)) من المخطط
               المحمّل. مرشح واحد يُكمَل، وبادئة مشتركة تتقدم، وإلا يسرد سطر الحالة
               المرشحين
  Ctrl+F       إضافة الاستعلام الحالي إلى قائمة المفضلة (حالة الجلسة فقط — لا يعرضها
               أي جزء ولا تُحفظ)
  Ctrl+Shift+F إزالة الاستعلام الحالي من قائمة المفضلة
  1-8          الانتقال إلى جزء إداري (فقط عند تركيز جزء Admin)
  q            الخروج من جزأي Tables/Rows؛ والعودة إلى Tables من Admin
  x            (Tables/Rows، نتيجة مباشرة) تصدير الصفوف الحالية إلى مسار CSV —
               يبقى رفض الكتابة فوق الملف مفعّلًا حتى يؤكد Enter
  i            (Tables/Rows) استيراد ملف CSV إلى جدول مسمى
  Ctrl+S       تصدير النتيجة/المعاينة المباشرة إلى CSV من أي جزء
  Esc          الخروج من جزأي Tables/Rows؛ إلغاء حوار Admin المفتوح
  Backspace    حذف الحرف قبل المؤشر (محرر Query)',
    'help.see_also' => 'انظر أيضًا
  https://github.com/sugarcraft/candy-sql',
];
