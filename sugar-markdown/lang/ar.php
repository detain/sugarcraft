<?php

/**
 * Arabic translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'تعذر قراءة ملف السمة: {path}',
    'theme.json_invalid'           => 'JSON غير صالح في ملف السمة',
    'theme.json_object'            => 'يجب أن يتم فك ترميز JSON للسمة إلى كائن',
    'theme.bad_color'              => 'تحديد لون غير صالح: {spec}',
    'renderer.unknown_style'       => 'نمط قياسي غير معروف: {name}',
    'renderer.stream_invalid'      => 'تطلب write() مورد تدفق',
    'renderer.stream_not_writable' => 'تطلب write() تدفقًا قابلًا للكتابة؛ تم الحصول على النمط "{mode}"',
    'renderer.write_failed'        => 'فشلت كتابة {bytes} بايت إلى تدفق الإخراج',
    'renderer.chunk_invalid'       => 'يجب أن تكون مقاطع stream() سلاسل نصية',
    'sink.closed'                  => 'المصب مغلق',
    'sink.parent_missing'          => 'دليل الإخراج غير موجود: {dir}',
    'sink.path_unwritable'         => 'مسار الإخراج غير قابل للكتابة: {path}',
    'sink.open_failed'             => 'فشل في فتح مسار الإخراج: {path}',
    'sink.flush_failed'            => 'فشلت عملية إفراغ تدفق الإخراج',
    'writer.feed_after_close'      => 'تم استدعاء feed() بعد close() — انتهى الكاتب',
];
