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

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'تم إلغاء الإرسال قبل بدئه',
    'smtp.tls_required'            => 'TLS مطلوب ولكن لم يمكن إقامته: {host} لم يقدم STARTTLS',
    'smtp.auth_insecure'           => 'رفض المصادقة عبر قناة SMTP غير آمنة (غير مشفرة)',
    'smtp.empty_response'          => 'أرسل الخادم استجابة فارغة',
    'smtp.send_cancelled'          => 'تم إلغاء إرسال SMTP قبل بدئه',
    'smtp.write_error'             => 'فشلت الكتابة إلى خادم SMTP',
    'smtp.incomplete_write'        => 'كتابة غير مكتملة إلى خادم SMTP',
    'email.crlf_in_address'        => 'تم رصد حقن CRLF في عنوان البريد الإلكتروني',
    'email.invalid_address'        => 'عنوان بريد إلكتروني غير صالح: {addr}',
    'email.crlf_in_header'         => 'تم رصد حقن CRLF في الترويسة {field}',
    'resend.send_cancelled'        => 'تم إلغاء إرسال Resend قبل بدئه',
    'resend.curl_missing'          => 'مطلوب ext-curl لنقل Resend (نقل SMTP لا يحتاج امتدادات)',
    'attachment.unreadable'        => 'ملف المرفق غير قابل للقراءة: {path}',
    'attachment.no_path'           => 'يجب توفير مسار الملف عند الإرفاق باسم الملف',
    'attachment.invalid_cid'       => 'تنسيق معرّف المحتوى غير صالح: {cid} (RFC 2392 يتطلب تنسيق msg-id)',
    'cli.unknown_option'           => 'خيار غير معروف: {option}',
    'cli.invalid_port'             => 'منفذ SMTP غير صالح: {port} (يجب أن يكون بين 1 و65535)',
];
