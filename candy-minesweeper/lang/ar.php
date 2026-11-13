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
    'ui.status' => 'ألغام: {mines}  ·  أعلام: {flags}  ·  متبقٍ: {remaining}  ·  وقت: {time}',
    'ui.status_stats' => '{preset}  ·  أعلام: {flags}  ·  متبقٍ: {remaining}  ·  وقت: {time}  ·  أفضل: {best}',
    'ui.preset_easy' => 'سهل',
    'ui.preset_standard' => 'قياسي',
    'ui.preset_medium' => 'متوسط',
    'ui.preset_expert' => 'خبير',
    'ui.boom' => '💥 بوم — اضغط r لإعادة البدء',
    'ui.cleared' => '★ تمّ المسح — اضغط r للعب مجدداً',
    'ui.help_rules' => 'الرقم يحسب الألغام المجاورة',
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
    'ui.resume' => 'هناك حفظ بانتظارك — اضغط L للتحميل',
    'ui.new_best' => 'رقم قياسي جديد',
    'ui.beat_best' => '{prev} → رقم قياسي جديد',
    'ui.fit_notice' => 'تحتاج candy-minesweeper إلى طرفية بارتفاع {need} صفاً؛ هذا الطرف {have}. وسّع النافذة وستستأنف اللعبة فور اتساعها لها. اضغط q للخروج.',
];
