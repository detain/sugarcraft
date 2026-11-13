<?php

/**
 * Arabic translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'فهرس لون ANSI 16 يجب أن يكون 0–15، تم الحصول على: {index}',
    'standard.ansi256_out_of_range' => 'فهرس لون ANSI 256 يجب أن يكون 0–255، تم الحصول على: {index}',
    'color.invalid_hex' => 'لون سداسي غير صالح: {hex}',
    'colormath.invalid_xyz' => 'مكون XYZ {key} مفقود أو ليس عددًا منتهيًا',
    'deltae.invalid_lab' => 'مصفوفة Lab {label} تفتقر إلى المكون الرقمي "{key}"',
    'deltae.non_finite_lab' => 'مصفوفة Lab {label} تحتوي على مكون "{key}" غير منتهٍ',
    'distance.euclidean_needs_rgb' => 'المقياس الإقليدي يقارن بايتات RGB — استخدم between() بدلاً من betweenLab()',
    'nearest.empty_palette' => 'لا يمكن مطابقة لون مع لوحة ألوان فارغة',
    'probe.unknown_capability' => 'مفاتيح القدرات غير المعروفة: {keys}',
    'nearest.error_shape' => 'withError() يتوقع قائمة من 3 عناصر [r, g, b]; تم استلام {count} عنصر(عناصر)',
    'nearest.error_channel' => 'يجب أن تكون قنوات مجموعة withError() من النوع int أو float; المستلم: {type}',
    'palette_index.metric' => 'يطلب PaletteIndex مقياسًا يحافظ على المسافة (Euclidean أو Cie76); لا يحتوي {metric} على أساس PCA ثابت - استخدم NearestColor::closest() بدلاً من ذلك',
];
