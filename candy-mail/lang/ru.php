<?php

/**
 * Russian translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'Письмо должно иметь хотя бы одного получателя (to, cc или bcc)',
    'mailer.no_from' => 'Письмо должно иметь адрес отправителя',
    'smtp.send_failed' => 'Отправка SMTP не удалась: {message}',
    'smtp.connect_failed' => 'Не удаётся подключиться к {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'Переговоры STARTTLS не удались',
    'smtp.not_connected' => 'Не подключено',
    'smtp.no_response' => 'Сервер не отправил ответ',
    'smtp.unexpected_response' => 'Неожиданный ответ SMTP: {response}',
    'resend.network_error' => 'Сетевая ошибка Resend: {error}',
    'resend.api_error' => 'Ошибка API Resend ({status}): {body}',
    'cli.error' => 'Ошибка: {message}',
    'cli.transport_error' => 'Ошибка транспорта: {message}',
    'cli.send_failed' => 'Отправка не удалась: {message}',
    'cli.email_sent' => '✓ Письмо отправлено через {transport}.',
    'cli.no_to_recipient' => 'Не указан получатель --to',
    'cli.attachment_not_found' => 'Файл вложения не найден: {file}',
    'cli.no_transport' => 'Транспорт не настроен. Установите переменную окружения RESEND_API_KEY или CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Отправка отменена до начала',
    'smtp.tls_required' => 'Требуется TLS, но установить не удалось: {host} не предложил STARTTLS',
    'smtp.auth_insecure' => 'Отказ в аутентификации по незащищённому (незашифрованному) каналу SMTP',
    'smtp.empty_response' => 'Сервер отправил пустой ответ',
    'smtp.send_cancelled' => 'Отправка SMTP отменена до начала',
    'smtp.write_error' => 'Не удалось выполнить запись на сервер SMTP',
    'smtp.incomplete_write' => 'Неполная запись на сервер SMTP',
    'smtp.invalid_helo_host' => 'Хост HELO {helo} не является допустимым именем хоста (только буквы, цифры, точки и дефисы)',
    'smtp.auth_unavailable' => 'SMTP-сервер {host} не предложил AUTH; отказываемся ретранслировать без аутентификации при настроенных учётных данных',
    'smtp.response_too_long' => 'Ответ SMTP-сервера превысил ограничение размера',
    'smtp.response_timeout' => 'Ответ SMTP-сервера превысил бюджет времени',
    'transport.not_serializable' => 'Транспорт с учётными данными нельзя сериализовать или импортировать обратно; создавайте его там, где он используется',
    'email.crlf_in_address' => 'Обнаружена CRLF-инъекция в адресе электронной почты',
    'email.invalid_address' => 'Недопустимый адрес электронной почты: {addr}',
    'email.crlf_in_header' => 'Обнаружена CRLF-инъекция в заголовке {field}',
    'resend.send_cancelled' => 'Отправка Resend отменена до начала',
    'resend.curl_missing' => 'Для транспорта Resend требуется ext-curl (транспорт SMTP не требует расширений для обычного SMTP; для TLS требуется ext-openssl)',
    'attachment.unreadable' => 'Файл вложения не читается: {path}',
    'attachment.no_path' => 'При вложении по имени файла необходимо указать путь к файлу',
    'attachment.invalid_cid' => 'Недопустимый формат Content-ID: {cid} (RFC 2392 требует формат msg-id)',
    'attachment.not_a_file' => 'Путь к вложению {path} не является обычным файлом',
    'attachment.too_large' => 'Вложение превышает лимит в {limit} байт',
    'cli.unknown_option' => 'Неизвестная опция: {option}',
    'cli.did_you_mean' => 'Вы имели в виду {suggestion}?',
    'cli.invalid_port' => 'Недопустимый порт SMTP: {port} (должен быть от 1 до 65535)',
    'cli.missing_value' => 'Параметр {option} требует значение (для значений через дефис пишите --{option}=ЗНАЧЕНИЕ)',
    'cli.no_sender' => 'Не указан адрес отправителя: передайте --from=АДРЕС или задайте CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => 'Переменная окружения {name} содержит недопустимое значение флага {value}; используйте 1/true/yes/on или 0/false/no/off',
    'cli.ca_file_unreadable' => 'Файл CA {file} не является доступным для чтения обычным файлом',
    'cli.invalid_timeout' => 'Тайм-аут {timeout} — не число секунд от 1 до 3600',
    'cli.render_resend_divergence' => 'Примечание: --render показывает предпросмотр SMTP MIME-конверта; настроенный транспорт — Resend, который отправляет JSON-поле. Приведённые ниже байты не будут отправлены.',
];
