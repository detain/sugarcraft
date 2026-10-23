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
    'config.yaml_unparseable'  => 'wishlist yaml: سطر غير قابل للتحليل: {line}',
    'config.entry_missing_field' => 'إدخال wishlist بحقل مطلوب مفقود: name أو host',
    'config.field_not_scalar'  => 'إدخال wishlist: الحقل [{field}] يجب أن يكون قياسياً، والنوع {type}',
    'config.unreadable'       => 'تعذر قراءة تكوين wishlist: {path}',
    'config.home_unset'       => 'لا يمكن توسيع ~ بدون HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: مجموعة التدفق المضمّنة غير مدعومة: {value} (استخدم قائمة متداخلة)',

    // endpoint security
    'endpoint.option_injection' => 'نقطة نهاية wishlist: الحقل [{field}] يحتوي قيمة غير آمنة تبدأ بشرطة [{value}]',
    'endpoint.port_invalid'     => 'نقطة نهاية wishlist [{host}]: منفذ غير صالح [{port}] — يجب أن يكون عدداً صحيحاً من 1 إلى 65535',

    'cli.usage'         => 'الاستخدام: candy-ssh-launcher [--config <مسار>] [--ssh <ssh-ثنائي>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: وسيطة غير معروفة: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: لم يتم العثور على تكوين. مرر --config <مسار>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: تم الإلغاء.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ثنائي ssh غير موجود أو غير قابل للتنفيذ: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} يتطلب قيمة',
    'cli.no_endpoints'   => 'candy-ssh-launcher: لا توجد نقاط نهاية في {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'تصفية: ',
    'picker.no_matches'  => '  (لا تطابقات)',
    'picker.help'        => '  ↑/↓ تحديد · Enter اتصال · Esc خروج · اكتب للتصفية',
    'picker.count'       => '  ({shown} من {total})',
];
