<?php

/**
 * Arabic translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'فشل تنفيذ {bin}',
    'config.not_found'         => 'تكوين wishlist غير موجود: {path}',
    'config.json_top_level'    => 'wishlist json: القيمة عالية المستوى يجب أن تكون مصفوفة',
    'config.json_entry_object' => 'wishlist json: كل إدخال يجب أن يكون كائنًا',
    'config.yaml_continuation' => "wishlist yaml: لا يمكن تعيين المتابعة قبل أي كتلة '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: سطر غير قابل للتحليل {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: تعيين كتلة تحت `{field}:` في السطر {line}: {found} — يجب أن يكون هذا الحقل قائمة عناصر `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: علامة اقتباس غير مغلقة في القيمة: {found}',
    'config.entry_missing_field' => 'عنصر wishlist رقم {index}: يفتقد حقلاً إلزامياً: {field}',
    'config.unknown_key' => 'تم تجاهل الحقل غير المعروف `{key}` — ليس مفتاحاً موثقاً',
    'config.unknown_key_nearmiss' => 'تم تجاهل الحقل غير المعروف `{key}` — هل قصدت `{field}`؟',
    'config.duplicate_name' => 'اسم نقطة اتصال مكرر `{name}` — تُحمَّل كل الصفوف؛ الأسماء معرِّفات',
    'config.field_not_scalar'  => 'إدخال wishlist: الحقل [{field}] يجب أن يكون قياسياً، والنوع {type}',
    'config.unreadable'       => 'تعذر قراءة تكوين wishlist: {path}',
    'config.home_unset'       => 'لا يمكن توسيع ~ بدون HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: مجموعة التدفق المضمّنة غير مدعومة: {value} (استخدم قائمة متداخلة)',

    // endpoint security
    'endpoint.option_injection' => 'نقطة نهاية wishlist: الحقل [{field}] يحتوي قيمة غير آمنة تبدأ بشرطة [{value}]',
    'endpoint.host_empty' => 'النقطة `{name}` تحتوي مضيفًا فارغًا — يحتاج الصف القابل للاختيار إلى اسم مضيف',
    'endpoint.name_empty' => 'اسم نقطة الاتصال فارغ — الصف القابل للتحديد يحتاج اسماً',
    'endpoint.option_empty' => 'النقطة `{name}` تحتوي مدخل خيار فارغًا — قيمة `-o` الفارغة مرفوضة',
    'endpoint.port_invalid'     => 'نقطة نهاية wishlist [{host}]: منفذ غير صالح [{port}] — يجب أن يكون عدداً صحيحاً من 1 إلى 65535',

    'cli.usage'         => 'الاستخدام: candy-ssh-launcher [--config <مسار>] [--ssh <ssh-ثنائي>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: وسيطة غير معروفة: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: لم يتم العثور على تكوين. أنشئ ~/.config/wishlist.yml (راجع البدء السريع في README) أو مرر --config <مسار>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: تم الإلغاء.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ثنائي ssh غير موجود أو غير قابل للتنفيذ: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} يتطلب قيمة',
    'cli.no_endpoints'   => 'candy-ssh-launcher: لا توجد نقاط نهاية في {path}',
    'cli.not_a_terminal' => 'يحتاج المحدد إلى طرفية تفاعلية؛ يجب أن يكون stdin وstdout من نوع TTY. استخدم --list للحصول على مخرج قابل للقراءة آليًا.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'تصفية: ',
    'picker.no_matches'  => '  (لا تطابقات)',
    'picker.help'        => '  ↑/↓ أو j/k تحديد · Enter اتصال · Esc خروج · اكتب للتصفية',
    'picker.count'       => '  ({shown} من {total})',
];
