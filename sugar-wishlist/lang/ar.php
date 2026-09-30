<?php

/**
 * Arabic translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec غير متاح; ext-pcntl مطلوب',
    'launcher.exec_failed'     => 'فشل تنفيذ {bin}',
    'config.not_found'         => 'تكوين wishlist غير موجود: {path}',
    'config.json_top_level'    => 'wishlist json: القيمة عالية المستوى يجب أن تكون مصفوفة',
    'config.json_entry_object' => 'wishlist json: كل إدخال يجب أن يكون كائنًا',
    'config.yaml_continuation' => "wishlist yaml: لا يمكن تعيين المتابعة قبل أي كتلة '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: سطر غير قابل للتحليل: {line}',
    'config.entry_missing_field' => 'إدخال wishlist بحقل مطلوب مفقود: name أو host',
    'config.field_not_scalar'  => 'إدخال wishlist: الحقل [{field}] يجب أن يكون قياسياً، والنوع {type}',

    // endpoint security
    'endpoint.option_injection' => 'نقطة نهاية wishlist: الحقل [{field}] يحتوي قيمة غير آمنة تبدأ بشرطة [{value}]',
    'endpoint.port_invalid'     => 'نقطة نهاية wishlist [{host}]: منفذ غير صالح [{port}] — يجب أن يكون عدداً صحيحاً من 1 إلى 65535',

    'cli.usage'         => 'الاستخدام: wishlist [--config <مسار>] [--ssh <ssh-ثنائي>]',
    'cli.unknown_arg'   => 'wishlist: وسيطة غير معروفة: {arg}',
    'cli.no_config'     => 'wishlist: لم يتم العثور على تكوين. مرر --config <مسار>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: تم الإلغاء.',
    'cli.ssh_not_executable' => 'wishlist: ثنائي ssh غير موجود أو غير قابل للتنفيذ: {bin}',
];
