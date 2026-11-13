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
];
