<?php

/**
 * Arabic translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'يجب أن يحتوي البريد الإلكتروني على مستلم واحد على الأقل (إلى أو cc أو bcc)',
    'mailer.no_from'             => 'يجب أن يحتوي البريد الإلكتروني على عنوان مرسل',
    'smtp.send_failed'           => 'فشل إرسال SMTP: {message}',
    'smtp.connect_failed'        => 'تعذر الاتصال بـ {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'فشلت مفاوضات STARTTLS',
    'smtp.not_connected'         => 'غير متصل',
    'smtp.no_response'           => 'الخادم لم يرسل ردًا',
    'smtp.unexpected_response'   => 'رد SMTP غير متوقع: {response}',
    'resend.network_error'       => 'خطأ شبكة Resend: {error}',
    'resend.api_error'           => 'خطأ API Resend ({status}): {body}',
    'cli.error'                  => 'خطأ: {message}',
    'cli.transport_error'        => 'خطأ في النقل: {message}',
    'cli.send_failed'            => 'فشل الإرسال: {message}',
    'cli.email_sent'             => '✓ تم إرسال البريد الإلكتروني عبر {transport}.',
    'cli.no_to_recipient'        => 'لم يتم تحديد مستلم --to',
    'cli.attachment_not_found'   => 'المرفق غير موجود: {file}',
    'cli.no_transport'           => 'لم يتم تكوين النقل. اضبط متغير البيئة RESEND_API_KEY أو CANDY_MAIL_SMTP_HOST.',
];
