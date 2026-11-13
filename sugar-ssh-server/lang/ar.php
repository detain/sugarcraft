<?php

/**
 * Arabic translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'تعذر فتح php://stderr',
    'logger.cannot_open_target'      => 'تعذر فتح هدف السجل: {target}',
    'logger.invalid_target'          => 'هدف المسجل يجب أن يكون مسارًا أو موردًا أو null',
    'bubbletea.bad_factory'          => 'مصنع BubbleTea يجب أن يُرجع كائنًا له طريقة run(); تم الحصول على: {got}',
    'middleware.cannot_open_stream'   => 'cannot open {target}',
    'middleware.stream_not_resource'  => '{target} must be a resource',
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'تم تجاوز حد الطلبات. حاول مرة أخرى لاحقًا.',
    'ratelimit.unavailable_denying'   => 'محدّ معدل الطلبات غير متاح، يتم رفض الاتصال: {why}',
    'ratelimit.degraded_allowing'     => 'محدّ معدل الطلبات في حالة تدهور، يتم السماح بالاتصال (فتح عند الخطأ): {why}',
    'ratelimit.state_lost'            => 'تعذّر حفظ حالة محدّ الطلبات (هذا التحديث مفقود): {why}',
    'auth.unauthorized'               => 'غير مصرح. ({reason})',
    'passwordauth.permission_denied'  => 'تم رفض الإذن.',
    'certificateauth.required'        => 'مطلوب شهادة ولكن لم يتم تقديم أي شهادة.',
    'certificateauth.rejected'        => 'تم رفض الشهادة.',
    'certificateauth.env_missing'     => 'لا توجد شهادة عميل في البيئة (تمت البحث عن: {vars})؛ لم يتحقق التوثيق بالشهادة من شيء.',
    'keyboardinteractive.auth_failed' => 'فشلت المصادقة.',
    'keyboardinteractive.input_visible' => 'لم يتم إسكات هذا الطرفية: كل ما تكتبه هنا يظهر على الشاشة.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'تم إسقاط متغيرات البيئة الواردة من الند لأنها تجاوزت الميزانية المقبولة.',
    'transport.pty_outside_loop' => 'تم استدعاء pty() خارج حلقة الضخ النشطة',
    'keyboardinteractive.bad_challenge' => 'يجب أن يكون التحدي {index} سلسلة مطالبة نصية أو مصفوفًا يحتوي على مفتاح "prompt" نصي؛ تم الحصول على: {got}.',
    'auth.reject_user' => 'المستخدم غير مسموح به: {name}',
    'auth.reject_key' => 'المفتاح غير مسموح به: {fingerprint}',
    'auth.reject_unpaired' => 'المفتاح غير مرتبط بالمستخدم المطلوب: {fingerprint} باسم {name}',
    'auth.reject_validation' => 'فشل التحقق من المفتاح: {fingerprint}',
    'middleware.async_auth_failed' => 'فشلت المصادقة: {why}',
];
