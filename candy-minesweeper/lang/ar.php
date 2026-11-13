<?php

/**
 * Arabic translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'اللوحة صغيرة جدًا',
    'board.minecount_out_of_range' => 'عدد الألغام خارج النطاق',
    'board.rows_shape_mismatch' => 'بيانات الشبكة لا تطابق اللوحة {width}×{height}',
    'custom.min_rows' => 'يجب أن يكون عدد الصفوف 2 على الأقل',
    'custom.min_cols' => 'يجب أن يكون عدد الأعمدة 2 على الأقل',
    'custom.max_rows' => 'يجب ألا يتجاوز عدد الصفوف 50',
    'custom.max_cols' => 'يجب ألا يتجاوز عدد الأعمدة 50',
    'custom.min_mines' => 'مطلوب لغم واحد على الأقل',
    'custom.max_mines' => 'ألغام كثيرة جدًا — اترك منطقة بداية آمنة',
    'custom.min_area'            => 'اللوحة المخصصة تحتاج 10 خانات على الأقل (صفوف × أعمدة)',
    'custom.label' => '{width}×{height} · {mines} ألغام',
    'ui.status' => 'ألغام: {mines}  ·  أعلام: {flags}  ·  متبقٍ: {remaining}  ·  وقت: {time}',
    'ui.status_stats' => '{preset}  ·  أعلام: {flags}  ·  متبقٍ: {remaining}  ·  وقت: {time}  ·  أفضل: {best}',
    'ui.preset_easy' => 'سهل',
    'ui.preset_standard' => 'قياسي',
    'ui.preset_medium' => 'متوسط',
    'ui.preset_expert' => 'خبير',
    'ui.boom' => '💥 بوم — اضغط r لإعادة البدء · n للوحة أخرى',
    'ui.cleared' => '★ تمّ المسح — اضغط r للعب مجدداً · n للوحة أخرى',
    'ui.help_rules' => 'الرقم يحصي الألغام المجاورة · علّم المشبوهة · اكشف كل الآمنة لتفوز',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  تحريك  ·  مسافة / enter  كشف',
    'ui.help_board' => 'النقر الأيسر  فتح · f / النقر الأيمن  علم · c / الزر الأوسط  فتح ما حول الأعلام',
    'ui.help_loss' => '{mark} علم خاطئ · * لغم غير مكتشف',
    'ui.help_session' => 'r  إعادة  ·  S  حفظ  ·  L  تحميل  ·  q / Esc / Ctrl+C  خروج',
    'ui.saved' => 'تم حفظ اللعبة',
    'ui.restarted' => 'تمت إعادة تشغيل اللوحة',
    'ui.loaded' => 'تم تحميل الحفظ',
    'ui.no_save' => 'لا يوجد حفظ للتحميل',
    'ui.load_refused' => 'رفض الحفظ (غير قابل للقراءة أو معدَّل)',
    'ui.save_failed' => 'فشل الحفظ',
    'ui.chord_not_ready' => 'الفتح حوله يحتاج أعلامًا تطابق الرقم',
    'ui.confirm_quit' => 'اضغط q مرة أخرى للخروج — اللعبة غير محفوظة',
    'ui.resume' => 'هناك حفظ بانتظارك — اضغط L للتحميل',
    'ui.new_best' => 'رقم قياسي جديد',
    'ui.beat_best' => '{prev} → رقم قياسي جديد',
    'ui.picker_title' => 'اختر لوحة',
    'ui.picker_custom' => 'مخصص',
    'ui.picker_hint' => '1-4 للبدء · c لوحة مخصصة · q / Esc رجوع',
    'ui.form_width' => 'عرض',
    'ui.form_height' => 'ارتفاع',
    'ui.form_mines' => 'ألغام',
    'ui.form_hint' => 'اكتب الأرقام · enter للحقل التالي · enter عند الألغام يبدأ · esc رجوع',
    'ui.fit_notice' => 'تحتاج candy-minesweeper إلى طرفية بارتفاع {need} صفاً؛ هذا الطرف {have}. وسّع النافذة وستستأنف اللعبة فور اتساعها لها. اضغط q للخروج.',
];
