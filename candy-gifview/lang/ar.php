<?php

/**
 * Arabic translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'الاستخدام: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: تعذر العثور على composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: خيار غير معروف --{flag}',
    'cli.bad_value'   => 'candy-gifview: قيمة غير صالحة \'{value}\' الخاص بـ {flag} (المطلوب رقم موجب؛ كما يقبل --speed الصيغتين Nfps أو max)',
    'cli.too_many'    => 'candy-gifview: وسيط غير متوقع \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: لا يقبل --{flag} أي قيمة',
];
