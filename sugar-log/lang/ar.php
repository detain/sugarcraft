<?php

/**
 * Arabic translations for sugar-log.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'logger.fatal' => 'سجل致命的: {message}',
    'logger.invalid_stream' => 'يجب أن يكون التدفق موردًا صالحًا',
    'psrbridge.not_string' => 'يجب أن تكون درجة السجل إحدى ثوابت PSR-3 LogLevel؛ المستلم {got}',
    'psrbridge.unknown_level' => 'درجة سجل غير معروفة \'{level}\'; المتوقع إحدى ثوابت PSR-3 LogLevel',
    'parts.unknown' => 'جزء (أجزاء) غير معروف من سطر السجل: {parts}',
    'parts.duplicate' => 'جزء (أجزاء) مكرر من سطر السجل: {parts}',
    'log.fatal_error' => 'خطأ قاتل',
    'log.fatal_parse' => 'خطأ تحليل',
    'log.fatal_core' => 'خطأ النواة',
    'log.fatal_compile' => 'خطأ ترجمة',
    'log.fatal_user' => 'خطأ المستخدم',
    'log.fatal_shutdown' => 'خطأ الإيقاف',
    'logger.unopenable_stream' => 'لا يمكن فتح ملف السجل \'{path}\'',
];
