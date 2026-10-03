<?php

/**
 * Arabic translations for candy-palette.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'فهرس لون ANSI 16 يجب أن يكون 0–15، تم الحصول على: {index}',
    'color.invalid_hex' => 'لون سداسي غير صالح: {hex}',
    'colormath.invalid_xyz' => 'مكون XYZ {key} مفقود أو ليس عددًا منتهيًا',
    'deltae.invalid_lab' => 'مصفوفة Lab {label} تفتقر إلى المكون الرقمي "{key}"',
    'deltae.non_finite_lab' => 'مصفوفة Lab {label} تحتوي على مكون "{key}" غير منتهٍ',
    'distance.euclidean_needs_rgb' => 'المقياس الإقليدي يقارن بايتات RGB — استخدم between() بدلاً من betweenLab()',
    'nearest.empty_palette' => 'لا يمكن مطابقة لون مع لوحة ألوان فارغة',
];
