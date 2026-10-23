<?php

/**
 * Arabic translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'اللوحة صغيرة جدًا',
    'board.minecount_out_of_range' => 'mineCount خارج النطاق',
    'board.rows_shape_mismatch' => 'شكل الصفوف لا يطابق العرض/الارتفاع',
    'custom.min_rows' => 'يجب أن يكون عدد الصفوف 2 على الأقل',
    'custom.min_cols' => 'يجب أن يكون عدد الأعمدة 2 على الأقل',
    'custom.max_rows' => 'يجب ألا يتجاوز عدد الصفوف 50',
    'custom.max_cols' => 'يجب ألا يتجاوز عدد الأعمدة 50',
    'custom.min_mines' => 'مطلوب لغم واحد على الأقل',
    'custom.max_mines' => 'ألغام كثيرة جدًا — اترك منطقة بداية آمنة',
    'ui.status' => 'ألغام: {mines}  ·  أعلام: {flags}  ·  متبقٍ: {remaining}  ·  وقت: {time}',
    'ui.boom' => '💥 بوم — اضغط r لإعادة البدء',
    'ui.cleared' => '★ تمّ التنقية — اضغط r للعب مجدداً',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  تحريك  ·  مسافة / enter  كشف',
    'ui.help_board' => 'f  علم  ·  c / الزر الأوسط  وتر  ·  النقر الأيمن  علم',
    'ui.help_session' => 'r  إعادة  ·  S  حفظ  ·  L  تحميل  ·  q / Esc / Ctrl+C  خروج',
];
