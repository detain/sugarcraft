<?php

/**
 * Russian translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'Письмо должно иметь хотя бы одного получателя (to, cc или bcc)',
    'mailer.no_from'             => 'Письмо должно иметь адрес отправителя',
    'smtp.send_failed'           => 'Отправка SMTP не удалась: {message}',
    'smtp.connect_failed'        => 'Не удаётся подключиться к {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'Переговоры STARTTLS не удались',
    'smtp.not_connected'         => 'Не подключено',
    'smtp.no_response'           => 'Сервер не отправил ответ',
    'smtp.unexpected_response'   => 'Неожиданный ответ SMTP: {response}',
    'resend.network_error'       => 'Сетевая ошибка Resend: {error}',
    'resend.api_error'           => 'Ошибка API Resend ({status}): {body}',
    'cli.error'                  => 'Ошибка: {message}',
    'cli.transport_error'        => 'Ошибка транспорта: {message}',
    'cli.send_failed'            => 'Отправка не удалась: {message}',
    'cli.email_sent'             => '✓ Письмо отправлено через {transport}.',
    'cli.no_to_recipient'        => 'Не указан получатель --to',
    'cli.attachment_not_found'   => 'Файл вложения не найден: {file}',
    'cli.no_transport'           => 'Транспорт не настроен. Установите переменную окружения RESEND_API_KEY или CANDY_MAIL_SMTP_HOST.',
];
