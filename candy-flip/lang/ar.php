<?php

/**
 * Arabic translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: الملف غير موجود: {path}',
    'decoder.no_gd'          => 'candy-flip: ext-gd مطلوب',
    'decoder.not_gif'        => 'candy-flip: ليس ملف GIF',
    'decoder.grid_too_large' => 'candy-flip: تجاوز حاصل شبكة الخلايا الحد الأقصى ({max})',
    'decoder.grid_too_small' => 'candy-flip: يجب أن تكون أبعاد شبكة الخلايا موجبة',
    'decoder.truncated'      => 'candy-flip: ملف GIF مبتور',
    'cli.usage'              => 'الاستخدام: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: تعذر العثور على composer autoload.php',
];
